<?php

namespace App\AI\Contracts;

use App\AI\Inputs\CharacterBrief;
use App\AI\Inputs\SceneBrief;
use App\AI\Results\ImageResult;

interface ImageProvider
{
    public function generateCharacterReference(CharacterBrief $character, string $style): ImageResult;

    /**
     * @param  list<CharacterBrief>  $characterReferences
     */
    public function generateSceneImage(SceneBrief $scene, array $characterReferences): ImageResult;
}
