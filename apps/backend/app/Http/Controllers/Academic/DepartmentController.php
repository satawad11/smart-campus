<?php

namespace App\Http\Controllers\Academic;

use App\Enums\AcademicStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Academic\StoreDepartmentRequest;
use App\Http\Requests\Academic\UpdateDepartmentRequest;
use App\Http\Resources\DepartmentResource;
use App\Models\Department;
use App\Services\Academic\AcademicMasterService;
use App\Services\Audit\AuditRequestContext;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Academic department master-data API (STD-005).
 *
 * Thin controller: domain logic (UUID resolution, lifecycle, audit,
 * transactions) lives in AcademicMasterService. Public identity is the UUID;
 * numeric ids are never exposed or accepted. Authorization is enforced by the
 * academic.view / academic.manage permission middleware.
 */
class DepartmentController extends Controller
{
    private const SORTABLE = ['code', 'name', 'status', 'created_at', 'updated_at'];

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
            'sort' => ['sometimes', 'string', 'in:'.implode(',', self::SORTABLE)],
            'order' => ['sometimes', 'string', 'in:asc,desc'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 20);
        $sort = $validated['sort'] ?? 'created_at';
        $order = $validated['order'] ?? 'desc';

        $query = Department::query();

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

        $paginator = $query->orderBy($sort, $order)->paginate($perPage);

        return $this->paginatedResponse(DepartmentResource::collection($paginator->items()), $paginator);
    }

    public function store(StoreDepartmentRequest $request): JsonResponse
    {
        $department = $this->service->createDepartment(
            $request->validated(),
            $request->user(),
            AuditRequestContext::fromRequest($request),
        );

        return response()->json([
            'success' => true,
            'message' => 'Department created',
            'data' => new DepartmentResource($department),
            'meta' => null,
        ], 201);
    }

    public function show(Department $department): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => new DepartmentResource($department),
            'meta' => null,
        ]);
    }

    public function update(UpdateDepartmentRequest $request, Department $department): JsonResponse
    {
        $updated = $this->service->updateDepartment(
            $department,
            $request->validated(),
            $request->user(),
            AuditRequestContext::fromRequest($request),
        );

        return response()->json([
            'success' => true,
            'message' => 'Department updated',
            'data' => new DepartmentResource($updated),
            'meta' => null,
        ]);
    }

    /**
     * @param  Arrayable<int, mixed>  $data
     * @param  LengthAwarePaginator<int, Model>  $paginator
     */
    private function paginatedResponse($data, $paginator): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => $data,
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
}
