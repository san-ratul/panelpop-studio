<?php

namespace App\Rules;

use App\AI\ProviderRegistry;
use App\Enums\ProviderCapability;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class ProviderIsKnown implements ValidationRule
{
    public function __construct(private readonly mixed $capability) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! is_string($this->capability)) {
            return;
        }

        $capability = ProviderCapability::tryFrom($this->capability);

        if ($capability === null) {
            return;
        }

        $registry = app(ProviderRegistry::class);
        $definition = $registry->definition($value);

        if ($definition === null) {
            $known = $registry->providersForCapability($capability);

            $fail(sprintf(
                '"%s" is not a known %s provider. Available providers: %s.',
                $value,
                $capability->value,
                $known === [] ? '(none registered yet — real vendor adapters are added after provider selection is verified)' : implode(', ', $known),
            ));

            return;
        }

        if (! in_array($capability->value, $registry->capabilitiesFor($value), true)) {
            $fail(sprintf(
                'The provider "%s" does not support %s generation (supported: %s).',
                $value,
                $capability->value,
                implode(', ', $registry->capabilitiesFor($value)),
            ));
        }
    }
}
