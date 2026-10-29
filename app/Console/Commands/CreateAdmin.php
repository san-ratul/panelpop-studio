<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Establishes the initial administrator account (Phase 8). The admin logs in
 * through the ordinary session login — there is no separate admin
 * registration surface and no default credential anywhere.
 *
 * Safety properties:
 *  - The password is always prompted with hidden input (never an option, so
 *    it can never land in shell history, logs, or process listings).
 *  - An existing ordinary user is only promoted with the explicit `--promote`
 *    flag AND an interactive confirmation. The first registered user is never
 *    promoted automatically.
 *  - Re-running for an existing admin is an idempotent no-op.
 *
 * Verify with: php artisan admin:show (or tinker: User::where('is_admin', true)->pluck('email')).
 * Revoke with: php artisan admin:revoke --email=...
 */
final class CreateAdmin extends Command
{
    protected $signature = 'admin:create
        {--email= : Email address of the administrator (prompted when omitted)}
        {--name= : Display name (prompted when omitted)}
        {--promote : Allow promoting an EXISTING user after explicit confirmation}';

    protected $description = 'Create an administrator account or promote an existing user to admin (password prompted securely, never stored in plaintext)';

    public function handle(): int
    {
        $email = $this->option('email') !== null && $this->option('email') !== ''
            ? (string) $this->option('email')
            : $this->ask('Admin email address');

        $emailError = $this->validateEmail($email);

        if ($emailError !== null) {
            $this->error($emailError);

            return self::FAILURE;
        }

        $user = User::query()->where('email', $email)->first();

        if ($user !== null) {
            return $this->promoteExisting($user);
        }

        if ((bool) $this->option('promote')) {
            $this->error(sprintf('No user exists with email "%s" — --promote only applies to existing users.', $email));

            return self::FAILURE;
        }

        return $this->createAdmin($email);
    }

    private function promoteExisting(User $user): int
    {
        if ($user->is_admin) {
            $this->info(sprintf('User #%d (%s) is already an administrator — nothing to do.', $user->id, $user->email));

            return self::SUCCESS;
        }

        if (! (bool) $this->option('promote')) {
            $this->error(sprintf(
                'User #%d (%s) already exists. Pass --promote to grant admin rights after confirming — existing users are never promoted silently.',
                $user->id,
                $user->email,
            ));

            return self::FAILURE;
        }

        $confirmed = $this->confirm(sprintf(
            'Grant ADMIN privileges to the existing user #%d (%s)? This exposes platform-wide cost analytics.',
            $user->id,
            $user->email,
        ));

        if (! $confirmed) {
            $this->warn('Cancelled — no privileges changed.');

            return self::FAILURE;
        }

        $user->forceFill(['is_admin' => true])->save();

        $this->info(sprintf('User #%d (%s) is now an administrator.', $user->id, $user->email));

        return self::SUCCESS;
    }

    private function createAdmin(string $email): int
    {
        $name = $this->option('name') !== null && $this->option('name') !== ''
            ? (string) $this->option('name')
            : $this->ask('Display name', str($email)->before('@')->toString());

        $password = $this->promptPassword();

        if ($password === null) {
            $this->error('No valid password entered after 3 attempts — no account was created.');

            return self::FAILURE;
        }

        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            // Plain value here; the model's `hashed` cast stores only the
            // bcrypt digest. The plaintext password is never printed, logged,
            // or persisted.
            'password' => $password,
            'is_admin' => true,
        ]);

        $this->info(sprintf('Administrator #%d created for %s.', $user->id, $user->email));
        $this->line('Log in through the ordinary login page (/login) with this email and the password you just entered.');

        return self::SUCCESS;
    }

    /**
     * Prompts for a password with hidden input until it satisfies the same
     * policy as the account password form (minimum 8 characters, confirmed).
     */
    private function promptPassword(): ?string
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $password = $this->secret('Password (input hidden, min 8 characters)');

            if ($password === null || $password === '') {
                $this->error('A password is required.');

                continue;
            }

            if (mb_strlen($password) < 8) {
                $this->error('The password must be at least 8 characters.');

                continue;
            }

            $confirmation = $this->secret('Confirm password');

            if ($password !== $confirmation) {
                $this->error('The passwords did not match — try again.');

                continue;
            }

            return $password;
        }

        return null;
    }

    private function validateEmail(?string $email): ?string
    {
        $validator = Validator::make(
            ['email' => $email],
            ['email' => ['required', 'string', 'email:rfc', 'max:255']],
        );

        return $validator->fails()
            ? 'A valid email address is required'.($email === null || $email === '' ? '.' : sprintf(' (got "%s").', $email))
            : null;
    }
}
