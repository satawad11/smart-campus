<?php

namespace App\Http\Controllers\Camera;

use App\Enums\DeviceStatus;
use App\Http\Controllers\Concerns\EnforcesScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\AiNode\StoreAiNodeRequest;
use App\Http\Requests\AiNode\UpdateAiNodeRequest;
use App\Http\Requests\AiNode\UpdateAiNodeStatusRequest;
use App\Http\Resources\AiNodeHealthLogResource;
use App\Http\Resources\AiNodeResource;
use App\Models\AiNode;
use App\Models\Site;
use App\Services\Audit\AuditRequestContext;
use App\Services\Camera\AiNodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AI Node administrative CRUD (CAM-003). Thin controller over
 * AiNodeService. Public identity is the AiNode uuid. Authorization via
 * ai_node.view / ai_node.manage. The node-facing check-in/heartbeat
 * endpoints live separately under /internal/v1 (AiNodeInternalController).
 */
class AiNodeController extends Controller
{
    use EnforcesScope;

    private const SORTABLE = ['node_code', 'name', 'status', 'last_seen_at', 'created_at', 'updated_at'];

    public function __construct(
        private readonly AiNodeService $service,
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
            'sort' => ['sometimes', 'string', 'in:'.implode(',', self::SORTABLE)],
            'order' => ['sometimes', 'string', 'in:asc,desc'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 20);
        $sort = $validated['sort'] ?? 'created_at';
        $order = $validated['order'] ?? 'desc';

        $query = $this->scopeQuery(AiNode::query(), $request, 'ai_node.view', 'site_id', null)->with('site');

        if (! empty($validated['keyword'])) {
            $keyword = '%'.mb_strtolower($validated['keyword']).'%';
            $query->where(function ($q) use ($keyword) {
                $q->whereRaw('LOWER(node_code) LIKE ?', [$keyword])
                    ->orWhereRaw('LOWER(name) LIKE ?', [$keyword]);
            });
        }

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['site_uuid'])) {
            $siteId = Site::where('uuid', $validated['site_uuid'])->value('id');
            $query->where('site_id', $siteId);
        }

        $paginator = $query->orderBy($sort, $order)->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => AiNodeResource::collection($paginator->items()),
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

    public function store(StoreAiNodeRequest $request): JsonResponse
    {
        $this->assertInScope($request, 'ai_node.manage', $request->validated('site_uuid') ? Site::where('uuid', $request->validated('site_uuid'))->value('id') : null);
        $node = $this->service->registerNode(
            $request->validated(),
            $request->user(),
            AuditRequestContext::fromRequest($request),
        );

        return response()->json([
            'success' => true,
            'message' => 'AI node registered',
            'data' => new AiNodeResource($node->load('site')),
            'meta' => null,
        ], 201);
    }

    public function show(Request $request, AiNode $aiNode): JsonResponse
    {
        $this->assertInScope($request, 'ai_node.view', $aiNode->site_id);

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => new AiNodeResource($aiNode->load('site')),
            'meta' => null,
        ]);
    }

    public function update(UpdateAiNodeRequest $request, AiNode $aiNode): JsonResponse
    {
        $this->assertInScope($request, 'ai_node.manage', $aiNode->site_id);
        if ($request->filled('site_uuid')) {
            $this->assertInScope($request, 'ai_node.manage', Site::where('uuid', $request->input('site_uuid'))->value('id'));
        }
        $updated = $this->service->updateNode(
            $aiNode,
            $request->validated(),
            $request->user(),
            AuditRequestContext::fromRequest($request),
        );

        return response()->json([
            'success' => true,
            'message' => 'AI node updated',
            'data' => new AiNodeResource($updated->load('site')),
            'meta' => null,
        ]);
    }

    public function updateStatus(UpdateAiNodeStatusRequest $request, AiNode $aiNode): JsonResponse
    {
        $this->assertInScope($request, 'ai_node.manage', $aiNode->site_id);
        $updated = $this->service->changeStatus(
            $aiNode,
            DeviceStatus::from($request->validated('status')),
            $request->user(),
            AuditRequestContext::fromRequest($request),
        );

        return response()->json([
            'success' => true,
            'message' => 'AI node status updated',
            'data' => new AiNodeResource($updated->load('site')),
            'meta' => null,
        ]);
    }

    public function health(AiNode $aiNode, Request $request): JsonResponse
    {
        $this->assertInScope($request, 'ai_node.view', $aiNode->site_id);
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 20);

        $paginator = $aiNode->healthLogs()->orderBy('recorded_at', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => AiNodeHealthLogResource::collection($paginator->items()),
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
