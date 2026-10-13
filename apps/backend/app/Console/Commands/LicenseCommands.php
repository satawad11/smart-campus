<?php

namespace App\Console\Commands;

use App\Exceptions\LicenseException;
use App\Services\License\LicenseService;
use Illuminate\Console\Command;

/**
 * Shared helpers for the license:* commands (COM-02).
 */
abstract class LicenseCommands extends Command
{
    protected function readDocument(string $path): ?string
    {
        if (! is_file($path) || ! is_readable($path)) {
            $this->error("File not readable: {$path}");

            return null;
        }

        return (string) file_get_contents($path);
    }

    protected function reportRejection(LicenseException $e): int
    {
        $this->error("Rejected ({$e->errorCode}): {$e->getMessage()}");

        return self::FAILURE;
    }

    protected function printState(LicenseService $licenses): void
    {
        $state = $licenses->state()->toArray();

        $rows = [
            ['Installation ID', $state['installation_id']],
            ['State', $state['state'].($state['reason_code'] ? " ({$state['reason_code']})" : '')],
            ['Enforcement', $state['enforcement']],
            ['Clock rollback detected', $state['clock_rollback_detected'] ? 'YES' : 'no'],
        ];
        if ($state['license'] !== null) {
            $l = $state['license'];
            $rows[] = ['License ID', $l['license_id']];
            $rows[] = ['Licensee', $l['licensee_name']];
            $rows[] = ['Edition / plan', $l['edition'].' / '.$l['plan']];
            $rows[] = ['Features', implode(', ', $l['features'])];
            $rows[] = ['Max cameras', $l['limits']['max_cameras'] ?? 'unlimited'];
            $rows[] = ['Max AI nodes', $l['limits']['max_ai_nodes'] ?? 'unlimited'];
            $rows[] = ['Expires at', $l['expires_at'].' (grace '.$l['grace_days'].' days, until '.$l['grace_ends_at'].')'];
        }

        $this->table(['Field', 'Value'], $rows);
    }
}
