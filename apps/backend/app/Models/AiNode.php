<?php

namespace App\Models;

use App\Concerns\HasPublicUuid;
use App\Enums\DeviceStatus;
use Database\Factories\AiNodeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Perception-processing node (CAM-001, database blueprint §6). May serve one
 * or more sites, so site_id is nullable. Registration/heartbeat behavior is
 * CAM-003; per-node authentication is CAM-004 — schema only here.
 */
class AiNode extends Model
{
    /** @use HasFactory<AiNodeFactory> */
    use HasFactory, HasPublicUuid;

    /** @var list<string> */
    protected $fillable = [
        'site_id',
        'node_code',
        'name',
        'hostname',
        'ip_address',
        'api_url',
        'hardware_type',
        'cpu_model',
        'gpu_name',
        'gpu_memory_mb',
        'max_camera',
        'status',
        'last_seen_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DeviceStatus::class,
            'gpu_memory_mb' => 'integer',
            'max_camera' => 'integer',
            'last_seen_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function cameras(): HasMany
    {
        return $this->hasMany(Camera::class);
    }

    public function healthLogs(): HasMany
    {
        return $this->hasMany(AiNodeHealthLog::class);
    }
}
