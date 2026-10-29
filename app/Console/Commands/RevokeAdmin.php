<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Revokes administrator privileges from a user (companion to
 * `admin:create --promote`). Idempotent: revoking a non-admin is a no-op.
 * Revoking the LAST remaining administrator requires an extra confirmation
 * so the operator cannot silently lock themselves out of the admin area.
 */
final class RevokeAdmin extends Command
{
    protected $signature = 'admin:revoke
        {--email= : Email address of the administrator to demote (prompted when omitted)}';

    protected $description = 'Revoke administrator privileges from a user (the user account itself is kept)';

    public function handle(): int
    {
        $email = $this->option('email') !== null && $this->option('email') !== ''
            ? (string) $this->option('email')
            : $this->ask('Email address of the administrator to demote');

        $validator = Validator::make(
            ['email' => $email],
            ['email' => ['required', 'string', 'email:rfc', 'max:255']],
        );

        if ($validator->fails()) {
            $this->error('A valid email address is required.');

            return self::FAILURE;
        }

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error(sprintf('No user exists with email "%s".', $email));

            return self::FAILURE;
        }

        if (! $user->is_admin) {
            $this->info(sprintf('User #%d (%s) is not an administrator — nothing to do.', $user->id, $user->email));

            return self::SUCCESS;
        }

        $adminCount = User::query()->where('is_admin', true)->count();
        $lastWarning = $adminCount <= 1
            ? ' This is the LAST administrator — the admin area becomes unreachable until another admin is created.'
            : '';

        $confirmed = $this->confirm(sprintf(
            'Revoke ADMIN privileges from user #%d (%s)? The account itself is kept.%s',
            $user->id,
            $user->email,
            $lastWarning,
        ));

        if (! $confirmed) {
            $this->warn('Cancelled — no privileges changed.');

            return self::FAILURE;
        }

        $user->forceFill(['is_admin' => false])->save();

        $this->info(sprintf('User #%d (%s) is no longer an administrator.', $user->id, $user->email));

        return self::SUCCESS;
    }
}
