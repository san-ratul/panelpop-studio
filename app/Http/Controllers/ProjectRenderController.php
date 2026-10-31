<?php

namespace App\Http\Controllers;

use App\AI\Exceptions\ProviderException;
use App\Enums\AssetType;
use App\Enums\GenerationJobType;
use App\Enums\PresentationMode;
use App\Enums\ProjectStatus;
use App\Enums\VideoMetadataPlatform;
use App\Http\Controllers\Support\GenerationJobPresenter;
use App\Http\Requests\GenerateVideoMetadataRequest;
use App\Http\Requests\SaveVideoMetadataRequest;
use App\Models\Asset;
use App\Models\Project;
use App\Models\Video;
use App\Services\AudioLicenceGate;
use App\Services\Cost\VideoCostReport;
use App\Services\GenerationJobGuard;
use App\Services\Render\BinaryProbe;
use App\Services\VideoMetadataService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Per-video render workflow: initiate rendering, monitor status, retry, and
 * reach the authorized download/preview endpoints (downloads flow through the
 * policy-checked assets.show route — private paths are never exposed).
 * Pre-flight itself runs inside the queued job; this layer only guards
 * obvious duplicate launches and machine configuration.
 *
 * Video metadata (title/description for YouTube Shorts / Facebook Reels) is
 * generated synchronously through the standard provider pipeline and only
 * persisted via the explicit save action; provider failures here never
 * affect the completed render or its downloads.
 */
class ProjectRenderController extends Controller
{
    public function __construct(
        private readonly GenerationJobGuard $jobGuard,
        private readonly BinaryProbe $binaryProbe,
        private readonly AudioLicenceGate $licenceGate,
        private readonly VideoMetadataService $metadataService,
        private readonly VideoCostReport $costReport,
    ) {}

    public function show(Project $project, Video $video): Response
    {
        $this->authorize('view', $video);

        $renderJobs = GenerationJobPresenter::recent($video, GenerationJobType::VideoRender);

        $assets = $video->assets()
            ->whereIn('type', [AssetType::Video->value, AssetType::Thumbnail->value, AssetType::Subtitle->value])
            ->where('status', 'ready')
            ->orderByDesc('id')
            ->get()
            ->map(static fn (Asset $asset): array => self::presentOutput($asset));

        return Inertia::render('Render/Show', [
            'project' => ProjectController::present($project),
            'video' => VideoController::present($video),
            'renderJobs' => $renderJobs,
            'renderJobActive' => GenerationJobPresenter::hasActive($renderJobs),
            'videoAsset' => $assets->firstWhere('type', AssetType::Video->value),
            'thumbnailAsset' => $assets->firstWhere('type', AssetType::Thumbnail->value),
            'subtitleAsset' => $assets->firstWhere('type', AssetType::Subtitle->value),
            'blockedAssets' => $this->licenceGate->blockMessages($project),
            'silentMode' => in_array($video->effectivePresentationMode(), [PresentationMode::Silent, PresentationMode::SpeechBubbles], true),
            'ffmpegConfigured' => $this->binaryProbe->configured(),
            'canRender' => $this->canRender($project, $video),
            'videoMetadata' => $video->videoMetadata ? [
                'platform' => $video->videoMetadata->platform->value,
                'title' => $video->videoMetadata->title,
                'description' => $video->videoMetadata->description,
                'generation_meta' => $video->videoMetadata->generation_meta,
            ] : null,
            'cost' => $this->costReport->forVideo($video),
        ]);
    }

    public function store(Project $project, Video $video): RedirectResponse
    {
        $this->authorize('update', $video);

        $this->jobGuard->cancelStale($project, GenerationJobType::VideoRender, $video);

        $active = $this->jobGuard->activeJob($project, GenerationJobType::VideoRender, $video);

        if ($active !== null) {
            throw ValidationException::withMessages([
                'render' => sprintf(
                    'A render is already %s for this video. Wait for it to finish.',
                    $active->status->value,
                ),
            ]);
        }

        if (in_array($video->status, [ProjectStatus::Draft, ProjectStatus::StoryReady, ProjectStatus::Rendering], true)) {
            throw ValidationException::withMessages([
                'render' => 'This video cannot start a render right now. Complete the storyboard (and wait for any running render) first.',
            ]);
        }

        if (! $this->binaryProbe->configured()) {
            throw ValidationException::withMessages([
                'render' => 'FFmpeg/FFprobe are not configured on the server. See the environment setup guide and run "php artisan ffmpeg:check".',
            ]);
        }

        $this->jobGuard->launch($project, GenerationJobType::VideoRender, [
            'presentation_mode' => $video->effectivePresentationMode()->value,
        ], $video);

        return redirect()->route('render.show', [$project, $video])
            ->with('success', 'Render queued.');
    }

    /**
     * Generates (but does not persist) a title/description for the selected
     * platform from the video's story, storyboard and render facts. The
     * generated values are flashed to the render page so the user can review
     * and edit them before the explicit save.
     */
    public function generateMetadata(GenerateVideoMetadataRequest $request, Project $project, Video $video): RedirectResponse
    {
        $this->authorize('update', $video);

        $platform = $request->validated('platform');

        try {
            $metadata = $this->metadataService->generate($video, $platform);
        } catch (ProviderException $exception) {
            throw ValidationException::withMessages([
                'metadata' => sprintf('Metadata generation failed: %s', $exception->getMessage()),
            ]);
        } catch (Throwable $exception) {
            Log::error('Video metadata generation failed unexpectedly.', [
                'project_id' => $project->id,
                'video_id' => $video->id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'metadata' => 'Metadata generation failed unexpectedly. Check the application logs and try again.',
            ]);
        }

        return redirect()->route('render.show', [$project, $video])
            ->with('generated_metadata', [
                'platform' => $platform,
                'title' => $metadata->title,
                'description' => $metadata->description,
            ])
            ->with('success', 'Metadata generated. Review, edit if needed, then save.');
    }

    /**
     * Persists user-reviewed metadata (an explicit overwrite — generation
     * never writes to the database).
     */
    public function saveMetadata(SaveVideoMetadataRequest $request, Project $project, Video $video): RedirectResponse
    {
        $this->authorize('update', $video);

        $data = $request->validated();

        $metadata = $video->videoMetadata()->firstOrNew(['video_id' => $video->id]);
        $metadata->platform = VideoMetadataPlatform::from($data['platform']);
        $metadata->title = $data['title'];
        $metadata->description = $data['description'];
        $metadata->save();

        return redirect()->route('render.show', [$project, $video])
            ->with('success', 'Video metadata saved.');
    }

    private function canRender(Project $project, Video $video): bool
    {
        return ! in_array($video->status, [ProjectStatus::Draft, ProjectStatus::StoryReady, ProjectStatus::Rendering], true)
            && $this->jobGuard->activeJob($project, GenerationJobType::VideoRender, $video) === null
            && $this->binaryProbe->configured();
    }

    /**
     * Output-asset presenter: authorized route URLs only — never private
     * filesystem paths (SECURITY.md).
     *
     * @return array<string, mixed>
     */
    public static function presentOutput(Asset $asset): array
    {
        $meta = is_array($asset->meta) ? $asset->meta : [];

        return [
            'id' => $asset->id,
            'type' => $asset->type->value,
            'mime' => $asset->mime,
            'size_bytes' => $asset->size_bytes,
            'duration_ms' => $meta['duration_ms'] ?? null,
            'width' => $meta['width'] ?? null,
            'height' => $meta['height'] ?? null,
            'download_url' => route('assets.show', ['project' => $asset->project_id, 'asset' => $asset->id, 'download' => 1]),
            'preview_url' => route('assets.show', ['project' => $asset->project_id, 'asset' => $asset->id]),
        ];
    }
}
