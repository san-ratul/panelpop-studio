<?php

namespace App\AI\Contracts;

use App\AI\Inputs\ScenePlanConstraints;
use App\AI\Inputs\StoryBrief;
use App\AI\Inputs\VideoMetadataBrief;
use App\AI\Results\ScenePlan;
use App\AI\Results\StoryDraft;
use App\AI\Results\VideoMetadata;

interface TextProvider
{
    public function generateStory(StoryBrief $brief): StoryDraft;

    public function planScenes(StoryDraft $story, ScenePlanConstraints $constraints): ScenePlan;

    public function generateVideoMetadata(VideoMetadataBrief $brief): VideoMetadata;
}
