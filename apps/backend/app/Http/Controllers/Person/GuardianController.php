<?php

namespace App\Http\Controllers\Person;

use App\Enums\AuditAction;
use App\Enums\GuardianRelation;
use App\Enums\PersonType;
use App\Http\Controllers\Controller;
use App\Models\GuardianLink;
use App\Models\Person;
use App\Models\StudentProfile;
use App\Services\Audit\AuditRequestContext;
use App\Services\Audit\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Guardian lookup and student link operations (PPL-005). */
class GuardianController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    /** Register a new standalone parent identity. */
    public function storeGuardian(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'person_code' => ['nullable', 'string', 'max:255', 'unique:persons,person_code'],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'display_name' => ['nullable', 'string', 'max:255'],
            'gender' => ['nullable', 'string', 'max:50'],
        ]);

        $guardian = DB::transaction(function () use ($request, $validated) {
            $guardian = Person::create($validated + ['person_type' => PersonType::PARENT, 'status' => 'ACTIVE']);
            $this->audit->record(
                AuditAction::GUARDIAN_CREATED, $request->user(), 'Person', $guardian->uuid,
                null, ['person_type' => PersonType::PARENT->value, 'person_code' => $guardian->person_code],
                null, AuditRequestContext::fromRequest($request), 'people'
            );

            return $guardian;
        });

        return response()->json(['success' => true, 'message' => 'Guardian created', 'data' => $this->personData($guardian), 'meta' => null], 201);
    }

    public function showGuardian(Person $guardian): JsonResponse
    {
        $this->ensureParent($guardian);

        return response()->json(['success' => true, 'message' => 'Success', 'data' => $this->personData($guardian), 'meta' => null]);
    }

    public function updateGuardian(Request $request, Person $guardian): JsonResponse
    {
        $this->ensureParent($guardian);
        $validated = $request->validate([
            'person_code' => ['sometimes', 'nullable', 'string', 'max:255', Rule::unique('persons', 'person_code')->ignore($guardian->id)],
            'first_name' => ['sometimes', 'required', 'string', 'max:255'],
            'last_name' => ['sometimes', 'required', 'string', 'max:255'],
            'display_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'gender' => ['sometimes', 'nullable', 'string', 'max:50'],
        ]);
        if ($validated === []) {
            throw ValidationException::withMessages(['guardian' => ['At least one field is required.']]);
        }

        $old = array_intersect_key($guardian->only(['person_code', 'first_name', 'last_name', 'display_name', 'gender']), $validated);
        $updated = DB::transaction(function () use ($request, $guardian, $validated, $old) {
            $guardian->fill($validated);
            $guardian->save();
            $this->audit->record(
                AuditAction::GUARDIAN_UPDATED, $request->user(), 'Person', $guardian->uuid,
                $old, array_intersect_key($guardian->only(array_keys($validated)), $validated),
                null, AuditRequestContext::fromRequest($request), 'people'
            );

            return $guardian;
        });

        return response()->json(['success' => true, 'message' => 'Guardian updated', 'data' => $this->personData($updated), 'meta' => null]);
    }

    private function ensureParent(Person $person): void
    {
        abort_unless($person->person_type === PersonType::PARENT, 404);
    }

    /** Search only eligible guardian identities and return minimal fields. */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'keyword' => ['sometimes', 'string', 'max:255'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Person::query()->whereIn('person_type', [PersonType::PARENT, PersonType::TEACHER, PersonType::STAFF]);
        if (! empty($validated['keyword'])) {
            $keyword = '%'.mb_strtolower($validated['keyword']).'%';
            $query->where(fn ($q) => $q->whereRaw('LOWER(first_name) LIKE ?', [$keyword])
                ->orWhereRaw('LOWER(last_name) LIKE ?', [$keyword])
                ->orWhereRaw('LOWER(COALESCE(display_name, \'\')) LIKE ?', [$keyword])
                ->orWhereRaw('LOWER(COALESCE(person_code, \'\')) LIKE ?', [$keyword]));
        }
        $page = $query->orderByRaw('LOWER(COALESCE(display_name, first_name || \' \' || last_name)) ASC')
            ->orderBy('id')->paginate((int) ($validated['per_page'] ?? 20));

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => $page->getCollection()->map(fn (Person $person) => $this->personData($person)),
            'meta' => ['pagination' => [
                'current_page' => $page->currentPage(), 'per_page' => $page->perPage(),
                'total' => $page->total(), 'last_page' => $page->lastPage(),
            ]],
        ]);
    }

    public function forStudent(StudentProfile $student): JsonResponse
    {
        $links = GuardianLink::query()->with('guardian')
            ->where('student_person_id', $student->person_id)->orderBy('id')->get();

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => $links->map(fn (GuardianLink $link) => [
                'uuid' => $link->uuid,
                'relation' => $link->relation->value,
                'guardian' => $this->personData($link->guardian),
                'created_at' => $link->created_at?->toIso8601String(),
            ]),
            'meta' => null,
        ]);
    }

    public function store(Request $request, StudentProfile $student): JsonResponse
    {
        $validated = $request->validate([
            'guardian_person_uuid' => ['required', 'uuid', 'exists:persons,uuid'],
            'relation' => ['required', Rule::enum(GuardianRelation::class)],
        ]);

        $guardian = Person::query()->where('uuid', $validated['guardian_person_uuid'])->firstOrFail();
        if (! in_array($guardian->person_type, [PersonType::PARENT, PersonType::TEACHER, PersonType::STAFF], true)) {
            return response()->json(['success' => false, 'message' => 'Person is not eligible to be a guardian', 'code' => 'GUARDIAN_TYPE_INVALID'], 422);
        }
        if ($guardian->id === $student->person_id) {
            return response()->json(['success' => false, 'message' => 'A student cannot be their own guardian', 'code' => 'GUARDIAN_SELF_LINK_INVALID'], 422);
        }
        if (GuardianLink::query()->where('guardian_person_id', $guardian->id)->where('student_person_id', $student->person_id)->exists()) {
            return response()->json(['success' => false, 'message' => 'Guardian link already exists', 'code' => 'GUARDIAN_LINK_EXISTS'], 409);
        }

        $link = DB::transaction(function () use ($request, $student, $guardian, $validated) {
            $link = GuardianLink::create([
                'guardian_person_id' => $guardian->id,
                'student_person_id' => $student->person_id,
                'relation' => $validated['relation'],
                'created_by_user_id' => $request->user()->id,
            ]);
            $this->audit->record(
                AuditAction::GUARDIAN_LINK_CREATED, $request->user(), 'GuardianLink', $link->uuid,
                null,
                ['student_uuid' => $student->uuid, 'guardian_uuid' => $guardian->uuid, 'relation' => $validated['relation']],
                null, AuditRequestContext::fromRequest($request), 'people'
            );

            return $link;
        });
        $link->load('guardian');

        return response()->json([
            'success' => true, 'message' => 'Guardian linked',
            'data' => ['uuid' => $link->uuid, 'relation' => $link->relation->value, 'guardian' => $this->personData($link->guardian), 'created_at' => $link->created_at?->toIso8601String()],
            'meta' => null,
        ], 201);
    }

    public function destroy(Request $request, GuardianLink $guardianLink): JsonResponse
    {
        DB::transaction(function () use ($request, $guardianLink) {
            $guardianLink->load(['guardian', 'student']);
            $this->audit->record(
                AuditAction::GUARDIAN_LINK_DELETED, $request->user(), 'GuardianLink', $guardianLink->uuid,
                ['student_uuid' => $guardianLink->student->uuid, 'guardian_uuid' => $guardianLink->guardian->uuid, 'relation' => $guardianLink->relation->value],
                null, null, AuditRequestContext::fromRequest($request), 'people'
            );
            $guardianLink->delete();
        });

        return response()->json(['success' => true, 'message' => 'Guardian link removed', 'data' => null, 'meta' => null]);
    }

    /** @return array<string, mixed> */
    private function personData(Person $person): array
    {
        return [
            'uuid' => $person->uuid, 'person_code' => $person->person_code,
            'first_name' => $person->first_name, 'last_name' => $person->last_name,
            'display_name' => $person->display_name, 'name' => $person->displayLabel(),
            'person_type' => $person->person_type?->value ?? $person->person_type,
            'status' => $person->status?->value ?? $person->status,
        ];
    }
}
