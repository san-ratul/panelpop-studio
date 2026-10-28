<?php

namespace Tests\Unit\Provider;

use App\AI\Support\SecretRedactor;
use PHPUnit\Framework\TestCase;

class SecretRedactorKeyShapeTest extends TestCase
{
    public function test_openai_style_key_in_error_message_is_redacted(): void
    {
        $message = 'Incorrect API key provided: sk-proj-abcdef1234567890abcd. You can find your key at https://platform.openai.com.';

        $redacted = SecretRedactor::message($message);

        $this->assertStringNotContainsString('sk-proj-abcdef1234567890abcd', $redacted);
        $this->assertStringContainsString(SecretRedactor::MASK, $redacted);
        $this->assertStringContainsString('abcd', $redacted);
    }

    public function test_bearer_scheme_still_redacts_without_double_masking(): void
    {
        $redacted = SecretRedactor::message('Auth failed for Bearer sk-superversecret-4242 while calling the provider.');

        $this->assertStringNotContainsString('sk-superversecret-4242', $redacted);
        $this->assertSame(1, substr_count($redacted, SecretRedactor::MASK));
    }

    public function test_plain_words_are_not_masked(): void
    {
        $message = 'The scene plan mentions sky and skate park research.';

        $this->assertSame($message, SecretRedactor::message($message));
    }
}
