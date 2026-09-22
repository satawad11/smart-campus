<?php

namespace App\Http\Requests\Internal;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Internal candidate-search + classification request (CAM-009).
 *
 * The AI node has already run detection/alignment/quality/embedding LOCALLY
 * (Module 03 pipeline, reused as-is — never duplicated here) and presents
 * only the resulting normalized vector. This endpoint performs the part
 * only the backend can do (pgvector search + canonical policy
 * classification) and returns MATCH/REVIEW/UNKNOWN — never an access
 * decision, never a raw vector back, never an internal id.
 */
class RecognizeEmbeddingRequest extends FormRequest
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
            'embedding' => ['required', 'array', 'min:2'],
            'embedding.*' => ['numeric'],
            'model_code' => ['required', 'string', 'max:255'],
            'model_version' => ['required', 'string', 'max:100'],
            'top_k' => ['sometimes', 'integer', 'min:1', 'max:50'],

            // Access decisions and internal identifiers are never client input.
            'access_decision' => ['prohibited'],
            'person_id' => ['prohibited'],
            'face_profile_id' => ['prohibited'],
        ];
    }
}
