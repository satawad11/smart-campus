<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SystemIncident extends Model
{
    use HasFactory, HasUuids;

    public const STATUS_OPEN = 'OPEN';

    public const STATUS_INVESTIGATING = 'INVESTIGATING';

    public const STATUS_MITIGATED = 'MITIGATED';

    public const STATUS_RESOLVED = 'RESOLVED';

    public const STATUS_POST_MORTEM = 'POST_MORTEM';

    public const SEVERITY_SEV1 = 'SEV1';

    public const SEVERITY_SEV2 = 'SEV2';

    public const SEVERITY_SEV3 = 'SEV3';

    protected $fillable = [
        'incident_number',
        'title',
        'severity',
        'status',
        'description',
        'impact_summary',
        'root_cause',
        'resolution_summary',
        'commander_user_id',
        'started_at',
        'mitigated_at',
        'resolved_at',
        'action_items',
        'metadata',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'mitigated_at' => 'datetime',
        'resolved_at' => 'datetime',
        'action_items' => 'array',
        'metadata' => 'array',
    ];

    public function commander(): BelongsTo
    {
        return $this->belongsTo(User::class, 'commander_user_id');
    }
}
