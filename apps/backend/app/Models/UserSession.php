<?php

namespace App\Models;

use App\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Application session record (user_sessions) — distinct from Laravel's
 * framework `sessions` store. Session-management service/API is a later task.
 */
class UserSession extends Model
{
    use HasPublicUuid;

    // Explicit lifecycle columns; no created_at/updated_at on this table.
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'session_id',
        'ip_address',
        'user_agent',
        'device_name',
        'login_at',
        'last_activity_at',
        'logout_at',
        'revoked_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'login_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'logout_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
