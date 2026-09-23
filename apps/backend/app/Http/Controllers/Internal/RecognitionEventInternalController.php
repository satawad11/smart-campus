<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Internal\IngestRecognitionEventRequest;
use App\Http\Resources\AccessEventResource;
use App\Services\Event\RecognitionEventIngestionService;
use Illuminate\Http\JsonResponse;

/**
 * Node-facing recognition-event ingestion (EVT-003), under /internal/v1
 * and gated by the ai_node.token middleware. Node identity is the
 * credential resolved by that middleware; RecognitionEventIngestionService
 * cross-checks the body's ai_node_uuid against it and verifies the
 * targeted camera actually belongs to this node before recording anything.
 */
class RecognitionEventInternalController extends Controller
{
    public function __construct(
        private readonly RecognitionEventIngestionService $service,
    ) {}

    public function store(IngestRecognitionEventRequest $request): JsonResponse
    {
        $event = $this->service->ingest(
            $request->attributes->get('resolved_ai_node'),
            $request->validated(),
        );

        return response()->json([
            'success' => true,
            'message' => 'Recognition event recorded',
            'data' => new AccessEventResource($event),
            'meta' => null,
        ], 201);
    }
}
