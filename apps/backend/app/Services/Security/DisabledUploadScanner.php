<?php

namespace App\Services\Security;

/** UPLOAD_SCAN_DRIVER=off: uploads are accepted without a malware scan. */
class DisabledUploadScanner implements UploadScanner
{
    public function enabled(): bool
    {
        return false;
    }

    public function scan(string $path): UploadScanResult
    {
        return UploadScanResult::clean();
    }
}
