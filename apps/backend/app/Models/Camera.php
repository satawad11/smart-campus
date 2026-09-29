<?php

namespace App\Models;

use App\Concerns\HasPublicUuid;
use App\Enums\DeviceStatus;
use Database\Factories\CameraFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Camera sensor (CAM-001, database blueprint §6). Belongs to exactly one
 * site and zone; AI-node assignment is optional. RTSP/credential columns
 * (*_encrypted) are encrypted at rest by the application layer (CAM-002,
 * same Crypt::encryptString() pattern as users.mfa_secret_encrypted) and are
 * always hidden from array/JSON serialization here — no controller/resource
 * has to remember to strip them (ACCEPTANCE_CRITERIA: "Camera credentials
 * are encrypted and never returned in clear text").
 *
 * CRUD/test/snapshot APIs are CAM-002; this task is schema only.
 */
class Camera extends Model
{
    /** @use HasFactory<CameraFactory> */
    use HasFactory, HasPublicUuid, SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'site_id',
        'zone_id',
        'ai_node_id',
        'camera_code',
        'name',
        'camera_type',
        'ip_address',
        'stream_protocol',
        'rtsp_url_encrypted',
        'username_encrypted',
        'password_encrypted',
        'onvif_url',
        'face_detection_enabled',
        'face_recognition_enabled',
        'liveness_enabled',
        'snapshot_enabled',
        'roi_polygon',
        'status',
        'last_seen_at',
    ];

    /**
     * Credential columns must never leave the model via ordinary
     * array()/toJson() serialization, regardless of what a future Resource
     * does or forgets to do.
     *
     * @var list<string>
     */
    protected $hidden = [
        'rtsp_url_encrypted',
        'username_encrypted',
        'password_encrypted',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DeviceStatus::class,
            'face_detection_enabled' => 'boolean',
            'face_recognition_enabled' => 'boolean',
            'liveness_enabled' => 'boolean',
            'snapshot_enabled' => 'boolean',
            'roi_polygon' => 'array',
            'last_seen_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function aiNode(): BelongsTo
    {
        return $this->belongsTo(AiNode::class);
    }

    public function healthLogs(): HasMany
    {
        return $this->hasMany(CameraHealthLog::class);
    }

    /**
     * The most recently recorded health status (RPT-005 dashboard
     * online/total counts) — same latestOfMany pattern as
     * AccessEvent::latestDecision().
     */
    public function latestHealth(): HasOne
    {
        return $this->hasOne(CameraHealthLog::class)->latestOfMany('recorded_at');
    }
}
