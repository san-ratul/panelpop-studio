<?php

namespace Tests\Feature;

use App\AI\Support\ToolRegistry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tool library (AITUBER mission §6B): config-driven tool presets over the
 * shared pipeline, deep-linking into project creation with the preset
 * applied. No tool registers a separate engine.
 */
class ToolLibraryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    public function test_index_requires_authentication(): void
    {
        $this->get('/tools')->assertRedirect(route('login'));
    }

    public function test_index_lists_the_configured_tools(): void
    {
        $response = $this->actingAs($this->user)->get('/tools');

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->component('Tools/Index')
            ->has('tools', count(ToolRegistry::options()))
            ->where('tools.0.slug', fn ($slug) => is_string($slug) && $slug !== ''));
    }

    public function test_project_create_applies_a_tool_preset(): void
    {
        $response = $this->actingAs($this->user)->get('/projects/create?tool=ugc-demo-videos');

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->component('Projects/Create')
            ->where('toolPreset.slug', 'ugc-demo-videos')
            ->where('toolPreset.label', 'UGC Demo Videos')
            ->where('toolPreset.visual_style', 'pixel_art')
            ->where('toolPreset.presentation_mode', 'dialogue'));
    }

    public function test_project_create_without_a_tool_has_no_preset(): void
    {
        $this->actingAs($this->user)->get('/projects/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Projects/Create')->where('toolPreset', null));

        $this->actingAs($this->user)->get('/projects/create?tool=not-a-real-tool')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('toolPreset', null));
    }

    public function test_every_registered_tool_preset_targets_real_pipeline_settings(): void
    {
        $styles = collect(config('catalog.visual_styles'))->pluck('value')->all();
        $modes = ['silent', 'speech_bubbles', 'narrator', 'dialogue'];

        foreach (ToolRegistry::all() as $slug => $tool) {
            $this->assertArrayHasKey('visual_style', $tool, "{$slug} must preset a visual style");
            $this->assertArrayHasKey('presentation_mode', $tool, "{$slug} must preset a presentation mode");
            $this->assertContains($tool['visual_style'], $styles, "{$slug} visual style must exist in config/catalog.php");
            $this->assertContains($tool['presentation_mode'], $modes, "{$slug} presentation mode must be a real mode");
        }
    }
}
