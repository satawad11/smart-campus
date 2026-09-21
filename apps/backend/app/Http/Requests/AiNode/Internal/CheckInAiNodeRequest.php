<?php

namespace App\Http\Requests\AiNode\Internal;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Node check-in / self-registration confirmation (CAM-003, internal.token
 * auth). A node identifies itself by node_code and reports only facts about
 * itself — never site_id, status, or any other node's identity.
 */
class CheckInAiNodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission enforced by internal.token middleware
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'node_code' => ['required', 'string', 'max:255'],
            'hostname' => ['sometimes', 'nullable', 'string', 'max:255'],
            'ip_address' => ['sometimes', 'nullable', 'ip'],
            'api_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'software_version' => ['sometimes', 'nullable', 'string', 'max:100'],
            'capabilities' => ['sometimes', 'nullable', 'array'],

            // A node can never assign or change its own site/scope/status.
            'site_uuid' => ['prohibited'],
            'site_id' => ['prohibited'],
            'status' => ['prohibited'],
            'id' => ['prohibited'],
            'uuid' => ['prohibited'],
        ];
    }
}
