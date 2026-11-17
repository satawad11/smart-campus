<?php

namespace App\Models;

use App\Enums\GateCameraRole;
use Database\Factories\GateCameraFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use LogicException;

/**
 * Camera assigned to a Smart Gate with its role (GATE-001, ACR-VEH-01 decision D3).
 *
 * A camera serves at most one gate (unique camera_id), so the gate of a face or plate event is
 * found from its camera. The camera must be in the gate's site.
 */
class GateCamera extends Pivot
{
    /** @use HasFactory<GateCameraFactory> */
    use HasFactory;

    protected $table = 'gate_cameras';

    public $incrementing = true;

    /** @var list<string> */
    protected $fillable = ['gate_id', 'camera_id', 'role'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['role' => GateCameraRole::class];
    }

    protected static function booted(): void
    {
        static::saving(function (self $link): void {
            $gateSite = Gate::withTrashed()->whereKey($link->gate_id)->value('site_id');
            $cameraSite = Camera::withTrashed()->whereKey($link->camera_id)->value('site_id');
            if ($gateSite === null || (int) $gateSite !== (int) $cameraSite) {
                throw new LogicException('A gate camera must be in the gate site.');
            }
        });
    }

    public function gate(): BelongsTo
    {
        return $this->belongsTo(Gate::class);
    }

    public function camera(): BelongsTo
    {
        return $this->belongsTo(Camera::class);
    }
}
