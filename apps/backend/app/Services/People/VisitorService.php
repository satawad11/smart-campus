<?php

namespace App\Services\People;

use App\Enums\AuditAction;
use App\Enums\PersonType;
use App\Models\Person;
use App\Models\User;
use App\Models\VisitorProfile;
use App\Services\Audit\AuditRequestContext;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;

/** Visitor identity/profile lifecycle (PPL-006). */
class VisitorService
{
    public function __construct(private readonly AuditService $audit) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor, AuditRequestContext $context): VisitorProfile
    {
        return DB::transaction(function () use ($data, $actor, $context) {
            $person = Person::create([
                'person_code' => $data['person_code'] ?? null,
                'person_type' => PersonType::from($data['person_type'] ?? PersonType::VISITOR->value),
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'display_name' => $data['display_name'] ?? null,
                'gender' => $data['gender'] ?? null,
                'status' => 'ACTIVE',
            ]);
            $profile = VisitorProfile::create([
                'person_id' => $person->id,
                'organization' => $data['organization'] ?? null,
                'phone' => $data['phone'] ?? null,
                'id_document_type' => $data['id_document_type'] ?? null,
                'id_document_last4' => $data['id_document_last4'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
            $this->audit->record(
                AuditAction::VISITOR_CREATED, $actor, 'VisitorProfile', $profile->uuid,
                null,
                ['person_uuid' => $person->uuid, 'person_type' => $person->person_type->value, 'organization' => $profile->organization],
                null, $context, 'people'
            );

            return $profile->load('person');
        });
    }

    /** @param array<string, mixed> $data */
    public function update(VisitorProfile $profile, array $data, User $actor, AuditRequestContext $context): VisitorProfile
    {
        return DB::transaction(function () use ($profile, $data, $actor, $context) {
            $profile->load('person');
            $personFields = ['person_code', 'first_name', 'last_name', 'display_name', 'gender'];
            // Do not copy identity-document fragments into audit history.
            $profileFields = ['organization', 'phone', 'id_document_type', 'notes'];
            $old = [
                'person' => array_intersect_key($profile->person->only($personFields), array_intersect_key($data, array_flip($personFields))),
                'profile' => array_intersect_key($profile->only($profileFields), array_intersect_key($data, array_flip($profileFields))),
            ];
            $profile->person->fill(array_intersect_key($data, array_flip($personFields)))->save();
            $profileData = array_intersect_key($data, array_flip(['organization', 'phone', 'id_document_type', 'id_document_last4', 'notes']));
            $profile->fill($profileData)->save();
            $this->audit->record(
                AuditAction::VISITOR_UPDATED, $actor, 'VisitorProfile', $profile->uuid,
                $old,
                [
                    'person' => array_intersect_key($profile->person->only($personFields), array_intersect_key($data, array_flip($personFields))),
                    'profile' => array_intersect_key($profile->only($profileFields), array_intersect_key($data, array_flip($profileFields))),
                ],
                null, $context, 'people'
            );

            return $profile->fresh()->load('person');
        });
    }
}
