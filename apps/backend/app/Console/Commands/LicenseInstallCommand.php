<?php

namespace App\Console\Commands;

use App\Exceptions\LicenseException;
use App\Services\License\LicenseService;

/**
 * Install a vendor-signed license file (COM-02). Works fully offline.
 */
class LicenseInstallCommand extends LicenseCommands
{
    protected $signature = 'license:install {path : Path to the signed license file}';

    protected $description = 'Verify and install a signed license file';

    public function handle(LicenseService $licenses): int
    {
        $document = $this->readDocument((string) $this->argument('path'));
        if ($document === null) {
            return self::FAILURE;
        }

        try {
            $licenses->install($document, null);
        } catch (LicenseException $e) {
            return $this->reportRejection($e);
        }

        $this->info('License installed.');
        $this->printState($licenses);

        return self::SUCCESS;
    }
}
