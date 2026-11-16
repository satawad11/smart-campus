<?php

namespace App\Services\Staff;

use App\Enums\AuditAction;
use App\Enums\PersonType;
use App\Enums\StaffEmploymentStatus;
use App\Enums\StatusChangeSource;
use App\Exceptions\ApiException;
use App\Models\Department;
use App\Models\Person;
use App\Models\StaffProfile;
use App\Models\StaffStatusHistory;
use App\Models\User;
use App\Services\Audit\AuditRequestContext;
use App\Services\Audit\AuditService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Teacher/staff domain service (Module 09, PPL-003).
 *
 * A staff member = Person identity (type TEACHER or STAFF) + StaffProfile. Like
 * StudentService, this owns the transactional invariants: creation (Person +
 * profile + initial status history + audit, atomic) and the employment-status
 * transition (current status kept in lock-step with the append-only history).
 * Employment status is registry data, never an access decision (Module 05 policy).
 *
 * Audit subject: the StaffProfile uuid, entity_type STAFF, module 'staff'.
 */
class StaffService
{
    /** Person types a staff profile may carry. */
    public const PERSON_TYPES = [PersonType::TEACHER, PersonType::STAFF];

    public function __construct(
        private readonly AuditService $audit,
    ) {}

    /**
     * @param  array{
     *     person: array{person_code?:?string, first_name:string, last_name:string, display_name?:?string, gender?:?string},
     *     person_type?: ?string,
     *     staff_code: string,
     *     position?: ?string,
     *     department_uuid?: ?string,
     *     phone?: ?string,
     *     email?: ?string
     * }  $data
     */
    public function createStaff(array $data, ?User $actor, ?AuditRequestContext $context): StaffProfile
    {
        $department = $this->resolveDepartment($data['department_uuid'] ?? null);
        $personType = PersonType::from($data['person_type'] ?? PersonType::STAFF->value);
        $now = CarbonImmutable::now();

        return DB::transaction(function () use ($data, $department, $personType, $now, $actor, $context) {
            $this->assertStaffCodeAvailable($data['staff_code']);

            $person = Person::create([
                'person_code' => $data['person']['person_code'] ?? null,
                'person_type' => $personType,
                'first_name' => $data['person']['first_name'],
                'last_name' => $data['person']['last_name'],
                'display_name' => $data['person']['display_name'] ?? null,
                'gender' => $data['person']['gender'] ?? null,
                'status' => 'ACTIVE',
            ]);

            $profile = StaffProfile::create([
                'person_id' => $person->id,
                'staff_code' => $data['staff_code'],
                'position' => $data['position'] ?? null,
                'department_id' => $department?->id,
                'employment_status' => StaffEmploymentStatus::ACTIVE,
                'status_start_date' => $now->toDateString(),
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
            ]);

            StaffStatusHistory::create([
                'staff_profile_id' => $profile->id,
                'old_status' => null,
                'new_status' => StaffEmploymentStatus::ACTIVE,
                'effective_from' => $now,
                'effective_to' => null,
                'reason' => null,
                'source' => StatusChangeSource::MANUAL,
                'changed_by_user_id' => $actor?->id,
            ]);

            $this->audit->record(
                AuditAction::STAFF_CREATED, $actor, 'STAFF', $profile->uuid,
                newValues: [
                    'staff_code' => $profile->staff_code,
                    'person_type' => $personType->value,
                    'employment_status' => StaffEmploymentStatus::ACTIVE->value,
                    'position' => $profile->position,
                    'person_uuid' => $person->uuid,
                    'department_uuid' => $department?->uuid,
                ],
                context: $context, module: 'staff',
            );

            return $profile;
        });
    }

    /**
     * Generic update of names and employment details. staff_code, person type and
     * employment status are not changed here (status has its own workflow).
     *
     * @param  array<string, mixed>  $data
     */
    public function updateStaff(StaffProfile $profile, array $data, ?User $actor, ?AuditRequestContext $context): StaffProfile
    {
        return DB::transaction(function () use ($profile, $data, $actor, $context) {
            $person = $profile->person()->firstOrFail();

            if (array_key_exists('department_uuid', $data)) {
                $data['department_id'] = $this->resolveDepartment($data['department_uuid'])?->id;
            }

            $personChanges = $this->applyChanges($person, $data['person'] ?? [], ['first_name', 'last_name', 'display_name', 'gender']);
            $profileChanges = $this->applyChanges($profile, $data, ['position', 'department_id', 'phone', 'email']);

            if ($personChanges !== []) {
                $person->save();
            }
            if ($profileChanges !== []) {
                $profile->save();
            }

            if ($personChanges !== [] || $profileChanges !== []) {
                $this->audit->record(
                    AuditAction::STAFF_UPDATED, $actor, 'STAFF', $profile->uuid,
                    oldValues: $this->prefixKeys($personChanges['old'] ?? [], 'person.') + ($profileChanges['old'] ?? []),
                    newValues: $this->prefixKeys($personChanges['new'] ?? [], 'person.') + ($profileChanges['new'] ?? [])
                        + ['staff_code' => $profile->staff_code, 'person_uuid' => $person->uuid],
                    context: $context, module: 'staff',
                );
            }

            return $profile;
        });
    }

    /**
     * Authoritative employment-status transition: atomic and row-locked, closes the
     * open history interval, opens a new one, and audits. changed_by comes from the
     * authenticated actor, never the client.
     */
    public function changeEmploymentStatus(
        StaffProfile $profile,
        StaffEmploymentStatus $newStatus,
        ?string $reason,
        ?User $actor,
        ?AuditRequestContext $context,
    ): StaffProfile {
        $effective = CarbonImmutable::now();

        return DB::transaction(function () use ($profile, $newStatus, $reason, $effective, $actor, $context) {
            /** @var StaffProfile $locked */
            $locked = StaffProfile::whereKey($profile->getKey())->lockForUpdate()->firstOrFail();
            $oldStatus = $locked->employment_status;

            if ($oldStatus === $newStatus) {
                throw ApiException::unprocessable('STAFF_STATUS_NO_OP', 'The staff member is already in the requested employment status.');
            }

            StaffStatusHistory::where('staff_profile_id', $locked->id)
                ->whereNull('effective_to')
                ->orderByDesc('effective_from')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first()
                ?->forceFill(['effective_to' => $effective])
                ->save();

            $locked->employment_status = $newStatus;
            $locked->status_start_date = $effective->toDateString();
            $locked->save();

            StaffStatusHistory::create([
                'staff_profile_id' => $locked->id,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'effective_from' => $effective,
                'effective_to' => null,
                'reason' => $reason,
                'source' => StatusChangeSource::API,
                'changed_by_user_id' => $actor?->id,
            ]);

            $this->audit->record(
                AuditAction::STAFF_STATUS_CHANGED, $actor, 'STAFF', $locked->uuid,
                oldValues: ['employment_status' => $oldStatus?->value],
                newValues: [
                    'employment_status' => $newStatus->value,
                    'reason' => $reason,
                    'effective_from' => $effective->toIso8601String(),
                ],
                context: $context, module: 'staff',
            );

            return $locked;
        });
    }

    private function resolveDepartment(?string $uuid): ?Department
    {
        if ($uuid === null) {
            return null;
        }

        $department = Department::where('uuid', $uuid)->first()
            ?? throw ApiException::unprocessable('DEPARTMENT_NOT_FOUND', 'The specified department does not exist.');

        if ($department->status->value !== 'ACTIVE') {
            throw ApiException::unprocessable('DEPARTMENT_INACTIVE', 'The specified department is not active.');
        }

        return $department;
    }

    private function assertStaffCodeAvailable(string $code): void
    {
        if (StaffProfile::where('staff_code', $code)->lockForUpdate()->exists()) {
            throw ApiException::conflict('STAFF_CODE_ALREADY_EXISTS', 'A staff member with this staff_code already exists.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $fields
     * @return array{old?: array<string, mixed>, new?: array<string, mixed>}
     */
    private function applyChanges(object $model, array $data, array $fields): array
    {
        $old = [];
        $new = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $data)) {
                $current = $model->{$field};
                $currentValue = $current instanceof \BackedEnum ? $current->value : $current;
                if ($currentValue !== $data[$field]) {
                    $old[$field] = $currentValue;
                    $new[$field] = $data[$field];
                    $model->{$field} = $data[$field];
                }
            }
        }

        return $new === [] ? [] : ['old' => $old, 'new' => $new];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function prefixKeys(array $values, string $prefix): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            $out[$prefix.$key] = $value;
        }

        return $out;
    }
}
