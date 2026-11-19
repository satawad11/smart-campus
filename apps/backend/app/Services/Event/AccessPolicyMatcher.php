<?php

namespace App\Services\Event;

use App\Enums\AccessDecisionOutcome;
use App\Enums\DecisionSource;
use App\Models\AccessPolicy;

/**
 * Evaluates an AccessContext against access_policies (EVT-006). Read-only:
 * never persists a decision itself (EVT-007's job — this only produces the
 * result EVT-007 will store).
 *
 * A policy dimension left null is a wildcard (matches regardless of that
 * dimension); a non-null dimension must equal the context's value exactly.
 * Among enabled, currently-valid (valid_from/valid_until window) matching
 * policies, the lowest `priority` value wins (see the access_policies
 * migration's docblock for that convention).
 *
 * Smart Gate passes (GATE-005) use the same rule for gate_id, gate_mode,
 * match_result and vehicle_status. An ordinary event's context leaves them
 * null, so each condition reduces to `column IS NULL` and a policy that sets
 * any of them never applies outside a gate pass.
 *
 * Fail-closed default: if nothing matches, the result is REVIEW/
 * NO_MATCHING_POLICY — never a silent ALLOW. An access event the seeded
 * configuration didn't anticipate must always reach a human, not bypass
 * one.
 */
class AccessPolicyMatcher
{
    public function match(AccessContext $context): AccessPolicyMatchResult
    {
        $policy = AccessPolicy::query()
            ->where('enabled', true)
            ->where(fn ($q) => $q->whereNull('subject_type')->orWhere('subject_type', $context->subjectType))
            ->where(fn ($q) => $q->whereNull('subject_status')->orWhere('subject_status', $context->subjectStatus))
            ->where(fn ($q) => $q->whereNull('site_id')->orWhere('site_id', $context->siteId))
            ->where(fn ($q) => $q->whereNull('zone_id')->orWhere('zone_id', $context->zoneId))
            ->where(fn ($q) => $q->whereNull('event_type')->orWhere('event_type', $context->eventType))
            // Smart Gate dimensions (GATE-005). Null in an ordinary event's context, so only
            // policies that leave them all null can match it.
            ->where(fn ($q) => $q->whereNull('gate_id')->orWhere('gate_id', $context->gateId))
            ->where(fn ($q) => $q->whereNull('gate_mode')->orWhere('gate_mode', $context->gateMode))
            ->where(fn ($q) => $q->whereNull('match_result')->orWhere('match_result', $context->matchResult))
            ->where(fn ($q) => $q->whereNull('vehicle_status')->orWhere('vehicle_status', $context->vehicleStatus))
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', now()))
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', now()))
            ->orderBy('priority')
            ->first();

        if ($policy === null) {
            return new AccessPolicyMatchResult(
                decision: AccessDecisionOutcome::REVIEW,
                reasonCode: 'NO_MATCHING_POLICY',
                decisionSource: DecisionSource::SYSTEM,
                matchedPolicyId: null,
            );
        }

        return new AccessPolicyMatchResult(
            decision: $policy->decision,
            reasonCode: $policy->reason_code,
            decisionSource: DecisionSource::POLICY,
            matchedPolicyId: $policy->id,
        );
    }
}
