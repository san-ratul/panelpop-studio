<?php

namespace App\AI\Fakes;

use App\AI\Contracts\TextProvider;
use App\AI\Inputs\ScenePlanConstraints;
use App\AI\Inputs\StoryBrief;
use App\AI\Results\PlannedScene;
use App\AI\Results\ProviderUsage;
use App\AI\Results\ScenePlan;
use App\AI\Results\StoryDraft;
use App\Enums\ProviderCapability;

final class FakeTextProvider implements TextProvider
{
    public const MODEL = 'fake-text-1';

    public function __construct(private readonly FakeProviderScenario $scenario) {}

    public function generateStory(StoryBrief $brief): StoryDraft
    {
        $this->scenario->throwIfFailure(ProviderCapability::Text);

        return new StoryDraft(
            title: 'The Deterministic Robot',
            fullText: 'A small robot paints cartoon panels every night. One morning the panels come to life and thank the robot. The robot learns that patient, repeated work creates joy.',
            logline: 'A scripted fake story used to exercise the provider layer.',
            usage: new ProviderUsage(inputTokens: 42, outputTokens: 1337),
            costActual: 0.001234,
            meta: ['provider' => 'fake', 'model' => self::MODEL, 'id' => 'fake-story-1'],
        );
    }

    public function planScenes(StoryDraft $story, ScenePlanConstraints $constraints): ScenePlan
    {
        $this->scenario->throwIfFailure(ProviderCapability::Text);

        $sceneCount = 3;
        $perScene = round($constraints->targetDurationSeconds / $sceneCount, 2);

        $scenes = [
            new PlannedScene(1, $perScene, 'A tidy workshop at night, moonlight through the window.', 'Cartoon workshop interior at night, moonlight, small robot painting panels, '.$constraints->targetDurationSeconds.' second pacing.', 'Every night, the little robot painted new cartoon panels.'),
            new PlannedScene(2, $perScene, 'The workshop at sunrise, panels glowing on the wall.', 'Cartoon workshop at sunrise, cartoon panels coming to life, happy colors.', 'One morning, the painted panels began to glow.'),
            new PlannedScene(3, $perScene, 'The workshop in daylight, robot and characters together.', 'Cartoon robot celebrating with painted cartoon characters, confetti.', 'The characters thanked him, and the robot smiled.'),
        ];

        return new ScenePlan(
            scenes: $scenes,
            usage: new ProviderUsage(inputTokens: 84, outputTokens: 640),
            costActual: 0.002345,
            meta: ['provider' => 'fake', 'model' => self::MODEL, 'id' => 'fake-plan-1'],
        );
    }
}
