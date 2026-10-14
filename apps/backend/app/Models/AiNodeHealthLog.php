<?php

namespace App\Models;

use App\Enums\HealthStatus;
use Database\Factories\AiNodeHealthLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AI node operational health telemetry (CAM-001). Historical/append-oriented;
 * collection logic is CAM-014. No secrets, no biometric data.
 */
class AiNodeHealthLog extends Model
{
    /** @use HasFactory<AiNodeHealthLogFactory> */
    use HasFactory;

    // Immutable telemetry snapshot: no updated_at column.
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'ai_node_id',
        'cpu_usage',
        'ram_usage',
        'gpu_usage',
        'gpu_memory_usage',
        'temperature',
        'active_camera_count',
        'queue_depth',
        'disk_usage',
        'status',
        'recorded_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => HealthStatus::class,
            'cpu_usage' => 'float',
            'ram_usage' => 'float',
            'gpu_usage' => 'float',
            'gpu_memory_usage' => 'float',
            'temperature' => 'float',
            'active_camera_count' => 'integer',
            'queue_depth' => 'integer',
            'disk_usage' => 'float',
            'recorded_at' => 'datetime',
        ];
    }

    public function aiNode(): BelongsTo
    {
        return $this->belongsTo(AiNode::class);
    }
}
