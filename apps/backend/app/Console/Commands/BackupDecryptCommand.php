<?php

namespace App\Console\Commands;

use App\Support\BackupEncryption;
use Illuminate\Console\Command;
use Throwable;

/**
 * Decrypt an encrypted backup (<file>.enc) for a manual restore (PDPA G-4).
 * Uses BACKUP_ENCRYPTION_KEY, or --key for a backup made with an older key.
 */
class BackupDecryptCommand extends Command
{
    protected $signature = 'backup:decrypt
                            {source : Encrypted backup file (.enc)}
                            {target : Where to write the decrypted file (must not exist)}
                            {--key= : Base64 key, if not the configured BACKUP_ENCRYPTION_KEY}';

    protected $description = 'Decrypt an encrypted backup file for a manual restore';

    public function handle(): int
    {
        $source = (string) $this->argument('source');
        $target = (string) $this->argument('target');

        if (! is_file($source) || ! BackupEncryption::isEncrypted($source)) {
            $this->error("Not an encrypted backup file: {$source}");

            return self::FAILURE;
        }
        if (file_exists($target)) {
            $this->error("Refusing to overwrite {$target}");

            return self::FAILURE;
        }

        try {
            $key = BackupEncryption::keyFromConfig($this->option('key') ?: config('backup.encryption_key'));
            if ($key === null) {
                $this->error('No key: set BACKUP_ENCRYPTION_KEY or pass --key.');

                return self::FAILURE;
            }
            BackupEncryption::decryptFile($source, $target, $key);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Decrypted to {$target} (mode 0600). Delete it after the restore.");

        return self::SUCCESS;
    }
}
