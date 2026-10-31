<?php

/**
 * User-facing catalogs for the project-level defaults (visual style +
 * language) that every video inherits and may override per video.
 *
 * Validation stays a bounded string (max length) rather than a closed enum so
 * existing projects with custom values keep working; the UI always merges the
 * current value into the dropdown options.
 */

return [

    'visual_styles' => [
        ['value' => 'simple_2d_cartoon', 'label' => 'Simple 2D Cartoon'],
        ['value' => 'animated_2d_cartoon', 'label' => 'Animated 2D Cartoon'],
        ['value' => 'comic_book', 'label' => 'Comic Book'],
        ['value' => 'anime', 'label' => 'Anime'],
        ['value' => 'storybook', 'label' => 'Storybook Illustration'],
        ['value' => 'watercolor', 'label' => 'Watercolor'],
        ['value' => 'papercut', 'label' => 'Paper Cutout'],
        ['value' => 'claymation', 'label' => 'Claymation'],
        ['value' => 'pixel_art', 'label' => 'Pixel Art'],
    ],

    'languages' => [
        ['value' => 'en', 'label' => 'English'],
        ['value' => 'bn', 'label' => 'Bengali (বাংলা)'],
        ['value' => 'hi', 'label' => 'Hindi (हिन्दी)'],
        ['value' => 'es', 'label' => 'Spanish (Español)'],
        ['value' => 'fr', 'label' => 'French (Français)'],
        ['value' => 'de', 'label' => 'German (Deutsch)'],
        ['value' => 'pt', 'label' => 'Portuguese (Português)'],
        ['value' => 'it', 'label' => 'Italian (Italiano)'],
        ['value' => 'ru', 'label' => 'Russian (Русский)'],
        ['value' => 'ja', 'label' => 'Japanese (日本語)'],
        ['value' => 'ko', 'label' => 'Korean (한국어)'],
        ['value' => 'zh', 'label' => 'Chinese (中文)'],
        ['value' => 'ar', 'label' => 'Arabic (العربية)'],
    ],

];
