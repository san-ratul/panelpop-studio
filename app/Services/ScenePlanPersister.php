<?php

namespace App\Services;

use App\AI\Results\PlannedDialogueLine;
use App\AI\Results\PlannedScene;
use App\AI\Results\ScenePlan;
use App\Enums\DialogueLineStatus;
use App\Enums\SceneStatus;
use App\Exceptions\DomainValidationException;
use App\Models\Character;
use App\Models\GenerationJob;
use App\Models\Project;
use App\Models\Scene;
use App\Models\Story;
use App\Models\Video;
use Illuminate\Support\Facades\DB;

/**
 * Persists an AI scene plan for ONE video: validates it through the plan
 * validator, then replaces the video's scenes and the project's characters
 * inside a single transaction (regenerate = clean replace, never duplicates).
 * Characters remain project-level so every video of the project can reuse
 * them; scene numbering is video-scoped.
 */
final class ScenePlanPersister
{
    public function __construct(
        private readonly ScenePlanValidator $planValidator,
        private readonly DurationValidator $durationValidator,
    ) {}

    /**
     * @return array<string, mixed> job result summary (no secrets)
     */
    public function persistPlan(Video $video, Story $story, ScenePlan $plan, GenerationJob $job, string $provider, ?string $model): array
    {
        $validated = $this->planValidator->validate([
            'scenes' => array_map(
                static fn (PlannedScene $scene): array => [
                    'number' => $scene->number,
                    'duration_seconds' => $scene->durationSeconds,
                    'background_description' => $scene->backgroundDescription,
                    'image_prompt' => $scene->imagePrompt,
                    'narration_text' => $scene->narrationText,
                    'narration_voice_instructions' => $scene->narrationVoiceInstructions,
                    'dialogue' => array_map(
                        static fn (PlannedDialogueLine $line): array => [
                            'character_name' => $line->characterName,
                            'text' => $line->text,
                            'emotion' => $line->emotion,
                            'voice_instructions' => $line->voiceInstructions,
                        ],
                        $scene->dialogue,
                    ),
                ],
                $plan->scenes,
            ),
        ]);

        $characters = $this->plannedCharacters($plan);

        $summary = DB::transaction(function () use ($video, $story, $validated, $characters, $job, $provider, $model): array {
            $characterIds = $this->syncCharacters($video->project, $characters);

            // Regenerate = clean replace: dropping the video's scenes cascades
            // its dialogue lines. Other videos of the project are untouched.
            $video->scenes()->delete();

            $number = 0;

            foreach ($validated['scenes'] as $scene) {
                $number++;

                $sceneModel = Scene::query()->create([
                    'video_id' => $video->id,
                    'story_id' => $story->id,
                    'number' => $number,
                    'duration_seconds' => $scene['duration_seconds'],
                    'background_description' => $scene['background_description'],
                    'image_prompt' => $scene['image_prompt'],
                    'narration_text' => $scene['narration_text'],
                    'narration_voice_instructions' => $scene['narration_voice_instructions'] ?? null,
                    'motion_action' => $scene['motion']['action'] ?? null,
                    'motion_camera' => $scene['motion']['camera'] ?? null,
                    'motion_environment' => $scene['motion']['environment'] ?? null,
                    'status' => SceneStatus::Pending,
                ]);

                $sortOrder = 0;

                foreach ($scene['dialogue'] ?? [] as $line) {
                    $sortOrder++;

                    $sceneModel->dialogueLines()->create([
                        'character_id' => $characterIds[mb_strtolower(trim($line['character_name'] ?? ''))] ?? null,
                        'text' => $line['text'],
                        'emotion' => $line['emotion'] ?? null,
                        'voice_instructions' => $line['voice_instructions'] ?? null,
                        'sort_order' => $sortOrder,
                        'status' => DialogueLineStatus::Pending,
                    ]);
                }
            }

            return [
                'scenes' => count($validated['scenes']),
                'characters' => count($characterIds),
                'generation_job_id' => $job->id,
                'provider' => $provider,
                'model' => $model,
            ];
        });

        return $summary + $this->reconciliation($video, $validated['scenes']);
    }

    /**
     * Σ(scene durations) vs the video's target with the documented ±2 s
     * tolerance. Planning never fails on a mismatch: the storyboard
     * completion gate owns the hard check, so the result only records a
     * user-actionable warning.
     *
     * @param  list<array<string, mixed>>  $scenes
     * @return array<string, mixed>
     */
    public function reconciliation(Video $video, array $scenes): array
    {
        $durations = array_map(
            static fn (array $scene): float => (float) $scene['duration_seconds'],
            $scenes,
        );

        $sum = array_sum($durations);
        $target = $video->target_duration_seconds;

        try {
            $this->durationValidator->assertSceneDurationsComplete($durations, $target);
        } catch (DomainValidationException) {
            return [
                'duration_total' => round($sum, 2),
                'duration_warning' => sprintf(
                    'The generated scene durations total %.2f s, which is outside the target of %d s with a tolerance of %s s. Adjust scene durations before completing the storyboard.',
                    $sum,
                    $target,
                    rtrim(rtrim(number_format(DurationValidator::TOLERANCE_SECONDS, 1, '.', ''), '0'), '.'),
                ),
            ];
        }

        return ['duration_total' => round($sum, 2), 'duration_warning' => null];
    }

    /**
     * @return list<array{name: string, description: string, consistency_notes: ?string}>
     */
    private function plannedCharacters(ScenePlan $plan): array
    {
        $characters = $plan->meta['characters'] ?? [];

        if (! is_array($characters)) {
            return [];
        }

        $planned = [];

        foreach ($characters as $character) {
            if (! is_array($character)) {
                continue;
            }

            $name = isset($character['name']) && is_string($character['name']) ? trim($character['name']) : '';

            if ($name === '') {
                continue;
            }

            $planned[] = [
                'name' => mb_substr($name, 0, 120),
                'description' => isset($character['description']) && is_string($character['description'])
                    ? $character['description']
                    : '',
                'consistency_notes' => isset($character['consistency_notes']) && is_string($character['consistency_notes'])
                    ? $character['consistency_notes']
                    : null,
            ];
        }

        return $planned;
    }

    /**
     * Creates missing characters and updates descriptions for existing ones.
     * Keyed by lowercase name for dialogue matching.
     *
     * @param  list<array{name: string, description: string, consistency_notes: ?string}>  $characters
     * @return array<string, int>
     */
    private function syncCharacters(Project $project, array $characters): array
    {
        $ids = [];

        foreach ($characters as $character) {
            $existing = Character::query()
                ->where('project_id', $project->id)
                ->where('name', $character['name'])
                ->first();

            if ($existing === null) {
                $existing = Character::query()->create([
                    'project_id' => $project->id,
                    'name' => $character['name'],
                    'description' => $character['description'] !== '' ? $character['description'] : $character['name'],
                    'consistency_notes' => $character['consistency_notes'],
                ]);
            } else {
                if ($character['description'] !== '') {
                    $existing->description = $character['description'];
                }

                if ($character['consistency_notes'] !== null) {
                    $existing->consistency_notes = $character['consistency_notes'];
                }

                $existing->save();
            }

            $ids[mb_strtolower($character['name'])] = $existing->id;
        }

        return $ids;
    }
}
