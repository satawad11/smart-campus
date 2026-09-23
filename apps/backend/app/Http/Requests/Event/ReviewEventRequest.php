<?php

namespace App\Http\Requests\Event;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /events/{uuid}/review (EVT-010). A human verdict — never UNKNOWN,
 * which describes a recognition outcome, not something an officer decides.
 * reason_code is mandatory: a manual override must always be justified.
 */
class ReviewEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by the route's permission:event.review middleware
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', 'in:ALLOW,REVIEW,DENY'],
            'reason_code' => ['required', 'string', 'max:255'],
            'reason_text' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
