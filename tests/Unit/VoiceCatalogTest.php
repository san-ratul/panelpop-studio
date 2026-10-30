<?php

namespace Tests\Unit;

use App\AI\Support\VoiceCatalog;
use Tests\TestCase;

class VoiceCatalogTest extends TestCase
{
    public function test_voice_groups_cover_every_configured_voice_exactly_once(): void
    {
        $configured = array_values((array) config('ai.openai.tts.voices', []));

        $flattened = [];
        $labels = [];

        foreach (VoiceCatalog::voiceGroups() as $group) {
            $this->assertNotSame('', $group['label']);

            foreach ($group['options'] as $option) {
                $flattened[] = $option['value'];
                $labels[] = $option['label'];
            }
        }

        // Grouping reorders the configured voices (female/male/neutral), so
        // compare as sorted sets: same voices, each exactly once.
        sort($configured);
        sort($flattened);

        $this->assertSame($configured, $flattened);

        foreach ($labels as $label) {
            $this->assertNotSame('', $label);
        }
    }

    public function test_configured_voices_are_grouped_by_gender(): void
    {
        $groups = VoiceCatalog::voiceGroups();
        $labels = array_column($groups, 'label');

        $this->assertContains('Female voices', $labels);
        $this->assertContains('Male voices', $labels);

        $female = $groups[array_search('Female voices', $labels, true)];
        $femaleVoices = array_column($female['options'], 'value');

        $this->assertContains('coral', $femaleVoices);
        $this->assertNotContains('onyx', $femaleVoices);
    }

    public function test_style_presets_include_kids_singing_happy_and_sad(): void
    {
        $presets = VoiceCatalog::stylePresets();
        $values = array_column($presets, 'value');

        foreach (['natural', 'kid_girl', 'kid_boy', 'singing', 'happy', 'sad'] as $expected) {
            $this->assertContains($expected, $values);
        }

        foreach ($presets as $preset) {
            $this->assertNotSame('', $preset['label']);
            $this->assertLessThanOrEqual(4096, mb_strlen($preset['instructions']));
        }

        $natural = $presets[array_search('natural', $values, true)];

        $this->assertSame('', $natural['instructions']);

        foreach (['kid_girl', 'kid_boy', 'singing', 'happy', 'sad'] as $styled) {
            $preset = $presets[array_search($styled, $values, true)];

            $this->assertNotSame('', $preset['instructions']);
        }
    }

    public function test_preset_for_resolves_saved_instructions(): void
    {
        $this->assertSame('natural', VoiceCatalog::presetFor(null));
        $this->assertSame('natural', VoiceCatalog::presetFor('  '));

        $singing = VoiceCatalog::stylePresets()[array_search('singing', array_column(VoiceCatalog::stylePresets(), 'value'), true)];

        $this->assertSame('singing', VoiceCatalog::presetFor($singing['instructions']));
        $this->assertSame('custom', VoiceCatalog::presetFor('A very specific impression of a grumpy cat.'));
    }
}
