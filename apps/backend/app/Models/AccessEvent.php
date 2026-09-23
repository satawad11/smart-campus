<?php

namespace App\Models;

use App\Concerns\HasPublicUuid;
use Database\Factories\AccessEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Durable recognition-occurrence record (EVT-001, database blueprint §7).
 *
 * Perception only — never an access decision (see AccessDecision, a
 * separate, related model: "Event != Decision", docs/09-module-05-event-
 * policy-monitor.md). Immutable once created: no $timestamps update
 * column, matching CameraHealthLog/AiNodeHealthLog's append-only
 * convention — an event is superseded by a later event, never edited.
 *
 * Unlike every other HasPublicUuid model, `uuid` here is not always
 * server-generated: EVT-003's ingestion service deliberately sets it to
 * the AI node's own event_uuid (the idempotency key CAM-011 already
 * assigned — HasUuids only autogenerates when the attribute is unset, so
 * an explicit value passed to create() is preserved as-is). `uuid` is
 * fillable for exactly that reason.
 *
 * @use HasFactory<AccessEventFactory>
 */
class AccessEvent extends Model
{
    use HasFactory, HasPublicUuid;

    protected $table = 'access_events';

    // Immutable telemetry-like record: no updated_at column.
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'uuid',
        'person_id',
        'site_id',
        'zone_id',
        'camera_id',
        'ai_node_id',
        'event_type',
        'occurred_at',
        'received_at',
        'face_score',
        'liveness_score',
        'quality_score',
        'track_id',
        'snapshot_path',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'received_at' => 'datetime',
            'created_at' => 'datetime',
            'face_score' => 'float',
            'liveness_score' => 'float',
            'quality_score' => 'float',
            'metadata' => 'array',
        ];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function camera(): BelongsTo
    {
        return $this->belongsTo(Camera::class);
    }

    public function aiNode(): BelongsTo
    {
        return $this->belongsTo(AiNode::class);
    }

    public function eventType(): BelongsTo
    {
        return $this->belongsTo(EventType::class, 'event_type', 'code');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(AccessDecision::class, 'event_id');
    }

    /**
     * The current decision — the latest-appended row (docs/09: "Officer
     * review adds a new decision. It never overwrites system decision"),
     * so "current" always means "most recent", not "the original".
     * Ordered by id (no updated_at on this append-only table, and id is
     * already strictly insertion-ordered).
     */
    public function latestDecision(): HasOne
    {
        return $this->hasOne(AccessDecision::class, 'event_id')->latestOfMany('id');
    }
}
