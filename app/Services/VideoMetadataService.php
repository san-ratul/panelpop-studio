<?php

namespace App\Services;

use App\AI\Inputs\VideoMetadataBrief;
use App\AI\ProviderCallContext;
use App\AI\ProviderGateway;
use App\AI\ProviderManager;
use App\AI\Results\VideoMetadata;
use App\Enums\ProviderCapability;
use App\Models\Project;
use App\Models\Scene;

/**
 * Builds AI video metadata (title/description for YouTube Shorts / Facebook
 * Reels) from the project's own story, storyboard and render facts, through
 * the standard provider pipeline (ProviderManager → ProviderGateway → text
 * adapter) so credential resolution, budget ceiling, ledger and retry apply.
 *
 * Generated metadata is returned to the caller for review; persistence only
 * happens through the explicit save action (never implicitly on generate).
 */
final class VideoMetadataService
{
    /** Bounded narration/dialogue digest sent as storyboard context. */
    private const MAX_NARRATION_EXCERPT_CHARS = 1500;

    public function __construct(
        private readonly ProviderManager $manager,
        private readonly ProviderGateway $gateway,
    ) {}

    public function generate(Project $project, string $platform): VideoMetadata
    {
        $resolved = $this->manager->resolve(ProviderCapability::Text, $project);

        $story = $project->story;
        $storyTitle = $story?->title ?? (string) $project->title;
        $storyText = (string) ($story?->full_text ?? '');

        $scenes = $project->scenes()->with('dialogueLines')->orderBy('number')->get();

        $brief = new VideoMetadataBrief(
            platform: $platform,
            storyTitle: $storyTitle,
            logline: $story?->logline,
            storyText: $storyText,
            presentationMode: $project->presentation_mode->value,
            visualStyle: (string) $project->visual_style,
            language: (string) $project->language,
            sceneCount: $scenes->count(),
            targetDurationSeconds: (int) $project->target_duration_seconds,
            characterNames: $project->characters()->orderBy('name')->pluck('name')->all(),
            narrationExcerpt: $this->narrationExcerpt($scenes),
        );

        return $this->gateway->call(
            $resolved,
            [
                'kind' => 'video_metadata',
                'platform' => $platform,
                'story_chars' => (string) mb_strlen($storyText),
                'title_chars' => (string) mb_strlen($storyTitle),
            ],
            fn (): VideoMetadata => $resolved->textAdapter()->generateVideoMetadata($brief),
            new ProviderCallContext($project->id),
        );
    }

    /**
     * @param  iterable<Scene>  $scenes
     */
    private function narrationExcerpt(iterable $scenes): string
    {
        $parts = [];

        foreach ($scenes as $scene) {
            if ($scene->narration_text !== null && trim((string) $scene->narration_text) !== '') {
                $parts[] = 'Scene '.$scene->number.' narration: '.trim((string) $scene->narration_text);
            }

            foreach ($scene->dialogueLines as $line) {
                if (trim((string) $line->text) !== '') {
                    $parts[] = 'Scene '.$scene->number.' '.$line->character?->name.' dialogue: '.trim((string) $line->text);
                }
            }

            if (mb_strlen(implode("\n", $parts)) > self::MAX_NARRATION_EXCERPT_CHARS) {
                break;
            }
        }

        return mb_substr(implode("\n", $parts), 0, self::MAX_NARRATION_EXCERPT_CHARS);
    }
}
