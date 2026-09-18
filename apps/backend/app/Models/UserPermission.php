<?php

namespace App\Models;

use App\Enums\PermissionEffect;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-user permission override (user_permissions). Precedence/resolution logic
 * is a later AUTH task. The table uses a composite primary key
 * (user_id, permission_id) with no auto-incrementing id.
 */
class UserPermission extends Model
{
    // created_at but no updated_at column (see UserRole).
    public $timestamps = true;

    const UPDATED_AT = null;

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'permission_id',
        'effect',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'effect' => PermissionEffect::class,
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class);
    }
}
