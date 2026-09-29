<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SystemAlert extends Model
{
    use HasFactory, HasUuids;

    public const STATUS_OPEN = 'OPEN';

    public const STATUS_ACKNOWLEDGED = 'ACKNOWLEDGED';

    public const STATUS_RESOLVED = 'RESOLVED';

    public const SEVERITY_INFO = 'INFO';

    public const SEVERITY_WARNING = 'WARNING';

    public const SEVERITY_CRITICAL = 'CRITICAL';

    protected $fillable = [
        'type',
        'severity',
        'source',
        'title',
        'message',
        'status',
        'acknowledged_by_user_id',
        'acknowledged_at',
        'resolved_by_user_id',
        'resolved_at',
        'resolution_notes',
        'metadata',
    ];

    protected $casts = [
        'acknowledged_at' => 'datetime',
        'resolved_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by_user_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }
}
