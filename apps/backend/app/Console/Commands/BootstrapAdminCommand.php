<?php

namespace App\Console\Commands;

use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Services\Audit\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Create the first SUPER_ADMIN on a fresh installation (V1 audit A-01).
 *
 * No API or seeder can create a privileged account in production
 * (InitialAdminUserSeeder is development-only), so without this command a
 * fresh pilot host has no administrator. Refuses to run once any active
 * SUPER_ADMIN exists. The generated one-time password is printed once and
 * must be changed at first login.
 */
class BootstrapAdminCommand extends Command
{
    protected $signature = 'access:bootstrap-admin
                            {username : Login name of the first administrator}
                            {--email= : Optional e-mail address}';

    protected $description = 'Create the first SUPER_ADMIN (only when none exists); prints a one-time password';

    public function handle(AuditService $audit): int
    {
        $role = Role::where('code', 'SUPER_ADMIN')->first();
        if ($role === null) {
            $this->error('Role SUPER_ADMIN not found: run the approved seed first (db:seed --force).');

            return self::FAILURE;
        }

        $existing = UserRole::query()
            ->where('role_id', $role->id)
            ->whereHas('user', fn ($q) => $q->where('status', UserStatus::ACTIVE))
            ->exists();
        if ($existing) {
            $this->error('An active SUPER_ADMIN already exists; use access:assign-role or the user management UI.');

            return self::FAILURE;
        }

        $username = (string) $this->argument('username');
        if (User::where('username', $username)->exists()) {
            $this->error("User {$username} already exists.");

            return self::FAILURE;
        }

        // 24 random characters from the alphanumeric set plus symbols to satisfy the password policy
        $password = Str::password(24);

        $user = DB::transaction(function () use ($username, $password, $role, $audit) {
            $user = User::create([
                'username' => $username,
                'email' => $this->option('email') ?: null,
                'password' => $password,
                'status' => UserStatus::ACTIVE,
                'mfa_enabled' => false,
                'must_change_password' => true,
            ]);

            UserRole::create(['user_id' => $user->id, 'role_id' => $role->id, 'created_at' => now()]);

            $audit->record(
                action: 'access.bootstrap_admin',
                actor: null,
                subjectType: 'user',
                subjectUuid: $user->uuid,
                oldValues: null,
                newValues: ['username' => $username, 'role' => 'SUPER_ADMIN', 'scope' => 'GLOBAL'],
                metadata: ['source' => 'cli'],
                module: 'auth_rbac'
            );

            return $user;
        });

        $this->info("SUPER_ADMIN {$user->username} created (uuid {$user->uuid}).");
        $this->warn('One-time password (shown once, change at first login, then enable MFA):');
        $this->line($password);

        return self::SUCCESS;
    }
}
