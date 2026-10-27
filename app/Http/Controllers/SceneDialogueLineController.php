<?php

namespace App\Http\Controllers;

use App\Http\Requests\DialogueLineRequest;
use App\Models\DialogueLine;
use App\Models\Project;
use App\Models\Scene;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class SceneDialogueLineController extends Controller
{
    public function store(DialogueLineRequest $request, Project $project, Scene $scene): RedirectResponse
    {
        $this->authorize('create', [DialogueLine::class, $scene]);

        $data = $request->validated();

        DB::transaction(function () use ($scene, &$data): void {
            $data['sort_order'] ??= (int) $scene->dialogueLines()->lockForUpdate()->max('sort_order') + 1;
            $scene->dialogueLines()->create($data);
        });

        return redirect()->route('storyboard.show', ['project' => $project->id])
            ->with('success', 'Dialogue line added.');
    }

    public function update(DialogueLineRequest $request, Project $project, Scene $scene, DialogueLine $dialogueLine): RedirectResponse
    {
        $this->authorize('update', $dialogueLine);

        $dialogueLine->update($request->validated());

        return redirect()->route('storyboard.show', ['project' => $project->id])
            ->with('success', 'Dialogue line updated.');
    }

    public function destroy(Project $project, Scene $scene, DialogueLine $dialogueLine): RedirectResponse
    {
        $this->authorize('delete', $dialogueLine);

        $dialogueLine->delete();

        return redirect()->route('storyboard.show', ['project' => $project->id])
            ->with('success', 'Dialogue line deleted.');
    }

    public static function present(DialogueLine $line): array
    {
        return [
            'id' => $line->id,
            'character_id' => $line->character_id,
            'character_name' => $line->character?->name,
            'text' => $line->text,
            'sort_order' => $line->sort_order,
            'status' => $line->status->value,
        ];
    }
}
