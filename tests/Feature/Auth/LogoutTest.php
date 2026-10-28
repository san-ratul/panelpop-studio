<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_log_out(): void
    {
        $response = $this->post('/logout');

        $response->assertRedirect(route('login', absolute: false));
        $this->assertGuest();
    }

    public function test_authenticated_users_are_logged_out_and_their_session_is_invalidated(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $response->assertRedirect(route('login', absolute: false));
        $this->assertGuest();

        // The invalidated session must no longer authenticate any request.
        $this->get(route('dashboard', absolute: false))
            ->assertRedirect(route('login', absolute: false));
    }
}
