<?php

namespace App\AI\Prompts;

use App\AI\Inputs\CharacterBrief;
use App\AI\Inputs\SceneBrief;

/**
 * Composes image-generation prompts from product data only (scene prompts,
 * character descriptions, consistency notes, project visual style).
 *
 * Prompts never contain credentials or provider configuration. Every prompt
 * carries the fixed style anchors (D-011) and vertical composition guidance
 * (PHASE_04) so a project's images stay visually consistent.
 */
final class ImagePromptBuilder
{
    public function characterReferencePrompt(CharacterBrief $character, string $style): string
    {
        $parts = [
            sprintf('Character reference sheet for "%s".', trim($character->name)),
            trim($character->description),
        ];

        if ($character->consistencyNotes !== null && trim($character->consistencyNotes) !== '') {
            $parts[] = 'Style anchors (keep these exact in every image): '.trim($character->consistencyNotes);
        }

        if (trim($style) !== '') {
            $parts[] = sprintf('Art style: %s.', trim($style));
        }

        $parts[] = 'Full-body character standing centered on a plain, uncluttered light background, facing forward, neutral pose. Single character only, no text, no watermarks, no background scenery.';

        return $this->join($parts);
    }

    /**
     * @param  list<CharacterBrief>  $characterReferences
     */
    public function scenePrompt(SceneBrief $scene, array $characterReferences): string
    {
        $parts = [trim($scene->imagePrompt)];

        if (trim($scene->backgroundDescription) !== '') {
            $parts[] = 'Background: '.trim($scene->backgroundDescription);
        }

        if ($characterReferences !== []) {
            $names = implode(', ', array_map(
                static fn (CharacterBrief $character): string => '"'.trim($character->name).'"',
                $characterReferences,
            ));

            $parts[] = sprintf(
                'Characters present: %s — keep each character\'s appearance consistent with the provided reference image(s).',
                $names,
            );

            $anchors = [];

            foreach ($characterReferences as $character) {
                if ($character->consistencyNotes !== null && trim($character->consistencyNotes) !== '') {
                    $anchors[] = sprintf('%s: %s', trim($character->name), trim($character->consistencyNotes));
                }
            }

            if ($anchors !== []) {
                $parts[] = 'Character style anchors: '.implode('; ', $anchors);
            }
        }

        if ($scene->style !== null && trim($scene->style) !== '') {
            $parts[] = sprintf('Art style: %s.', trim($scene->style));
        }

        $parts[] = 'Vertical 9:16 composition framed for a full-height portrait crop, clean cartoon illustration, family-friendly, no text, no watermarks.';

        return $this->join($parts);
    }

    /**
     * @param  list<string>  $parts
     */
    private function join(array $parts): string
    {
        return implode("\n", array_values(array_filter(
            $parts,
            static fn (string $part): bool => trim($part) !== '',
        )));
    }
}
