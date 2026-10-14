<?php

namespace App\Console\Commands;

use App\Support\BackupEncryption;
use Illuminate\Console\Command;

/**
 * Print a new random backup encryption key (PDPA G-4). Nothing is stored:
 * the operator puts it in BACKUP_ENCRYPTION_KEY and in a password vault.
 */
class BackupKeyCommand extends Command
{
    protected $signature = 'backup:key';

    protected $description = 'Generate a backup encryption key (prints it once; store it in a vault)';

    public function handle(): int
    {
        $this->line(BackupEncryption::generateKey());
        $this->warn('Store this key in a password vault, separately from the backups. Without it, encrypted backups cannot be restored.');

        return self::SUCCESS;
    }
}
