<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BackupJob extends Model
{
    use HasFactory, HasUuids;

    public const TYPE_POSTGRESQL = 'POSTGRESQL';

    public const TYPE_STORAGE = 'STORAGE';

    public const TYPE_CONFIG = 'CONFIG';

    public const TYPE_RESTORE_DRILL = 'RESTORE_DRILL';

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_RUNNING = 'RUNNING';

    public const STATUS_COMPLETED = 'COMPLETED';

    public const STATUS_FAILED = 'FAILED';

    public const STATUS_VERIFIED = 'VERIFIED';

    protected $fillable = [
        'type',
        'target',
        'status',
        'size_bytes',
        'checksum',
        'checksum_algorithm',
        'started_at',
        'completed_at',
        'error_message',
        'metadata',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'metadata' => 'array',
    ];
}
