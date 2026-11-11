<?php

namespace App\Http\Controllers\Camera;

use App\Enums\DeviceStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Concerns\EnforcesScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Camera\PreviewCameraStreamRequest;
use App\Http\Requests\Camera\StoreCameraRequest;
use App\Http\Requests\Camera\UpdateCameraRequest;
use App\Http\Requests\Camera\UpdateCameraRoiRequest;
use App\Http\Requests\Camera\UpdateCameraStatusRequest;
use App\Http\Resources\CameraHealthLogResource;
use App\Http\Resources\CameraResource;
use App\Models\AiNode;
use App\Models\Camera;
use App\Models\Site;
use App\Models\Zone;
use App\Services\Audit\AuditRequestContext;
use App\Services\Camera\CameraService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Camera CRUD / connection-test / snapshot API (CAM-002).
 *
 * Thin HTTP layer over CameraService: all credential encryption, cross-entity
 * consistency, and audit live in the service. Public identity is the Camera
 * uuid; no internal ids are accepted or exposed. Authorization is by
 * permission middleware (camera.view/create/update/enable/test/live).
 */
class CameraController extends Controller
{
    use EnforcesScope;

    private const SORTABLE = ['camera_code', 'name', 'status', 'created_at', 'updated_at'];

    public function __construct(
        private readonly CameraService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'keyword' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', 'string', 'in:'.implode(',', array_column(DeviceStatus::cases(), 'value'))],
            // 'bail' + 'uuid' before 'exists': PostgreSQL's uuid column rejects
            // a malformed string at the SQL level (invalid input syntax), so
            // format must be checked first or an invalid value 500s instead
            // of failing validation cleanly. See ReviewEventRequest.
            'site_uuid' => ['bail', 'sometimes', 'string', 'uuid', 'exists:sites,uuid'],
            'zone_uuid' => ['bail', 'sometimes', 'string', 'uuid', 'exists:zones,uuid'],
            'ai_node_uuid' => ['bail', 'sometimes', 'string', 'uuid', 'exists:ai_nodes,uuid'],
            'sort' => ['sometimes', 'string', 'in:'.implode(',', self::SORTABLE)],
            'order' => ['sometimes', 'string', 'in:asc,desc'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 20);
        $sort = $validated['sort'] ?? 'created_at';
        $order = $validated['order'] ?? 'desc';

        $query = $this->scopeQuery(Camera::query(), $request, 'camera.view')->with(['site', 'zone', 'aiNode']);

        if (! empty($validated['keyword'])) {
            $keyword = '%'.mb_strtolower($validated['keyword']).'%';
            $query->where(function ($q) use ($keyword) {
                $q->whereRaw('LOWER(camera_code) LIKE ?', [$keyword])
                    ->orWhereRaw('LOWER(name) LIKE ?', [$keyword]);
            });
        }

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        foreach ([
            'site_uuid' => [Site::class, 'site_id'],
            'zone_uuid' => [Zone::class, 'zone_id'],
            'ai_node_uuid' => [AiNode::class, 'ai_node_id'],
        ] as $param => [$model, $column]) {
            if (! empty($validated[$param])) {
                $id = $model::where('uuid', $validated[$param])->value('id');
                $query->where($column, $id);
            }
        }

        $paginator = $query->orderBy($sort, $order)->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => CameraResource::collection($paginator->items()),
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
     * Data for the add-camera form: the sites/zones the caller may place a
     * camera in, and the next suggested camera code. Read-only — defaults
     * are provisioned only when a camera is actually created.
     */
    public function formOptions(Request $request): JsonResponse
    {
        $zones = $this->scopeQuery(Zone::query(), $request, 'camera.create', 'site_id', 'id')
            ->where('status', 'ACTIVE')
            ->orderBy('name')
            ->get(['id', 'uuid', 'site_id', 'code', 'name']);

        $global = $this->scopes()->accessibleSiteIds($request->user(), 'camera.create') === null;
        $siteQuery = Site::query()->where('status', 'ACTIVE')->orderBy('id');
        if (! $global) {
            $siteQuery->whereIn('id', $zones->pluck('site_id')->unique()->all());
        }

        $sites = $siteQuery->get(['id', 'uuid', 'code', 'name'])->map(fn (Site $site) => [
            'uuid' => $site->uuid,
            'code' => $site->code,
            'name' => $site->name,
            'zones' => $zones->where('site_id', $site->id)->values()->map(fn (Zone $zone) => [
                'uuid' => $zone->uuid,
                'code' => $zone->code,
                'name' => $zone->name,
            ])->all(),
        ])->values();

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => [
                'sites' => $sites,
                'suggested_camera_code' => $this->service->nextCameraCode(),
                'can_provision_default_location' => $global,
            ],
            'meta' => null,
        ]);
    }

    /**
     * Grabs one live frame from a not-yet-saved stream (pre-save preview).
     */
    public function preview(PreviewCameraStreamRequest $request): JsonResponse
    {
        $result = $this->service->previewStream(
            $request->validated(),
            $request->user(),
            AuditRequestContext::fromRequest($request),
        );

        if (! $result->success) {
            throw ApiException::unprocessable($result->errorCode ?? 'CAMERA_SNAPSHOT_FAILED', 'Could not read a frame from the camera stream.');
        }

        return response()->json([
            'success' => true,
            'message' => 'Preview captured',
            'data' => [
                'content_type' => $result->contentType,
                'image_base64' => base64_encode($result->bytes),
                'source' => $result->source,
            ],
            'meta' => null,
        ]);
    }

    public function store(StoreCameraRequest $request): JsonResponse
    {
        $data = $request->validated();
        $context = AuditRequestContext::fromRequest($request);

        // Fill a blank site/zone from what was given, else from the defaults.
        if (empty($data['site_uuid']) && ! empty($data['zone_uuid'])) {
            $data['site_uuid'] = Zone::where('uuid', $data['zone_uuid'])->first()?->site?->uuid;
        } elseif (! empty($data['site_uuid']) && empty($data['zone_uuid'])) {
            $site = Site::where('uuid', $data['site_uuid'])->firstOrFail();
            $this->assertInScope($request, 'camera.create', $site->id);
            $data['zone_uuid'] = $this->service->defaultZoneFor($site, $request->user(), $context)->uuid;
        } elseif (empty($data['site_uuid']) && empty($data['zone_uuid'])) {
            [$site, $zone] = $this->service->defaultLocation(
                $request->user(),
                $context,
                $this->scopes()->accessibleSiteIds($request->user(), 'camera.create'),
            );
            $data['site_uuid'] = $site->uuid;
            $data['zone_uuid'] = $zone->uuid;
        }

        $this->assertInScope(
            $request,
            'camera.create',
            Site::where('uuid', $data['site_uuid'])->value('id'),
            Zone::where('uuid', $data['zone_uuid'])->value('id'),
        );
        $camera = $this->service->createCamera($data, $request->user(), $context);

        return response()->json([
            'success' => true,
            'message' => 'Camera created',
            'data' => new CameraResource($camera->load(['site', 'zone', 'aiNode'])),
            'meta' => null,
        ], 201);
    }

    public function show(Request $request, Camera $camera): JsonResponse
    {
        $this->assertInScope($request, 'camera.view', $camera->site_id, $camera->zone_id);

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => new CameraResource($camera->load(['site', 'zone', 'aiNode'])),
            'meta' => null,
        ]);
    }

    public function update(UpdateCameraRequest $request, Camera $camera): JsonResponse
    {
        $this->assertInScope($request, 'camera.update', $camera->site_id, $camera->zone_id);
        if ($request->has('site_uuid') || $request->has('zone_uuid')) {
            $this->assertInScope(
                $request,
                'camera.update',
                $request->has('site_uuid') ? Site::where('uuid', $request->input('site_uuid'))->value('id') : $camera->site_id,
                $request->has('zone_uuid') ? Zone::where('uuid', $request->input('zone_uuid'))->value('id') : $camera->zone_id,
            );
        }
        $updated = $this->service->updateCamera(
            $camera,
            $request->validated(),
            $request->user(),
            AuditRequestContext::fromRequest($request),
        );

        return response()->json([
            'success' => true,
            'message' => 'Camera updated',
            'data' => new CameraResource($updated->load(['site', 'zone', 'aiNode'])),
            'meta' => null,
        ]);
    }

    public function updateStatus(UpdateCameraStatusRequest $request, Camera $camera): JsonResponse
    {
        $this->assertInScope($request, 'camera.enable', $camera->site_id, $camera->zone_id);
        $updated = $this->service->changeStatus(
            $camera,
            DeviceStatus::from($request->validated('status')),
            $request->user(),
            AuditRequestContext::fromRequest($request),
        );

        return response()->json([
            'success' => true,
            'message' => 'Camera status updated',
            'data' => new CameraResource($updated->load(['site', 'zone', 'aiNode'])),
            'meta' => null,
        ]);
    }

    public function test(Camera $camera, Request $request): JsonResponse
    {
        $this->assertInScope($request, 'camera.test', $camera->site_id, $camera->zone_id);
        $result = $this->service->testConnection($camera, $request->user(), AuditRequestContext::fromRequest($request));

        return response()->json([
            'success' => true,
            'message' => $result->reachable ? 'Camera reachable' : 'Camera unreachable',
            'data' => $result->toArray(),
            'meta' => null,
        ]);
    }

    public function snapshot(Camera $camera, Request $request): JsonResponse
    {
        $this->assertInScope($request, 'camera.live', $camera->site_id, $camera->zone_id);
        $result = $this->service->captureSnapshot($camera, $request->user(), AuditRequestContext::fromRequest($request));

        if (! $result->success) {
            throw ApiException::unprocessable($result->errorCode ?? 'CAMERA_SNAPSHOT_FAILED', 'Snapshot capture failed.');
        }

        return response()->json([
            'success' => true,
            'message' => 'Snapshot captured',
            'data' => [
                'content_type' => $result->contentType,
                'image_base64' => base64_encode($result->bytes),
                'source' => $result->source,
            ],
            'meta' => null,
        ]);
    }

    public function updateRoi(UpdateCameraRoiRequest $request, Camera $camera): JsonResponse
    {
        $this->assertInScope($request, 'camera.update', $camera->site_id, $camera->zone_id);
        $updated = $this->service->updateRoi(
            $camera,
            $request->validated('points'),
            $request->user(),
            AuditRequestContext::fromRequest($request),
        );

        return response()->json([
            'success' => true,
            'message' => 'Camera ROI updated',
            'data' => new CameraResource($updated->load(['site', 'zone', 'aiNode'])),
            'meta' => null,
        ]);
    }

    public function health(Camera $camera, Request $request): JsonResponse
    {
        $this->assertInScope($request, 'camera.view', $camera->site_id, $camera->zone_id);
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 20);

        $paginator = $camera->healthLogs()->orderBy('recorded_at', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => CameraHealthLogResource::collection($paginator->items()),
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
