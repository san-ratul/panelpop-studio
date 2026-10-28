<?php

namespace Tests\Support;

use App\AI\Fakes\FakeAudioFixture;
use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\DialogueLineStatus;
use App\Enums\SceneStatus;
use App\Models\Asset;
use App\Models\Character;
use App\Models\DialogueLine;
use App\Models\Project;
use App\Models\Scene;
use App\Models\User;
use App\Services\Render\ProcessResult;
use App\Services\Render\ProcessRunner;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Builds ready-to-render fixture projects (Phase 6 tests): real PNG/WAV
 * bytes on the fake private disk, ready scenes with measured durations, and
 * optional narration/dialogue audio with meta durations.
 */
trait BuildsRenderFixtures
{
    /**
     * @param  array<int, array{narration?: ?string, narration_duration_ms?: ?int, dialogue?: list<array{text: string, duration_ms?: ?int}>, bubble?: ?array<string, mixed>}>  $scenes
     */
    protected function createRenderableProject(User $user, string $mode = 'narrator', array $scenes = [], int $target = 30): Project
    {
        $project = $user->projects()->create([
            'title' => 'Render fixture project',
            'presentation_mode' => $mode,
            'target_duration_seconds' => $target,
            'visual_style' => 'simple_2d_cartoon',
            'language' => 'en',
            'status' => 'planned',
            'settings' => [],
        ]);

        if ($scenes === []) {
            $scenes = [
                1 => ['narration' => 'The story begins here.', 'narration_duration_ms' => 4000],
                2 => ['narration' => 'Then it concludes.', 'narration_duration_ms' => 4000],
            ];
        }

        $number = 0;

        foreach ($scenes as $spec) {
            $number++;

            $scene = Scene::factory()->for($project)->create([
                'number' => $number,
                'duration_seconds' => $target / count($scenes),
                'narration_text' => $spec['narration'] ?? null,
                'speech_bubble_settings' => $spec['bubble'] ?? null,
                'status' => SceneStatus::Pending,
            ]);

            $image = $this->fixturePng();
            $imagePath = sprintf('fixtures/images/scene-%d.png', $number);
            Storage::disk('media-private')->put($imagePath, $image);

            $imageAsset = Asset::query()->create([
                'project_id' => $project->id,
                'type' => AssetType::SceneImage,
                'disk' => 'media-private',
                'path' => $imagePath,
                'mime' => 'image/png',
                'size_bytes' => strlen($image),
                'meta' => ['width' => 64, 'height' => 96],
                'status' => AssetStatus::Ready,
            ]);

            $narrationAudio = null;

            if (($spec['narration'] ?? null) !== null && array_key_exists('narration_duration_ms', $spec)) {
                $narrationAudio = $this->storeVoiceFixture($project, 'narration-'.$number, (int) $spec['narration_duration_ms']);
            }

            foreach ($spec['dialogue'] ?? [] as $index => $line) {
                $character = Character::factory()->for($project)->create();

                $audio = $line['duration_ms'] !== null
                    ? $this->storeVoiceFixture($project, 'line-'.$number.'-'.$index, (int) $line['duration_ms'])
                    : null;

                DialogueLine::factory()->for($scene)->for($character)->create([
                    'text' => $line['text'],
                    'sort_order' => $index,
                    'audio_asset_id' => $audio?->id,
                    'status' => $audio !== null ? DialogueLineStatus::Ready : DialogueLineStatus::Pending,
                ]);
            }

            $scene->update([
                'image_asset_id' => $imageAsset->id,
                'narration_audio_asset_id' => $narrationAudio?->id,
                'status' => SceneStatus::Ready,
                'error_message' => null,
            ]);
        }

        return $project;
    }

    protected function storeVoiceFixture(Project $project, string $name, int $durationMs): Asset
    {
        $wav = FakeAudioFixture::wav($durationMs);
        $path = sprintf('fixtures/audio/%s.wav', $name.'-'.strtolower(Str::ulid()->toBase32()));
        Storage::disk('media-private')->put($path, $wav);

        return Asset::query()->create([
            'project_id' => $project->id,
            'type' => AssetType::VoiceAudio,
            'disk' => 'media-private',
            'path' => $path,
            'mime' => 'audio/wav',
            'size_bytes' => strlen($wav),
            'meta' => ['duration_ms' => $durationMs, 'duration_source' => 'wav_header', 'provenance' => 'ai_tts'],
            'status' => AssetStatus::Ready,
        ]);
    }

    /** A real, decodable PNG (64x96, two colors). */
    protected function fixturePng(): string
    {
        $image = imagecreatetruecolor(64, 96);
        imagefilledrectangle($image, 0, 0, 64, 96, imagecolorallocate($image, 60, 90, 160));
        imagefilledrectangle($image, 12, 12, 40, 60, imagecolorallocate($image, 250, 210, 80));

        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    protected function bindFakeRunner(FakeProcessRunner $fake): void
    {
        $this->app->bind(ProcessRunner::class, fn () => $fake);
    }

    /**
     * Points the ffmpeg/ffprobe config at harmless stub files so pipeline
     * tests never depend on a real binary (the fake runner answers all
     * invocations), and queues the BinaryProbe handshake responses.
     */
    protected function stubBinaryConfig(FakeProcessRunner $fake): void
    {
        $stub = storage_path('app/testing/ffmpeg-stub.exe');

        if (! is_dir(dirname($stub))) {
            mkdir(dirname($stub), 0775, true);
        }

        if (! is_file($stub)) {
            touch($stub);
        }

        config(['ffmpeg.binary' => $stub, 'ffmpeg.probe_binary' => $stub]);

        // BinaryProbe order: ffmpeg -version, ffprobe -version, -encoders.
        $fake->queue(
            fn (): ProcessResult => new ProcessResult(0, "ffmpeg version 9.0.2-stub\n", '', false, false),
            fn (): ProcessResult => new ProcessResult(0, "ffprobe version 9.0.2-stub\n", '', false, false),
            fn (): ProcessResult => new ProcessResult(0, self::ENCODER_LIST, '', false, false),
        );
    }

    private const ENCODER_LIST = <<< 'TEXT'
        V....D libx264              libx264 H.264 / AVC / MPEG-4 AVC
        A....D aac                  AAC (Advanced Audio Coding)
        TEXT;

    /** Synthetic MP4-ish bytes with an exact top-level box order. */
    protected function mp4FaststartBytes(bool $faststart = true): string
    {
        $box = static fn (string $type, string $payload): string => pack('N', 8 + strlen($payload)).$type.$payload;

        $ftyp = $box('ftyp', 'isom'.pack('N', 512).'isomiso2avc1mp41');
        $moov = $box('moov', $box('mvhd', str_repeat("\0", 96)));
        $mdat = $box('mdat', str_repeat("\1", 128));

        return $faststart ? $ftyp.$moov.$mdat : $ftyp.$mdat.$moov;
    }

    /** Bright (non-black) PNG for gate checks. */
    protected function brightPng(): string
    {
        $image = imagecreatetruecolor(32, 32);
        imagefilledrectangle($image, 0, 0, 32, 32, imagecolorallocate($image, 200, 160, 60));

        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    /**
     * An ffprobe ProcessResult built from a stored fixture, with the format
     * duration overridden to match the plan's expected total.
     */
    protected function fixtureProbeResult(string $fixture, float $durationSeconds): ProcessResult
    {
        $json = json_decode((string) file_get_contents(base_path('tests/fixtures/ffprobe/'.$fixture)), true);
        $json['format']['duration'] = number_format($durationSeconds, 6, '.', '');
        $json['format']['size'] = (string) strlen($this->mp4FaststartBytes());

        return new ProcessResult(0, (string) json_encode($json), '', false, false);
    }
}
