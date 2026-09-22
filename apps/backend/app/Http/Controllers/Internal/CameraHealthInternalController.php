<?php

namespace App\Http\Controllers\Internal;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Internal\RecordCameraHealthRequest;
use App\Http\Resources\CameraHealthLogResource;
use App\Models\Camera;
use App\Services\Camera\CameraHealthService;
use Illuminate\Http\JsonResponse;

/**
 * Node-facing camera health telemetry ingestion (CAM-013), under
 * /internal/v1 and gated by the ai_node.token middleware. Node identity is
 * the credential resolved by that middleware, never a client-supplied
 * value — CameraHealthService verifies the resolved node actually owns the
 * targeted camera before recording anything.
 */
class CameraHealthInternalController extends Controller
{
    public function __construct(
        private readonly CameraHealthService $service,
    ) {}

    public function record(RecordCameraHealthRequest $request, string $cameraUuid): JsonResponse
    {
        $camera = Camera::where('uuid', $cameraUuid)->first()
            ?? throw ApiException::unprocessable('CAMERA_NOT_FOUND', 'The specified camera does not exist.');

        $log = $this->service->recordHealth(
            $camera,
            $request->attributes->get('resolved_ai_node'),
            $request->validated(),
        );

        return response()->json([
            'success' => true,
            'message' => 'Camera health recorded',
            'data' => new CameraHealthLogResource($log),
            'meta' => null,
        ]);
    }
}
