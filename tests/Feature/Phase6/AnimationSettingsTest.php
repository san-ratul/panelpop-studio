<?php

namespace Tests\Feature\Phase6;

use App\Enums\ProviderCapability;
use App\Models\Project;
use App\Models\User;
use App\Models\Video;
use App\Services\Render\RenderPlanBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsRenderFixtures;
use Tests\TestCase;

/**
 * Layered scene animation settings (VIDEO_ANIMATION.md): effective value
 * resolution (video override -> project default -> config default) and the
 * render plan wiring.
 */
class AnimationSettingsTest extends TestCase
{
    use BuildsRenderFixtures;
    use RefreshDatabase;

    public function test_animation_defaults_to_enabled(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $video = $this->makeVideo($project);

        $this->assertTrue($video->effectiveAnimationEnabled());
    }

    public function test_config_master_switch_can_disable_animation(): void
    {
        config(['ffmpeg.animation.enabled' => false]);

        $user = User::factory()->create();
        $video = $this->makeVideo(Project::factory()->for($user)->create());

        $this->assertFalse($video->effectiveAnimationEnabled());
    }

    public function test_project_setting_disables_animation_for_its_videos(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['settings' => ['animation' => ['enabled' => false]]]);
        $video = $this->makeVideo($project);

        $this->assertFalse($video->effectiveAnimationEnabled());
    }

    public function test_video_override_wins_over_the_project_setting(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['settings' => ['animation' => ['enabled' => false]]]);
        $video = $this->makeVideo($project, ['settings' => ['animation' => ['enabled' => true]]]);

        $other = $this->makeVideo($project);

        $this->assertTrue($video->effectiveAnimationEnabled());
        $this->assertFalse($other->effectiveAnimationEnabled());
    }

    public function test_render_plan_carries_the_effective_animation_setting(): void
    {
        $user = User::factory()->create();
        $video = $this->createRenderableProject($user);
        $video->update(['settings' => ['animation' => ['enabled' => false]]]);

        $plan = app(RenderPlanBuilder::class)->build($video->fresh());

        $this->assertFalse($plan->animationEnabled);

        $video->fresh()->update(['settings' => ['animation' => ['enabled' => true]]]);
        $plan = app(RenderPlanBuilder::class)->build($video->fresh());

        $this->assertTrue($plan->animationEnabled);
    }

    public function test_video_capability_exists_for_the_future_image_to_video_provider(): void
    {
        // The abstraction (App\AI\Contracts\VideoProvider) is registered but
        // no provider implements it yet — resolution fails cleanly instead of
        // silently pretending animation-by-provider exists.
        $this->assertSame('video', ProviderCapability::Video->value);
        $this->assertSame('Image-to-video', ProviderCapability::Video->label());
    }
}
