<?php

namespace App\Http\Controllers;

use App\Enums\ProjectStatus;
use App\Enums\StoryStatus;
use App\Http\Requests\SaveStoryRequest;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class StoryController extends Controller
{
    public function edit(Project $project): Response
    {
        $this->authorize('view', $project);

        $story = $project->story;

        return Inertia::render('Story/Edit', [
            'project' => ProjectController::present($project),
            'story' => $story ? [
                'title' => $story->title,
                'logline' => $story->logline,
                'full_text' => $story->full_text,
                'status' => $story->status->value,
            ] : null,
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
}
