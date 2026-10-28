<?php

namespace App\Http\Controllers;

use App\Enums\GenerationJobType;
use App\Enums\ProjectStatus;
use App\Enums\StoryStatus;
use App\Http\Controllers\Support\GenerationJobPresenter;
use App\Http\Requests\GenerateStoryRequest;
use App\Http\Requests\SaveStoryRequest;
use App\Models\Project;
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

    public function edit(Project $project): Response
    {
        $this->authorize('view', $project);

        $story = $project->story;

        $storyJobs = GenerationJobPresenter::recent($project, GenerationJobType::StoryGeneration);

        return Inertia::render('Story/Edit', [
            'project' => ProjectController::present($project),
            'story' => $story ? [
                'title' => $story->title,
                'logline' => $story->logline,
                'full_text' => $story->full_text,
                'status' => $story->status->value,
                'generation_meta' => $story->generation_meta,
            ] : null,
            'storyJobs' => $storyJobs,
            'storyJobActive' => GenerationJobPresenter::hasActive($storyJobs),
            'storyIdea' => $storyJobs[0]['payload']['story_idea'] ?? '',
        ]);
    }

    public function save(SaveStoryRequest $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $data = $request->validated();
        $data['status'] = $data['status'] ?? StoryStatus::Ready->value;

        DB::transaction(function () use ($project, $data): void {
            $project->story()->updateOrCreate(
                ['project_id' => $project->id],
                $data
            );

            if ($project->status === ProjectStatus::Draft) {
                $project->update(['status' => ProjectStatus::StoryReady]);
            }
        });

        return redirect()->route('story.edit', $project)
            ->with('success', 'Story saved.');
    }

    public function generate(GenerateStoryRequest $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $this->jobGuard->cancelStale($project, GenerationJobType::StoryGeneration);

        $active = $this->jobGuard->activeJob($project, GenerationJobType::StoryGeneration);

        if ($active !== null) {
            throw ValidationException::withMessages([
                'story_idea' => sprintf(
                    'A story generation is already %s for this project. Wait for it to finish or review its status below.',
                    $active->status->value,
                ),
            ]);
        }

        $data = $request->validated();

        $this->jobGuard->launch($project, GenerationJobType::StoryGeneration, [
            'story_idea' => $data['story_idea'],
        ]);

        return redirect()->route('story.edit', $project)
            ->with('success', 'Story generation queued.');
    }
}
