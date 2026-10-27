<?php

namespace App\AI\Contracts;

use App\AI\Inputs\ScenePlanConstraints;
use App\AI\Inputs\StoryBrief;
use App\AI\Results\ScenePlan;
use App\AI\Results\StoryDraft;

interface TextProvider
{
    public function generateStory(StoryBrief $brief): StoryDraft;

    public function planScenes(StoryDraft $story, ScenePlanConstraints $constraints): ScenePlan;
}
