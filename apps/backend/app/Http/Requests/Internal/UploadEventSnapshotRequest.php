<?php

namespace App\Http\Requests\Internal;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Event face crop upload (EVT-025, ai_node.token auth). One JPEG file under
 * the configured byte limit. EventSnapshotService re-checks the real image
 * type and its dimensions (a face crop, never a full frame).
 */
class UploadEventSnapshotRequest extends FormRequest
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
        $maxKb = max(1, intdiv((int) config('event.snapshot.max_bytes', 262144), 1024));

        return [
            'image' => ['required', 'file', 'mimetypes:image/jpeg', 'max:'.$maxKb],
        ];
    }
}
