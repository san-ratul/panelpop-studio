<?php

namespace App\Http\Controllers;

use App\Http\Requests\CharacterRequest;
use App\Models\Character;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class CharacterController extends Controller
{
    public function index(Project $project): Response
    {
        $this->authorize('view', $project);

        return Inertia::render('Characters/Index', [
            'project' => ProjectController::present($project),
            'characters' => $project->characters()->orderBy('name')->get()->map(
                static fn (Character $character): array => self::present($character)
            ),
        ]);
    }

    public function store(CharacterRequest $request, Project $project): RedirectResponse
    {
        $this->authorize('create', [Character::class, $project]);

        $project->characters()->create($request->validated());

        return redirect()->route('characters.index', $project)
            ->with('success', 'Character added.');
    }

    public function edit(Project $project, Character $character): Response
    {
        $this->authorize('view', $character);

        return Inertia::render('Characters/Edit', [
            'project' => ProjectController::present($project),
            'character' => self::present($character),
        ]);
    }

    public function update(CharacterRequest $request, Project $project, Character $character): RedirectResponse
    {
        $this->authorize('update', $character);

        $character->update($request->validated());

        return redirect()->route('characters.index', $project)
            ->with('success', 'Character updated.');
    }

    public function destroy(Project $project, Character $character): RedirectResponse
    {
        $this->authorize('delete', $character);

        $character->delete();

        return redirect()->route('characters.index', $project)
            ->with('success', 'Character deleted.');
    }

    public static function present(Character $character): array
    {
        return [
            'id' => $character->id,
            'name' => $character->name,
            'description' => $character->description,
            'consistency_notes' => $character->consistency_notes,
            'voice_config' => $character->voice_config,
            'has_reference_image' => $character->reference_asset_id !== null,
        ];
    }
}
