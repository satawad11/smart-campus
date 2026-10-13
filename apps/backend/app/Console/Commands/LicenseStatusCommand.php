<?php

namespace App\Console\Commands;

use App\Services\License\LicenseService;

/**
 * Print this installation's ID (needed to request a license) and the
 * evaluated license state (COM-02).
 */
class LicenseStatusCommand extends LicenseCommands
{
    protected $signature = 'license:status {--json : Print the state as JSON}';

    protected $description = 'Show the installation ID and the current license state';

    public function handle(LicenseService $licenses): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($licenses->state()->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->printState($licenses);

        return self::SUCCESS;
    }
}
