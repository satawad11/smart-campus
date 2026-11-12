<?php

namespace App\Http\Controllers\Integration;

use App\Http\Controllers\Controller;
use App\Http\Requests\Integration\UpdateRmsConnectionRequest;
use App\Services\Audit\AuditRequestContext;
use App\Services\Integration\IntegrationConnectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * External API connections (ACR-INT-02). Read: system.view. Change and
 * test: integration.manage. The API key is never returned.
 */
class IntegrationConnectionController extends Controller
{
    public function __construct(
        private readonly IntegrationConnectionService $service,
    ) {}

    public function index(): JsonResponse
    {
        return $this->ok($this->service->list());
    }

    public function updateRms(UpdateRmsConnectionRequest $request): JsonResponse
    {
        $data = $this->service->updateRms(
            $request->validated(),
            $request->user(),
            AuditRequestContext::fromRequest($request),
        );

        return $this->ok($data, null, 'บันทึกการตั้งค่าการเชื่อมต่อ RMS แล้ว');
    }

    public function testRms(Request $request): JsonResponse
    {
        $result = $this->service->testRms($request->user(), AuditRequestContext::fromRequest($request));

        return $this->ok($result, ['connection' => $this->service->presentRms()]);
    }

    private function ok(mixed $data, ?array $meta = null, string $message = 'Success'): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data, 'meta' => $meta]);
    }
}
