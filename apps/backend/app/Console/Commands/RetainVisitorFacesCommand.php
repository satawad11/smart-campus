<?php

namespace App\Console\Commands;

use App\Services\People\VisitorFaceRetentionService;
use App\Services\System\SystemSettingService;
use Illuminate\Console\Command;

class RetainVisitorFacesCommand extends Command
{
    protected $signature = 'visitors:retain-faces';

    protected $description = 'Revoke and erase visitor face data after its retention period';

    public function handle(SystemSettingService $settings, VisitorFaceRetentionService $retention): int
    {
        $days = $settings->get('visitor.face_retention_days', 30);
        if (! is_int($days) || $days < 1) {
            $this->error('visitor.face_retention_days must be a positive integer; no visitor biometric data was changed.');

            return self::FAILURE;
        }

        $result = $retention->process($days);
        $this->info(sprintf(
            'Retention: %d day(s); visitors processed %d; profiles revoked %d; embeddings cleared %d; samples deleted %d; sample deletions failed %d; errors %d.',
            $days,
            $result['eligible'],
            $result['profiles_revoked'],
            $result['embeddings_cleared'],
            $result['samples_deleted'],
            $result['samples_failed'],
            $result['errors'],
        ));

        return $result['errors'] > 0 || $result['samples_failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
