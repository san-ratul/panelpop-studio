<?php

namespace App\AI\Prompts;

use App\AI\Inputs\ScenePlanConstraints;
use App\AI\Inputs\StoryBrief;
use App\AI\Results\StoryDraft;

/**
 * Prompt text for structured text generation. Content is provider-agnostic;
 * the OpenAI adapter turns these message arrays into Responses API input items.
 */
final class StoryPromptBuilder
{
    /**
     * @return array{system: string, user: string}
     */
    public function storyMessages(StoryBrief $brief): array
    {
        $secondsPerScene = 8.0;
        $wordsPerSecond = 2.4;
        $targetWords = (int) round($brief->targetDurationSeconds * $wordsPerSecond);

        $system = <<< 'SYSTEM'
            You write short-form cartoon stories for vertical (9:16) videos.
            Rules:
            - Write in clear, engaging English prose suited to narration.
            - Keep the story complete: setup, a small conflict or twist, and a satisfying ending.
            - Family-friendly content only.
            - Never mention that you are an AI and never add meta commentary.
            SYSTEM;

        $user = sprintf(
            "Write a cartoon story based on the following idea.\n\nIdea: %s\n\n".
            "Constraints:\n- The story will be narrated in about %d seconds, so aim for roughly %d words.\n".
            "- Visual style: %s.\n- Language: %s.\n- Presentation mode: %s.\n\n".
            'Return JSON with fields: title, logline, full_text.',
            $brief->topic,
            $brief->targetDurationSeconds,
            $targetWords,
            $brief->visualStyle,
            $brief->language,
            $brief->presentationMode,
        );

        return ['system' => $system, 'user' => $user];
    }

    /**
     * @return array{system: string, user: string}
     */
    public function planMessages(StoryDraft $story, ScenePlanConstraints $constraints): array
    {
        $mode = (string) ($constraints->extra['presentation_mode'] ?? 'narrator');
        $visualStyle = (string) ($constraints->extra['visual_style'] ?? 'simple_2d_cartoon');
        $language = (string) ($constraints->extra['language'] ?? 'en');
        $existing = $constraints->extra['characters'] ?? [];

        $sceneCount = max(3, min(12, (int) round($constraints->targetDurationSeconds / 8)));
        $tolerance = rtrim(rtrim(number_format($constraints->toleranceSeconds, 1, '.', ''), '0'), '.');

        $system = "You storyboard cartoon shorts into structured scene plans.\n"
            ."Each scene is illustrated as a vertical 9:16 cartoon still with narration and/or dialogue over it.\n"
            ."Scene durations in seconds must sum exactly to the requested target duration.\n"
            ."Family-friendly content only. Never add meta commentary.\n";

        $system .= match ($mode) {
            'narrator' => 'Presentation mode "narrator": every scene gets narration_text; the dialogue array stays empty.',
            'dialogue' => 'Presentation mode "dialogue": every scene carries spoken dialogue lines; narration_text stays null.',
            'hybrid' => 'Presentation mode "hybrid": scenes mix narration_text and dialogue lines.',
            'silent' => 'Presentation mode "silent": no narration and no dialogue; tell the story purely through visuals. narration_text stays null and dialogue stays empty.',
            'speech_bubbles' => 'Presentation mode "speech_bubbles": no narration; characters speak short lines shown as speech bubbles, so dialogue carries the story.',
            default => 'Presentation mode "'.$mode.'": prefer narration_text with dialogue where it helps.',
        };

        $characterList = '';

        if (is_array($existing) && $existing !== []) {
            $lines = [];

            foreach ($existing as $character) {
                if (! is_array($character)) {
                    continue;
                }

                $name = isset($character['name']) && is_string($character['name']) ? $character['name'] : '';
                $description = isset($character['description']) && is_string($character['description']) ? $character['description'] : '';

                if ($name === '') {
                    continue;
                }

                $lines[] = '- '.$name.($description !== '' ? ': '.$description : '');
            }

            if ($lines !== []) {
                $characterList = "\n\nExisting characters to reuse (keep their look consistent; refine descriptions only if needed):\n"
                    .implode("\n", $lines);
            }
        }

        $user = "Story title: {$story->title}\n\nStory:\n{$story->fullText}\n\n"
            ."Plan this story into scenes.\n\n"
            ."Constraints:\n"
            ."- Use about {$sceneCount} scenes (between 3 and 12).\n"
            ."- Scene durations must be between 3 and 20 seconds and sum to exactly {$constraints->targetDurationSeconds} seconds (tolerance {$tolerance} s).\n"
            ."- image_prompt must describe a {$visualStyle} cartoon illustration in vertical 9:16 composition, including character looks so images stay consistent.\n"
            ."- Every named character must appear in the characters array with a visual description.\n"
            ."- dialogue character_name values must exactly match a character name.\n"
            ."- Language: {$language}.\n"
            .$characterList;

        return ['system' => $system, 'user' => $user];
    }
}
