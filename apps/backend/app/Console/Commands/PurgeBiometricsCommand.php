<?php

namespace App\Console\Commands;

use App\Exceptions\Face\FaceLifecycleException;
use App\Models\Person;
use App\Services\Face\BiometricPurgeService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Erase a person's biometric data after revocation (PDPA G-1). CLI only:
 * who may run an irreversible erasure is an operational decision, so no API
 * or permission is added. See docs/commercial/PDPA_DATA_MAP.md.
 */
class PurgeBiometricsCommand extends Command
{
    protected $signature = 'face:purge
                            {person_uuid : Person whose biometric data is erased}
                            {--reason= : Required: why (e.g. DSR ticket reference)}
                            {--dry-run : Show what would be erased, change nothing}
                            {--force : Do not ask for confirmation}';

    protected $description = 'Irreversibly erase a revoked person\'s face embeddings and sample images';

    public function handle(BiometricPurgeService $purger): int
    {
        $uuid = (string) $this->argument('person_uuid');
        $person = Str::isUuid($uuid) ? Person::where('uuid', $uuid)->first() : null;
        if ($person === null) {
            $this->error("Person not found: {$uuid}");

            return self::FAILURE;
        }

        $plan = $purger->plan($person);
        $this->table(['Face profiles', 'Not revoked', 'Embeddings to clear', 'Sample images to delete'],
            [[$plan['profiles'], $plan['not_revoked'], $plan['embeddings'], $plan['samples']]]);

        if ($this->option('dry-run')) {
            $this->info('Dry run: nothing changed.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('This permanently erases the biometric data above. Continue?')) {
            $this->warn('Aborted: nothing changed.');

            return self::FAILURE;
        }

        try {
            $result = $purger->purge($person, (string) $this->option('reason'));
        } catch (FaceLifecycleException $e) {
            $this->error("{$e->errorCode}: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Cleared {$result['embeddings_cleared']} embedding(s), deleted {$result['samples_deleted']} sample image(s).");
        if ($result['samples_failed'] !== []) {
            $this->error(count($result['samples_failed']).' sample object(s) could not be deleted; re-run the command (it is idempotent).');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
