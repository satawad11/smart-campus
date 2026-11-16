<?php

namespace App\Services\Event;

use App\Enums\AccessDecisionOutcome;
use App\Enums\UserStatus;
use App\Events\NotificationCreated;
use App\Models\AccessDecision;
use App\Models\AccessEvent;
use App\Models\User;
use App\Services\Auth\PermissionService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fans a qualifying AccessDecision out to every recipient currently
 * holding monitor.view (docs/09 processing pipeline: "... -> persist
 * decision -> commit -> broadcast -> notify"). Only decisions needing
 * human attention notify — ALLOW is the routine, expected outcome and
 * would just be noise.
 *
 * Recipients are resolved via PermissionService::hasPermission() per
 * active user rather than a bespoke reverse query, so this always agrees
 * with the same DENY-override/role/temporal-validity rules every
 * permission:* route already enforces — never a second, divergent
 * definition of "who can see this".
 */
class NotificationService
{
    private const ACTIONABLE = [
        AccessDecisionOutcome::REVIEW,
        AccessDecisionOutcome::DENY,
        AccessDecisionOutcome::UNKNOWN,
    ];

    public function __construct(
        private readonly PermissionService $permissions,
    ) {}

    public function notifyIfActionable(AccessEvent $event, AccessDecision $decision, ?int $excludeUserId = null): void
    {
        if (! in_array($decision->decision, self::ACTIONABLE, true)) {
            return;
        }

        $type = match ($decision->decision) {
            AccessDecisionOutcome::DENY => 'EVENT_DENIED',
            AccessDecisionOutcome::UNKNOWN => 'EVENT_UNKNOWN_PERSON',
            default => 'EVENT_REVIEW_REQUIRED',
        };

        $title = match ($decision->decision) {
            AccessDecisionOutcome::DENY => 'Access denied',
            AccessDecisionOutcome::UNKNOWN => 'Unknown person detected',
            default => 'An event needs review',
        };

        $data = [
            'event_uuid' => $event->uuid,
            'event_type' => $event->event_type,
            'decision' => $decision->decision->value,
            'reason_code' => $decision->reason_code,
            // Display context for the alert popup (EVT-022): where and when.
            'camera_code' => $event->camera?->camera_code,
            'camera_name' => $event->camera?->name,
            'occurred_at' => $event->occurred_at?->toISOString(),
        ];

        foreach ($this->recipients() as $recipient) {
            if ($recipient->id === $excludeUserId) {
                continue; // a reviewer doesn't need a notification about their own review
            }

            $notification = $recipient->appNotifications()->create([
                'type' => $type,
                'title' => $title,
                'body' => null,
                'data' => $data,
                'read_at' => null,
            ]);

            // Realtime push to the recipient's console. Best effort: the inbox row is
            // already saved and the console still polls it if the socket is down.
            try {
                broadcast(new NotificationCreated($notification, $recipient->uuid));
            } catch (Throwable $e) {
                Log::warning('Notification push failed', ['notification' => $notification->uuid, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * @return Collection<int, User>
     */
    private function recipients()
    {
        return User::query()
            ->where('status', UserStatus::ACTIVE)
            ->get()
            ->filter(fn (User $user) => $this->permissions->hasPermission($user, 'monitor.view'))
            ->values();
    }
}
