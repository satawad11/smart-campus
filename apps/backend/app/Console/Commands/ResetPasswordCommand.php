<?php

namespace App\Console\Commands;

use App\Enums\AuditAction;
use App\Enums\UserStatus;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Auth\UserManagementService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Host-level account recovery: reset a forgotten password from the server
 * shell when no administrator can log in to use the user management UI.
 *
 * Shell access to the backend host is the authorisation. Reuses
 * UserManagementService::resetPassword (policy check, forced change at next
 * login, all sessions revoked, USER_PASSWORD_RESET audit with no actor) and
 * clears the login lockout. The one-time password is printed once and never
 * written to the audit trail. --reset-mfa also removes the TOTP secret for an
 * administrator who lost the authenticator device (MFA_DISABLED audit,
 * source cli); they enrol again after logging in.
 */
class ResetPasswordCommand extends Command
{
    protected $signature = 'access:reset-password
                            {username : Login name of the account to recover}
                            {--reset-mfa : Also remove the TOTP secret (lost authenticator device)}
                            {--yes : Skip the confirmation prompt}';

    protected $description = 'Reset a user password from the server shell; prints a one-time password';

    public function handle(UserManagementService $users, AuditService $audit): int
    {
        $username = (string) $this->argument('username');
        $user = User::where('username', $username)->first();
        if ($user === null) {
            $this->error("User {$username} not found.");

            return self::FAILURE;
        }
        if ($user->status !== UserStatus::ACTIVE) {
            $this->error("User {$username} is not active; reactivate it in the user management UI first.");

            return self::FAILURE;
        }

        $resetMfa = (bool) $this->option('reset-mfa');
        $question = $resetMfa
            ? "Reset the password AND two-factor authentication of {$username} and sign out all of its sessions?"
            : "Reset the password of {$username} and sign out all of its sessions?";
        if (! $this->option('yes') && ! $this->confirm($question)) {
            $this->line('Cancelled.');

            return self::FAILURE;
        }

        $password = Str::password(24);
        $hadMfa = (bool) $user->mfa_enabled;

        DB::transaction(function () use ($users, $audit, $user, $password, $resetMfa, $hadMfa) {
            $users->resetPassword($user, $password);
            User::whereKey($user->id)->update(['failed_login_count' => 0, 'locked_until' => null]);

            if ($resetMfa && $hadMfa) {
                User::whereKey($user->id)->update(['mfa_enabled' => false, 'mfa_secret_encrypted' => null]);
                $audit->record(
                    AuditAction::MFA_DISABLED,
                    null,
                    'USER',
                    $user->uuid,
                    oldValues: ['mfa_enabled' => true],
                    newValues: ['mfa_enabled' => false],
                    metadata: ['source' => 'cli', 'reason' => 'account_recovery'],
                );
            }
        });

        $this->info("Password of {$username} reset; all sessions revoked and the login lockout cleared.");
        if ($resetMfa) {
            $this->info($hadMfa
                ? 'Two-factor authentication removed; enrol again after logging in.'
                : 'Two-factor authentication was not enabled; nothing to remove.');
        }
        $this->warn('One-time password (shown once, must be changed at next login):');
        $this->line($password);

        return self::SUCCESS;
    }
}
