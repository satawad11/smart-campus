<?php

namespace App\Http\Controllers\Camera;

use App\Http\Controllers\Concerns\EnforcesScope;
use App\Http\Controllers\Controller;
use App\Models\AiNode;
use App\Models\AiNodeConfigRevision;
use App\Services\Audit\AuditRequestContext;
use App\Services\Camera\AiNodeRuntimeConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Versioned node runtime settings with rollback (COM-03).
 */
class AiNodeConfigRevisionController extends Controller
{
    use EnforcesScope;

    public function __construct(
        private readonly AiNodeRuntimeConfigService $service,
    ) {}

    public function index(Request $request, AiNode $aiNode): JsonResponse
    {
        $this->assertInScope($request, 'ai_node.view', $aiNode->site_id);
        $revisions = AiNodeConfigRevision::with('createdBy')
            ->where('ai_node_id', $aiNode->id)
            ->orderByDesc('revision')
            ->limit(50)
            ->get();

        return $this->ok([
            'applied_revision' => $aiNode->applied_config_revision,
            'schema' => collect(AiNodeRuntimeConfigService::SCHEMA)
                ->map(fn (array $b) => ['min' => $b[0], 'max' => $b[1]]),
            'revisions' => $revisions->map(fn (AiNodeConfigRevision $r) => $this->present($r))->values(),
        ]);
    }

    public function store(Request $request, AiNode $aiNode): JsonResponse
    {
        $this->assertInScope($request, 'ai_node.manage', $aiNode->site_id);
        $validated = $request->validate([
            'settings' => ['required', 'array'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $revision = $this->service->revise(
            $aiNode, $validated['settings'], $validated['note'] ?? null,
            $request->user(), AuditRequestContext::fromRequest($request),
        );

        return $this->ok($this->present($revision->load('createdBy')), 'Node settings saved as a new revision', 201);
    }

    public function rollback(Request $request, AiNode $aiNode, int $revision): JsonResponse
    {
        $this->assertInScope($request, 'ai_node.manage', $aiNode->site_id);

        $new = $this->service->rollback($aiNode, $revision, $request->user(), AuditRequestContext::fromRequest($request));

        return $this->ok($this->present($new->load('createdBy')), "Rolled back to revision {$revision}", 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(AiNodeConfigRevision $r): array
    {
        return [
            'uuid' => $r->uuid,
            'revision' => $r->revision,
            'settings' => $r->settings,
            'note' => $r->note,
            'rolled_back_from_revision' => $r->rolled_back_from_revision,
            'created_by' => $r->createdBy === null ? null : ['uuid' => $r->createdBy->uuid, 'username' => $r->createdBy->username],
            'created_at' => $r->created_at?->toIso8601String(),
        ];
    }

    private function ok(mixed $data, string $message = 'Success', int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data, 'meta' => null], $status);
    }
}
