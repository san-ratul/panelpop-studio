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
use App\Services\AudioLicenceGate;
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
 * Phase 6 render workflow: initiate rendering, monitor status, retry, and
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
    ) {}

    public function show(Project $project): Response
    {
        $this->authorize('view', $project);

        $renderJobs = GenerationJobPresenter::recent($project, GenerationJobType::VideoRender);
        $latest = $renderJobs[0] ?? null;

        $assets = $project->assets()
            ->whereIn('type', [AssetType::Video->value, AssetType::Thumbnail->value, AssetType::Subtitle->value])
            ->where('status', 'ready')
            ->orderByDesc('id')
            ->get()
            ->map(static fn (Asset $asset): array => self::presentOutput($asset));

        return Inertia::render('Render/Show', [
            'project' => ProjectController::present($project),
            'renderJobs' => $renderJobs,
            'renderJobActive' => GenerationJobPresenter::hasActive($renderJobs),
            'videoAsset' => $assets->firstWhere('type', AssetType::Video->value),
            'thumbnailAsset' => $assets->firstWhere('type', AssetType::Thumbnail->value),
            'subtitleAsset' => $assets->firstWhere('type', AssetType::Subtitle->value),
            'blockedAssets' => $this->licenceGate->blockMessages($project),
            'silentMode' => in_array($project->presentation_mode, [PresentationMode::Silent, PresentationMode::SpeechBubbles], true),
            'ffmpegConfigured' => $this->binaryProbe->configured(),
            'canRender' => $this->canRender($project),
            'videoMetadata' => $project->videoMetadata ? [
                'platform' => $project->videoMetadata->platform->value,
                'title' => $project->videoMetadata->title,
                'description' => $project->videoMetadata->description,
                'generation_meta' => $project->videoMetadata->generation_meta,
            ] : null,
        ]);
    }

    public function store(Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $this->jobGuard->cancelStale($project, GenerationJobType::VideoRender);

        $active = $this->jobGuard->activeJob($project, GenerationJobType::VideoRender);

        if ($active !== null) {
            throw ValidationException::withMessages([
                'render' => sprintf(
                    'A render is already %s for this project. Wait for it to finish.',
                    $active->status->value,
                ),
            ]);
        }

        if (in_array($project->status, [ProjectStatus::Draft, ProjectStatus::StoryReady, ProjectStatus::Rendering], true)) {
            throw ValidationException::withMessages([
                'render' => 'This project cannot start a render right now. Complete the storyboard (and wait for any running render) first.',
            ]);
        }

        if (! $this->binaryProbe->configured()) {
            throw ValidationException::withMessages([
                'render' => 'FFmpeg/FFprobe are not configured on the server. See the environment setup guide and run "php artisan ffmpeg:check".',
            ]);
        }

        $this->jobGuard->launch($project, GenerationJobType::VideoRender, [
            'presentation_mode' => $project->presentation_mode->value,
        ]);

        return redirect()->route('render.show', $project)
            ->with('success', 'Render queued.');
    }

    /**
     * Generates (but does not persist) a title/description for the selected
     * platform from the project's story, storyboard and render facts. The
     * generated values are flashed to the render page so the user can review
     * and edit them before the explicit save.
     */
    public function generateMetadata(GenerateVideoMetadataRequest $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $platform = $request->validated('platform');

        try {
            $metadata = $this->metadataService->generate($project, $platform);
        } catch (ProviderException $exception) {
            throw ValidationException::withMessages([
                'metadata' => sprintf('Metadata generation failed: %s', $exception->getMessage()),
            ]);
        } catch (Throwable $exception) {
            Log::error('Video metadata generation failed unexpectedly.', [
                'project_id' => $project->id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'metadata' => 'Metadata generation failed unexpectedly. Check the application logs and try again.',
            ]);
        }

        return redirect()->route('render.show', $project)
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
    public function saveMetadata(SaveVideoMetadataRequest $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $data = $request->validated();

        $metadata = $project->videoMetadata()->firstOrNew(['project_id' => $project->id]);
        $metadata->platform = VideoMetadataPlatform::from($data['platform']);
        $metadata->title = $data['title'];
        $metadata->description = $data['description'];
        $metadata->save();

        return redirect()->route('render.show', $project)
            ->with('success', 'Video metadata saved.');
    }

    private function canRender(Project $project): bool
    {
        return ! in_array($project->status, [ProjectStatus::Draft, ProjectStatus::StoryReady, ProjectStatus::Rendering], true)
            && $this->jobGuard->activeJob($project, GenerationJobType::VideoRender) === null
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
