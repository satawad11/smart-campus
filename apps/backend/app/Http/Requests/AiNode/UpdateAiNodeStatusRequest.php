<?php

namespace App\Http\Requests\AiNode;

use App\Enums\DeviceStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAiNodeStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission enforced by route middleware (ai_node.manage)
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(DeviceStatus::class)],
        ];
    }
}
