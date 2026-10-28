<?php

namespace App\Http\Controllers;

use App\Enums\GenerationJobType;
use App\Enums\SceneStatus;
use App\Http\Requests\ReorderScenesRequest;
use App\Http\Requests\SceneRequest;
use App\Models\DialogueLine;
use App\Models\Project;
use App\Models\Scene;
use App\Services\GenerationJobGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SceneController extends Controller
{
    public function __construct(
        private readonly GenerationJobGuard $jobGuard,
    ) {}

    public function store(SceneRequest $request, Project $project): RedirectResponse
    {
        $this->authorize('create', [Scene::class, $project]);

        $data = $request->validated();
        $data['story_id'] = $project->story?->id;

        DB::transaction(function () use ($project, &$data): void {
            $data['number'] ??= (int) $project->scenes()->lockForUpdate()->max('number') + 1;
            $project->scenes()->create($data);
        });

        return redirect()->route('storyboard.show', $project)
            ->with('success', 'Scene added.');
    }

    public function update(SceneRequest $request, Project $project, Scene $scene): RedirectResponse
    {
        $this->authorize('update', $scene);

        $scene->update($request->validated());

        return redirect()->route('storyboard.show', $project)
            ->with('success', 'Scene updated.');
    }

    public function destroy(Project $project, Scene $scene): RedirectResponse
    {
        $this->authorize('delete', $scene);

        DB::transaction(function () use ($project, $scene): void {
            $scene->delete();
            self::renumber($project->scenes()->orderBy('number')->pluck('id')->all());
        });

        return redirect()->route('storyboard.show', $project)
            ->with('success', 'Scene deleted.');
    }

    public function reorder(ReorderScenesRequest $request, Project $project): RedirectResponse
    {
        $this->authorize('view', $project);

        $sceneIds = $request->validated('scene_ids');
        $projectSceneIds = $project->scenes()->pluck('id')->all();

        sort($sceneIds);
        $sortedProjectSceneIds = $projectSceneIds;
        sort($sortedProjectSceneIds);

        if ($sceneIds !== $sortedProjectSceneIds) {
            return redirect()->route('storyboard.show', $project)
                ->withErrors(['scene_ids' => 'The scene order must contain every scene of this project exactly once.']);
        }

        DB::transaction(fn () => self::renumber($request->input('scene_ids')));

        return redirect()->route('storyboard.show', $project)
            ->with('success', 'Scenes reordered.');
    }

    public function generateImage(Project $project, Scene $scene): RedirectResponse
    {
        $this->authorize('update', $scene);

        $this->jobGuard->cancelStaleForSubject($project, GenerationJobType::SceneImage, 'scene_id', $scene->id);

        $active = $this->jobGuard->activeJobForSubject($project, GenerationJobType::SceneImage, 'scene_id', $scene->id);

        if ($active !== null) {
            throw ValidationException::withMessages([
                'image' => sprintf(
                    'Scene %d image generation is already %s. Wait for it to finish.',
                    $scene->number,
                    $active->status->value,
                ),
            ]);
        }

        // Explicit per-scene action: regenerating a ready scene is intended,
        // so force is set for scenes that already have an image. The pending
        // state is marked BEFORE the launch: with the sync queue driver the
        // job runs inline and would otherwise be overwritten afterwards.
        $force = $scene->status === SceneStatus::Ready;

        if (! $force) {
            $scene->update(['status' => SceneStatus::ImagePending, 'error_message' => null]);
        }

        $this->jobGuard->launch($project, GenerationJobType::SceneImage, [
            'scene_id' => $scene->id,
            'scene_number' => $scene->number,
            'force' => $force,
        ]);

        return redirect()->route('storyboard.show', $project)
            ->with('success', sprintf('Image generation queued for scene %d.', $scene->number));
    }

    /**
     * Two-phase renumber (offset, then final) to respect the (project_id, number) unique index.
     *
     * @param  list<int>  $orderedSceneIds
     */
    public static function renumber(array $orderedSceneIds): void
    {
        $offset = 10000;
        foreach ($orderedSceneIds as $id) {
            Scene::where('id', $id)->increment('number', $offset);
        }
        foreach (array_values($orderedSceneIds) as $index => $id) {
            Scene::where('id', $id)->update(['number' => $index + 1]);
        }
    }

    public static function present(Scene $scene): array
    {
        return [
            'id' => $scene->id,
            'number' => $scene->number,
            'duration_seconds' => (float) $scene->duration_seconds,
            'background_description' => $scene->background_description,
            'image_prompt' => $scene->image_prompt,
            'narration_text' => $scene->narration_text,
            'speech_bubble_settings' => $scene->speech_bubble_settings,
            'status' => $scene->status->value,
            'error_message' => $scene->error_message,
            'has_image' => $scene->image_asset_id !== null,
            'image_url' => $scene->image_asset_id !== null
                ? route('assets.show', ['project' => $scene->project_id, 'asset' => $scene->image_asset_id])
                : null,
            'dialogue_lines' => $scene->dialogueLines->map(
                static fn (DialogueLine $line): array => SceneDialogueLineController::present($line)
            )->values()->all(),
        ];
    }
}
