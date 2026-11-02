<?php

namespace Tests\Feature\Admin;

use App\Models\ModelPricing;
use App\Models\User;
use App\Services\Cost\PricingEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Admin model-pricing management (AITUBER mission §7/§8): authorization,
 * CRUD with validation, and the pricing engine honoring admin edits.
 */
class AdminModelPricingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->member = User::factory()->create();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'provider' => 'replicate',
            'model' => 'wan-video/wan-2.5-i2v-fast',
            'capability' => 'video',
            'pricing_unit' => 'per_second',
            'provider_cost_per_unit' => '0.080000',
            'currency' => 'USD',
            'enabled' => true,
        ], $overrides);
    }

    public function test_index_requires_admin(): void
    {
        $this->get('/admin/models')->assertRedirect(route('login'));

        $this->actingAs($this->member)->get('/admin/models')->assertForbidden();

        $this->actingAs($this->admin)->get('/admin/models')->assertOk();
    }

    public function test_store_creates_a_pricing_row(): void
    {
        $response = $this->actingAs($this->admin)->post('/admin/models', $this->payload());

        $response->assertRedirect();
        $response->assertSessionHas('flashSuccess');

        $row = ModelPricing::query()->firstOrFail();
        $this->assertSame('replicate', $row->provider);
        $this->assertSame('wan-video/wan-2.5-i2v-fast', $row->model);
        $this->assertSame('video', $row->capability);
        $this->assertSame('0.080000', (string) $row->provider_cost_per_unit);
        $this->assertTrue($row->enabled);
    }

    public function test_store_rejects_duplicate_provider_model_capability(): void
    {
        $this->actingAs($this->admin)->post('/admin/models', $this->payload());
        $this->actingAs($this->admin)->post('/admin/models', $this->payload());

        $response = $this->actingAs($this->admin)->post('/admin/models', $this->payload());

        $response->assertSessionHasErrors(['model']);
        $this->assertSame(1, ModelPricing::query()->count());
    }

    public function test_store_validates_inputs(): void
    {
        $response = $this->actingAs($this->admin)->post('/admin/models', $this->payload([
            'provider_cost_per_unit' => '0',
            'margin_override_bps' => 9900,
            'pricing_unit' => 'per_fortnight',
        ]));

        $response->assertSessionHasErrors(['provider_cost_per_unit', 'margin_override_bps', 'pricing_unit']);
        $this->assertSame(0, ModelPricing::query()->count());
    }

    public function test_update_changes_the_row(): void
    {
        $row = ModelPricing::query()->create($this->payload());

        $response = $this->actingAs($this->admin)->patch("/admin/models/{$row->id}", $this->payload([
            'provider_cost_per_unit' => '0.050000',
            'enabled' => false,
        ]));

        $response->assertRedirect();

        $row->refresh();
        $this->assertSame('0.050000', (string) $row->provider_cost_per_unit);
        $this->assertFalse($row->enabled);
    }

    public function test_destroy_removes_the_row(): void
    {
        $row = ModelPricing::query()->create($this->payload());

        $response = $this->actingAs($this->admin)->delete("/admin/models/{$row->id}");

        $response->assertRedirect();
        $this->assertSame(0, ModelPricing::query()->count());
    }

    public function test_member_cannot_modify_pricing(): void
    {
        $row = ModelPricing::query()->create($this->payload());

        $this->actingAs($this->member)->post('/admin/models', $this->payload())->assertForbidden();
        $this->actingAs($this->member)->patch("/admin/models/{$row->id}", $this->payload())->assertForbidden();
        $this->actingAs($this->member)->delete("/admin/models/{$row->id}")->assertForbidden();

        $this->assertSame(1, ModelPricing::query()->count());
    }

    public function test_pricing_engine_uses_the_admin_row(): void
    {
        ModelPricing::query()->create($this->payload([
            'provider_cost_per_unit' => '0.080000',
            'margin_override_bps' => 5000,
        ]));

        $estimate = app(PricingEngine::class)
            ->estimateCredits('replicate', 'wan-video/wan-2.5-i2v-fast', 10);

        $this->assertSame('db', $estimate->source);
        $this->assertSame('0.800000', $estimate->providerCostUsd);
        $this->assertSame(5000, $estimate->marginBps);
        $this->assertSame(160, $estimate->credits);
    }
}
