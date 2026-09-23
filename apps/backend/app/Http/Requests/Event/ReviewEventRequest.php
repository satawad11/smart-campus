<?php

namespace App\Http\Requests\Event;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /events/{uuid}/review (EVT-010). A human verdict — never UNKNOWN,
 * which describes a recognition outcome, not something an officer decides.
 * reason_code is mandatory: a manual override must always be justified.
 *
 * expected_decision_uuid (EVT-011) is an optional optimistic-concurrency
 * token: the uuid of the `current_decision` the reviewer's client last saw
 * (from GET /events/{uuid}). If the event's decision has since moved on —
 * another reviewer got there first — the review is rejected with 409
 * instead of silently appending on top of a decision the reviewer never
 * saw. Omitted entirely, review proceeds unconditionally (back-compat with
 * EVT-010 clients and non-interactive callers).
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
            // 'bail' + 'uuid' before 'exists': PostgreSQL's uuid column rejects
            // a malformed string at the SQL level (invalid input syntax), so
            // format must be checked first or an invalid value 500s instead
            // of failing validation cleanly (SQLite's text-typed uuid column
            // never surfaced this).
            'expected_decision_uuid' => ['bail', 'sometimes', 'nullable', 'string', 'uuid', 'exists:access_decisions,uuid'],
        ];
    }
}
