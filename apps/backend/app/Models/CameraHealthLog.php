<?php

namespace App\Models;

use App\Enums\HealthStatus;
use Database\Factories\CameraHealthLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Camera operational health telemetry (CAM-001). Historical/append-oriented;
 * collection logic is CAM-013. No secrets, no frames/images.
 */
class CameraHealthLog extends Model
{
    /** @use HasFactory<CameraHealthLogFactory> */
    use HasFactory;

    // Immutable telemetry snapshot: no updated_at column.
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'camera_id',
        'status',
        'fps',
        'latency_ms',
        'dropped_frames',
        'resolution',
        'brightness_score',
        'blur_score',
        'reconnect_count',
        'recorded_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => HealthStatus::class,
            'fps' => 'float',
            'latency_ms' => 'integer',
            'dropped_frames' => 'integer',
            'brightness_score' => 'float',
            'blur_score' => 'float',
            'reconnect_count' => 'integer',
            'recorded_at' => 'datetime',
        ];
    }

    public function camera(): BelongsTo
    {
        return $this->belongsTo(Camera::class);
    }
}
