<?php

namespace App\Http\Controllers;

use App\Enums\GenerationJobType;
use App\Enums\ProjectStatus;
use App\Enums\StoryStatus;
use App\Http\Controllers\Support\GenerationJobPresenter;
use App\Http\Requests\GenerateStoryRequest;
use App\Http\Requests\SaveStoryRequest;
use App\Models\Project;
use App\Models\Video;
use App\Services\GenerationJobGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class StoryController extends Controller
{
    public function __construct(
        private readonly GenerationJobGuard $jobGuard,
    ) {}

    public function edit(Project $project, Video $video): Response
    {
        $this->authorize('view', $video);

        $story = $video->story;

        $storyJobs = GenerationJobPresenter::recent($video, GenerationJobType::StoryGeneration);
        $planJobs = GenerationJobPresenter::recent($video, GenerationJobType::ScenePlanning);

        return Inertia::render('Story/Edit', [
            'project' => ProjectController::present($project),
            'video' => VideoController::present($video),
            'story' => $story ? [
                'title' => $story->title,
                'logline' => $story->logline,
                'full_text' => $story->full_text,
                'status' => $story->status->value,
                'generation_meta' => $story->generation_meta,
            ] : null,
            'storyJobs' => $storyJobs,
            'storyJobActive' => GenerationJobPresenter::hasActive($storyJobs),
            'planJobActive' => GenerationJobPresenter::hasActive($planJobs),
            'storyIdea' => $storyJobs[0]['payload']['story_idea'] ?? '',
        ]);
    }

    public function save(SaveStoryRequest $request, Project $project, Video $video): RedirectResponse
    {
        $this->authorize('update', $video);

        $data = $request->validated();
        $data['status'] = $data['status'] ?? StoryStatus::Ready->value;

        DB::transaction(function () use ($video, $data): void {
            $video->story()->updateOrCreate(
                ['video_id' => $video->id],
                $data
            );

            if ($video->status === ProjectStatus::Draft) {
                $video->update(['status' => ProjectStatus::StoryReady]);
            }
        });

        return redirect()->route('story.edit', [$project, $video])
            ->with('success', 'Story saved.');
    }

    public function generate(GenerateStoryRequest $request, Project $project, Video $video): RedirectResponse
    {
        $this->authorize('update', $video);

        $this->jobGuard->cancelStale($project, GenerationJobType::StoryGeneration, $video);

        $active = $this->jobGuard->activeJob($project, GenerationJobType::StoryGeneration, $video);

        if ($active !== null) {
            throw ValidationException::withMessages([
                'story_idea' => sprintf(
                    'A story generation is already %s for this video. Wait for it to finish or review its status below.',
                    $active->status->value,
                ),
            ]);
        }

        $data = $request->validated();

        $this->jobGuard->launch($project, GenerationJobType::StoryGeneration, [
            'story_idea' => $data['story_idea'],
        ], $video);

        return redirect()->route('story.edit', [$project, $video])
            ->with('success', 'Story generation queued.');
    }
}
