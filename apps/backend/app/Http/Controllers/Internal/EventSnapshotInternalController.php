<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Internal\UploadEventSnapshotRequest;
use App\Services\Event\EventSnapshotService;
use Illuminate\Http\JsonResponse;

/**
 * Node-facing event face crop upload (EVT-025, ACR-EVT-24). Only the node
 * that delivered the event may attach a crop, once, soon after the event.
 * The response never echoes the storage key or the image.
 */
class EventSnapshotInternalController extends Controller
{
    public function __construct(
        private readonly EventSnapshotService $service,
    ) {}

    public function store(UploadEventSnapshotRequest $request, string $eventUuid): JsonResponse
    {
        $event = $this->service->store(
            $request->attributes->get('resolved_ai_node'),
            $eventUuid,
            (string) $request->file('image')->get(),
        );

        return response()->json([
            'success' => true,
            'message' => 'Event face image stored',
            'data' => ['event_uuid' => $event->uuid, 'snapshot_available' => true],
            'meta' => null,
        ], 201);
    }
}
