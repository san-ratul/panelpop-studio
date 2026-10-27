<?php

namespace App\Http\Controllers;

use App\Enums\PresentationMode;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public static function presentationModes(): array
    {
        return array_map(
            static fn (PresentationMode $mode): array => ['value' => $mode->value, 'label' => $mode->label()],
            PresentationMode::cases()
        );
    }

    public function create(): Response
    {
        return Inertia::render('Projects/Create', [
            'presentationModes' => self::presentationModes(),
        ]);
    }

    public function store(StoreProjectRequest $request): RedirectResponse
    {
        $project = $request->user()->projects()->create($request->validated());

        return redirect()->route('story.edit', $project)
            ->with('success', 'Project created. Add your story next.');
    }

    public function edit(Request $request, Project $project): Response
    {
        $this->authorize('update', $project);

        return Inertia::render('Projects/Edit', [
            'project' => self::present($project),
            'presentationModes' => self::presentationModes(),
        ]);
    }

    public function update(UpdateProjectRequest $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $project->update($request->validated());

        return redirect()->route('dashboard')->with('success', 'Project updated.');
    }

    public function destroy(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('delete', $project);

        DB::transaction(fn (): bool => (bool) $project->delete());

        return redirect()->route('dashboard')->with('success', 'Project deleted.');
    }

    public static function present(Project $project): array
    {
        return [
            'id' => $project->id,
            'title' => $project->title,
            'presentation_mode' => $project->presentation_mode->value,
            'target_duration_seconds' => $project->target_duration_seconds,
            'visual_style' => $project->visual_style,
            'language' => $project->language,
            'status' => $project->status->value,
            'settings' => $project->settings,
        ];
    }
}
