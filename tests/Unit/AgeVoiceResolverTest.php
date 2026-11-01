<?php

namespace Tests\Unit;

use App\Services\AgeVoiceResolver;
use Tests\TestCase;

/**
 * Age-based voice suggestions from EXPLICIT character metadata only.
 * Provider behavior follows the VOICES honesty rules: OpenAI has no child
 * voice (preset suggestion instead), Replicate/MiniMax offers youthful
 * system voices labeled unverified. Missing metadata → no suggestion.
 */
class AgeVoiceResolverTest extends TestCase
{
    public function test_missing_age_yields_no_suggestion(): void
    {
        $this->assertNull(AgeVoiceResolver::suggest(null, 'female'));
        $this->assertNull(AgeVoiceResolver::suggest(0, 'male'));
    }

    public function test_replicate_child_characters_get_youthful_system_voices(): void
    {
        config(['ai.env.tts.provider' => 'replicate']);

        $girl = AgeVoiceResolver::suggest(6, 'female');

        $this->assertSame('English_PlayfulGirl', $girl['voice']);
        $this->assertStringContainsString('unverified', $girl['note'], 'youthful system voices are labeled unverified');

        $boy = AgeVoiceResolver::suggest(8, 'male');

        $this->assertSame('English_Strong-WilledBoy', $boy['voice']);
    }

    public function test_replicate_teen_and_adult_bands_map_by_gender(): void
    {
        config(['ai.env.tts.provider' => 'replicate']);

        $this->assertSame('English_LovelyGirl', AgeVoiceResolver::suggest(14, 'female')['voice']);
        $this->assertSame('English_DecentYoungMan', AgeVoiceResolver::suggest(16, 'male')['voice']);
        $this->assertSame('English_CalmWoman', AgeVoiceResolver::suggest(34, 'female')['voice']);
        $this->assertSame('English_PatientMan', AgeVoiceResolver::suggest(52, 'male')['voice']);
        $this->assertSame('English_FriendlyPerson', AgeVoiceResolver::suggest(40, 'neutral')['voice']);
    }

    public function test_replicate_unknown_gender_falls_back_to_neutral(): void
    {
        config(['ai.env.tts.provider' => 'replicate']);

        $this->assertSame('English_AnimeCharacter', AgeVoiceResolver::suggest(7, null)['voice']);
        $this->assertSame('English_AnimeCharacter', AgeVoiceResolver::suggest(7, 'dragon')['voice']);
    }

    public function test_openai_children_get_the_honest_youthful_delivery_preset(): void
    {
        config(['ai.env.tts.provider' => 'openai']);

        $girl = AgeVoiceResolver::suggest(6, 'female');

        $this->assertSame('youthful_girl', $girl['style_preset']);
        $this->assertArrayNotHasKey('voice', $girl, 'OpenAI has no child voice — no voice id is suggested');
        $this->assertStringContainsString('no child voice', $girl['note']);

        $boy = AgeVoiceResolver::suggest(9, 'male');

        $this->assertSame('youthful_boy', $boy['style_preset']);
    }

    public function test_openai_adults_get_a_built_in_voice(): void
    {
        config(['ai.env.tts.provider' => 'openai']);

        $this->assertSame('coral', AgeVoiceResolver::suggest(30, 'female')['voice']);
        $this->assertSame('ash', AgeVoiceResolver::suggest(45, 'male')['voice']);
        $this->assertSame('alloy', AgeVoiceResolver::suggest(30, null)['voice']);
    }

    public function test_unconfigured_provider_yields_no_suggestion(): void
    {
        config(['ai.env.tts.provider' => null]);

        $this->assertNull(AgeVoiceResolver::suggest(6, 'female'));
    }
}
