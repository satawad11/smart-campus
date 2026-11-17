<?php

namespace App\Http\Controllers\Person;

use App\Enums\IdDocumentType;
use App\Http\Controllers\Controller;
use App\Http\Resources\VisitorResource;
use App\Models\VisitorProfile;
use App\Services\Audit\AuditRequestContext;
use App\Services\People\VisitorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Visitor profile CRUD (PPL-006). */
class VisitorController extends Controller
{
    public function __construct(private readonly VisitorService $service) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'keyword' => ['sometimes', 'string', 'max:255'],
            'person_type' => ['sometimes', 'string', 'in:VISITOR,CONTRACTOR'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $query = VisitorProfile::query()->with('person');
        if (! empty($validated['person_type'])) {
            $query->whereHas('person', fn ($person) => $person->where('person_type', $validated['person_type']));
        }
        if (! empty($validated['keyword'])) {
            $keyword = '%'.mb_strtolower($validated['keyword']).'%';
            $query->where(function ($profile) use ($keyword) {
                $profile->whereRaw('LOWER(COALESCE(organization, \'\')) LIKE ?', [$keyword])
                    ->orWhereRaw('LOWER(COALESCE(phone, \'\')) LIKE ?', [$keyword])
                    ->orWhereHas('person', fn ($person) => $person->whereRaw('LOWER(first_name) LIKE ?', [$keyword])
                        ->orWhereRaw('LOWER(last_name) LIKE ?', [$keyword])
                        ->orWhereRaw('LOWER(COALESCE(display_name, \'\')) LIKE ?', [$keyword])
                        ->orWhereRaw('LOWER(COALESCE(person_code, \'\')) LIKE ?', [$keyword]));
            });
        }
        $page = $query->orderByDesc('id')->paginate((int) ($validated['per_page'] ?? 20));

        return response()->json([
            'success' => true, 'message' => 'Success',
            'data' => VisitorResource::collection($page->items()),
            'meta' => ['pagination' => [
                'current_page' => $page->currentPage(), 'per_page' => $page->perPage(),
                'total' => $page->total(), 'last_page' => $page->lastPage(),
            ]],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'person_code' => ['nullable', 'string', 'max:255', 'unique:persons,person_code'],
            'person_type' => ['sometimes', 'string', 'in:VISITOR,CONTRACTOR'],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'display_name' => ['nullable', 'string', 'max:255'],
            'gender' => ['nullable', 'string', 'max:50'],
            'organization' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'id_document_type' => ['nullable', Rule::enum(IdDocumentType::class)],
            'id_document_last4' => ['nullable', 'string', 'alpha_num', 'size:4'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        $profile = $this->service->create($data, $request->user(), AuditRequestContext::fromRequest($request));

        return response()->json(['success' => true, 'message' => 'Visitor created', 'data' => new VisitorResource($profile), 'meta' => null], 201);
    }

    public function show(VisitorProfile $visitor): JsonResponse
    {
        return response()->json(['success' => true, 'message' => 'Success', 'data' => new VisitorResource($visitor->load('person')), 'meta' => null]);
    }

    public function update(Request $request, VisitorProfile $visitor): JsonResponse
    {
        $person = $visitor->person;
        $data = $request->validate([
            'person_code' => ['sometimes', 'nullable', 'string', 'max:255', Rule::unique('persons', 'person_code')->ignore($person->id)],
            'first_name' => ['sometimes', 'required', 'string', 'max:255'],
            'last_name' => ['sometimes', 'required', 'string', 'max:255'],
            'display_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'gender' => ['sometimes', 'nullable', 'string', 'max:50'],
            'organization' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'id_document_type' => ['sometimes', 'nullable', Rule::enum(IdDocumentType::class)],
            'id_document_last4' => ['sometimes', 'nullable', 'string', 'alpha_num', 'size:4'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);
        abort_if($data === [], 422, 'At least one field is required.');
        $profile = $this->service->update($visitor, $data, $request->user(), AuditRequestContext::fromRequest($request));

        return response()->json(['success' => true, 'message' => 'Visitor updated', 'data' => new VisitorResource($profile), 'meta' => null]);
    }
}
