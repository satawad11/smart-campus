<?php

namespace App\Events;

use App\Models\AccessEvent;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Realtime push for a plate read (VEH-010, ACR-VEH-01). Same site channel as face events, but a
 * separate event name and no decision block: a plate read is a record, never an access decision
 * (face stays primary, D2). Smart Gate decisions arrive later as gate passes (GATE-007).
 */
class PlateEventBroadcast implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        public readonly AccessEvent $event,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("site.{$this->event->site->uuid}")];
    }

    public function broadcastAs(): string
    {
        return 'plate.read';
    }

    public function broadcastWith(): array
    {
        $plate = $this->event->metadata['plate'] ?? [];

        return [
            'event' => [
                'uuid' => $this->event->uuid,
                'event_type' => $this->event->event_type,
                'occurred_at' => $this->event->occurred_at?->toISOString(),
                'site_uuid' => $this->event->site->uuid,
                'zone_uuid' => $this->event->zone?->uuid,
                'zone_name' => $this->event->zone?->name,
                'camera_uuid' => $this->event->camera?->uuid,
                'camera_name' => $this->event->camera?->name,
            ],
            'plate' => [
                'plate_number' => $plate['plate_number'] ?? null,
                'plate_display' => $plate['plate_display'] ?? null,
                'plate_province' => $plate['plate_province'] ?? null,
                'ocr_score' => $plate['ocr_score'] ?? null,
                'vehicle_uuid' => $plate['vehicle_uuid'] ?? null,
                'vehicle_status' => $plate['vehicle_status'] ?? null,
            ],
        ];
    }
}
