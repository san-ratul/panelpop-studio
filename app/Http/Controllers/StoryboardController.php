<?php

namespace App\Http\Controllers;

use App\Enums\ProjectStatus;
use App\Exceptions\DomainValidationException;
use App\Models\Project;
use App\Services\DurationValidator;
use App\Services\ScenePlanValidator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class StoryboardController extends Controller
{
    public function __construct(
        private readonly DurationValidator $durationValidator,
        private readonly ScenePlanValidator $scenePlanValidator,
    ) {}

    public function show(Project $project): Response
    {
        $this->authorize('view', $project);

        $scenes = $project->scenes()->with('dialogueLines.character')->get()
            ->map(static fn ($scene) => SceneController::present($scene))
            ->values()
            ->all();

        return Inertia::render('Storyboard/Show', [
            'project' => ProjectController::present($project),
            'scenes' => $scenes,
            'characters' => $project->characters()->orderBy('name')->get()
                ->map(static fn ($character) => CharacterController::present($character))
                ->values()
                ->all(),
            'durationTotal' => array_sum(array_map(
                static fn (array $scene): float => $scene['duration_seconds'],
                $scenes
            )),
            'toleranceSeconds' => DurationValidator::TOLERANCE_SECONDS,
        ]);
    }

    public function complete(Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $scenes = $project->scenes()->with('dialogueLines')->orderBy('number')->get();

        try {
            $plan = $this->scenePlanValidator->validate([
                'scenes' => $scenes->map(static fn ($scene) => [
                    'number' => $scene->number,
                    'duration_seconds' => (float) $scene->duration_seconds,
                    'background_description' => $scene->background_description,
                    'image_prompt' => $scene->image_prompt,
                    'narration_text' => $scene->narration_text,
                    'speech_bubble_settings' => $scene->speech_bubble_settings,
                ])->all(),
            ]);

            $this->durationValidator->assertSceneDurationsComplete(
                array_map(static fn (array $scene): float => $scene['duration_seconds'], $plan['scenes']),
                $project->target_duration_seconds
            );
        } catch (DomainValidationException $exception) {
            throw ValidationException::withMessages($exception->errors);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages([
                'duration_sum' => collect($exception->errors())->flatten()->implode(' '),
            ]);
        }

        $lockableStatuses = [
            ProjectStatus::Draft,
            ProjectStatus::StoryReady,
            ProjectStatus::Planned,
        ];

        if (! in_array($project->status, $lockableStatuses, true)) {
            throw ValidationException::withMessages([
                'status' => 'This storyboard is already locked at its current pipeline stage ('.$project->status->value.').',
            ]);
        }

        $project->update(['status' => ProjectStatus::Planned]);

        return redirect()->route('storyboard.show', $project)
            ->with('success', 'Storyboard completed — scene plan locked.');
    }
}
