<?php

namespace App\Models;

use App\Concerns\HasPublicUuid;
use App\Enums\VehicleRelation;
use Database\Factories\VehicleOwnershipHistoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Link between a vehicle and a person over time (VEH-002, ACR-VEH-01).
 *
 * Append-oriented history (PROJECT_RULES §7): a link is never rewritten. The only change a
 * saved row accepts is closing it once by setting effective_to; changing who, which vehicle,
 * the relation or the start date means ending this row and adding a new one. Rows are never
 * deleted through the model.
 */
class VehicleOwnershipHistory extends Model
{
    /** @use HasFactory<VehicleOwnershipHistoryFactory> */
    use HasFactory, HasPublicUuid;

    protected $table = 'vehicle_ownership_histories';

    // created_at only; no updated_at column.
    const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'vehicle_id',
        'person_id',
        'relation',
        'effective_from',
        'effective_to',
        'reason',
        'created_by_user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'relation' => VehicleRelation::class,
            'effective_from' => 'datetime',
            'effective_to' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $row): void {
            $dirty = array_keys($row->getDirty());

            if ($dirty !== ['effective_to'] || $row->getOriginal('effective_to') !== null) {
                throw new LogicException('Vehicle ownership history is append-only: a saved link can only be closed once by setting effective_to.');
            }
            if ($row->effective_to === null || $row->effective_to->lt($row->effective_from)) {
                throw new LogicException('effective_to must be set and not earlier than effective_from.');
            }
        });

        static::deleting(function (): void {
            throw new LogicException('Vehicle ownership history rows are never deleted.');
        });
    }

    /**
     * Links that apply at the given moment (default: now).
     *
     * @param  Builder<self>  $query
     */
    public function scopeCurrent(Builder $query, ?Carbon $at = null): void
    {
        $at ??= now();

        $query->where('effective_from', '<=', $at)
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $at));
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
