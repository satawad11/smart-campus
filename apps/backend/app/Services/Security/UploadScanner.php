<?php

namespace App\Services\Security;

/**
 * Malware scan of an uploaded file before the application reads or stores it
 * (SEC-NEXT upload scan). Implementations: ClamAvUploadScanner (clamd over
 * TCP) and DisabledUploadScanner (UPLOAD_SCAN_DRIVER=off).
 */
interface UploadScanner
{
    public function enabled(): bool;

    /**
     * @throws UploadScanUnavailableException when the scanner cannot give a verdict
     */
    public function scan(string $path): UploadScanResult;
}
