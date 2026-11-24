<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Internal\IngestPlateEventRequest;
use App\Services\Vehicle\PlateEventIngestionService;
use Illuminate\Http\JsonResponse;

/**
 * Node-facing plate-read ingestion (VEH-010), /internal/v1, ai_node.token auth. Node identity is
 * the credential resolved by the middleware; the service cross-checks the body against it.
 */
class PlateEventInternalController extends Controller
{
    public function __construct(
        private readonly PlateEventIngestionService $service,
    ) {}

    public function store(IngestPlateEventRequest $request): JsonResponse
    {
        $event = $this->service->ingest($request->attributes->get('resolved_ai_node'), $request->validated());
        $plate = $event->metadata['plate'] ?? [];

        // 201 for a newly stored read; 200 for an idempotent retry or a cooldown duplicate.
        return response()->json([
            'success' => true,
            'message' => $event->wasRecentlyCreated ? 'Plate event recorded' : 'Plate event already recorded',
            'data' => [
                'event_uuid' => $event->uuid,
                'event_type' => $event->event_type,
                'plate_number' => $plate['plate_number'] ?? null,
                'plate_province' => $plate['plate_province'] ?? null,
                'vehicle_uuid' => $plate['vehicle_uuid'] ?? null,
            ],
            'meta' => null,
        ], $event->wasRecentlyCreated ? 201 : 200);
    }
}
