<?php

namespace App\Models;

use App\Concerns\HasPublicUuid;
use App\Enums\UserStatus;
use App\Services\System\SystemSettingService;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasPublicUuid, Notifiable, SoftDeletes;

    /**
     * Mass-assignable attributes. System-managed security fields
     * (mfa_secret_encrypted, failed_login_count, locked_until, uuid, ...) are
     * intentionally NOT fillable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'person_id',
        'username',
        'email',
        'password',
        'status',
        'mfa_enabled',
        'must_change_password',
    ];

    /**
     * Never serialize these to JSON/array.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'mfa_secret_encrypted',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'status' => UserStatus::class,
            'mfa_enabled' => 'boolean',
            'must_change_password' => 'boolean',
            'failed_login_count' => 'integer',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
        ];
    }

    /**
     * The real-world person this login account belongs to, if any (STD-002).
     * Nullable: a User may exist without a Person.
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles')
            ->withPivot(['site_id', 'zone_id', 'valid_from', 'valid_until', 'created_at']);
    }

    public function roleAssignments(): HasMany
    {
        return $this->hasMany(UserRole::class);
    }

    public function permissionOverrides(): HasMany
    {
        return $this->hasMany(UserPermission::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(UserSession::class);
    }

    public function loginLogs(): HasMany
    {
        return $this->hasMany(LoginLog::class);
    }

    public function importJobs(): HasMany
    {
        return $this->hasMany(ImportJob::class, 'created_by');
    }

    /**
     * EVT-012 personal notification inbox. Deliberately NOT named
     * `notifications()` — that accessor already belongs to the Notifiable
     * trait's built-in morph relation, pointing at a differently-shaped
     * `notifications` table this app doesn't use.
     */
    public function appNotifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    /**
     * @return HasMany<ReportExport, $this>
     */
    public function reportExports(): HasMany
    {
        return $this->hasMany(ReportExport::class);
    }

    /**
     * When an administrator switches on System Settings -> Security ->
     * security.admin_mfa_enforced (off by default, owner decision
     * 2026-10-14), holders of the roles in auth_security.mfa_required_roles
     * must enrol MFA before using any permission-protected API.
     * Self-service routes (me, MFA setup, password, sessions, logout) stay
     * reachable so they can enrol.
     */
    public function requiresMfaEnrollment(): bool
    {
        if ($this->mfa_enabled) {
            return false;
        }

        if (! (bool) app(SystemSettingService::class)->get('security.admin_mfa_enforced', false)) {
            return false;
        }

        $required = array_filter(array_map('trim', explode(',', (string) config('auth_security.mfa_required_roles', ''))));
        if ($required === []) {
            return false;
        }

        return $this->roles()->whereIn('code', $required)->exists();
    }
}
