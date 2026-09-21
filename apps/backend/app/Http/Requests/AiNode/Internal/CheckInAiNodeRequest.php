<?php

namespace App\Http\Requests\AiNode\Internal;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Node check-in / self-registration confirmation (CAM-003/CAM-004,
 * ai_node.token auth). Identity comes from the authenticated per-node
 * credential (resolved by middleware), never a client-supplied node_code —
 * a credential cannot be used to check in as a different node. A node
 * reports only facts about itself — never site_id or status.
 */
class CheckInAiNodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission enforced by ai_node.token middleware
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'hostname' => ['sometimes', 'nullable', 'string', 'max:255'],
            'ip_address' => ['sometimes', 'nullable', 'ip'],
            'api_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'software_version' => ['sometimes', 'nullable', 'string', 'max:100'],
            'capabilities' => ['sometimes', 'nullable', 'array'],

            // Identity/scope/status can never be self-declared by a node.
            'node_code' => ['prohibited'],
            'site_uuid' => ['prohibited'],
            'site_id' => ['prohibited'],
            'status' => ['prohibited'],
            'id' => ['prohibited'],
            'uuid' => ['prohibited'],
        ];
    }
}
