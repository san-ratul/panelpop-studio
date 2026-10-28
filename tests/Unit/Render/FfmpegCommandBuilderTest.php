<?php

namespace Tests\Unit\Render;

use App\Enums\PresentationMode;
use App\Services\Render\FfmpegCommandBuilder;
use App\Services\Render\RenderAudio;
use App\Services\Render\RenderDialogueLine;
use App\Services\Render\RenderPlan;
use App\Services\Render\RenderPlanScene;
use App\Services\Render\RenderSettings;
use App\Services\Render\RenderTimeline;
use InvalidArgumentException;
use Tests\TestCase;

class FfmpegCommandBuilderTest extends TestCase
{
    private FfmpegCommandBuilder $builder;

    private RenderSettings $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = new RenderSettings(
            ffmpegPath: 'C:/ffmpeg/bin/ffmpeg.exe',
            ffprobePath: 'C:/ffmpeg/bin/ffprobe.exe',
            x264Preset: 'veryfast',
            crf: 23,
            audioBitrate: '192k',
            sampleRate: 48000,
            musicVolume: 0.25,
            sfxVolume: 0.5,
            loudnessI: -16.0,
            loudnessTp: -1.5,
            loudnessLra: 11.0,
            transitionSeconds: 0.5,
            timeoutSeconds: 1800.0,
            probeTimeoutSeconds: 60.0,
            minFreeDiskMb: 500,
            gateDurationToleranceSeconds: 1.0,
            gateMaxBlackness: 8.0,
            bubbleFontPath: null,
            bubbleFontScale: 2.0,
            thumbnailScale: 0.5,
            tempDir: '/tmp/rendering',
        );

        $this->builder = new FfmpegCommandBuilder($this->settings);
    }

    private function scene(int $number, float $duration, ?RenderAudio $narration = null, array $lines = []): RenderPlanScene
    {
        return new RenderPlanScene(
            number: $number,
            durationSeconds: $duration,
            imageDisk: 'media-private',
            imagePath: 'images/scene.png',
            bubbleSettings: null,
            narrationText: 'UNIQUE-USER-TEXT narration',
            narrationAudio: $narration,
            dialogueLines: $lines,
        );
    }

    private function plan(array $scenes, array $music = [], array $sfx = []): RenderPlan
    {
        return new RenderPlan(1, PresentationMode::Narrator->value, $scenes, $music, $sfx, []);
    }

    public function test_segment_command_carries_the_output_contract(): void
    {
        $args = $this->builder->segmentCommand($this->scene(1, 10.0), 10.0, 'C:/media/img.png', [], '/tmp/seg.mp4');

        $this->assertSame('C:/ffmpeg/bin/ffmpeg.exe', $args[0]);
        $this->assertContains('-y', $args);
        $this->assertContains('C:/media/img.png', $args);
        $this->assertContains('libx264', $args);
        $this->assertContains('yuv420p', $args);
        $this->assertContains('30', $args); // fps via zoompan fps= and -r

        $filter = $args[array_search('-filter_complex', $args, true) + 1];

        // 1080x1920 prep + Ken Burns + fixed pixel format, all in one chain.
        $this->assertStringContainsString('scale=2160:3840:force_original_aspect_ratio=increase', $filter);
        $this->assertStringContainsString('crop=2160:3840', $filter);
        $this->assertStringContainsString('zoompan=', $filter);
        $this->assertStringContainsString('s=1080x1920:fps=30', $filter);
        $this->assertStringContainsString('setsar=1,format=yuv420p[v]', $filter);
    }

    public function test_segment_frames_match_the_segment_length(): void
    {
        $args = $this->builder->segmentCommand($this->scene(1, 10.0), 10.0, 'C:/media/img.png', [], '/tmp/seg.mp4');

        $framesIndex = array_search('-frames:v', $args, true);

        $this->assertNotFalse($framesIndex);
        $this->assertSame('300', $args[$framesIndex + 1]); // 10 s × 30 fps
    }

    public function test_segment_command_is_deterministic_per_scene_number(): void
    {
        $a = $this->builder->segmentCommand($this->scene(1, 10.0), 10.0, 'C:/media/img.png', [], '/tmp/a.mp4');
        $b = $this->builder->segmentCommand($this->scene(1, 10.0), 10.0, 'C:/media/img.png', [], '/tmp/a.mp4');
        $c = $this->builder->segmentCommand($this->scene(2, 10.0), 10.0, 'C:/media/img.png', [], '/tmp/a.mp4');

        $this->assertSame($a, $b);

        $filterA = $a[array_search('-filter_complex', $a, true) + 1];
        $filterC = $c[array_search('-filter_complex', $c, true) + 1];

        $this->assertNotSame($filterA, $filterC); // Ken Burns direction alternates
    }

    public function test_last_scene_segment_does_not_carry_the_transition(): void
    {
        $this->assertSame(15.0 + 0.5, $this->settings->segmentLength(15.0, false));
        $this->assertSame(15.0, $this->settings->segmentLength(15.0, true));
    }

    public function test_xfade_offsets_match_the_documented_math(): void
    {
        $offsets = RenderTimeline::xfadeOffsets([10.0, 20.0, 30.0], 0.5);

        $this->assertSame([10.0, 30.0], $offsets);
    }

    public function test_scene_starts_are_cumulative_durations(): void
    {
        $starts = RenderTimeline::sceneStarts([
            $this->scene(1, 10.0),
            $this->scene(2, 20.0),
            $this->scene(3, 30.0),
        ]);

        $this->assertSame([0.0, 10.0, 30.0], $starts);
    }

    public function test_final_mux_silent_render_has_no_audio_streams(): void
    {
        $plan = $this->plan([$this->scene(1, 30.0)]);

        $args = $this->builder->finalMuxCommand($plan, ['/tmp/seg.mp4'], [], [], [], '/tmp/final.mp4');

        $this->assertContains('-an', $args);
        $this->assertNotContains('aac', $args);
        $this->assertContains('+faststart', $args);
        $this->assertContains('30.000', $args); // exact -t total
        $this->assertContains('libx264', $args);
    }

    public function test_final_mux_narrator_render_mixes_aac_with_offsets(): void
    {
        $narration = new RenderAudio('media-private', 'n.wav', 4000);
        $line = new RenderDialogueLine(3, 'UNIQUE-USER-TEXT line', new RenderAudio('media-private', 'l.wav', 2000));

        $plan = $this->plan([
            $this->scene(1, 10.0, $narration, [$line]),
            $this->scene(2, 10.0, new RenderAudio('media-private', 'n2.wav', 4000), []),
        ]);

        $args = $this->builder->finalMuxCommand(
            $plan,
            ['/tmp/s1.mp4', '/tmp/s2.mp4'],
            ['C:/media/n.wav', 'C:/media/l.wav', 'C:/media/n2.wav'],
            [],
            [],
            '/tmp/final.mp4',
        );

        $this->assertContains('aac', $args);
        $this->assertContains('192k', $args);
        $this->assertContains('48000', $args);
        $this->assertContains('+faststart', $args);

        $filter = $args[array_search('-filter_complex', $args, true) + 1];

        // Scene 1 starts at 0 (narration), dialogue after the 4 s narration,
        // scene 2 narration at 10 s.
        $this->assertStringContainsString('adelay=0:all=1[sp0]', $filter);
        $this->assertStringContainsString('adelay=4000:all=1[sp1]', $filter);
        $this->assertStringContainsString('adelay=10000:all=1[sp2]', $filter);
        $this->assertStringContainsString('amix=inputs=3:normalize=0', $filter);
        $this->assertStringContainsString('loudnorm=I=-16:TP=-1.5:LRA=11', $filter);

        // xfade: transition starts where scene 1 ends (10 s).
        $this->assertStringContainsString('xfade=transition=fade:duration=0.500:offset=10.000', $filter);
    }

    public function test_final_mux_music_is_looped_ducked_and_trimmed(): void
    {
        $plan = $this->plan(
            [$this->scene(1, 30.0, new RenderAudio('media-private', 'n.wav', 4000))],
            music: [new RenderAudio('media-private', 'm.mp3', null)],
        );

        $args = $this->builder->finalMuxCommand(
            $plan,
            ['/tmp/seg.mp4'],
            ['C:/media/narration.wav'],
            ['C:/media/music.mp3'],
            [],
            '/tmp/final.mp4',
        );

        $streamLoop = array_search('-stream_loop', $args, true);

        $this->assertNotFalse($streamLoop);
        $this->assertSame('-1', $args[$streamLoop + 1]);
        $this->assertSame('C:/media/music.mp3', $args[$streamLoop + 3]);

        $filter = $args[array_search('-filter_complex', $args, true) + 1];

        $this->assertStringContainsString('volume=0.250', $filter);
        $this->assertStringContainsString('atrim=start=0:end=30.000', $filter);
        $this->assertStringContainsString('sidechaincompress=threshold=0.02:ratio=8:attack=30:release=400', $filter);
        $this->assertStringContainsString('[mixm][sc]sidechaincompress', $filter);
        $this->assertStringContainsString('amix=inputs=2:normalize=0:duration=longest', $filter);
    }

    public function test_music_only_render_skips_ducking(): void
    {
        $plan = $this->plan([$this->scene(1, 30.0)], music: [new RenderAudio('media-private', 'm.mp3', null)]);

        $args = $this->builder->finalMuxCommand(
            $plan,
            ['/tmp/seg.mp4'],
            [],
            ['C:/media/music.mp3'],
            [],
            '/tmp/final.mp4',
        );

        $filter = $args[array_search('-filter_complex', $args, true) + 1];

        $this->assertStringNotContainsString('sidechaincompress', $filter);
        $this->assertStringContainsString('loudnorm', $filter);
    }

    public function test_user_text_never_reaches_the_command_line(): void
    {
        $narration = new RenderAudio('media-private', 'n.wav', 4000);
        $line = new RenderDialogueLine(3, 'SECRET-USER-PHRASE must not leak', new RenderAudio('media-private', 'l.wav', 2000));

        $plan = $this->plan([
            $this->scene(1, 30.0, $narration, [$line]),
        ], music: [new RenderAudio('media-private', 'm.mp3', null)], sfx: [new RenderAudio('media-private', 's.wav', null)]);

        $allArgs = [
            $this->builder->segmentCommand($plan->scenes[0], 30.0, 'C:/media/img.png', [], '/tmp/seg.mp4'),
            $this->builder->finalMuxCommand($plan, ['/tmp/seg.mp4'], ['C:/media/n.wav', 'C:/media/l.wav'], ['C:/media/m.mp3'], ['C:/media/s.wav'], '/tmp/final.mp4'),
            $this->builder->thumbnailCommand('/tmp/final.mp4', 15.0, '/tmp/thumb.png'),
            $this->builder->firstFrameCommand('/tmp/final.mp4', '/tmp/first.png'),
            $this->builder->probeCommand('C:/ffmpeg/bin/ffprobe.exe', '/tmp/final.mp4'),
        ];

        foreach ($allArgs as $args) {
            foreach ($args as $arg) {
                $this->assertStringNotContainsString('SECRET-USER-PHRASE', (string) $arg);
                $this->assertStringNotContainsString('UNIQUE-USER-TEXT', (string) $arg);
                $this->assertStringNotContainsString('narration', (string) $arg);
            }
        }
    }

    public function test_thumbnail_and_probe_commands(): void
    {
        $thumb = $this->builder->thumbnailCommand('/tmp/final.mp4', 7.5, '/tmp/thumb.png');

        $this->assertSame('C:/ffmpeg/bin/ffmpeg.exe', $thumb[0]);
        $this->assertContains('scale=540:960', $thumb); // 0.5 × 1080x1920
        $this->assertContains('7.500', $thumb);
        $this->assertContains('-frames:v', $thumb);

        $probe = $this->builder->probeCommand('C:/ffmpeg/bin/ffprobe.exe', '/tmp/final.mp4');

        $this->assertSame('C:/ffmpeg/bin/ffprobe.exe', $probe[0]);
        $this->assertContains('json', $probe);
        $this->assertContains('/tmp/final.mp4', $probe);
    }

    public function test_mux_requires_segments(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->builder->finalMuxCommand($this->plan([$this->scene(1, 30.0)]), [], [], [], [], '/tmp/final.mp4');
    }

    public function test_single_scene_mux_has_no_xfade(): void
    {
        $plan = $this->plan([$this->scene(1, 30.0)]);

        $args = $this->builder->finalMuxCommand($plan, ['/tmp/seg.mp4'], [], [], [], '/tmp/final.mp4');
        $filter = $args[array_search('-filter_complex', $args, true) + 1];

        $this->assertSame('[0:v]null[v]', $filter);
        $this->assertNotContains('xfade', $args);
    }
}
