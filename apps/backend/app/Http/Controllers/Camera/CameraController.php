<?php

namespace App\Http\Controllers\Camera;

use App\Enums\DeviceStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
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
            'site_uuid' => ['sometimes', 'string', 'exists:sites,uuid'],
            'zone_uuid' => ['sometimes', 'string', 'exists:zones,uuid'],
            'ai_node_uuid' => ['sometimes', 'string', 'exists:ai_nodes,uuid'],
            'sort' => ['sometimes', 'string', 'in:'.implode(',', self::SORTABLE)],
            'order' => ['sometimes', 'string', 'in:asc,desc'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 20);
        $sort = $validated['sort'] ?? 'created_at';
        $order = $validated['order'] ?? 'desc';

        $query = Camera::query()->with(['site', 'zone', 'aiNode']);

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

    public function store(StoreCameraRequest $request): JsonResponse
    {
        $camera = $this->service->createCamera(
            $request->validated(),
            $request->user(),
            AuditRequestContext::fromRequest($request),
        );

        return response()->json([
            'success' => true,
            'message' => 'Camera created',
            'data' => new CameraResource($camera->load(['site', 'zone', 'aiNode'])),
            'meta' => null,
        ], 201);
    }

    public function show(Camera $camera): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => new CameraResource($camera->load(['site', 'zone', 'aiNode'])),
            'meta' => null,
        ]);
    }

    public function update(UpdateCameraRequest $request, Camera $camera): JsonResponse
    {
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
