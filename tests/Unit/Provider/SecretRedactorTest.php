<?php

namespace Tests\Unit\Provider;

use App\AI\Support\SecretRedactor;
use PHPUnit\Framework\TestCase;

final class SecretRedactorTest extends TestCase
{
    public function test_sensitive_headers_are_masked_with_last_four_only(): void
    {
        $redacted = SecretRedactor::headers([
            'Authorization' => 'Bearer sk-abc1234supersecret',
            'Content-Type' => 'application/json',
            'x-api-key' => 'keyvalue-9876543210',
        ]);

        $this->assertSame('[REDACTED:***cret]', $redacted['Authorization']);
        $this->assertSame('[REDACTED:***3210]', $redacted['x-api-key']);
        $this->assertSame('application/json', $redacted['Content-Type']);
        $this->assertStringNotContainsString('supersecret', json_encode($redacted));
    }

    public function test_header_matching_is_case_insensitive(): void
    {
        $redacted = SecretRedactor::headers(['AUTHORIZATION' => 'Bearer tokenvalue-123456']);

        $this->assertSame('[REDACTED:***3456]', $redacted['AUTHORIZATION']);
    }

    public function test_sensitive_query_parameters_are_masked_recursively(): void
    {
        $redacted = SecretRedactor::queryParams([
            'key' => 'supersecretkey-4321',
            'q' => 'cartoon',
            'filter' => [
                'api_key' => 'nestedsecret-1111',
                'sort' => 'date',
            ],
        ]);

        $this->assertSame('[REDACTED:***4321]', $redacted['key']);
        $this->assertSame('cartoon', $redacted['q']);
        $this->assertSame('[REDACTED:***1111]', $redacted['filter']['api_key']);
        $this->assertSame('date', $redacted['filter']['sort']);
    }

    public function test_sensitive_body_keys_are_masked(): void
    {
        $redacted = SecretRedactor::body([
            'model' => 'gpt-fake',
            'metadata' => ['client_secret' => 'topsecret-9999'],
            'prompt' => 'A cartoon robot',
        ]);

        $this->assertSame('[REDACTED:***9999]', $redacted['metadata']['client_secret']);
        $this->assertSame('gpt-fake', $redacted['model']);
        $this->assertSame('A cartoon robot', $redacted['prompt']);
    }

    public function test_message_redacts_key_value_pairs(): void
    {
        $message = SecretRedactor::message('Request failed: api_key=sk-live-abcdef123456 and retrying.');

        $this->assertStringNotContainsString('sk-live-abcdef123456', $message);
        $this->assertStringContainsString('api_key=[REDACTED]', $message);
    }

    public function test_message_redacts_bearer_tokens_keeping_last_four(): void
    {
        $message = SecretRedactor::message('Auth failed for Bearer sk-1234567890abcd.');

        $this->assertStringNotContainsString('sk-1234567890abcd', $message);
        $this->assertStringContainsString('Bearer [REDACTED:***abcd]', $message);
    }

    public function test_message_without_secrets_is_untouched(): void
    {
        $message = 'The provider returned an unexpected HTTP 500 response.';

        $this->assertSame($message, SecretRedactor::message($message));
    }

    public function test_short_values_show_only_asterisks(): void
    {
        $redacted = SecretRedactor::headers(['x-api-key' => 'abc']);

        $this->assertSame('[REDACTED:***]', $redacted['x-api-key']);
    }
}
