<?php

namespace App\Events;

use App\Models\Notification;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Pushes a new inbox notification to its recipient right away (private channel
 * user.{userUuid}), so the console alert does not wait for the next inbox poll.
 * Carries only what the alert shows; the inbox stays the source of truth.
 */
class NotificationCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly Notification $notification,
        public readonly string $userUuid,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("user.{$this->userUuid}")];
    }

    public function broadcastAs(): string
    {
        return 'notification.created';
    }

    public function broadcastWith(): array
    {
        return [
            'uuid' => $this->notification->uuid,
            'type' => $this->notification->type,
            'title' => $this->notification->title,
            'data' => $this->notification->data,
            'read_at' => null,
            'created_at' => $this->notification->created_at?->toISOString(),
        ];
    }
}
