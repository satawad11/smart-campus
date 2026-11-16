<?php

namespace App\Http\Controllers\Staff;

use App\Enums\StaffEmploymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\ChangeStaffStatusRequest;
use App\Http\Requests\Staff\StoreStaffRequest;
use App\Http\Requests\Staff\UpdateStaffRequest;
use App\Http\Resources\StaffResource;
use App\Http\Resources\StaffStatusHistoryResource;
use App\Models\Department;
use App\Models\StaffProfile;
use App\Services\Audit\AuditRequestContext;
use App\Services\Staff\StaffService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Teacher/staff API (Module 09, PPL-003). Thin HTTP layer over StaffService;
 * {staff} binds by StaffProfile uuid. Permissions: staff.view / staff.create /
 * staff.update / staff.status.change (route middleware). No DELETE.
 */
class StaffController extends Controller
{
    private const SORTABLE = ['staff_code', 'employment_status', 'position', 'created_at', 'updated_at'];

    private const RELATIONS = ['person', 'department'];

    public function __construct(
        private readonly StaffService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'keyword' => ['sometimes', 'string', 'max:255'],
            'employment_status' => ['sometimes', 'string', 'in:'.implode(',', array_column(StaffEmploymentStatus::cases(), 'value'))],
            'person_type' => ['sometimes', 'string', 'in:TEACHER,STAFF'],
            'department_uuid' => ['bail', 'sometimes', 'string', 'uuid', 'exists:departments,uuid'],
            'sort' => ['sometimes', 'string', 'in:'.implode(',', self::SORTABLE)],
            'order' => ['sometimes', 'string', 'in:asc,desc'],
        ]);

        $query = StaffProfile::query()->with(self::RELATIONS);

        if (! empty($validated['keyword'])) {
            $keyword = '%'.mb_strtolower($validated['keyword']).'%';
            $query->where(function ($q) use ($keyword) {
                $q->whereRaw('LOWER(staff_code) LIKE ?', [$keyword])
                    ->orWhereRaw('LOWER(COALESCE(position, \'\')) LIKE ?', [$keyword])
                    ->orWhereHas('person', function ($p) use ($keyword) {
                        $p->whereRaw('LOWER(first_name) LIKE ?', [$keyword])
                            ->orWhereRaw('LOWER(last_name) LIKE ?', [$keyword])
                            ->orWhereRaw('LOWER(COALESCE(display_name, \'\')) LIKE ?', [$keyword]);
                    });
            });
        }

        if (! empty($validated['employment_status'])) {
            $query->where('employment_status', $validated['employment_status']);
        }

        if (! empty($validated['person_type'])) {
            $query->whereHas('person', fn ($p) => $p->where('person_type', $validated['person_type']));
        }

        if (! empty($validated['department_uuid'])) {
            $query->where('department_id', Department::where('uuid', $validated['department_uuid'])->value('id'));
        }

        $paginator = $query
            ->orderBy($validated['sort'] ?? 'created_at', $validated['order'] ?? 'desc')
            ->paginate((int) ($validated['per_page'] ?? 20));

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => StaffResource::collection($paginator->items()),
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

    public function store(StoreStaffRequest $request): JsonResponse
    {
        $profile = $this->service->createStaff(
            $request->validated(),
            $request->user(),
            AuditRequestContext::fromRequest($request),
        );

        return $this->respond($profile, 'Staff created', 201);
    }

    public function show(StaffProfile $staff): JsonResponse
    {
        return $this->respond($staff, 'Success');
    }

    public function update(UpdateStaffRequest $request, StaffProfile $staff): JsonResponse
    {
        $updated = $this->service->updateStaff(
            $staff,
            $request->validated(),
            $request->user(),
            AuditRequestContext::fromRequest($request),
        );

        return $this->respond($updated, 'Staff updated');
    }

    public function changeStatus(ChangeStaffStatusRequest $request, StaffProfile $staff): JsonResponse
    {
        $updated = $this->service->changeEmploymentStatus(
            $staff,
            StaffEmploymentStatus::from($request->validated('status')),
            $request->validated('reason'),
            $request->user(),
            AuditRequestContext::fromRequest($request),
        );

        return $this->respond($updated, 'Staff status updated');
    }

    public function statusHistory(Request $request, StaffProfile $staff): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = $staff->statusHistories()
            ->with('changedBy')
            ->orderByDesc('id')
            ->paginate((int) ($validated['per_page'] ?? 10));

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => StaffStatusHistoryResource::collection($paginator->items()),
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

    private function respond(StaffProfile $profile, string $message, int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => new StaffResource($profile->load(self::RELATIONS)),
            'meta' => null,
        ], $status);
    }
}
