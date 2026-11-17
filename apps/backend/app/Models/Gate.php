<?php

namespace App\Models;

use App\Concerns\HasPublicUuid;
use App\Enums\GateDirection;
use App\Enums\GateMode;
use App\Enums\LocationStatus;
use Database\Factories\GateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;

/**
 * Smart Gate (GATE-001, ACR-VEH-01 Phase 5): an entry/exit point of a zone with its cameras.
 *
 * Configuration only. The gate pairs face and plate events into a decision (GATE-004+); it never
 * opens a barrier. Actuation needs Phase 2 and its own ACR (decision D5).
 */
class Gate extends Model
{
    /** @use HasFactory<GateFactory> */
    use HasFactory, HasPublicUuid, SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'site_id',
        'zone_id',
        'code',
        'name',
        'direction',
        'correlation_window_seconds',
        'mode',
        'status',
        'notes',
        'created_by_user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => GateDirection::class,
            'mode' => GateMode::class,
            'status' => LocationStatus::class,
            'correlation_window_seconds' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $gate): void {
            if (! $gate->isDirty(['site_id', 'zone_id'])) {
                return;
            }
            $zoneSite = Zone::whereKey($gate->zone_id)->value('site_id');
            if ($zoneSite === null || (int) $zoneSite !== (int) $gate->site_id) {
                throw new LogicException('A gate zone must belong to the gate site.');
            }
        });
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function gateCameras(): HasMany
    {
        return $this->hasMany(GateCamera::class);
    }

    public function cameras(): BelongsToMany
    {
        return $this->belongsToMany(Camera::class, 'gate_cameras')
            ->using(GateCamera::class)
            ->withPivot('id', 'role')
            ->withTimestamps();
    }

    /** Passes through this gate (GATE-003). */
    public function passes(): HasMany
    {
        return $this->hasMany(GatePass::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
