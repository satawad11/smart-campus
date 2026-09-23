<?php

namespace App\Services\Event;

use App\Enums\AccessDecisionOutcome;
use App\Enums\AuditAction;
use App\Enums\DecisionSource;
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
    ): AccessDecision {
        return DB::transaction(function () use ($event, $decision, $reasonCode, $reasonText, $actor, $context) {
            $locked = AccessEvent::whereKey($event->getKey())->lockForUpdate()->firstOrFail();
            $previous = $locked->latestDecision()->first();

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
