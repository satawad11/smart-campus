<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditRequestContext;
use App\Services\License\LicenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Commercial license status and installation (COM-02).
 * The signed envelope is accepted as text and never returned.
 */
class LicenseController extends Controller
{
    public function __construct(
        private readonly LicenseService $licenses,
    ) {}

    public function show(): JsonResponse
    {
        return $this->ok($this->licenses->state()->toArray());
    }

    public function install(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'license' => ['required', 'string', 'max:65536'],
        ]);

        $this->licenses->install($validated['license'], $request->user(), AuditRequestContext::fromRequest($request));

        return $this->ok($this->licenses->state()->toArray(), 'License installed', 201);
    }

    public function importRevocations(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'revocation_list' => ['required', 'string', 'max:65536'],
        ]);

        $added = $this->licenses->importRevocationList(
            $validated['revocation_list'], $request->user(), AuditRequestContext::fromRequest($request),
        );

        return $this->ok(['newly_revoked' => $added, 'license' => $this->licenses->state()->toArray()], 'Revocation list imported');
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
