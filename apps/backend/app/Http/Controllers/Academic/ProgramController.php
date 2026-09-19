<?php

namespace App\Http\Controllers\Academic;

use App\Enums\AcademicStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Academic\StoreProgramRequest;
use App\Http\Requests\Academic\UpdateProgramRequest;
use App\Http\Resources\ProgramResource;
use App\Models\Department;
use App\Models\Program;
use App\Services\Academic\AcademicMasterService;
use App\Services\Audit\AuditRequestContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Academic program master-data API (STD-005).
 *
 * Thin controller over AcademicMasterService. Public identity is the UUID;
 * the owning department is addressed by department_uuid (resolved to the
 * internal id in the service). Authorization via academic.view / academic.manage.
 */
class ProgramController extends Controller
{
    private const SORTABLE = ['code', 'name', 'education_level', 'status', 'created_at', 'updated_at'];

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
            'education_level' => ['sometimes', 'string', 'in:VOC_CERT,HIGH_VOC_CERT,SHORT_COURSE,OTHER'],
            'department_uuid' => ['sometimes', 'string', 'exists:departments,uuid'],
            'sort' => ['sometimes', 'string', 'in:'.implode(',', self::SORTABLE)],
            'order' => ['sometimes', 'string', 'in:asc,desc'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 20);
        $sort = $validated['sort'] ?? 'created_at';
        $order = $validated['order'] ?? 'desc';

        $query = Program::query()->with('department');

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

        if (! empty($validated['education_level'])) {
            $query->where('education_level', $validated['education_level']);
        }

        if (! empty($validated['department_uuid'])) {
            $departmentId = Department::where('uuid', $validated['department_uuid'])->value('id');
            $query->where('department_id', $departmentId);
        }

        $paginator = $query->orderBy($sort, $order)->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => ProgramResource::collection($paginator->items()),
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

    public function store(StoreProgramRequest $request): JsonResponse
    {
        $program = $this->service->createProgram(
            $request->validated(),
            $request->user(),
            AuditRequestContext::fromRequest($request),
        );

        return response()->json([
            'success' => true,
            'message' => 'Program created',
            'data' => new ProgramResource($program->load('department')),
            'meta' => null,
        ], 201);
    }

    public function show(Program $program): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => new ProgramResource($program->load('department')),
            'meta' => null,
        ]);
    }

    public function update(UpdateProgramRequest $request, Program $program): JsonResponse
    {
        $updated = $this->service->updateProgram(
            $program,
            $request->validated(),
            $request->user(),
            AuditRequestContext::fromRequest($request),
        );

        return response()->json([
            'success' => true,
            'message' => 'Program updated',
            'data' => new ProgramResource($updated->load('department')),
            'meta' => null,
        ]);
    }
}
