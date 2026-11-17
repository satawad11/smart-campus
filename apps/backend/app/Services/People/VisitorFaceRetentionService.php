<?php

namespace App\Services\People;

use App\Enums\FaceProfileStatus;
use App\Enums\VisitStatus;
use App\Models\FaceProfile;
use App\Models\Person;
use App\Models\Visit;
use App\Services\Audit\AuditRequestContext;
use App\Services\Face\BiometricPurgeService;
use App\Services\Face\FaceLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Revoke and erase visitor face data after its configured retention period (PPL-009). */
class VisitorFaceRetentionService
{
    private const END_STATUSES = [VisitStatus::CHECKED_OUT, VisitStatus::EXPIRED, VisitStatus::CANCELLED];

    private const ACTIVE_STATUSES = [VisitStatus::SCHEDULED, VisitStatus::CHECKED_IN];

    public function __construct(
        private readonly FaceLifecycleService $lifecycle,
        private readonly BiometricPurgeService $purger,
    ) {}

    /** @return array{eligible: int, profiles_revoked: int, embeddings_cleared: int, samples_deleted: int, samples_failed: int, errors: int} */
    public function process(int $retentionDays): array
    {
        if ($retentionDays < 1) {
            throw new \InvalidArgumentException('Visitor face retention must be at least one day.');
        }

        $cutoff = CarbonImmutable::now()->subDays($retentionDays);
        $result = [
            'eligible' => 0,
            'profiles_revoked' => 0,
            'embeddings_cleared' => 0,
            'samples_deleted' => 0,
            'samples_failed' => 0,
            'errors' => 0,
        ];

        $this->eligibleVisitorsQuery($cutoff)->chunkById(100, function ($rows) use ($cutoff, $retentionDays, &$result) {
            foreach ($rows as $row) {
                try {
                    $eligiblePerson = DB::transaction(function () use ($row, $cutoff, $retentionDays) {
                        $person = Person::query()->whereKey($row->visitor_person_id)->lockForUpdate()->first();
                        if ($person === null || ! $person->visitorProfile()->exists()) {
                            return null;
                        }

                        // VisitService::create takes this same person lock. Rechecking here
                        // prevents a concurrent new visit from being erased by this run.
                        $hasOpenVisit = Visit::query()->where('visitor_person_id', $person->id)
                            ->whereIn('status', array_map(fn (VisitStatus $status) => $status->value, self::ACTIVE_STATUSES))
                            ->lockForUpdate()->first() !== null;
                        $lastEndedAt = $this->lastVisitEnd($person->id);
                        if ($hasOpenVisit || $lastEndedAt === null || CarbonImmutable::parse($lastEndedAt)->gt($cutoff)) {
                            return null;
                        }

                        $profiles = FaceProfile::query()->where('person_id', $person->id)->lockForUpdate()->get();
                        if ($profiles->isEmpty()) {
                            return null;
                        }

                        $revokedCount = 0;
                        foreach ($profiles as $profile) {
                            if ($profile->status !== FaceProfileStatus::REVOKED) {
                                $this->lifecycle->revoke(
                                    $profile,
                                    "Visitor face retention elapsed ({$retentionDays} days)",
                                    null,
                                    AuditRequestContext::empty(),
                                );
                                $revokedCount++;
                            }
                        }

                        return [$person, $revokedCount];
                    });

                    if ($eligiblePerson === null) {
                        continue;
                    }

                    [$person, $revokedCount] = $eligiblePerson;
                    $result['profiles_revoked'] += $revokedCount;

                    $result['eligible']++;
                    $purged = $this->purger->purge(
                        $person,
                        "Visitor face retention elapsed ({$retentionDays} days)",
                        null,
                        AuditRequestContext::empty(),
                    );
                    $result['embeddings_cleared'] += $purged['embeddings_cleared'];
                    $result['samples_deleted'] += $purged['samples_deleted'];
                    $result['samples_failed'] += count($purged['samples_failed']);
                } catch (Throwable $exception) {
                    $result['errors']++;
                    Log::error('Visitor face retention failed for a person.', [
                        'person_id' => $row->visitor_person_id,
                        'exception' => $exception::class,
                        'message' => $exception->getMessage(),
                    ]);
                }
            }
        }, 'last_ended_visits.visitor_person_id', 'visitor_person_id');

        return $result;
    }

    private function eligibleVisitorsQuery(CarbonImmutable $cutoff): Builder
    {
        $endStatuses = array_map(fn (VisitStatus $status) => $status->value, self::END_STATUSES);
        $activeStatuses = array_map(fn (VisitStatus $status) => $status->value, self::ACTIVE_STATUSES);
        $endedVisits = DB::table('visits')
            ->select('visitor_person_id')
            ->selectRaw(
                'MAX(CASE WHEN status = ? THEN checked_out_at WHEN status = ? THEN valid_until WHEN status = ? THEN updated_at END) AS last_ended_at',
                $endStatuses,
            )
            ->whereIn('status', $endStatuses)
            ->groupBy('visitor_person_id');

        return DB::query()->fromSub($endedVisits, 'last_ended_visits')
            ->join('visitor_profiles', 'visitor_profiles.person_id', '=', 'last_ended_visits.visitor_person_id')
            ->where('last_ended_visits.last_ended_at', '<=', $cutoff)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('visits as open_visits')
                ->whereColumn('open_visits.visitor_person_id', 'last_ended_visits.visitor_person_id')
                ->whereIn('open_visits.status', $activeStatuses))
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('face_profiles as profiles')
                ->whereColumn('profiles.person_id', 'last_ended_visits.visitor_person_id')
                ->where(function ($profileQuery) {
                    $profileQuery->whereNull('profiles.biometrics_purged_at')
                        ->orWhereExists(fn ($sampleQuery) => $sampleQuery->selectRaw('1')->from('face_samples as samples')
                            ->whereColumn('samples.face_profile_id', 'profiles.id')
                            ->whereNull('samples.purged_at'));
                }))
            ->select('last_ended_visits.visitor_person_id')
            ->orderBy('last_ended_visits.visitor_person_id');
    }

    private function lastVisitEnd(int $personId): ?string
    {
        $statuses = array_map(fn (VisitStatus $status) => $status->value, self::END_STATUSES);

        return Visit::query()->where('visitor_person_id', $personId)->whereIn('status', $statuses)
            ->selectRaw(
                'CASE WHEN status = ? THEN checked_out_at WHEN status = ? THEN valid_until WHEN status = ? THEN updated_at END AS last_ended_at',
                $statuses,
            )
            ->orderByDesc('last_ended_at')
            ->value('last_ended_at');
    }
}
