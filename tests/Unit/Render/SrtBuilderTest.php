<?php

namespace Tests\Unit\Render;

use App\Services\Render\RenderAudio;
use App\Services\Render\RenderDialogueLine;
use App\Services\Render\RenderPlanScene;
use App\Services\Render\RenderTimeline;
use App\Services\Render\SrtBuilder;
use Tests\TestCase;

class SrtBuilderTest extends TestCase
{
    private function scene(int $number, float $duration, ?RenderAudio $narration, string $narrationText, array $lines = []): RenderPlanScene
    {
        return new RenderPlanScene(
            number: $number,
            durationSeconds: $duration,
            imageDisk: 'media-private',
            imagePath: 'x.png',
            bubbleSettings: null,
            narrationText: $narrationText,
            narrationAudio: $narration,
            dialogueLines: $lines,
        );
    }

    private function audio(?int $durationMs): ?RenderAudio
    {
        return $durationMs === null ? null : new RenderAudio('media-private', 'a.wav', $durationMs);
    }

    public function test_timestamps_use_the_srt_format(): void
    {
        $this->assertSame('00:00:00,000', SrtBuilder::timestamp(0.0));
        $this->assertSame('00:00:01,500', SrtBuilder::timestamp(1.5));
        $this->assertSame('00:01:05,250', SrtBuilder::timestamp(65.25));
        $this->assertSame('01:00:00,000', SrtBuilder::timestamp(3600.0));
        $this->assertSame('00:00:00,000', SrtBuilder::timestamp(-3.0));
    }

    public function test_cues_follow_scene_order_and_measured_durations(): void
    {
        $srt = (new SrtBuilder)->build([
            $this->scene(1, 10.0, $this->audio(4000), 'First scene.', []),
            $this->scene(2, 10.0, $this->audio(5000), 'Second scene.', []),
        ]);

        $this->assertStringContainsString("1\n00:00:00,000 --> 00:00:04,000\nFirst scene.", $srt);
        $this->assertStringContainsString("2\n00:00:10,000 --> 00:00:15,000\nSecond scene.", $srt);
    }

    public function test_dialogue_lines_play_after_narration_within_a_scene(): void
    {
        $line = new RenderDialogueLine(7, 'Hello there!', $this->audio(2000));

        $srt = (new SrtBuilder)->build([
            $this->scene(1, 10.0, $this->audio(3000), 'Narration first.', [$line]),
        ]);

        $this->assertStringContainsString("1\n00:00:00,000 --> 00:00:03,000\nNarration first.", $srt);
        $this->assertStringContainsString("2\n00:00:03,000 --> 00:00:05,000\nHello there!", $srt);
    }

    public function test_cues_without_measured_durations_are_skipped(): void
    {
        $srt = (new SrtBuilder)->build([
            $this->scene(1, 10.0, null, 'No audio, no cue.', []),
            $this->scene(2, 10.0, $this->audio(null), 'Unknown duration.', []),
            $this->scene(3, 10.0, $this->audio(2000), 'Measured.', []),
        ]);

        $this->assertStringNotContainsString('No audio, no cue.', $srt);
        $this->assertStringNotContainsString('Unknown duration.', $srt);
        $this->assertStringContainsString('Measured.', $srt);
    }

    public function test_long_text_splits_proportionally_into_cues(): void
    {
        // Greedy packing: sentences join until the 84-char cue limit forces a
        // split, and the cue window is shared proportionally by char count.
        $pieces = SrtBuilder::splitText(
            'The first part of the narration sets up the entire scene here. '
            .'The second part continues the story for a while longer in the same cue window. '
            .'The third part finishes the scene completely and closes the cue.',
            9.0,
            1.0,
        );

        $this->assertGreaterThan(1, count($pieces));
        $this->assertSame(1.0, $pieces[0]['start']);
        $this->assertEqualsWithDelta(10.0, $pieces[count($pieces) - 1]['end'], 0.001);

        $totalChars = array_sum(array_map(static fn (array $p): int => mb_strlen((string) $p['text']), $pieces));

        foreach ($pieces as $piece) {
            $expectedShare = mb_strlen((string) $piece['text']) / $totalChars * 9.0;
            $actualShare = $piece['end'] - $piece['start'];

            $this->assertEqualsWithDelta($expectedShare, $actualShare, 0.2);
        }

        foreach ($pieces as $piece) {
            $this->assertLessThanOrEqual(84, mb_strlen((string) $piece['text']));
        }
    }

    public function test_long_sentences_hard_wrap_within_the_character_limit(): void
    {
        $pieces = SrtBuilder::splitText(str_repeat('word ', 40), 10.0, 0.0);

        foreach ($pieces as $piece) {
            foreach (explode("\n", (string) $piece['text']) as $line) {
                $this->assertLessThanOrEqual(84, mb_strlen($line));
            }
        }
    }

    public function test_cues_are_clamped_to_their_scene(): void
    {
        // Narration audio (9s) exceeds the 6s scene: the cue window clamps.
        $cues = RenderTimeline::speechCues([
            $this->scene(1, 6.0, $this->audio(9000), 'Overrunning narration.', []),
        ]);

        $blocks = (new SrtBuilder)->cues([$this->scene(1, 6.0, $this->audio(9000), 'Overrunning narration.', [])]);

        $this->assertCount(1, $blocks);
        $this->assertStringContainsString('00:00:00,000 --> 00:00:06,000', $blocks[0]);
        $this->assertTrue($cues[0]->overrunsScene());
    }

    public function test_empty_plan_produces_no_srt(): void
    {
        $this->assertSame('', (new SrtBuilder)->build([]));
    }
}
