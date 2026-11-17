<?php

namespace App\Http\Controllers\Gate;

use App\Enums\LocationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Gate\ReplaceGateCamerasRequest;
use App\Http\Requests\Gate\StoreGateRequest;
use App\Http\Requests\Gate\UpdateGateRequest;
use App\Http\Resources\GateResource;
use App\Models\Camera;
use App\Models\Gate;
use App\Models\Site;
use App\Services\Audit\AuditRequestContext;
use App\Services\Gate\GateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Smart Gate configuration API (GATE-002, ACR-VEH-01 Phase 5).
 *
 * Behind feature:vehicle (off by default) and gate.view / gate.manage. UUID public identity only.
 * No DELETE: a gate is deactivated (status INACTIVE). Nothing here opens a barrier (D5).
 */
class GateController extends Controller
{
    public function __construct(
        private readonly GateService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'keyword' => ['sometimes', 'string', 'max:255'],
            'site_uuid' => ['sometimes', 'uuid'],
            'zone_uuid' => ['sometimes', 'uuid'],
            'status' => ['sometimes', Rule::enum(LocationStatus::class)],
        ]);

        $query = Gate::query()->with(['site', 'zone'])->withCount('gateCameras');

        if (isset($validated['site_uuid'])) {
            $query->whereHas('site', fn ($q) => $q->where('uuid', $validated['site_uuid']));
        }
        if (isset($validated['zone_uuid'])) {
            $query->whereHas('zone', fn ($q) => $q->where('uuid', $validated['zone_uuid']));
        }
        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (! empty($validated['keyword'])) {
            $keyword = '%'.mb_strtolower($validated['keyword']).'%';
            $query->where(fn ($q) => $q->whereRaw('LOWER(code) LIKE ?', [$keyword])->orWhereRaw('LOWER(name) LIKE ?', [$keyword]));
        }

        $paginator = $query->orderBy('name')->paginate((int) ($validated['per_page'] ?? 20));

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => GateResource::collection($paginator->items()),
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

    /**
     * Sites with their zones and cameras, for the gate form. Each camera says which gate (if any)
     * it already serves, because a camera serves at most one gate.
     */
    public function formOptions(): JsonResponse
    {
        $sites = Site::query()->where('status', 'ACTIVE')->orderBy('name')->with(['zones' => fn ($q) => $q->orderBy('name')])->get();
        $cameras = Camera::query()->with(['gateCamera.gate'])->orderBy('name')->get();

        $data = $sites->map(fn (Site $site) => [
            'uuid' => $site->uuid,
            'name' => $site->name,
            'zones' => $site->zones->map(fn ($zone) => ['uuid' => $zone->uuid, 'name' => $zone->name])->values()->all(),
            'cameras' => $cameras->where('site_id', $site->id)->map(fn (Camera $camera) => [
                'uuid' => $camera->uuid,
                'name' => $camera->name,
                'camera_code' => $camera->camera_code,
                'status' => $camera->status?->value,
                'face_recognition_enabled' => (bool) $camera->face_recognition_enabled,
                'plate_recognition_enabled' => (bool) $camera->plate_recognition_enabled,
                'gate_uuid' => $camera->gateCamera?->gate?->uuid,
                'gate_name' => $camera->gateCamera?->gate?->name,
            ])->values()->all(),
        ])->values()->all();

        return $this->respond($data);
    }

    public function store(StoreGateRequest $request): JsonResponse
    {
        $gate = $this->service->create($request->validated(), $request->user(), AuditRequestContext::fromRequest($request));

        return $this->respond(new GateResource($gate), 'Gate created', 201);
    }

    public function show(Gate $gate): JsonResponse
    {
        return $this->respond(new GateResource($this->service->loadDetail($gate)));
    }

    public function update(UpdateGateRequest $request, Gate $gate): JsonResponse
    {
        $gate = $this->service->update($gate, $request->validated(), $request->user(), AuditRequestContext::fromRequest($request));

        return $this->respond(new GateResource($gate), 'Gate updated');
    }

    public function replaceCameras(ReplaceGateCamerasRequest $request, Gate $gate): JsonResponse
    {
        $gate = $this->service->replaceCameras($gate, $request->validated()['cameras'], $request->user(), AuditRequestContext::fromRequest($request));

        return $this->respond(new GateResource($gate), 'Gate cameras updated');
    }

    private function respond(mixed $data, string $message = 'Success', int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data, 'meta' => null], $status);
    }
}
