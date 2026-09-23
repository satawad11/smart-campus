<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Internal\IngestRecognitionEventRequest;
use App\Http\Resources\AccessEventResource;
use App\Services\Event\RecognitionEventIngestionService;
use Illuminate\Http\JsonResponse;

/**
 * Node-facing recognition-event ingestion (EVT-003/EVT-004), under
 * /internal/v1 and gated by the ai_node.token middleware. Node identity is
 * the credential resolved by that middleware; RecognitionEventIngestionService
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

        // wasRecentlyCreated is false for an idempotent replay (EVT-004:
        // the service returned the already-stored event rather than
        // inserting a new one) — 200, not 201, since nothing was created
        // by this particular request.
        return response()->json([
            'success' => true,
            'message' => $event->wasRecentlyCreated ? 'Recognition event recorded' : 'Recognition event already recorded',
            'data' => new AccessEventResource($event),
            'meta' => null,
        ], $event->wasRecentlyCreated ? 201 : 200);
    }
}
