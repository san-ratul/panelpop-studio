<?php

namespace App\AI\Fakes;

use App\AI\Contracts\ImageProvider;
use App\AI\Inputs\CharacterBrief;
use App\AI\Inputs\SceneBrief;
use App\AI\Results\ImageResult;
use App\AI\Results\ProviderUsage;
use App\AI\Support\RequestFingerprint;
use App\Enums\ProviderCapability;
use Illuminate\Support\Facades\Storage;

final class FakeImageProvider implements ImageProvider
{
    public const MODEL = 'fake-image-1';

    private const COST_ACTUAL = 0.012345;

    public function __construct(private readonly FakeProviderScenario $scenario) {}

    public function generateCharacterReference(CharacterBrief $character, string $style): ImageResult
    {
        return $this->render('character_reference', [
            'name' => $character->name,
            'description' => $character->description,
            'consistency_notes' => $character->consistencyNotes,
            'style' => $style,
        ]);
    }

    public function generateSceneImage(SceneBrief $scene, array $characterReferences): ImageResult
    {
        return $this->render('scene_image', [
            'number' => $scene->number,
            'image_prompt' => $scene->imagePrompt,
            'background_description' => $scene->backgroundDescription,
            'character_names' => array_map(
                fn (CharacterBrief $character): string => $character->name,
                $characterReferences,
            ),
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function render(string $kind, array $input): ImageResult
    {
        $this->scenario->throwIfFailure(ProviderCapability::Image);

        $fingerprint = RequestFingerprint::generate('fake', self::MODEL, ['kind' => $kind] + $input);

        $disk = (string) config('ai.artifact_disk');
        $path = sprintf('provider-fake/image/%s.png', $fingerprint);
        $bytes = FakeImageFixture::png();

        Storage::disk($disk)->put($path, $bytes);

        return new ImageResult(
            disk: $disk,
            path: $path,
            mime: 'image/png',
            sizeBytes: strlen($bytes),
            width: FakeImageFixture::width(),
            height: FakeImageFixture::height(),
            usage: new ProviderUsage(raw: ['images' => 1]),
            costActual: self::COST_ACTUAL,
            meta: ['provider' => 'fake', 'model' => self::MODEL, 'id' => 'fake-image-'.$kind],
        );
    }
}
