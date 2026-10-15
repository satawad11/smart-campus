<?php

namespace App\Services\Security;

/**
 * Streams a file to clamd with the INSTREAM command (zINSTREAM, 4-byte
 * big-endian length-prefixed chunks, zero-length terminator). No PHP
 * extension and no shared filesystem with the clamav container are needed.
 *
 * Replies: "stream: OK", "stream: <Signature> FOUND", or "... ERROR" (for
 * example the file exceeds clamd's StreamMaxLength). Anything but OK/FOUND
 * is "unavailable": the caller fails closed.
 */
class ClamAvUploadScanner implements UploadScanner
{
    private const CHUNK = 8192;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly float $timeoutSeconds,
    ) {}

    public function enabled(): bool
    {
        return true;
    }

    public function scan(string $path): UploadScanResult
    {
        $file = @fopen($path, 'rb');
        if ($file === false) {
            throw new UploadScanUnavailableException('Uploaded file is not readable for scanning.');
        }

        $socket = @stream_socket_client(
            "tcp://{$this->host}:{$this->port}",
            $errno,
            $errstr,
            $this->timeoutSeconds,
        );
        if ($socket === false) {
            fclose($file);
            throw new UploadScanUnavailableException("ClamAV unreachable at {$this->host}:{$this->port} ({$errstr}).");
        }

        try {
            stream_set_timeout($socket, (int) ceil($this->timeoutSeconds));
            $this->write($socket, "zINSTREAM\0");
            while (! feof($file)) {
                $chunk = fread($file, self::CHUNK);
                if ($chunk === false) {
                    throw new UploadScanUnavailableException('Read error while streaming the upload to ClamAV.');
                }
                if ($chunk === '') {
                    continue;
                }
                $this->write($socket, pack('N', strlen($chunk)).$chunk);
            }
            $this->write($socket, pack('N', 0));

            $reply = trim((string) stream_get_contents($socket), "\0\r\n ");
        } finally {
            fclose($file);
            fclose($socket);
        }

        if (preg_match('/^stream: OK$/', $reply) === 1) {
            return UploadScanResult::clean();
        }
        if (preg_match('/^stream: (.+) FOUND$/', $reply, $m) === 1) {
            return UploadScanResult::infected($m[1]);
        }

        throw new UploadScanUnavailableException('ClamAV gave no verdict: '.($reply === '' ? 'empty reply' : $reply));
    }

    /** @param resource $socket */
    private function write($socket, string $data): void
    {
        $length = strlen($data);
        $written = 0;
        while ($written < $length) {
            $n = @fwrite($socket, substr($data, $written));
            if ($n === false || $n === 0) {
                throw new UploadScanUnavailableException('Connection to ClamAV lost while streaming.');
            }
            $written += $n;
        }
    }
}
