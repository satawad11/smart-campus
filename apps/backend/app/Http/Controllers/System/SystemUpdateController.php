<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditRequestContext;
use App\Services\System\SystemUpdateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Web "System update" page (ACR-OPS-01). All routes need system.update
 * (SUPER_ADMIN only). Starting an update needs the caller's password again.
 */
class SystemUpdateController extends Controller
{
    public function __construct(
        private readonly SystemUpdateService $updates,
    ) {}

    public function show(): JsonResponse
    {
        return $this->ok($this->updates->state());
    }

    public function check(Request $request): JsonResponse
    {
        $queued = $this->updates->requestCheck($request->user(), AuditRequestContext::fromRequest($request));

        return $this->ok(['request' => $queued, 'state' => $this->updates->state()], 'Release check requested', 202);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'version' => ['required', 'string', 'max:64', 'regex:'.SystemUpdateService::TAG_PATTERN],
            'current_password' => ['required', 'string', 'max:255'],
        ]);

        $queued = $this->updates->requestUpdate(
            $request->user(), $validated['version'], $validated['current_password'], AuditRequestContext::fromRequest($request),
        );

        return $this->ok(['request' => $queued, 'state' => $this->updates->state()], 'Update requested', 202);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function ok(array $data, string $message = 'Success', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'meta' => null,
        ], $status);
    }
}
