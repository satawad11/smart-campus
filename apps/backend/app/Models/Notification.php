<?php

namespace App\Models;

use App\Concerns\HasPublicUuid;
use Database\Factories\NotificationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A recipient's personal notification (EVT-012, docs/02-api-contract.md
 * §17). One row per recipient — read state is per-user, never shared.
 *
 * Backed by `app_notifications`, not `notifications` — see the migration
 * for why (collision with Laravel's own Notifiable::notifications() morph
 * relation, which User already inherits).
 *
 * @use HasFactory<NotificationFactory>
 */
class Notification extends Model
{
    use HasFactory, HasPublicUuid;

    protected $table = 'app_notifications';

    // read_at is the only mutable field; no updated_at column.
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'type',
        'title',
        'body',
        'data',
        'read_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
