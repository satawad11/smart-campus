<?php

namespace App\Models;

use App\Concerns\HasPublicUuid;
use App\Enums\RoleStatus;
use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory, HasPublicUuid;

    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        'description',
        'system_role',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'system_role' => 'boolean',
            'status' => RoleStatus::class,
        ];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions');
    }

    public function userAssignments(): HasMany
    {
        return $this->hasMany(UserRole::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_roles')
            ->withPivot(['site_id', 'zone_id', 'valid_from', 'valid_until', 'created_at']);
    }
}
