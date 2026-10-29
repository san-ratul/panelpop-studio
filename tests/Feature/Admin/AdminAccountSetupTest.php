<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Phase 8 admin account setup: the only supported way to create the initial
 * administrator is `admin:create` (password prompted with hidden input —
 * there is no default credential, no public admin registration, and no
 * silent promotion of existing users).
 */
class AdminAccountSetupTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD_PROMPT = 'Password (input hidden, min 8 characters)';

    private const CONFIRM_PROMPT = 'Confirm password';

    public function test_creates_a_new_administrator_with_a_prompted_password(): void
    {
        $this->artisan('admin:create', [
            '--email' => 'admin@example.test',
            '--name' => 'Platform Admin',
        ])->expectsQuestion(self::PASSWORD_PROMPT, 'sup3r-secret-pass')
            ->expectsQuestion(self::CONFIRM_PROMPT, 'sup3r-secret-pass')
            ->assertExitCode(0);

        $admin = User::query()->where('email', 'admin@example.test')->firstOrFail();

        $this->assertTrue($admin->is_admin);
        $this->assertSame('Platform Admin', $admin->name);
        // Only the bcrypt digest is stored — never the plaintext.
        $this->assertNotSame('sup3r-secret-pass', $admin->password);
        $this->assertTrue(Hash::check('sup3r-secret-pass', $admin->password));
        $this->assertSame(1, User::query()->where('is_admin', true)->count());
    }

    public function test_created_admin_can_log_in_through_the_ordinary_login(): void
    {
        $this->artisan('admin:create', [
            '--email' => 'admin@example.test',
            '--name' => 'Platform Admin',
        ])->expectsQuestion(self::PASSWORD_PROMPT, 'sup3r-secret-pass')
            ->expectsQuestion(self::CONFIRM_PROMPT, 'sup3r-secret-pass')
            ->assertExitCode(0);

        $admin = User::query()->where('email', 'admin@example.test')->firstOrFail();

        $this->assertTrue(auth()->validate([
            'email' => $admin->email,
            'password' => 'sup3r-secret-pass',
        ]));
    }

    public function test_rejects_short_password_and_reprompts(): void
    {
        $this->artisan('admin:create', ['--email' => 'admin@example.test', '--name' => 'Platform Admin'])
            ->expectsQuestion(self::PASSWORD_PROMPT, 'short')
            ->expectsQuestion(self::PASSWORD_PROMPT, 'sup3r-secret-pass')
            ->expectsQuestion(self::CONFIRM_PROMPT, 'sup3r-secret-pass')
            ->assertExitCode(0);

        $this->assertTrue(User::query()->where('email', 'admin@example.test')->sole()->is_admin);
    }

    public function test_rejects_mismatched_confirmation_and_reprompts(): void
    {
        $this->artisan('admin:create', ['--email' => 'admin@example.test', '--name' => 'Platform Admin'])
            ->expectsQuestion(self::PASSWORD_PROMPT, 'sup3r-secret-pass')
            ->expectsQuestion(self::CONFIRM_PROMPT, 'different-pass')
            ->expectsQuestion(self::PASSWORD_PROMPT, 'sup3r-secret-pass')
            ->expectsQuestion(self::CONFIRM_PROMPT, 'sup3r-secret-pass')
            ->assertExitCode(0);

        $this->assertTrue(Hash::check('sup3r-secret-pass', User::query()->where('email', 'admin@example.test')->sole()->password));
    }

    public function test_gives_up_after_three_invalid_password_attempts(): void
    {
        $this->artisan('admin:create', ['--email' => 'admin@example.test', '--name' => 'Platform Admin'])
            ->expectsQuestion(self::PASSWORD_PROMPT, 'short')
            ->expectsQuestion(self::PASSWORD_PROMPT, 'short')
            ->expectsQuestion(self::PASSWORD_PROMPT, 'short')
            ->assertExitCode(1);

        $this->assertDatabaseMissing('users', ['email' => 'admin@example.test']);
    }

    public function test_refuses_to_touch_an_existing_user_without_promote(): void
    {
        $member = User::factory()->create(['email' => 'member@example.test']);

        $this->artisan('admin:create', ['--email' => 'member@example.test'])
            ->assertExitCode(1);

        $this->assertFalse($member->fresh()->is_admin);
    }

    public function test_promotes_an_existing_user_only_after_explicit_flag_and_confirmation(): void
    {
        $member = User::factory()->create(['email' => 'member@example.test']);

        // Declined confirmation changes nothing.
        $this->artisan('admin:create', [
            '--email' => 'member@example.test',
            '--promote' => true,
        ])->expectsConfirmation('Grant ADMIN privileges to the existing user #'.$member->id.' ('.$member->email.')? This exposes platform-wide cost analytics.', 'no')
            ->assertExitCode(1);

        $this->assertFalse($member->fresh()->is_admin);

        // Accepted confirmation promotes.
        $this->artisan('admin:create', [
            '--email' => 'member@example.test',
            '--promote' => true,
        ])->expectsConfirmation('Grant ADMIN privileges to the existing user #'.$member->id.' ('.$member->email.')? This exposes platform-wide cost analytics.', 'yes')
            ->assertExitCode(0);

        $this->assertTrue($member->fresh()->is_admin);
    }

    public function test_promoting_an_existing_admin_is_an_idempotent_noop(): void
    {
        $admin = User::factory()->create(['email' => 'admin@example.test', 'is_admin' => true]);

        $this->artisan('admin:create', ['--email' => 'admin@example.test'])
            ->expectsOutputToContain('already an administrator')
            ->assertExitCode(0);

        $this->assertTrue($admin->fresh()->is_admin);
    }

    public function test_promote_flag_with_unknown_email_fails_cleanly(): void
    {
        $this->artisan('admin:create', [
            '--email' => 'ghost@example.test',
            '--promote' => true,
        ])->assertExitCode(1);

        $this->assertDatabaseMissing('users', ['email' => 'ghost@example.test']);
    }

    public function test_rejects_an_invalid_email(): void
    {
        $this->artisan('admin:create', ['--email' => 'not-an-email'])
            ->assertExitCode(1);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_revoke_demotes_an_admin_after_confirmation_and_keeps_the_account(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->artisan('admin:revoke', ['--email' => $admin->email])
            ->expectsConfirmation('Revoke ADMIN privileges from user #'.$admin->id.' ('.$admin->email.')? The account itself is kept. This is the LAST administrator — the admin area becomes unreachable until another admin is created.', 'yes')
            ->assertExitCode(0);

        $this->assertFalse($admin->fresh()->is_admin);
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_revoke_is_a_noop_for_non_admins(): void
    {
        $member = User::factory()->create();

        $this->artisan('admin:revoke', ['--email' => $member->email])
            ->expectsOutputToContain('not an administrator')
            ->assertExitCode(0);

        $this->assertFalse($member->fresh()->is_admin);
    }

    public function test_revoke_requires_confirmation(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->artisan('admin:revoke', ['--email' => $admin->email])
            ->expectsConfirmation('Revoke ADMIN privileges from user #'.$admin->id.' ('.$admin->email.')? The account itself is kept. This is the LAST administrator — the admin area becomes unreachable until another admin is created.', 'no')
            ->assertExitCode(1);

        $this->assertTrue($admin->fresh()->is_admin);
    }
}
