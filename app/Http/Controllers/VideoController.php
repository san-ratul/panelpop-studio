<?php

namespace App\Http\Controllers;

use App\Http\Requests\VideoRequest;
use App\Models\Project;
use App\Models\Video;
use App\Services\VideoDuplicator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Manages the videos inside one project. Every video is an independent
 * pipeline: story, scenes, audio, render output and generation history are
 * video-scoped; project settings act as defaults that a video may override.
 */
class VideoController extends Controller
{
    public function __construct(
        private readonly VideoDuplicator $duplicator,
    ) {}

    public function index(Request $request, Project $project): Response
    {
        $this->authorize('view', $project);

        $showArchived = $request->boolean('archived');

        $videos = $project->videos()
            ->when(! $showArchived, fn ($query) => $query->whereNull('archived_at'))
            ->when($showArchived, fn ($query) => $query->whereNotNull('archived_at'))
            ->withCount('scenes')
            ->get()
            ->map(static fn (Video $video) => self::present($video))
            ->values()
            ->all();

        return Inertia::render('Videos/Index', [
            'project' => ProjectController::present($project),
            'videos' => $videos,
            'showArchived' => $showArchived,
        ]);
    }

    public function create(Project $project): Response
    {
        $this->authorize('create', [Video::class, $project]);

        return Inertia::render('Videos/Create', [
            'project' => ProjectController::present($project),
            'presentationModes' => ProjectController::presentationModes(),
            'visualStyles' => ProjectController::styleOptions($project->visual_style),
            'languages' => ProjectController::languageOptions($project->language),
        ]);
    }

    public function store(VideoRequest $request, Project $project): RedirectResponse
    {
        $this->authorize('create', [Video::class, $project]);

        $nextSortOrder = (int) $project->videos()->max('sort_order') + 1;

        $video = DB::transaction(function () use ($project, $request, $nextSortOrder): Video {
            /** @var Video $video */
            $video = $project->videos()->create([
                ...$request->validated(),
                'status' => 'draft',
                'sort_order' => $nextSortOrder,
            ]);

            // A video starts without a story; the title the user chose is the
            // working title until a story provides a better one.
            return $video;
        });

        return redirect()->route('story.edit', [$project, $video])
            ->with('success', 'Video created. Add or generate its story next.');
    }

    public function edit(Project $project, Video $video): Response
    {
        $this->authorize('update', $video);

        return Inertia::render('Videos/Edit', [
            'project' => ProjectController::present($project),
            'video' => self::present($video),
            'presentationModes' => ProjectController::presentationModes(),
            'visualStyles' => ProjectController::styleOptions($video->effectiveVisualStyle()),
            'languages' => ProjectController::languageOptions($video->effectiveLanguage()),
        ]);
    }

    public function update(VideoRequest $request, Project $project, Video $video): RedirectResponse
    {
        $this->authorize('update', $video);

        $video->update($request->validated());

        return redirect()->back()
            ->with('success', 'Video settings saved.');
    }

    public function destroy(Project $project, Video $video): RedirectResponse
    {
        $this->authorize('delete', $video);

        DB::transaction(fn (): bool => (bool) $video->delete());

        return redirect()->route('videos.index', $project)
            ->with('success', 'Video deleted.');
    }

    public function duplicate(Project $project, Video $video): RedirectResponse
    {
        $this->authorize('create', [Video::class, $project]);

        $copy = $this->duplicator->duplicate($video);

        return redirect()->route('story.edit', [$project, $copy])
            ->with('success', sprintf('Video duplicated as "%s". Its audio, images and renders are not copied — generate fresh ones.', $copy->title));
    }

    public function archive(Project $project, Video $video): RedirectResponse
    {
        $this->authorize('update', $video);

        $video->update(['archived_at' => now()]);

        return redirect()->route('videos.index', $project)
            ->with('success', 'Video archived. Find it under "Archived videos".');
    }

    public function restore(Project $project, Video $video): RedirectResponse
    {
        $this->authorize('update', $video);

        $video->update(['archived_at' => null]);

        return redirect()->route('videos.index', $project)
            ->with('success', 'Video restored.');
    }

    /**
     * Canonical Inertia video props: settings carry both the raw overrides
     * and the resolved effective values so the UI can distinguish inherited
     * project defaults from video-level customizations.
     *
     * @return array<string, mixed>
     */
    public static function present(Video $video): array
    {
        return [
            'id' => $video->id,
            'project_id' => $video->project_id,
            'title' => $video->title,
            'status' => $video->status->value,
            'target_duration_seconds' => $video->target_duration_seconds,
            'presentation_mode_override' => $video->presentation_mode_override?->value,
            'visual_style_override' => $video->visual_style_override,
            'language_override' => $video->language_override,
            'settings' => $video->settings,
            'sort_order' => $video->sort_order,
            'archived_at' => $video->archived_at?->toIso8601String(),
            'scene_count' => $video->scenes()->count(),
            'effective_visual_style' => $video->effectiveVisualStyle(),
            'effective_language' => $video->effectiveLanguage(),
            'effective_presentation_mode' => $video->effectivePresentationMode()->value,
            'narration_voice_config' => $video->effectiveNarrationVoiceConfig(),
        ];
    }
}
