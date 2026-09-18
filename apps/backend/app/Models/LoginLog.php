<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Login attempt history (login_logs). Append-oriented security record; the
 * user relationship tolerates a null user_id (failed logins for unknown users).
 */
class LoginLog extends Model
{
    // login_logs has created_at but no updated_at. Eloquent sets created_at
    // automatically; updated_at is disabled so it is never written.
    public $timestamps = true;

    const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'username',
        'success',
        'failure_reason',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'success' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
