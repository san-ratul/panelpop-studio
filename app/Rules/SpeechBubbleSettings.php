<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class SpeechBubbleSettings implements ValidationRule
{
    public const STYLES = ['classic', 'thought', 'shout'];

    public const POSITIONS = ['top', 'center', 'bottom'];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null) {
            return;
        }

        if (! is_array($value)) {
            $fail('The :attribute must be an object.');

            return;
        }

        $allowedKeys = ['style', 'position', 'max_width_percent', 'background_color', 'font_size'];
        $unknown = array_diff(array_keys($value), $allowedKeys);

        if ($unknown !== []) {
            $fail('The :attribute contains unsupported keys: '.implode(', ', $unknown).'.');

            return;
        }

        if (array_key_exists('style', $value) && $value['style'] !== null
            && ! in_array($value['style'], self::STYLES, true)) {
            $fail('The :attribute.style must be one of: '.implode(', ', self::STYLES).'.');
        }

        if (array_key_exists('position', $value) && $value['position'] !== null
            && ! in_array($value['position'], self::POSITIONS, true)) {
            $fail('The :attribute.position must be one of: '.implode(', ', self::POSITIONS).'.');
        }

        if (array_key_exists('max_width_percent', $value) && $value['max_width_percent'] !== null
            && (! is_int($value['max_width_percent']) || $value['max_width_percent'] < 20 || $value['max_width_percent'] > 100)) {
            $fail('The :attribute.max_width_percent must be an integer between 20 and 100.');
        }

        if (array_key_exists('background_color', $value) && $value['background_color'] !== null
            && (! is_string($value['background_color']) || preg_match('/^#[0-9A-Fa-f]{6}$/', $value['background_color']) !== 1)) {
            $fail('The :attribute.background_color must be a hex color like #FFFFFF.');
        }

        if (array_key_exists('font_size', $value) && $value['font_size'] !== null
            && (! is_int($value['font_size']) || $value['font_size'] < 8 || $value['font_size'] > 72)) {
            $fail('The :attribute.font_size must be an integer between 8 and 72.');
        }
    }
}
