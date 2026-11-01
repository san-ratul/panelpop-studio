<?php

namespace Tests\Unit\Provider\Replicate;

use App\AI\Exceptions\ProviderConfigurationException;
use App\AI\Exceptions\ProviderRequestException;
use App\AI\Fakes\FakeAudioFixture;
use App\AI\ProviderManager;
use App\Enums\ProviderCapability;
use App\Models\Project;
use Database\Factories\ProviderSettingsFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * HTTP-fake tests for the Replicate speech adapter (minimax/speech-02-turbo,
 * verified schema 2026-11-01): POST /predictions {version, input} in sync
 * mode (`Prefer: wait`), optional polling, output download from the delivery
 * CDN, wav persistence on the artifact disk.
 */
class ReplicateTtsProviderTest extends TestCase
{
    use RefreshDatabase;

    private ProviderManager $manager;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = app(ProviderManager::class);
        $this->project = Project::factory()->create();

        Storage::fake('media-private');
        Sleep::fake();
    }

    private function resolveReplicate(): object
    {
        ProviderSettingsFactory::new()
            ->forCapability(ProviderCapability::Tts)
            ->asDefault()
            ->withCredentials('r8-test-credential-1234')
            ->create([
                'provider' => 'replicate',
                'config' => ['model' => 'minimax/speech-02-turbo'],
            ]);

        return $this->manager->resolve(ProviderCapability::Tts, $this->project);
    }

    public function test_synthesis_pins_the_version_and_persists_a_valid_wav(): void
    {
        Http::fake([
            'https://api.replicate.com/v1/predictions' => Http::response([
                'id' => 'pred_tts_1',
                'status' => 'succeeded',
                'output' => 'https://replicate.delivery/audio-1.wav',
            ], 200),
            'https://replicate.delivery/audio-1.wav' => Http::response(
                FakeAudioFixture::wav(1500),
                200,
                ['Content-Type' => 'audio/wav'],
            ),
        ]);

        $resolved = $this->resolveReplicate();

        $result = $resolved->ttsAdapter()->synthesize(
            'Once upon a time, a small robot found a friend.',
            ['voice' => 'English_PlayfulGirl', 'instructions' => 'an excited, happy delivery'],
        );

        Http::assertSent(function (Request $request): bool {
            if ($request->url() === 'https://api.replicate.com/v1/predictions') {
                $this->assertSame('Bearer r8-test-credential-1234', $request->header('Authorization')[0]);
                $this->assertStringStartsWith('wait=', (string) $request->header('Prefer')[0], 'sync mode');

                $body = $request->data();

                $this->assertSame(
                    'f39649380c14fcc2263e2cc4f27d112629b72e95d7c5a8e5eb23bd90444fd34c',
                    $body['version'],
                    'pinned verified version',
                );

                $input = $body['input'];

                $this->assertSame('Once upon a time, a small robot found a friend.', $input['text']);
                $this->assertSame('English_PlayfulGirl', $input['voice_id']);
                $this->assertSame('wav', $input['audio_format']);
                $this->assertSame('happy', $input['emotion'], 'delivery keywords map to the documented emotion enum');

                return true;
            }

            // The delivery-CDN download must NOT carry the API token.
            if (str_starts_with($request->url(), 'https://replicate.delivery/')) {
                $this->assertSame([], $request->header('Authorization'), 'no credentials leak to the CDN');

                return true;
            }

            return false;
        });

        $this->assertSame('audio/wav', $result->mime);
        $this->assertSame(1500, $result->durationMs);
        $this->assertNull($result->usage());
        $this->assertNull($result->costActual());
        $this->assertSame('English_PlayfulGirl', $result->meta['voice']);

        Storage::disk('media-private')->assertExists($result->path);
        $this->assertStringStartsWith('replicate/audio/', $result->path);
    }

    public function test_a_processing_prediction_is_polled_to_completion(): void
    {
        Http::fake([
            'https://api.replicate.com/v1/predictions' => Http::response([
                'id' => 'pred_tts_2',
                'status' => 'processing',
            ], 200),
            'https://api.replicate.com/v1/predictions/pred_tts_2' => Http::sequence()
                ->push(['id' => 'pred_tts_2', 'status' => 'processing'], 200)
                ->push(['id' => 'pred_tts_2', 'status' => 'succeeded', 'output' => 'https://replicate.delivery/audio-2.wav'], 200),
            'https://replicate.delivery/audio-2.wav' => Http::response(
                FakeAudioFixture::wav(800),
                200,
                ['Content-Type' => 'audio/wav'],
            ),
        ]);

        $resolved = $this->resolveReplicate();

        $result = $resolved->ttsAdapter()->synthesize('Short line.', ['voice' => 'English_CalmWoman']);

        $this->assertSame(800, $result->durationMs);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.replicate.com/v1/predictions/pred_tts_2');
    }

    public function test_a_failed_prediction_surfaces_the_provider_error(): void
    {
        Http::fake([
            'https://api.replicate.com/v1/predictions' => Http::response([
                'id' => 'pred_tts_3',
                'status' => 'failed',
                'error' => 'voice_id not found',
            ], 200),
        ]);

        $resolved = $this->resolveReplicate();

        try {
            $resolved->ttsAdapter()->synthesize('Hello.', ['voice' => 'Nope_Voice']);

            $this->fail('Expected ProviderRequestException.');
        } catch (ProviderRequestException $exception) {
            $this->assertStringContainsString('voice_id not found', $exception->getMessage());
        }
    }

    public function test_identical_requests_reuse_the_cached_artifact(): void
    {
        Http::fake([
            'https://api.replicate.com/v1/predictions' => Http::response([
                'id' => 'pred_tts_4',
                'status' => 'succeeded',
                'output' => 'https://replicate.delivery/audio-4.wav',
            ], 200),
            'https://replicate.delivery/audio-4.wav' => Http::response(
                FakeAudioFixture::wav(900),
                200,
                ['Content-Type' => 'audio/wav'],
            ),
        ]);

        $resolved = $this->resolveReplicate();
        $adapter = $resolved->ttsAdapter();

        $config = ['voice' => 'English_Strong-WilledBoy', 'speed' => 1.1];

        $first = $adapter->synthesize('The dragon roared happily.', $config);
        $this->assertNotNull($first);

        $cached = $adapter->cachedArtifactPath('The dragon roared happily.', $config);

        $this->assertNotNull($cached, 'the identical request finds the persisted artifact');
        $this->assertSame($first->path, $cached);

        $this->assertNull($adapter->cachedArtifactPath('A different line.', $config));
    }

    public function test_oversized_text_is_rejected_with_the_documented_limit(): void
    {
        $resolved = $this->resolveReplicate();

        $this->expectException(ProviderConfigurationException::class);
        $this->expectExceptionMessage('documented Replicate speech limit');

        $resolved->ttsAdapter()->synthesize(str_repeat('a', 10001));
    }

    public function test_unmatched_delivery_instructions_do_not_invent_an_emotion(): void
    {
        Http::fake([
            'https://api.replicate.com/v1/predictions' => Http::response([
                'id' => 'pred_tts_5',
                'status' => 'succeeded',
                'output' => 'https://replicate.delivery/audio-5.wav',
            ], 200),
            'https://replicate.delivery/audio-5.wav' => Http::response(
                FakeAudioFixture::wav(400),
                200,
                ['Content-Type' => 'audio/wav'],
            ),
        ]);

        $resolved = $this->resolveReplicate();

        $resolved->ttsAdapter()->synthesize('Neutral line.', [
            'voice' => 'English_WiseScholar',
            'instructions' => 'a very unusual bespoke delivery style with no keyword match',
        ]);

        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== 'https://api.replicate.com/v1/predictions') {
                return false;
            }

            $this->assertSame('auto', $request->data()['input']['emotion']);

            return true;
        });
    }
}
