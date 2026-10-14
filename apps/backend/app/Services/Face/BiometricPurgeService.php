<?php

namespace App\Services\Face;

use App\Enums\AuditAction;
use App\Enums\FaceProfileStatus;
use App\Exceptions\Face\FaceLifecycleException;
use App\Models\FaceProfile;
use App\Models\FaceSample;
use App\Models\Person;
use App\Models\User;
use App\Services\Audit\AuditRequestContext;
use App\Services\Audit\AuditService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Irreversible erasure of a person's biometric data (PDPA G-1).
 *
 * Revocation (FACE-014) only stops a profile from matching; the embedding
 * and the sample images stayed in PostgreSQL and object storage. Purging:
 * - requires every face profile of the person to be REVOKED first (the
 *   audited withdrawal step), so an in-use identity is never erased;
 * - nulls each profile's embedding and records biometrics_purged_at;
 * - deletes every sample image object and records purged_at;
 * - keeps the rows, checksums and quality metadata so events, reviews and
 *   the audit trail stay referentially valid;
 * - audits counts and identifiers only, never image or vector data.
 *
 * Idempotent: a re-run finishes samples whose object deletion failed.
 * Copies inside existing backups persist until those backups expire.
 */
class BiometricPurgeService
{
    public function __construct(
        private readonly AuditService $audit,
    ) {}

    /**
     * What a purge would touch, without changing anything.
     *
     * @return array{profiles: int, not_revoked: int, embeddings: int, samples: int}
     */
    public function plan(Person $person): array
    {
        $profiles = FaceProfile::where('person_id', $person->id)->get();

        return [
            'profiles' => $profiles->count(),
            'not_revoked' => $profiles->where('status', '!=', FaceProfileStatus::REVOKED)->count(),
            'embeddings' => $profiles->whereNull('biometrics_purged_at')->count(),
            'samples' => $this->pendingSamples($profiles)->count(),
        ];
    }

    /**
     * @return array{profiles: int, embeddings_cleared: int, samples_deleted: int, samples_failed: list<string>}
     *
     * @throws FaceLifecycleException
     */
    public function purge(Person $person, string $reason, ?User $actor = null, ?AuditRequestContext $context = null): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new FaceLifecycleException('A purge reason is required.', 'BIOMETRIC_PURGE_REASON_REQUIRED', 422);
        }

        $profiles = FaceProfile::where('person_id', $person->id)->get();
        $inUse = $profiles->where('status', '!=', FaceProfileStatus::REVOKED);
        if ($inUse->isNotEmpty()) {
            throw new FaceLifecycleException(
                "Person [{$person->uuid}] has face profiles that are not revoked; revoke them before purging.",
                'BIOMETRIC_PURGE_PROFILE_NOT_REVOKED',
                409,
            );
        }

        // Objects first: a row is only marked purged once its object is gone.
        $deleted = [];
        $failed = [];
        foreach ($this->pendingSamples($profiles) as $sample) {
            try {
                $ok = Storage::disk('face_samples')->delete($sample->image_path);
            } catch (Throwable) {
                $ok = false;
            }
            if ($ok) {
                $deleted[] = $sample->id;
            } else {
                $failed[] = $sample->uuid;
            }
        }

        $embeddingProfiles = $profiles->whereNull('biometrics_purged_at')->pluck('id')->all();
        $now = now();

        DB::transaction(function () use ($person, $profiles, $embeddingProfiles, $deleted, $failed, $reason, $now, $actor, $context) {
            if ($embeddingProfiles !== []) {
                DB::table('face_profiles')->whereIn('id', $embeddingProfiles)->update([
                    'embedding' => null,
                    'biometrics_purged_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            if ($deleted !== []) {
                DB::table('face_samples')->whereIn('id', $deleted)->update(['purged_at' => $now, 'updated_at' => $now]);
            }

            $this->audit->record(
                action: AuditAction::FACE_BIOMETRICS_PURGED,
                actor: $actor,
                subjectType: 'Person',
                subjectUuid: $person->uuid,
                newValues: [
                    'face_profile_uuids' => $profiles->pluck('uuid')->all(),
                    'embeddings_cleared' => count($embeddingProfiles),
                    'samples_deleted' => count($deleted),
                    'samples_failed' => count($failed),
                ],
                metadata: ['reason' => $reason, 'failed_sample_uuids' => $failed],
                context: $context,
                module: 'face_biometrics',
            );
        });

        return [
            'profiles' => $profiles->count(),
            'embeddings_cleared' => count($embeddingProfiles),
            'samples_deleted' => count($deleted),
            'samples_failed' => $failed,
        ];
    }

    /**
     * @param  Collection<int, FaceProfile>  $profiles
     * @return Collection<int, FaceSample>
     */
    private function pendingSamples(Collection $profiles): Collection
    {
        return FaceSample::whereIn('face_profile_id', $profiles->pluck('id'))->whereNull('purged_at')->get();
    }
}
