<?php

namespace App\AI\Prompts;

use App\AI\Inputs\VideoMetadataBrief;

/**
 * Prompt text for AI video metadata (title/description for YouTube Shorts /
 * Facebook Reels). The rules push the model toward accurate, platform-fit
 * copy grounded strictly in the provided story material.
 */
final class VideoMetadataPromptBuilder
{
    /** Upper bound for the story excerpt sent to the provider. */
    private const MAX_STORY_CHARS = 16000;

    /**
     * @return array{system: string, user: string}
     */
    public function metadataMessages(VideoMetadataBrief $brief): array
    {
        $system = <<< 'SYSTEM'
            You write video titles and descriptions for short-form cartoon videos
            published as YouTube Shorts and Facebook Reels.
            Rules:
            - Base everything strictly on the provided story material; never invent
              plot details, facts, or claims the story does not support.
            - Match the story's genre and tone (for example mystery, historical
              documentary, horror, comedy) without exaggeration or misleading clickbait.
            - Use relevant keywords naturally; never stuff keywords.
            - Plain text only: never use Markdown formatting, code fences, bullet
              markers, or JSON anywhere in the output fields.
            - Never mention that you are an AI and never add meta commentary.
            SYSTEM;

        $logline = $brief->logline !== null && $brief->logline !== ''
            ? $brief->logline
            : '(none)';
        $characters = $brief->characterNames === []
            ? '(none named)'
            : implode(', ', $brief->characterNames);
        $narration = $brief->narrationExcerpt !== ''
            ? $brief->narrationExcerpt
            : '(no narration text recorded)';

        $platform = match ($brief->platform) {
            'youtube_shorts' => 'YouTube Shorts only: one title and one description tuned for YouTube Shorts.',
            'facebook_reels' => 'Facebook Reels only: one title and one description tuned for Facebook Reels.',
            default => 'both YouTube Shorts and Facebook Reels: one title and one description that work well on both platforms.',
        };

        $user = sprintf(
            "Story title: %s\n\nLogline: %s\n\nStory:\n%s\n\n".
            "Production details:\n".
            "- Presentation mode: %s\n".
            "- Visual style: %s\n".
            "- Language: %s\n".
            "- Scenes: %d (about %d seconds total)\n".
            "- Characters: %s\n\n".
            "Scene narration/dialogue excerpt:\n%s\n\n".
            "Task: write video metadata for %s\n\n".
            "Constraints:\n".
            "- title: plain text, at most 100 characters, engaging and accurate, with the story's genre and keywords used naturally.\n".
            '- description: plain text, at most 2200 characters, a compelling and faithful summary of the story in a concise, readable short-form format.',
            $brief->storyTitle,
            $logline,
            mb_substr($brief->storyText, 0, self::MAX_STORY_CHARS),
            $brief->presentationMode,
            $brief->visualStyle,
            $brief->language,
            $brief->sceneCount,
            $brief->targetDurationSeconds,
            $characters,
            $narration,
            $platform,
        );

        return ['system' => $system, 'user' => $user];
    }
}
