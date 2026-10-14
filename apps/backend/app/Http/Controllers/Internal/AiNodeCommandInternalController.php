<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\AiNode;
use App\Models\AiNodeCommand;
use App\Services\Camera\AiNodeCommandService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Node side of remote operations (COM-03), behind ai_node.token. The node
 * identity comes from its own credential, never from the request, so a
 * node can only see and answer its own commands.
 */
class AiNodeCommandInternalController extends Controller
{
    public function __construct(
        private readonly AiNodeCommandService $service,
    ) {}

    public function claim(Request $request): JsonResponse
    {
        $commands = $this->service->claim($this->node($request));

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => $commands->map(fn (AiNodeCommand $c) => [
                'uuid' => $c->uuid,
                'command' => $c->command->value,
                'expires_at' => $c->expires_at->toIso8601String(),
            ])->values(),
            'meta' => null,
        ]);
    }

    public function result(Request $request, string $commandUuid): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:SUCCEEDED,FAILED'],
            'message' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $command = $this->service->reportResult(
            $this->node($request),
            $commandUuid,
            $validated['status'] === 'SUCCEEDED',
            $validated['message'] ?? null,
        );

        return response()->json([
            'success' => true,
            'message' => 'Result recorded',
            'data' => ['uuid' => $command->uuid, 'status' => $command->status],
            'meta' => null,
        ]);
    }

    private function node(Request $request): AiNode
    {
        return $request->attributes->get('resolved_ai_node');
    }
}
