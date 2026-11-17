<?php

namespace App\Http\Resources;

use App\Models\VisitorProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Safe visitor profile and minimum identity details (PPL-006). */
class VisitorResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var VisitorProfile $profile */
        $profile = $this->resource;
        $person = $profile->person;

        return [
            'uuid' => $profile->uuid,
            'person' => [
                'uuid' => $person->uuid,
                'person_code' => $person->person_code,
                'person_type' => $person->person_type?->value ?? $person->person_type,
                'first_name' => $person->first_name,
                'last_name' => $person->last_name,
                'display_name' => $person->display_name,
                'name' => $person->displayLabel(),
                'status' => $person->status?->value ?? $person->status,
            ],
            'organization' => $profile->organization,
            'phone' => $profile->phone,
            'id_document_type' => $profile->id_document_type?->value ?? $profile->id_document_type,
            'id_document_last4' => $profile->id_document_last4,
            'notes' => $profile->notes,
            'created_at' => $profile->created_at?->toIso8601String(),
            'updated_at' => $profile->updated_at?->toIso8601String(),
        ];
    }
}
