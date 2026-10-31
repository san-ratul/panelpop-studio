<?php

namespace App\AI\Contracts;

use App\AI\Inputs\DialogueVoiceAssignmentBrief;
use App\AI\Inputs\ScenePlanConstraints;
use App\AI\Inputs\StoryBrief;
use App\AI\Inputs\VideoMetadataBrief;
use App\AI\Results\DialogueVoiceAssignments;
use App\AI\Results\ScenePlan;
use App\AI\Results\StoryDraft;
use App\AI\Results\VideoMetadata;

interface TextProvider
{
    public function generateStory(StoryBrief $brief): StoryDraft;

    public function planScenes(StoryDraft $story, ScenePlanConstraints $constraints): ScenePlan;

    public function generateVideoMetadata(VideoMetadataBrief $brief): VideoMetadata;

    /**
     * AI-assisted dialogue voice assignment: decides the speaker (or
     * narrator), emotional tone and contextual delivery instructions for
     * each line.
     */
    public function assignDialogueVoices(DialogueVoiceAssignmentBrief $brief): DialogueVoiceAssignments;
}
