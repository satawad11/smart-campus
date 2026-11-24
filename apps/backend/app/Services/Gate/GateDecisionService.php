<?php

namespace App\Services\Gate;

use App\Enums\DecisionSource;
use App\Events\AccessEventBroadcast;
use App\Models\AccessDecision;
use App\Models\AccessEvent;
use App\Models\GatePass;
use App\Services\Event\AccessContextResolver;
use App\Services\Event\AccessPolicyMatcher;
use App\Services\Event\NotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Decides a Smart Gate pass (GATE-007, ACR-VEH-01 Phase 5 item 8). Decision and notification only:
 * nothing here drives a barrier (D5).
 *
 * The decision is an ordinary AccessDecision ("decisions go into access_decisions as today") on the
 * pass's primary event — the face event, or the plate event for a PLATE_ONLY pass — tagged with
 * gate_pass_id. Being the event's latest decision, it is what Live Monitor, event history and the
 * review queue show for that event; the face event's own earlier decision stays in its history.
 * An officer reviews it like any event (EventReviewService), which appends, never overwrites.
 *
 * Order as for any decision: persist -> commit -> broadcast -> notify. At gate cameras the face
 * event itself does not notify (owner choice 2026-11-20: one alert per pass, on the gate decision).
 */
class GateDecisionService
{
    public function __construct(
        private readonly AccessContextResolver $contextResolver,
        private readonly AccessPolicyMatcher $matcher,
        private readonly NotificationService $notifier,
    ) {}

    /** Decide a pass once. Returns the decision, or null when it was already decided or skipped. */
    public function decide(GatePass $pass): ?AccessDecision
    {
        $created = DB::transaction(function () use ($pass) {
            $primaryId = $pass->face_event_id ?? $pass->plate_event_id;
            // Serialises with a concurrent sweep and with officer reviews of the same event.
            $event = AccessEvent::whereKey($primaryId)->lockForUpdate()->first();
            if ($event === null || $this->isDecided($pass)) {
                return null;
            }

            // An officer already ruled on this event before the pass formed: that ruling stands
            // as the latest decision; the gate never overrides a human.
            if ($event->latestDecision()->first()?->decision_source === DecisionSource::OFFICER) {
                return null;
            }

            $result = $this->matcher->match($this->contextResolver->resolvePass($pass));

            $decision = AccessDecision::create([
                'event_id' => $event->id,
                'person_id' => $pass->person_id,
                'decision' => $result->decision,
                'reason_code' => $result->reasonCode,
                'access_policy_id' => $result->matchedPolicyId,
                'gate_pass_id' => $pass->id,
                'decision_source' => $result->decisionSource,
                'decided_by_user_id' => null,
                'reason_text' => null,
            ]);

            return [$event, $decision];
        });

        if ($created === null) {
            return null;
        }

        [$event, $decision] = $created;
        try {
            broadcast(new AccessEventBroadcast($event, $decision));
        } catch (Throwable $e) {
            Log::warning('Gate decision broadcast failed; clients recover via missed-event sync', [
                'gate_pass_uuid' => $pass->uuid,
                'error' => $e->getMessage(),
            ]);
        }
        try {
            $this->notifier->notifyIfActionable($event, $decision);
        } catch (Throwable $e) {
            Log::error('Gate decision notification failed', ['gate_pass_uuid' => $pass->uuid, 'error' => $e->getMessage()]);
        }

        return $decision;
    }

    /**
     * Retry passes created since $since that still have no gate decision (a failure right after the
     * pass was created). Returns the number decided.
     */
    public function decideUndecided(Carbon $since): int
    {
        $decided = 0;

        GatePass::query()
            ->where('created_at', '>=', $since)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('access_decisions')
                ->whereColumn('access_decisions.gate_pass_id', 'gate_passes.id'))
            ->orderBy('id')
            ->each(function (GatePass $pass) use (&$decided) {
                try {
                    if ($this->decide($pass) !== null) {
                        $decided++;
                    }
                } catch (Throwable $e) {
                    Log::error('Gate decision failed', ['gate_pass_uuid' => $pass->uuid, 'error' => $e->getMessage()]);
                }
            });

        return $decided;
    }

    private function isDecided(GatePass $pass): bool
    {
        return AccessDecision::where('gate_pass_id', $pass->id)->exists();
    }
}
