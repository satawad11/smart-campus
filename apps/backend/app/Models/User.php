<?php

namespace App\Models;

use App\Concerns\HasPublicUuid;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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

    // person_id remains a scalar nullable field. The Person Eloquent
    // relationship is deferred until Module 02 (persons table/model).

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
}
