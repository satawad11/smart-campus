<?php

namespace App\Http\Controllers\Camera;

use App\Enums\AiNodeCommandType;
use App\Http\Controllers\Concerns\EnforcesScope;
use App\Http\Controllers\Controller;
use App\Http\Resources\AiNodeCommandResource;
use App\Models\AiNode;
use App\Models\AiNodeCommand;
use App\Services\Audit\AuditRequestContext;
use App\Services\Camera\AiNodeCommandService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin side of remote node operations (COM-03). Human session only; the
 * command must be one of AiNodeCommandType (no free-form text).
 */
class AiNodeCommandController extends Controller
{
    use EnforcesScope;

    public function __construct(
        private readonly AiNodeCommandService $service,
    ) {}

    public function index(Request $request, AiNode $aiNode): JsonResponse
    {
        $this->assertInScope($request, 'ai_node.view', $aiNode->site_id);
        $this->service->expireStale($aiNode);

        $commands = AiNodeCommand::with('requestedBy')
            ->where('ai_node_id', $aiNode->id)
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => AiNodeCommandResource::collection($commands),
            'meta' => null,
        ]);
    }

    public function store(Request $request, AiNode $aiNode): JsonResponse
    {
        $this->assertInScope($request, 'ai_node.manage', $aiNode->site_id);
        $validated = $request->validate([
            'command' => ['required', 'string', Rule::enum(AiNodeCommandType::class)],
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $command = $this->service->request(
            $aiNode,
            AiNodeCommandType::from($validated['command']),
            $validated['reason'] ?? null,
            $request->user(),
            AuditRequestContext::fromRequest($request),
        );

        return response()->json([
            'success' => true,
            'message' => 'Command queued for the node',
            'data' => new AiNodeCommandResource($command->load('requestedBy')),
            'meta' => null,
        ], 201);
    }
}
