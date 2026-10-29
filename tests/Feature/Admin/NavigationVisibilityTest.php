<?php

namespace Tests\Feature\Admin;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sidebar visibility contract: the shared Inertia props drive what the
 * layout can offer (admin section only for is_admin users, project
 * workspace links only when a project is in scope). Hiding menu entries is
 * never authorization — every admin endpoint is gated server-side (D-030)
 * and covered by the ACL tests in this suite.
 */
class NavigationVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_flag_is_shared_to_drive_sidebar_visibility(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(
                fn ($page) => $page
                    ->has('auth.user', fn ($user) => $user
                        ->where('is_admin', true)
                        ->has('id')
                        ->has('name')
                        ->has('email')
                        ->etc()),
            );
    }

    public function test_ordinary_users_do_not_receive_the_admin_flag(): void
    {
        $member = User::factory()->create(['is_admin' => false]);

        $this->actingAs($member)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(
                fn ($page) => $page->where('auth.user.is_admin', false),
            );
    }

    public function test_admin_route_entries_exist_in_the_shared_manifest(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        // Route names contain dots, so the manifest is checked with literal
        // key lookups rather than nested traversal.
        $expectedRoutes = [
            'admin.dashboard',
            'admin.costs.index',
            'admin.costs.calls',
            'admin.pricing.simulation',
            'dashboard',
            'story.edit',
            'storyboard.show',
            'characters.index',
            'audio.show',
            'render.show',
            'provider-settings.index',
            'password.edit',
            'logout',
        ];

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(
                fn ($page) => $page->where('routes', function ($routes) use ($expectedRoutes): bool {
                    foreach ($expectedRoutes as $name) {
                        if (! $routes->offsetExists($name)) {
                            return false;
                        }
                    }

                    return true;
                }),
            );
    }

    public function test_project_pages_share_project_context_for_the_workspace_sidebar(): void
    {
        $member = User::factory()->create();
        $project = Project::factory()->for($member)->create(['title' => 'Sidebar probe']);

        $this->actingAs($member)
            ->get(route('storyboard.show', $project))
            ->assertOk()
            ->assertInertia(
                fn ($page) => $page
                    ->has('project', fn ($shared) => $shared
                        ->where('id', $project->id)
                        ->where('title', 'Sidebar probe')
                        ->etc()),
            );
    }

    public function test_admin_sidebar_entries_cannot_substitute_for_authorization(): void
    {
        // An ordinary user hitting the admin URLs directly is still denied —
        // the server gate, not the hidden menu entry, is the boundary.
        $member = User::factory()->create();

        $this->actingAs($member)
            ->get(route('admin.dashboard'))
            ->assertForbidden();

        $this->actingAs($member)
            ->get(route('admin.costs.calls'))
            ->assertForbidden();

        $this->actingAs($member)
            ->get(route('admin.pricing.simulation'))
            ->assertForbidden();
    }
}
