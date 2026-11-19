<?php

namespace App\Models;

use App\Concerns\HasPublicUuid;
use App\Enums\AccessDecisionOutcome;
use App\Enums\GateMatchResult;
use App\Enums\GateMode;
use App\Enums\VehicleStatus;
use Database\Factories\AccessPolicyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Configuration-driven access-control rule (EVT-002, database blueprint
 * §7). The matcher that evaluates these against an AccessContext is
 * EVT-006 — this model is schema + admin CRUD only.
 *
 * A null subject_type/subject_status/site_id/zone_id/event_type means
 * "matches regardless of that dimension" (a wildcard) — the same
 * nullable-optional-dimension shape AccessEvent itself uses. priority:
 * lower value is evaluated first (see the creating migration's docblock).
 *
 * gate_id/gate_mode/match_result/vehicle_status (GATE-005) are Smart Gate
 * dimensions with the same wildcard rule; a policy that sets any of them
 * applies only to gate passes.
 *
 * @use HasFactory<AccessPolicyFactory>
 */
class AccessPolicy extends Model
{
    use HasFactory, HasPublicUuid;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'subject_type',
        'subject_status',
        'site_id',
        'zone_id',
        'event_type',
        'gate_id',
        'gate_mode',
        'match_result',
        'vehicle_status',
        'decision',
        'reason_code',
        'priority',
        'enabled',
        'valid_from',
        'valid_until',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'decision' => AccessDecisionOutcome::class,
            'gate_mode' => GateMode::class,
            'match_result' => GateMatchResult::class,
            'vehicle_status' => VehicleStatus::class,
            'priority' => 'integer',
            'enabled' => 'boolean',
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
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

    public function gate(): BelongsTo
    {
        return $this->belongsTo(Gate::class)->withTrashed();
    }

    public function eventType(): BelongsTo
    {
        return $this->belongsTo(EventType::class, 'event_type', 'code');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(AccessDecision::class, 'access_policy_id');
    }
}
