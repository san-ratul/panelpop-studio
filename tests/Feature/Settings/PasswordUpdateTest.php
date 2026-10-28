<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as InertiaAssert;
use Tests\TestCase;

class PasswordUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('password.edit', absolute: false))
            ->assertRedirect(route('login', absolute: false));
    }

    public function test_password_screen_can_be_rendered(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('password.edit', absolute: false))
            ->assertOk()
            ->assertInertia(
                fn (InertiaAssert $page) => $page->component('Settings/Password')
            );
    }

    public function test_current_password_is_validated(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('current-password'),
        ]);

        $this->actingAs($user)
            ->from(route('password.edit', absolute: false))
            ->put(route('password.update', absolute: false), [
                'current_password' => 'wrong-password',
                'password' => 'new-secure-password',
                'password_confirmation' => 'new-secure-password',
            ])
            ->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('current-password', $user->fresh()->password));
    }

    public function test_new_password_must_be_confirmed(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('current-password'),
        ]);

        $this->actingAs($user)
            ->from(route('password.edit', absolute: false))
            ->put(route('password.update', absolute: false), [
                'current_password' => 'current-password',
                'password' => 'new-secure-password',
                'password_confirmation' => 'different-password',
            ])
            ->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('current-password', $user->fresh()->password));
    }

    public function test_new_password_must_meet_the_minimum_length(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('current-password'),
        ]);

        $this->actingAs($user)
            ->from(route('password.edit', absolute: false))
            ->put(route('password.update', absolute: false), [
                'current_password' => 'current-password',
                'password' => 'short',
                'password_confirmation' => 'short',
            ])
            ->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('current-password', $user->fresh()->password));
    }

    public function test_password_can_be_updated(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('old-password'),
        ]);
        $oldHash = $user->password;

        $response = $this->actingAs($user)
            ->from(route('password.edit', absolute: false))
            ->put(route('password.update', absolute: false), [
                'current_password' => 'old-password',
                'password' => 'new-secure-password',
                'password_confirmation' => 'new-secure-password',
            ]);

        $response->assertRedirect(route('password.edit', absolute: false));
        $response->assertSessionHas('status', 'password-updated');

        $fresh = $user->fresh();

        $this->assertNotSame($oldHash, $fresh->password);
        $this->assertTrue(Hash::check('new-secure-password', $fresh->password));
        $this->assertFalse(Hash::check('old-password', $fresh->password));

        // The session password hash stays in sync so the current session
        // remains authenticated after the rotation.
        $this->assertSame($fresh->password, $this->app['session.store']->get('password_hash_web'));

        $this->get(route('password.edit', absolute: false))->assertOk();
    }

    public function test_plain_passwords_are_never_exposed_in_the_response(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('old-password'),
        ]);

        $response = $this->actingAs($user)
            ->from(route('password.edit', absolute: false))
            ->put(route('password.update', absolute: false), [
                'current_password' => 'old-password',
                'password' => 'new-secure-password',
                'password_confirmation' => 'new-secure-password',
            ]);

        $content = $response->getContent();

        $this->assertStringNotContainsString('old-password', $content);
        $this->assertStringNotContainsString('new-secure-password', $content);
    }
}
