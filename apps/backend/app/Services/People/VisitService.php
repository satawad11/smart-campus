<?php

namespace App\Services\People;

use App\Enums\AuditAction;
use App\Enums\VisitStatus;
use App\Exceptions\ApiException;
use App\Models\Person;
use App\Models\User;
use App\Models\Visit;
use App\Services\Audit\AuditRequestContext;
use App\Services\Audit\AuditService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Visit lifecycle and immutable audit trail (PPL-006). */
class VisitService
{
    public function __construct(private readonly AuditService $audit) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor, AuditRequestContext $context): Visit
    {
        return DB::transaction(function () use ($data, $actor, $context) {
            // Serialize a new visit against visitor face-retention processing.
            Person::query()->whereKey($data['visitor_person_id'])->lockForUpdate()->firstOrFail();
            $visit = Visit::create($data + [
                'status' => VisitStatus::SCHEDULED,
                'created_by_user_id' => $actor->id,
            ]);
            $this->audit->record(
                AuditAction::VISIT_CREATED, $actor, 'Visit', $visit->uuid, null,
                ['visitor_person_uuid' => $visit->visitor->uuid, 'site_uuid' => $visit->site->uuid, 'zone_uuid' => $visit->zone?->uuid, 'valid_from' => $visit->valid_from?->toIso8601String(), 'valid_until' => $visit->valid_until?->toIso8601String()],
                null, $context, 'people'
            );

            return $visit->load(['visitor', 'host', 'site', 'zone']);
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Visit $visit, array $data, User $actor, AuditRequestContext $context): Visit
    {
        if ($visit->status !== VisitStatus::SCHEDULED) {
            throw ApiException::conflict('VISIT_NOT_EDITABLE', 'Only scheduled visits can be edited.');
        }

        return DB::transaction(function () use ($visit, $data, $actor, $context) {
            $visit = Visit::query()->lockForUpdate()->findOrFail($visit->id);
            if ($visit->status !== VisitStatus::SCHEDULED) {
                throw ApiException::conflict('VISIT_NOT_EDITABLE', 'Only scheduled visits can be edited.');
            }
            $fields = ['host_person_id', 'purpose', 'site_id', 'zone_id', 'valid_from', 'valid_until'];
            $old = array_intersect_key($visit->only($fields), $data);
            $visit->fill($data)->save();
            $this->audit->record(
                AuditAction::VISIT_UPDATED, $actor, 'Visit', $visit->uuid, $old,
                array_intersect_key($visit->only($fields), $data), null, $context, 'people'
            );

            return $visit->fresh()->load(['visitor', 'host', 'site', 'zone']);
        });
    }

    public function checkIn(Visit $visit, User $actor, AuditRequestContext $context): Visit
    {
        $result = DB::transaction(function () use ($visit, $actor, $context) {
            $locked = Visit::query()->lockForUpdate()->findOrFail($visit->id);
            if ($locked->status !== VisitStatus::SCHEDULED) {
                throw ApiException::conflict('VISIT_NOT_CHECKINABLE', 'Only scheduled visits can be checked in.');
            }
            $now = CarbonImmutable::now();
            if ($now->lt($locked->valid_from)) {
                throw ApiException::conflict('VISIT_NOT_YET_VALID', 'The visit time window has not started.');
            }
            if ($now->gt($locked->valid_until)) {
                $locked->status = VisitStatus::EXPIRED;
                $locked->save();
                $this->audit->record(
                    AuditAction::VISIT_EXPIRED, $actor, 'Visit', $locked->uuid,
                    ['status' => VisitStatus::SCHEDULED->value], ['status' => VisitStatus::EXPIRED->value],
                    null, $context, 'people'
                );

                return null;
            }

            return $this->transitionLocked($locked, VisitStatus::CHECKED_IN, AuditAction::VISIT_CHECKED_IN, $actor, $context, ['checked_in_at' => $now]);
        });
        if (! $result) {
            throw ApiException::conflict('VISIT_EXPIRED', 'The visit time window has expired.');
        }

        return $result;
    }

    public function checkOut(Visit $visit, User $actor, AuditRequestContext $context): Visit
    {
        if ($visit->status !== VisitStatus::CHECKED_IN) {
            throw ApiException::conflict('VISIT_NOT_CHECKED_IN', 'Only checked-in visits can be checked out.');
        }

        return $this->transition($visit, VisitStatus::CHECKED_IN, VisitStatus::CHECKED_OUT, AuditAction::VISIT_CHECKED_OUT, $actor, $context, ['checked_out_at' => now()]);
    }

    public function cancel(Visit $visit, User $actor, AuditRequestContext $context): Visit
    {
        if ($visit->status !== VisitStatus::SCHEDULED) {
            throw ApiException::conflict('VISIT_NOT_CANCELLABLE', 'Only scheduled visits can be cancelled.');
        }

        return $this->transition($visit, VisitStatus::SCHEDULED, VisitStatus::CANCELLED, AuditAction::VISIT_CANCELLED, $actor, $context);
    }

    /** @return int Number of visits expired. */
    public function expireScheduled(): int
    {
        $expired = 0;
        Visit::query()->where('status', VisitStatus::SCHEDULED->value)
            ->where('valid_until', '<', now())->orderBy('id')->chunkById(100, function ($visits) use (&$expired) {
                foreach ($visits as $visit) {
                    DB::transaction(function () use ($visit, &$expired) {
                        $locked = Visit::query()->lockForUpdate()->find($visit->id);
                        if (! $locked || $locked->status !== VisitStatus::SCHEDULED || $locked->valid_until->isFuture()) {
                            return;
                        }
                        $locked->status = VisitStatus::EXPIRED;
                        $locked->save();
                        $this->audit->record(
                            AuditAction::VISIT_EXPIRED, null, 'Visit', $locked->uuid,
                            ['status' => VisitStatus::SCHEDULED->value], ['status' => VisitStatus::EXPIRED->value],
                            null, null, 'people'
                        );
                        $expired++;
                    });
                }
            });

        return $expired;
    }

    /** @param array<string, mixed> $values */
    private function transition(Visit $visit, VisitStatus $expected, VisitStatus $status, AuditAction $action, User $actor, AuditRequestContext $context, array $values = []): Visit
    {
        return DB::transaction(function () use ($visit, $expected, $status, $action, $actor, $context, $values) {
            $locked = Visit::query()->lockForUpdate()->findOrFail($visit->id);
            if ($locked->status !== $expected) {
                throw ApiException::conflict('VISIT_STATUS_CONFLICT', 'The visit status changed before this action could be applied.');
            }

            return $this->transitionLocked($locked, $status, $action, $actor, $context, $values);
        });
    }

    /** @param array<string, mixed> $values */
    private function transitionLocked(Visit $visit, VisitStatus $status, AuditAction $action, User $actor, AuditRequestContext $context, array $values = []): Visit
    {
        $oldStatus = $visit->status->value;
        $visit->fill($values);
        $visit->status = $status;
        $visit->save();
        $this->audit->record(
            $action, $actor, 'Visit', $visit->uuid,
            ['status' => $oldStatus], ['status' => $status->value],
            null, $context, 'people'
        );

        return $visit->fresh()->load(['visitor', 'host', 'site', 'zone']);
    }
}
