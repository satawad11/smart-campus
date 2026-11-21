<?php

namespace App\Models;

use App\Concerns\HasPublicUuid;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use Database\Factories\VehicleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Vehicle registry record (VEH-002, ACR-VEH-01).
 *
 * Describes a vehicle and its plate. Ownership is NOT stored here: links to persons live in
 * the append-only VehicleOwnershipHistory. A plate alone never identifies a person for access;
 * face recognition stays primary (ACR-VEH-01 D2).
 */
class Vehicle extends Model
{
    /** @use HasFactory<VehicleFactory> */
    use HasFactory, HasPublicUuid, SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'plate_number',
        'plate_province',
        'vehicle_type',
        'brand',
        'model',
        'color',
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
            'vehicle_type' => VehicleType::class,
            'status' => VehicleStatus::class,
        ];
    }

    public function ownershipHistories(): HasMany
    {
        return $this->hasMany(VehicleOwnershipHistory::class);
    }

    /**
     * Links that apply right now (see VehicleOwnershipHistory::scopeCurrent).
     */
    public function currentOwnerships(): HasMany
    {
        return $this->ownershipHistories()->current();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
