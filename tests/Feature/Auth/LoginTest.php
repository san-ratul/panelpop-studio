<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as InertiaAssert;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $response->assertInertia(
            fn (InertiaAssert $page) => $page->component('Auth/Login')
        );
    }

    public function test_login_page_shares_the_frontend_route_manifest(): void
    {
        // Regression: the login form posts through the shared route() helper
        // (resources/js/route.js); without the shared manifest it throws
        // "ReferenceError: route is not defined" on submit.
        $this->get('/login')->assertInertia(
            fn (InertiaAssert $page) => $page
                ->component('Auth/Login')
                ->has('routes')
                ->where('routes', function (mixed $routes): bool {
                    $manifest = $routes instanceof Collection
                        ? $routes->all()
                        : (array) $routes;

                    return ($manifest['login'] ?? null) === 'login'
                        && ($manifest['dashboard'] ?? null) === 'dashboard'
                        && ($manifest['story.edit'] ?? null) === 'projects/{project}/story/edit'
                        && ($manifest['scenes.update'] ?? null) === 'projects/{project}/scenes/{scene}'
                        && ($manifest['provider-settings.update'] ?? null) === 'settings/providers/{providerSetting}';
                })
        );
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_seeded_user_can_authenticate(): void
    {
        $this->seed();

        $response = $this->post('/login', [
            'email' => 'test@example.com',
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect(route('login', absolute: false));
    }

    public function test_registration_route_is_not_available(): void
    {
        $response = $this->get('/register');

        $response->assertNotFound();
    }
}
