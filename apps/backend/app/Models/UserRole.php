<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Role assignment metadata (user_roles). site_id/zone_id are scalar scope
 * fields; Site/Zone models and scope resolution are later tasks/modules.
 */
class UserRole extends Model
{
    // user_roles has created_at only (no updated_at); DB fills the default.
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'role_id',
        'site_id',
        'zone_id',
        'valid_from',
        'valid_until',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
