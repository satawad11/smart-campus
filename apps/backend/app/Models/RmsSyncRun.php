<?php

namespace App\Models;

use App\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One RMS sync run and its report (ACR-INT-01): counts and review items,
 * never names. A dry run computes the same report and writes nothing else.
 */
class RmsSyncRun extends Model
{
    use HasPublicUuid;

    public const RUNNING = 'RUNNING';

    public const COMPLETED = 'COMPLETED';

    public const FAILED = 'FAILED';

    /** @var list<string> */
    protected $fillable = [
        'status', 'trigger', 'dry_run', 'counts', 'issues', 'error',
        'created_by_user_id', 'started_at', 'finished_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dry_run' => 'boolean',
            'counts' => 'array',
            'issues' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
