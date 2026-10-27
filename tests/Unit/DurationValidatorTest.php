<?php

namespace Tests\Unit;

use App\Exceptions\DomainValidationException;
use App\Services\DurationValidator;
use Tests\TestCase;

class DurationValidatorTest extends TestCase
{
    private DurationValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new DurationValidator;
    }

    public function test_accepts_target_sum_within_tolerance(): void
    {
        $this->validator->assertSceneDurationsComplete([10.0, 20.0, 30.0], 60);

        $this->expectNotToPerformAssertions();
    }

    public function test_accepts_sum_at_exact_tolerance_edges(): void
    {
        $this->validator->assertSceneDurationsComplete([58.0], 60);
        $this->validator->assertSceneDurationsComplete([62.0], 60);

        $this->expectNotToPerformAssertions();
    }

    public function test_rejects_sum_just_outside_tolerance(): void
    {
        $this->expectException(DomainValidationException::class);
        $this->validator->assertSceneDurationsComplete([57.99], 60);
    }

    public function test_rejects_sum_above_tolerance(): void
    {
        try {
            $this->validator->assertSceneDurationsComplete([62.01], 60);
            $this->fail('Expected DomainValidationException.');
        } catch (DomainValidationException $exception) {
            $this->assertArrayHasKey('duration_sum', $exception->errors);
            $this->assertStringContainsString('62.01', $exception->errors['duration_sum']);
            $this->assertStringContainsString('58.00', $exception->errors['duration_sum']);
            $this->assertStringContainsString('62.00', $exception->errors['duration_sum']);
        }
    }

    public function test_empty_scene_list_is_rejected(): void
    {
        try {
            $this->validator->assertSceneDurationsComplete([], 60);
            $this->fail('Expected DomainValidationException.');
        } catch (DomainValidationException $exception) {
            $this->assertStringContainsString('no scenes', $exception->errors['duration_sum']);
        }
    }

    public function test_rejects_target_below_minimum(): void
    {
        try {
            $this->validator->assertSceneDurationsComplete([20.0], 20);
            $this->fail('Expected DomainValidationException.');
        } catch (DomainValidationException $exception) {
            $this->assertArrayHasKey('target_duration_seconds', $exception->errors);
            $this->assertStringContainsString('between 30 and 90', $exception->errors['target_duration_seconds']);
        }
    }

    public function test_rejects_target_above_maximum(): void
    {
        try {
            $this->validator->assertSceneDurationsComplete([120.0], 120);
            $this->fail('Expected DomainValidationException.');
        } catch (DomainValidationException $exception) {
            $this->assertArrayHasKey('target_duration_seconds', $exception->errors);
        }
    }

    public function test_boundary_targets_are_accepted(): void
    {
        $this->validator->assertValidTarget(30);
        $this->validator->assertValidTarget(90);

        $this->expectNotToPerformAssertions();
    }
}
