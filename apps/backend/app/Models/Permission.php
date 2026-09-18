<?php

namespace App\Models;

use Database\Factories\PermissionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Permission extends Model
{
    /** @use HasFactory<PermissionFactory> */
    use HasFactory;

    // The permissions table has no timestamps (database blueprint §4).
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'code',
        'module',
        'name',
        'description',
    ];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permissions');
    }

    public function userOverrides(): HasMany
    {
        return $this->hasMany(UserPermission::class);
    }
}
