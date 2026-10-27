<?php

namespace Tests\Unit\Provider;

use App\AI\Support\RequestFingerprint;
use App\Enums\ProviderCapability;
use PHPUnit\Framework\TestCase;

final class RequestFingerprintTest extends TestCase
{
    public function test_fingerprint_is_stable_for_identical_input(): void
    {
        $input = ['prompt' => 'A cartoon robot', 'size' => '1080x1920'];

        $first = RequestFingerprint::generate('fake', 'fake-image-1', $input);
        $second = RequestFingerprint::generate('fake', 'fake-image-1', $input);

        $this->assertSame($first, $second);
    }

    public function test_fingerprint_is_independent_of_associative_key_order(): void
    {
        $first = RequestFingerprint::generate('fake', 'fake-text-1', ['a' => 1, 'b' => 2, 'c' => ['x' => 1, 'y' => 2]]);
        $second = RequestFingerprint::generate('fake', 'fake-text-1', ['c' => ['y' => 2, 'x' => 1], 'b' => 2, 'a' => 1]);

        $this->assertSame($first, $second);
    }

    public function test_fingerprint_is_sensitive_to_list_order(): void
    {
        $first = RequestFingerprint::generate('fake', 'fake-text-1', ['scenes', 'characters']);
        $second = RequestFingerprint::generate('fake', 'fake-text-1', ['characters', 'scenes']);

        $this->assertNotSame($first, $second);
    }

    public function test_fingerprint_changes_with_provider_model_or_input(): void
    {
        $base = RequestFingerprint::generate('fake', 'fake-text-1', 'input');

        $this->assertNotSame($base, RequestFingerprint::generate('other', 'fake-text-1', 'input'));
        $this->assertNotSame($base, RequestFingerprint::generate('fake', 'fake-text-2', 'input'));
        $this->assertNotSame($base, RequestFingerprint::generate('fake', 'fake-text-1', 'different input'));
    }

    public function test_fingerprint_normalizes_enums_to_their_value(): void
    {
        $withEnum = RequestFingerprint::generate('fake', null, ProviderCapability::Image);
        $withValue = RequestFingerprint::generate('fake', null, 'image');

        $this->assertSame($withValue, $withEnum);
    }

    public function test_fingerprint_is_a_64_character_hex_digest(): void
    {
        $fingerprint = RequestFingerprint::generate('fake', null, ['x' => 1]);

        $this->assertSame(64, strlen($fingerprint));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $fingerprint);
    }
}
