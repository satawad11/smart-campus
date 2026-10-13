<?php

namespace App\Console\Commands;

use App\Exceptions\LicenseException;
use App\Services\License\LicenseService;

/**
 * Import a vendor-signed license revocation list (COM-02).
 */
class LicenseImportRevocationsCommand extends LicenseCommands
{
    protected $signature = 'license:import-revocations {path : Path to the signed revocation list}';

    protected $description = 'Verify and import a signed license revocation list';

    public function handle(LicenseService $licenses): int
    {
        $document = $this->readDocument((string) $this->argument('path'));
        if ($document === null) {
            return self::FAILURE;
        }

        try {
            $added = $licenses->importRevocationList($document, null);
        } catch (LicenseException $e) {
            return $this->reportRejection($e);
        }

        $this->info("Revocation list imported ({$added} newly revoked).");

        return self::SUCCESS;
    }
}
