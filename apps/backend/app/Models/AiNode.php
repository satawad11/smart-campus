<?php

namespace App\Models;

use App\Concerns\HasPublicUuid;
use App\Enums\AIModelStatus;
use App\Enums\AIModelType;
use App\Enums\DeviceStatus;
use Database\Factories\AiNodeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Perception-processing node (CAM-001, database blueprint §6). May serve one
 * or more sites, so site_id is nullable. Registration/heartbeat behavior is
 * CAM-003; per-node authentication is CAM-004.
 *
 * software_version/capabilities are self-reported facts a node contributes
 * about itself on register/heartbeat — never authorization or scope. Site
 * assignment stays admin-owned; a node cannot change it via self-report.
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
        'software_version',
        'model_code',
        'model_version',
        'applied_config_revision',
        'capabilities',
        'cpu_model',
        'gpu_name',
        'gpu_memory_mb',
        'max_camera',
        'status',
        'last_seen_at',
    ];

    /**
     * COM-03: whether the model this node reports is registered as an ACTIVE
     * FACE_EMBEDDING model, the rule InternalFaceRecognitionService applies
     * to every recognition request. Null when the node hasn't reported one.
     */
    public function isModelCompatible(): ?bool
    {
        if ($this->model_code === null || $this->model_version === null) {
            return null;
        }

        return AIModel::query()
            ->where('model_type', AIModelType::FACE_EMBEDDING)
            ->where('status', AIModelStatus::ACTIVE)
            ->where('code', $this->model_code)
            ->where('version', $this->model_version)
            ->exists();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DeviceStatus::class,
            'capabilities' => 'array',
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

    /**
     * The most recently recorded health status (RPT-005 dashboard
     * online/total counts) — same latestOfMany pattern as
     * AccessEvent::latestDecision().
     */
    public function latestHealth(): HasOne
    {
        return $this->hasOne(AiNodeHealthLog::class)->latestOfMany('recorded_at');
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(AiNodeCredential::class);
    }
}
