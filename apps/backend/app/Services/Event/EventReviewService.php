<?php

namespace App\Services\Event;

use App\Enums\AccessDecisionOutcome;
use App\Enums\AuditAction;
use App\Enums\DecisionSource;
use App\Exceptions\ApiException;
use App\Models\AccessDecision;
use App\Models\AccessEvent;
use App\Models\User;
use App\Services\Audit\AuditRequestContext;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;

/**
 * Manual event review (EVT-010, docs/09 "Officer review adds a new
 * decision. It never overwrites system decision"). Always appends a new
 * AccessDecision row — the event's prior decisions, including the original
 * system/policy one, are never edited or deleted.
 *
 * Concurrency (EVT-011): `AccessEvent::lockForUpdate()` serializes two
 * concurrent reviews of the same event — the second reviewer's transaction
 * blocks until the first commits, then re-reads the now-current latest
 * decision. Combined with `$expectedDecisionUuid`, this turns a race into a
 * detectable conflict: if the caller's client fetched the event, then a
 * different reviewer resolved it first, the stale reviewer's `expected`
 * token no longer matches and the request is rejected with 409 rather than
 * silently appending on top of a decision it never saw.
 */
class EventReviewService
{
    public function __construct(
        private readonly AuditService $audit,
    ) {}

    public function review(
        AccessEvent $event,
        AccessDecisionOutcome $decision,
        string $reasonCode,
        ?string $reasonText,
        User $actor,
        ?AuditRequestContext $context,
        ?string $expectedDecisionUuid = null,
    ): AccessDecision {
        return DB::transaction(function () use ($event, $decision, $reasonCode, $reasonText, $actor, $context, $expectedDecisionUuid) {
            $locked = AccessEvent::whereKey($event->getKey())->lockForUpdate()->firstOrFail();
            $previous = $locked->latestDecision()->first();

            if ($expectedDecisionUuid !== null && $previous?->uuid !== $expectedDecisionUuid) {
                throw ApiException::conflict(
                    'EVENT_REVIEW_CONFLICT',
                    'This event was already reviewed by someone else since you loaded it. Refresh and try again.',
                );
            }

            $created = AccessDecision::create([
                'event_id' => $locked->id,
                'person_id' => $locked->person_id,
                'decision' => $decision,
                'reason_code' => $reasonCode,
                'access_policy_id' => null,
                'decision_source' => DecisionSource::OFFICER,
                'decided_by_user_id' => $actor->id,
                'reason_text' => $reasonText,
            ]);

            $this->audit->record(
                AuditAction::EVENT_REVIEWED, $actor, 'ACCESS_EVENT', $locked->uuid,
                oldValues: ['decision' => $previous?->decision?->value],
                newValues: [
                    'decision' => $decision->value,
                    'reason_code' => $reasonCode,
                    'reason_text' => $reasonText,
                ],
                context: $context, module: 'event',
            );

            return $created;
        });
    }
}
