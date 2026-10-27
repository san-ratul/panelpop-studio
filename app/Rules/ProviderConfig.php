<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class ProviderConfig implements ValidationRule
{
    private const ALLOWED_KEYS = ['model', 'base_url', 'timeout_seconds'];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null) {
            return;
        }

        if (! is_array($value)) {
            $fail('The :attribute must be an object.');

            return;
        }

        $unknown = array_diff(array_keys($value), self::ALLOWED_KEYS);

        if ($unknown !== []) {
            $fail('The :attribute contains unsupported keys: '.implode(', ', $unknown).'. Allowed keys: '.implode(', ', self::ALLOWED_KEYS).'.');

            return;
        }

        if (array_key_exists('model', $value) && $value['model'] !== null
            && (! is_string($value['model']) || trim($value['model']) === '' || strlen($value['model']) > 120)) {
            $fail('The :attribute.model must be a non-empty string of at most 120 characters.');
        }

        if (array_key_exists('base_url', $value) && $value['base_url'] !== null
            && (! is_string($value['base_url']) || filter_var($value['base_url'], FILTER_VALIDATE_URL) === false || strlen($value['base_url']) > 255)) {
            $fail('The :attribute.base_url must be a valid URL of at most 255 characters.');
        }

        if (array_key_exists('timeout_seconds', $value) && $value['timeout_seconds'] !== null
            && (! is_int($value['timeout_seconds']) || $value['timeout_seconds'] < 5 || $value['timeout_seconds'] > 600)) {
            $fail('The :attribute.timeout_seconds must be an integer between 5 and 600.');
        }
    }
}
