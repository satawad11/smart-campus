<?php

namespace App\Models;

use App\Concerns\HasPublicUuid;
use App\Enums\AccessDecisionOutcome;
use App\Enums\DecisionSource;
use Database\Factories\AccessDecisionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Access-control decision for one event (EVT-001, database blueprint §7).
 *
 * Append-only: a manual officer review creates a NEW row, it never edits
 * an existing one (docs/09: "Officer review adds a new decision. It never
 * overwrites system decision") — hence no $timestamps update column, same
 * convention as AccessEvent/CameraHealthLog/AiNodeHealthLog.
 *
 * @use HasFactory<AccessDecisionFactory>
 */
class AccessDecision extends Model
{
    use HasFactory, HasPublicUuid;

    protected $table = 'access_decisions';

    // Immutable, append-only record: no updated_at column.
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'event_id',
        'person_id',
        'decision',
        'reason_code',
        'access_policy_id',
        'gate_pass_id',
        'decision_source',
        'decided_by_user_id',
        'reason_text',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'decision' => AccessDecisionOutcome::class,
            'decision_source' => DecisionSource::class,
            'created_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(AccessEvent::class, 'event_id');
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(AccessPolicy::class, 'access_policy_id');
    }

    /** The Smart Gate pass this decision decided (GATE-007); null for a non-gate decision. */
    public function gatePass(): BelongsTo
    {
        return $this->belongsTo(GatePass::class);
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }
}
