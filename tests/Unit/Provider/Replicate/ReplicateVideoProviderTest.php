<?php

namespace Tests\Unit\Provider\Replicate;

use App\AI\Contracts\VideoTaskHandle;
use App\AI\Exceptions\ProviderConfigurationException;
use App\AI\Exceptions\ProviderRequestException;
use App\AI\Inputs\SceneAnimationBrief;
use App\AI\ProviderManager;
use App\Enums\ProviderCapability;
use App\Models\Project;
use App\Models\ProviderSettings;
use Database\Factories\ProviderSettingsFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsRenderFixtures;
use Tests\TestCase;

/**
 * HTTP-fake tests for the Replicate scene-video adapter (no real API calls
 * — Http::preventStrayRequests() is armed in the base test case). Request
 * and response shapes mirror the VERIFIED official model schemas and the
 * prediction API (config ai.replicate.*; verified 2026-11-01):
 * POST {base}/predictions {version, input} → {id, status};
 * GET {base}/predictions/{id} → {status, output, error}.
 */
class ReplicateVideoProviderTest extends TestCase
{
    use BuildsRenderFixtures;
    use RefreshDatabase;

    private ProviderManager $manager;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = app(ProviderManager::class);
        $this->project = Project::factory()->create();

        Storage::fake('media-private');
    }

    private function resolveReplicate(string $model): object
    {
        ProviderSettingsFactory::new()
            ->forCapability(ProviderCapability::Video)
            ->asDefault()
            ->withCredentials('r8-test-credential-1234')
            ->create([
                'provider' => 'replicate',
                'config' => ['model' => $model],
            ]);

        return $this->manager->resolve(ProviderCapability::Video, $this->project);
    }

    private function brief(): SceneAnimationBrief
    {
        Storage::disk('media-private')->put('images/scene-1.png', $this->fixturePng());

        return new SceneAnimationBrief(
            imageDisk: 'media-private',
            imagePath: 'images/scene-1.png',
            durationSeconds: 7,
            emotion: 'excited',
            characterNames: ['Robby'],
            action: 'Robby jumps over the puddle',
            camera: 'Stable camera',
            environment: 'Leaves drift in the wind',
            visualStyle: 'simple 2D cartoon',
            dialogueLines: [],
            withNativeAudio: false,
        );
    }

    public function test_wan_submission_pins_the_version_and_builds_the_verified_input(): void
    {
        Http::fake([
            'https://api.replicate.com/v1/predictions' => Http::response([
                'id' => 'pred_wan_1',
                'status' => 'starting',
            ], 201),
        ]);

        $resolved = $this->resolveReplicate('wan-video/wan-2.5-i2v-fast');

        $handle = $resolved->videoAdapter()->createSceneTask($this->brief());

        $this->assertSame('pred_wan_1', $handle->remoteId);

        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== 'https://api.replicate.com/v1/predictions') {
                return false;
            }

            $this->assertSame('Bearer r8-test-credential-1234', $request->header('Authorization')[0]);

            $body = $request->data();

            // Pinned catalog version (reproducible; verified schema).
            $this->assertSame(
                '89a2696cf16b9aa6536d766585e0fc3a5c6f8082cd7eeb2f89f2bf88eb28d4af',
                $body['version'],
            );

            $input = $body['input'];

            $this->assertSame('720p', $input['resolution'], 'catalog default resolution');
            $this->assertSame(10, $input['duration'], '7 s scene resolves to the nearest supported 10 s clip');
            $this->assertStringStartsWith('data:image/png;base64,', $input['image'], 'start frame = the scene still');
            $this->assertStringContainsString('Robby jumps over the puddle', $input['prompt']);
            $this->assertStringContainsString('simple 2D cartoon', $input['prompt']);
            $this->assertSame('speech, talking, voiceover', $input['negative_prompt'], 'no audio attached → the clip stays silent');

            return true;
        });
    }

    public function test_veo_submission_declares_916_and_native_audio(): void
    {
        Http::fake([
            'https://api.replicate.com/v1/predictions' => Http::response([
                'id' => 'pred_veo_1',
                'status' => 'starting',
            ], 201),
        ]);

        $resolved = $this->resolveReplicate('google/veo-3-fast');

        $brief = $this->brief();
        $brief = new SceneAnimationBrief(
            imageDisk: $brief->imageDisk,
            imagePath: $brief->imagePath,
            durationSeconds: 7,
            emotion: $brief->emotion,
            characterNames: $brief->characterNames,
            aspectRatio: '9:16',
            action: $brief->action,
            camera: $brief->camera,
            environment: $brief->environment,
            visualStyle: $brief->visualStyle,
            dialogueLines: ['Hello there!'],
            withNativeAudio: true,
        );

        $resolved->videoAdapter()->createSceneTask($brief);

        Http::assertSent(function (Request $request): bool {
            $input = $request->data()['input'];

            $this->assertSame('9:16', $input['aspect_ratio'], 'veo-3-fast supports an explicit aspect ratio');
            $this->assertTrue($input['generate_audio'], 'native speech requested');
            $this->assertSame(8, $input['duration'], '7 s resolves to the nearest supported 8 s clip');
            $this->assertStringContainsString('Hello there!', $input['prompt'], 'dialogue quoted for native speech');

            return true;
        });
    }

    public function test_kling_submission_uses_the_start_image_key(): void
    {
        Http::fake([
            'https://api.replicate.com/v1/predictions' => Http::response([
                'id' => 'pred_kling_1',
                'status' => 'starting',
            ], 201),
        ]);

        $resolved = $this->resolveReplicate('kwaivgi/kling-v2.1');

        $resolved->videoAdapter()->createSceneTask($this->brief());

        Http::assertSent(function (Request $request): bool {
            $input = $request->data()['input'];

            $this->assertArrayHasKey('start_image', $input, 'kling-v2.1 requires start_image (verified schema)');
            $this->assertSame('standard', $input['mode']);
            $this->assertArrayNotHasKey('resolution', $input, 'kling standard/pro mode replaces a resolution field');

            return true;
        });
    }

    public function test_polling_maps_the_documented_statuses(): void
    {
        Http::fake([
            'https://api.replicate.com/v1/predictions/pred_1' => Http::sequence()
                ->push(['id' => 'pred_1', 'status' => 'starting'], 200)
                ->push(['id' => 'pred_1', 'status' => 'processing'], 200)
                ->push(['id' => 'pred_1', 'status' => 'succeeded', 'output' => 'https://replicate.delivery/clip.mp4'], 200),
            'https://api.replicate.com/v1/predictions/pred_2' => Http::response([
                'id' => 'pred_2', 'status' => 'failed', 'error' => 'NSFW filter triggered',
            ], 200),
            'https://api.replicate.com/v1/predictions/pred_3' => Http::response([
                'id' => 'pred_3', 'status' => 'canceled',
            ], 200),
        ]);

        $resolved = $this->resolveReplicate('wan-video/wan-2.5-i2v-fast');
        $adapter = $resolved->videoAdapter();

        $starting = $adapter->pollSceneTask(new VideoTaskHandle('pred_1'));
        $this->assertSame('queued', $starting->state);

        $processing = $adapter->pollSceneTask(new VideoTaskHandle('pred_1'));
        $this->assertSame('running', $processing->state);

        $succeeded = $adapter->pollSceneTask(new VideoTaskHandle('pred_1'));
        $this->assertSame('succeeded', $succeeded->state);
        $this->assertSame(['https://replicate.delivery/clip.mp4'], $succeeded->outputUrls);

        $failed = $adapter->pollSceneTask(new VideoTaskHandle('pred_2'));
        $this->assertSame('failed', $failed->state);
        $this->assertStringContainsString('NSFW filter triggered', (string) $failed->failureReason);

        $canceled = $adapter->pollSceneTask(new VideoTaskHandle('pred_3'));
        $this->assertSame('failed', $canceled->state);
    }

    public function test_capabilities_come_from_the_verified_catalog(): void
    {
        $wan = $this->resolveReplicate('wan-video/wan-2.5-i2v-fast')->videoAdapter()->capabilities();

        $this->assertTrue($wan->supportsReferenceImage);
        $this->assertTrue($wan->supportsNativeAudio);
        $this->assertSame('audio_input', $wan->lipSync, 'wan-2.5 lip-syncs a provided audio track');
        $this->assertSame([5, 10], $wan->supportedDurations);

        // Drop the DB default row so the env configuration below wins
        // (a stored default setting always outranks env in resolution).
        ProviderSettings::query()->delete();

        config([
            'ai.env.video' => ['provider' => 'replicate', 'model' => 'kwaivgi/kling-v2.1', 'api_key' => 'r8-test-credential-1234'],
        ]);

        $kling = $this->manager->resolve(ProviderCapability::Video)->videoAdapter()->capabilities();

        $this->assertFalse($kling->supportsNativeAudio);
        $this->assertSame('none', $kling->lipSync, 'kling-v2.1 is silent');
    }

    public function test_unknown_model_is_refused(): void
    {
        $resolved = $this->resolveReplicate('someone/made-up-model');

        $this->expectException(ProviderConfigurationException::class);
        $this->expectExceptionMessage('is not in the verified catalog');

        $resolved->videoAdapter()->createSceneTask($this->brief());
    }

    public function test_missing_scene_image_is_refused(): void
    {
        $resolved = $this->resolveReplicate('wan-video/wan-2.5-i2v-fast');

        $brief = new SceneAnimationBrief(
            imageDisk: 'media-private',
            imagePath: 'images/missing.png',
            durationSeconds: 7,
            emotion: null,
            characterNames: [],
        );

        $this->expectException(ProviderConfigurationException::class);
        $this->expectExceptionMessage('missing from storage');

        $resolved->videoAdapter()->createSceneTask($brief);
    }

    public function test_download_validates_an_mp4_stream(): void
    {
        Http::fake([
            'https://replicate.delivery/clip.mp4' => Http::response("\x00\x00\x00\x18ftypmp42data", 200),
            'https://replicate.delivery/not-mp4' => Http::response('<html>nope</html>', 200),
        ]);

        $resolved = $this->resolveReplicate('wan-video/wan-2.5-i2v-fast');
        $adapter = $resolved->videoAdapter();

        $bytes = $adapter->downloadContent('https://replicate.delivery/clip.mp4');
        $this->assertStringContainsString('ftyp', substr($bytes, 4, 4));

        $this->expectException(ProviderRequestException::class);
        $this->expectExceptionMessage('not an MP4 stream');

        $adapter->downloadContent('https://replicate.delivery/not-mp4');
    }
}
