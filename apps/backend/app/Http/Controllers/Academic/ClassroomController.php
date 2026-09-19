<?php

namespace App\Http\Controllers\Academic;

use App\Enums\AcademicStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Academic\StoreClassroomRequest;
use App\Http\Requests\Academic\UpdateClassroomRequest;
use App\Http\Resources\ClassroomResource;
use App\Models\Classroom;
use App\Models\Department;
use App\Models\Program;
use App\Services\Academic\AcademicMasterService;
use App\Services\Audit\AuditRequestContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Academic classroom master-data API (STD-005).
 *
 * Thin controller over AcademicMasterService. Public identity is the UUID;
 * department/program are addressed by uuid and resolved server-side, where
 * cross-entity consistency (program belongs to department) is enforced.
 * Authorization via academic.view / academic.manage.
 */
class ClassroomController extends Controller
{
    private const SORTABLE = ['code', 'name', 'academic_year', 'year_level', 'status', 'created_at', 'updated_at'];

    public function __construct(
        private readonly AcademicMasterService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'keyword' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', 'string', 'in:'.implode(',', array_column(AcademicStatus::cases(), 'value'))],
            'academic_year' => ['sometimes', 'string', 'max:255'],
            'year_level' => ['sometimes', 'integer', 'min:1', 'max:65535'],
            'department_uuid' => ['sometimes', 'string', 'exists:departments,uuid'],
            'program_uuid' => ['sometimes', 'string', 'exists:programs,uuid'],
            'sort' => ['sometimes', 'string', 'in:'.implode(',', self::SORTABLE)],
            'order' => ['sometimes', 'string', 'in:asc,desc'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 20);
        $sort = $validated['sort'] ?? 'created_at';
        $order = $validated['order'] ?? 'desc';

        $query = Classroom::query()->with(['department', 'program']);

        if (! empty($validated['keyword'])) {
            $keyword = '%'.mb_strtolower($validated['keyword']).'%';
            $query->where(function ($q) use ($keyword) {
                $q->whereRaw('LOWER(code) LIKE ?', [$keyword])
                    ->orWhereRaw('LOWER(name) LIKE ?', [$keyword]);
            });
        }

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['academic_year'])) {
            $query->where('academic_year', $validated['academic_year']);
        }

        if (! empty($validated['year_level'])) {
            $query->where('year_level', $validated['year_level']);
        }

        if (! empty($validated['department_uuid'])) {
            $departmentId = Department::where('uuid', $validated['department_uuid'])->value('id');
            $query->where('department_id', $departmentId);
        }

        if (! empty($validated['program_uuid'])) {
            $programId = Program::where('uuid', $validated['program_uuid'])->value('id');
            $query->where('program_id', $programId);
        }

        $paginator = $query->orderBy($sort, $order)->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => ClassroomResource::collection($paginator->items()),
            'meta' => [
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
            ],
        ]);
    }

    public function store(StoreClassroomRequest $request): JsonResponse
    {
        $classroom = $this->service->createClassroom(
            $request->validated(),
            $request->user(),
            AuditRequestContext::fromRequest($request),
        );

        return response()->json([
            'success' => true,
            'message' => 'Classroom created',
            'data' => new ClassroomResource($classroom->load(['department', 'program'])),
            'meta' => null,
        ], 201);
    }

    public function show(Classroom $classroom): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => new ClassroomResource($classroom->load(['department', 'program'])),
            'meta' => null,
        ]);
    }

    public function update(UpdateClassroomRequest $request, Classroom $classroom): JsonResponse
    {
        $validated = $request->validated();

        $updated = $this->service->updateClassroom(
            $classroom,
            $validated,
            $request->user(),
            AuditRequestContext::fromRequest($request),
            programProvided: array_key_exists('program_uuid', $validated),
        );

        return response()->json([
            'success' => true,
            'message' => 'Classroom updated',
            'data' => new ClassroomResource($updated->load(['department', 'program'])),
            'meta' => null,
        ]);
    }
}
