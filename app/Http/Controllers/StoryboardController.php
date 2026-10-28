<?php

namespace App\Http\Controllers;

use App\Enums\GenerationJobType;
use App\Enums\ProjectStatus;
use App\Enums\StoryStatus;
use App\Exceptions\DomainValidationException;
use App\Http\Controllers\Support\GenerationJobPresenter;
use App\Models\Project;
use App\Services\DurationValidator;
use App\Services\GenerationJobGuard;
use App\Services\SceneImageDispatcher;
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
        private readonly GenerationJobGuard $jobGuard,
        private readonly SceneImageDispatcher $sceneImageDispatcher,
    ) {}

    public function show(Project $project): Response
    {
        $this->authorize('view', $project);

        $scenes = $project->scenes()->with('dialogueLines.character')->get()
            ->map(static fn ($scene) => SceneController::present($scene))
            ->values()
            ->all();

        $planJobs = GenerationJobPresenter::recent($project, GenerationJobType::ScenePlanning);
        $imageJobs = GenerationJobPresenter::recent($project, GenerationJobType::SceneImage);

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
            'planJobs' => $planJobs,
            'planJobActive' => GenerationJobPresenter::hasActive($planJobs),
            'imageJobs' => $imageJobs,
            'imageJobActive' => GenerationJobPresenter::hasActive($imageJobs),
            'hasReadyStory' => ($project->story?->status ?? null) === StoryStatus::Ready,
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

    public function generatePlan(Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        if (($project->story?->status ?? null) !== StoryStatus::Ready) {
            throw ValidationException::withMessages([
                'plan' => 'Scene planning needs a ready story. Generate or save a story first.',
            ]);
        }

        $this->jobGuard->cancelStale($project, GenerationJobType::ScenePlanning);

        $active = $this->jobGuard->activeJob($project, GenerationJobType::ScenePlanning);

        if ($active !== null) {
            throw ValidationException::withMessages([
                'plan' => sprintf(
                    'Scene planning is already %s for this project. Wait for it to finish.',
                    $active->status->value,
                ),
            ]);
        }

        $this->jobGuard->launch($project, GenerationJobType::ScenePlanning, []);

        return redirect()->route('storyboard.show', $project)
            ->with('success', 'Scene planning queued.');
    }

    /**
     * Batch fan-out: queues character-reference jobs for every character that
     * still needs one, then scene-image jobs for every scene whose character
     * references are complete. Scenes whose references are missing are
     * reported as waiting (they auto-queue once the last reference lands).
     */
    public function generateImages(Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        if ($project->scenes()->doesntExist()) {
            throw ValidationException::withMessages([
                'images' => 'No scenes to illustrate. Generate or add a scene plan first.',
            ]);
        }

        $queuedReferences = 0;

        foreach ($project->characters()->orderBy('name')->get() as $character) {
            if ($character->reference_asset_id !== null) {
                continue;
            }

            if ($this->jobGuard->activeJobForSubject($project, GenerationJobType::CharacterReference, 'character_id', $character->id) !== null) {
                continue;
            }

            $this->jobGuard->launch($project, GenerationJobType::CharacterReference, [
                'character_id' => $character->id,
                'character_name' => $character->name,
            ]);

            $queuedReferences++;
        }

        $summary = $this->sceneImageDispatcher->dispatchPendingForProject($project);

        $parts = [];

        if ($queuedReferences > 0) {
            $parts[] = sprintf('%d character reference image(s)', $queuedReferences);
        }

        if ($summary['queued'] > 0) {
            $parts[] = sprintf('%d scene image(s)', $summary['queued']);
        }

        if ($summary['waiting'] > 0) {
            $parts[] = sprintf('%d scene(s) waiting for character references', $summary['waiting']);
        }

        $message = $parts === []
            ? 'All scene images are already up to date.'
            : 'Queued '.implode(' and ', $parts).'.';

        return redirect()->route('storyboard.show', $project)
            ->with('success', $message);
    }
}
