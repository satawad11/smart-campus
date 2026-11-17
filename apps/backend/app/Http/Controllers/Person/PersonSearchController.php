<?php

namespace App\Http\Controllers\Person;

use App\Enums\PersonType;
use App\Http\Controllers\Controller;
use App\Http\Resources\PersonSearchResource;
use App\Models\Person;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Safe search endpoint for face enrollment and authorized person pickers (PPL-007). */
class PersonSearchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'keyword' => ['sometimes', 'string', 'max:255'],
            'person_type' => ['sometimes', 'string', 'in:'.implode(',', array_column(PersonType::cases(), 'value'))],
        ]);

        $query = Person::query()->with(['studentProfile', 'staffProfile', 'faceProfile']);

        if (! empty($validated['keyword'])) {
            $keyword = '%'.mb_strtolower($validated['keyword']).'%';
            $query->where(function ($q) use ($keyword) {
                $q->whereRaw('LOWER(COALESCE(person_code, \'\')) LIKE ?', [$keyword])
                    ->orWhereRaw('LOWER(first_name) LIKE ?', [$keyword])
                    ->orWhereRaw('LOWER(last_name) LIKE ?', [$keyword])
                    ->orWhereRaw('LOWER(COALESCE(display_name, \'\')) LIKE ?', [$keyword])
                    ->orWhereHas('studentProfile', fn ($student) => $student->whereRaw('LOWER(student_code) LIKE ?', [$keyword]))
                    ->orWhereHas('staffProfile', fn ($staff) => $staff->whereRaw('LOWER(staff_code) LIKE ?', [$keyword]));
            });
        }

        if (! empty($validated['person_type'])) {
            $query->where('person_type', $validated['person_type']);
        }

        $paginator = $query
            ->orderByRaw('LOWER(COALESCE(display_name, first_name || \' \' || last_name)) ASC')
            ->orderBy('id')
            ->paginate((int) ($validated['per_page'] ?? 20));

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => PersonSearchResource::collection($paginator->items()),
            'meta' => ['pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ]],
        ]);
    }
}
