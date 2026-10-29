<?php

namespace Tests\Feature\Cost;

use App\Models\ProviderRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Historical rate preservation: the snapshot command pins the currently
 * verified price lists under the active rate version so provider_calls rows
 * always reference an auditable snapshot (source URL + verification date).
 */
class RateSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_snapshot_command_persists_all_configured_rate_sets(): void
    {
        $this->artisan('cost:snapshot-rates')->assertSuccessful();

        $rates = ProviderRate::query()->get();

        // One rate set per configured model: 4 text + 1 image + 1 tts.
        $this->assertSame(6, $rates->count());

        $text = $rates->where('capability', 'text')->where('model', 'gpt-6.1-terra')->first();

        $this->assertNotNull($text);
        $this->assertSame('openai', $text->provider);
        $this->assertSame('openai-text-2026-10-28', $text->version);
        $this->assertSame('USD', $text->currency);
        // MySQL JSON normalizes 1.0 → 1; compare numerically.
        $this->assertEqualsWithDelta(1.0, $text->rates['input'], 1e-12);
        $this->assertEqualsWithDelta(5.0, $text->rates['output'], 1e-12);
        $this->assertNotNull($text->source_url);
        $this->assertNotNull($text->verified_at);

        $image = $rates->where('capability', 'image')->where('model', 'gpt-image-2.5-flare')->sole();
        $this->assertEqualsWithDelta(5.0, $image->rates['text_input'], 1e-12);
        $this->assertEqualsWithDelta(8.0, $image->rates['image_input'], 1e-12);
        $this->assertEqualsWithDelta(30.0, $image->rates['image_output'], 1e-12);

        $tts = $rates->where('capability', 'tts')->where('model', 'gpt-4o-mini-tts')->sole();
        $this->assertEqualsWithDelta(0.6, $tts->rates['text_input'], 1e-12);
        $this->assertEqualsWithDelta(12.0, $tts->rates['audio_output'], 1e-12);
    }

    public function test_snapshot_is_idempotent_across_re_runs(): void
    {
        $this->artisan('cost:snapshot-rates')->assertSuccessful();
        $this->artisan('cost:snapshot-rates')->assertSuccessful();

        $this->assertSame(6, ProviderRate::query()->count());
    }

    public function test_custom_rate_version_creates_a_new_snapshot_generation(): void
    {
        $this->artisan('cost:snapshot-rates')->assertSuccessful();

        config(['ai.rate_version' => '2027-01-15']);

        $this->artisan('cost:snapshot-rates')->assertSuccessful();

        $this->assertSame(12, ProviderRate::query()->count());
        $this->assertSame(
            6,
            ProviderRate::query()->where('version', 'openai-text-2026-10-28')->count()
                + ProviderRate::query()->where('version', 'openai-image-2026-10-28')->count()
                + ProviderRate::query()->where('version', 'openai-tts-2026-10-28')->count(),
        );
        $this->assertSame(
            6,
            ProviderRate::query()->where('version', 'like', '%-2027-01-15')->count(),
        );
    }
}
