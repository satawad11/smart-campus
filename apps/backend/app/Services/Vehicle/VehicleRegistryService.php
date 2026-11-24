<?php

namespace App\Services\Vehicle;

use App\Enums\AuditAction;
use App\Enums\VehicleRelation;
use App\Exceptions\ApiException;
use App\Models\Person;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleOwnershipHistory;
use App\Services\Audit\AuditRequestContext;
use App\Services\Audit\AuditService;
use App\Support\Vehicles\ThaiPlate;
use App\Support\Vehicles\ThaiProvince;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Vehicle registry domain service (VEH-004, ACR-VEH-01).
 *
 * Owns plate normalisation, uniqueness, person linking and audit. Controllers stay thin.
 * Registry data only: nothing here decides access (a plate never lets anyone in on its own).
 * The plate and province of a vehicle are fixed after registration; a wrong plate is fixed by
 * deactivating the record and registering the correct one, so plate history stays truthful.
 */
class VehicleRegistryService
{
    private const MUTABLE = ['vehicle_type', 'brand', 'model', 'color', 'status', 'notes'];

    public function __construct(
        private readonly AuditService $audit,
    ) {}

    /**
     * @param  array{plate_number:string,plate_province:string,vehicle_type?:string,brand?:?string,model?:?string,color?:?string,status?:string,notes?:?string}  $data
     */
    public function create(array $data, ?User $actor, ?AuditRequestContext $context): Vehicle
    {
        $plate = ThaiPlate::normalize($data['plate_number']);
        $province = ThaiProvince::resolve($data['plate_province'])
            ?? throw ApiException::unprocessable('VEHICLE_PROVINCE_INVALID', 'Unknown plate province.');

        return DB::transaction(function () use ($data, $plate, $province, $actor, $context) {
            $exists = Vehicle::where('plate_number', $plate)->where('plate_province', $province)->lockForUpdate()->exists();
            if ($exists) {
                throw ApiException::conflict('VEHICLE_PLATE_EXISTS', 'A vehicle with this plate is already registered.');
            }

            $vehicle = Vehicle::create([
                'plate_number' => $plate,
                'plate_province' => $province,
                'vehicle_type' => $data['vehicle_type'] ?? 'CAR',
                'brand' => $data['brand'] ?? null,
                'model' => $data['model'] ?? null,
                'color' => $data['color'] ?? null,
                'status' => $data['status'] ?? 'ACTIVE',
                'notes' => $data['notes'] ?? null,
                'created_by_user_id' => $actor?->id,
            ])->refresh();

            $this->audit->record(
                AuditAction::VEHICLE_CREATED, $actor, 'VEHICLE', $vehicle->uuid,
                newValues: [
                    'plate_number' => $vehicle->plate_number,
                    'plate_province' => $vehicle->plate_province,
                    'vehicle_type' => $vehicle->vehicle_type->value,
                    'status' => $vehicle->status->value,
                ],
                context: $context, module: 'vehicle',
            );

            return $vehicle;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Vehicle $vehicle, array $data, ?User $actor, ?AuditRequestContext $context): Vehicle
    {
        return DB::transaction(function () use ($vehicle, $data, $actor, $context) {
            $old = [];
            $new = [];
            foreach (self::MUTABLE as $field) {
                if (! array_key_exists($field, $data)) {
                    continue;
                }
                $current = $vehicle->{$field};
                $currentValue = $current instanceof \BackedEnum ? $current->value : $current;
                if ($currentValue !== $data[$field]) {
                    $old[$field] = $currentValue;
                    $new[$field] = $data[$field];
                    $vehicle->{$field} = $data[$field];
                }
            }

            if ($new !== []) {
                $vehicle->save();
                $this->audit->record(
                    AuditAction::VEHICLE_UPDATED, $actor, 'VEHICLE', $vehicle->uuid,
                    oldValues: $old, newValues: $new + ['plate_number' => $vehicle->plate_number],
                    context: $context, module: 'vehicle',
                );
            }

            return $vehicle->refresh();
        });
    }

    /**
     * Link a person to a vehicle from effective_from (default now). A person can hold several
     * relations to one vehicle, but not the same relation twice at the same time.
     *
     * @param  array{person_uuid:string,relation:string,effective_from?:?string,reason?:?string}  $data
     */
    public function link(Vehicle $vehicle, array $data, ?User $actor, ?AuditRequestContext $context): VehicleOwnershipHistory
    {
        $person = Person::where('uuid', $data['person_uuid'])->first()
            ?? throw ApiException::unprocessable('PERSON_NOT_FOUND', 'Person not found.');
        $relation = VehicleRelation::from($data['relation']);
        $from = isset($data['effective_from']) ? Carbon::parse($data['effective_from']) : now();

        return DB::transaction(function () use ($vehicle, $person, $relation, $from, $data, $actor, $context) {
            $overlap = VehicleOwnershipHistory::query()
                ->where('vehicle_id', $vehicle->id)
                ->where('person_id', $person->id)
                ->where('relation', $relation->value)
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $from))
                ->lockForUpdate()
                ->exists();
            if ($overlap) {
                throw ApiException::conflict('VEHICLE_LINK_EXISTS', 'This person already has this link to the vehicle.');
            }

            $link = VehicleOwnershipHistory::create([
                'vehicle_id' => $vehicle->id,
                'person_id' => $person->id,
                'relation' => $relation,
                'effective_from' => $from,
                'reason' => $data['reason'] ?? null,
                'created_by_user_id' => $actor?->id,
            ]);

            $this->audit->record(
                AuditAction::VEHICLE_PERSON_LINKED, $actor, 'VEHICLE', $vehicle->uuid,
                newValues: [
                    'link_uuid' => $link->uuid,
                    'person_uuid' => $person->uuid,
                    'relation' => $relation->value,
                    'effective_from' => $from->toIso8601String(),
                ],
                context: $context, module: 'vehicle',
            );

            return $link->load('person');
        });
    }

    /**
     * End a link at effective_to (default now). The row is closed, never deleted or rewritten.
     *
     * @param  array{effective_to?:?string,reason?:?string}  $data
     */
    public function unlink(Vehicle $vehicle, VehicleOwnershipHistory $link, array $data, ?User $actor, ?AuditRequestContext $context): VehicleOwnershipHistory
    {
        if ($link->vehicle_id !== $vehicle->id) {
            throw new ApiException('NOT_FOUND', 'Link not found.', 404);
        }
        if ($link->effective_to !== null) {
            throw ApiException::conflict('VEHICLE_LINK_ENDED', 'This link has already ended.');
        }

        $to = isset($data['effective_to']) ? Carbon::parse($data['effective_to']) : now();
        if ($to->lt($link->effective_from)) {
            throw ApiException::unprocessable('VEHICLE_LINK_END_BEFORE_START', 'The end date is before the start date.');
        }

        return DB::transaction(function () use ($vehicle, $link, $to, $data, $actor, $context) {
            $link->update(['effective_to' => $to]);

            $this->audit->record(
                AuditAction::VEHICLE_PERSON_UNLINKED, $actor, 'VEHICLE', $vehicle->uuid,
                oldValues: ['effective_to' => null],
                newValues: ['link_uuid' => $link->uuid, 'effective_to' => $to->toIso8601String()],
                metadata: isset($data['reason']) ? ['reason' => $data['reason']] : null,
                context: $context, module: 'vehicle',
            );

            return $link->refresh()->load('person');
        });
    }
}
