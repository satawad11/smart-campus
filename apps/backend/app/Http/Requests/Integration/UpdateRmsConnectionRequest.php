<?php

namespace App\Http\Requests\Integration;

use Illuminate\Foundation\Http\FormRequest;

/**
 * RMS connection settings (ACR-INT-02). api_key is optional: leave it out to
 * keep the stored key; clear_api_key removes it (falls back to .env).
 */
class UpdateRmsConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission:integration.manage on the route
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'base_url' => ['required', 'string', 'max:255', 'url:https', 'starts_with:https://'],
            'api_key' => ['nullable', 'string', 'min:16', 'max:255', 'regex:/^[\x21-\x7E]+$/'],
            'clear_api_key' => ['sometimes', 'boolean'],
            'timeout_seconds' => ['required', 'integer', 'min:5', 'max:120'],
            'sync_enabled' => ['required', 'boolean'],
            'sync_time' => ['required', 'string', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'base_url.url' => 'ที่อยู่ API ต้องเป็น URL ที่ขึ้นต้นด้วย https://',
            'base_url.starts_with' => 'ที่อยู่ API ต้องขึ้นต้นด้วย https://',
            'api_key.min' => 'กุญแจ API สั้นเกินไป',
            'api_key.regex' => 'กุญแจ API ต้องไม่มีช่องว่างหรืออักขระพิเศษ',
            'timeout_seconds.*' => 'เวลารอการตอบกลับต้องอยู่ระหว่าง 5-120 วินาที',
            'sync_time.regex' => 'เวลาซิงก์ต้องอยู่ในรูปแบบ HH:MM',
        ];
    }
}
