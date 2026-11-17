<?php

namespace App\Models;

use App\Concerns\HasPublicUuid;
use App\Enums\GateMatchResult;
use Database\Factories\GatePassFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One pass through a Smart Gate (GATE-003, ACR-VEH-01 Phase 5).
 *
 * Links the face event and/or the plate event the correlation service (GATE-004) put together.
 * The events themselves are never modified. A pass is immutable: it is never updated or deleted
 * through the model. Retention (RetentionCleanupService) is the only writer after creation: it
 * removes passes with their events and clears vehicle_id when plate reads are redacted.
 *
 * Rules checked on create:
 * - the events required by match_result are present, and only those (see GateMatchResult);
 * - MATCHED and MISMATCH carry both person_id and vehicle_id;
 * - the face event is a recognition event and the plate event a plate event;
 * - every event is in the gate's site.
 */
class GatePass extends Model
{
    /** @use HasFactory<GatePassFactory> */
    use HasFactory, HasPublicUuid;

    protected $table = 'gate_passes';

    // created_at only; no updated_at column.
    const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'gate_id',
        'face_event_id',
        'plate_event_id',
        'person_id',
        'vehicle_id',
        'match_result',
        'occurred_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'match_result' => GateMatchResult::class,
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $pass): void {
            $pass->assertConsistent();
        });

        static::updating(function (): void {
            throw new LogicException('Gate passes are immutable: a saved pass is never changed.');
        });

        static::deleting(function (): void {
            throw new LogicException('Gate passes are never deleted through the model.');
        });
    }

    private function assertConsistent(): void
    {
        $result = $this->match_result;
        if (! $result instanceof GateMatchResult) {
            throw new LogicException('A gate pass needs a match_result.');
        }

        if ($result->needsFaceEvent() !== ($this->face_event_id !== null)) {
            throw new LogicException("match_result {$result->value} does not fit the face event of this pass.");
        }
        if ($result->needsPlateEvent() !== ($this->plate_event_id !== null)) {
            throw new LogicException("match_result {$result->value} does not fit the plate event of this pass.");
        }
        if ($result->needsPersonAndVehicle() && ($this->person_id === null || $this->vehicle_id === null)) {
            throw new LogicException("match_result {$result->value} needs both person_id and vehicle_id.");
        }

        $gateSite = Gate::withTrashed()->whereKey($this->gate_id)->value('site_id');
        if ($gateSite === null) {
            throw new LogicException('A gate pass needs an existing gate.');
        }

        $this->assertEvent($this->face_event_id, 'RECOGNITION', (int) $gateSite, 'face');
        $this->assertEvent($this->plate_event_id, 'PLATE', (int) $gateSite, 'plate');
    }

    private function assertEvent(?int $eventId, string $category, int $gateSite, string $label): void
    {
        if ($eventId === null) {
            return;
        }

        $event = AccessEvent::query()->whereKey($eventId)->first(['id', 'site_id', 'event_type']);
        if ($event === null) {
            throw new LogicException("The {$label} event of a gate pass must exist.");
        }
        if (EventType::query()->where('code', $event->event_type)->value('category') !== $category) {
            throw new LogicException("The {$label} event of a gate pass must be a {$category} event.");
        }
        if ((int) $event->site_id !== $gateSite) {
            throw new LogicException("The {$label} event of a gate pass must be in the gate site.");
        }
    }

    public function gate(): BelongsTo
    {
        return $this->belongsTo(Gate::class)->withTrashed();
    }

    public function faceEvent(): BelongsTo
    {
        return $this->belongsTo(AccessEvent::class, 'face_event_id');
    }

    public function plateEvent(): BelongsTo
    {
        return $this->belongsTo(AccessEvent::class, 'plate_event_id');
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class)->withTrashed();
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class)->withTrashed();
    }
}
