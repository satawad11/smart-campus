<?php

namespace App\Support;

use RuntimeException;

/**
 * Authenticated file encryption for backups (PDPA G-4; security baseline 14).
 *
 * libsodium secretstream (XChaCha20-Poly1305) in 64 KiB chunks, so large
 * dumps never sit fully in memory. Layout: MAGIC | header | chunks; the last
 * chunk carries TAG_FINAL, so truncation and tampering are detected.
 *
 * The key (32 bytes, base64 in BACKUP_ENCRYPTION_KEY) must be kept apart from
 * the backups themselves (e.g. a password vault): without it encrypted
 * backups can't be restored, and with it stored next to them they gain
 * nothing.
 */
class BackupEncryption
{
    public const MAGIC = "SCBK1\0";

    private const CHUNK = 65536;

    /**
     * Decode and validate a base64 key; null when encryption is not configured.
     */
    public static function keyFromConfig(?string $encoded): ?string
    {
        if ($encoded === null || trim($encoded) === '') {
            return null;
        }
        $key = base64_decode(trim($encoded), true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
            throw new RuntimeException('BACKUP_ENCRYPTION_KEY must be 32 random bytes, base64-encoded.');
        }

        return $key;
    }

    public static function generateKey(): string
    {
        return base64_encode(sodium_crypto_secretstream_xchacha20poly1305_keygen());
    }

    public static function isEncrypted(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        $head = fread($handle, strlen(self::MAGIC));
        fclose($handle);

        return $head === self::MAGIC;
    }

    public static function encryptFile(string $source, string $target, string $key): void
    {
        self::assertSodium();
        $in = self::open($source, 'rb');
        $out = self::open($target, 'wb');
        try {
            [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
            fwrite($out, self::MAGIC.$header);

            $chunk = fread($in, self::CHUNK);
            do {
                $next = feof($in) ? '' : fread($in, self::CHUNK);
                $tag = ($next === '' || $next === false)
                    ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                    : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
                fwrite($out, sodium_crypto_secretstream_xchacha20poly1305_push($state, (string) $chunk, '', $tag));
                $chunk = $next;
            } while ($tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);
        } finally {
            fclose($in);
            fclose($out);
        }
        @chmod($target, 0600);
    }

    public static function decryptFile(string $source, string $target, string $key): void
    {
        self::assertSodium();
        $in = self::open($source, 'rb');
        $out = self::open($target, 'wb');
        $ok = false;
        try {
            if (fread($in, strlen(self::MAGIC)) !== self::MAGIC) {
                throw new RuntimeException('Not an encrypted backup file.');
            }
            $header = fread($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull((string) $header, $key);
            $cipherChunk = self::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;

            $tag = null;
            while (! feof($in)) {
                $cipher = fread($in, $cipherChunk);
                if ($cipher === '' || $cipher === false) {
                    break;
                }
                $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $cipher);
                if ($result === false) {
                    throw new RuntimeException('Backup decryption failed: wrong key or corrupted file.');
                }
                [$plain, $tag] = $result;
                fwrite($out, $plain);
                if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                    break;
                }
            }
            $trailing = ! feof($in) && fread($in, 1) !== '';
            if ($tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL || $trailing) {
                throw new RuntimeException('Backup decryption failed: file truncated or has trailing data.');
            }
            $ok = true;
        } finally {
            fclose($in);
            fclose($out);
            if (! $ok) {
                @unlink($target);
            }
        }
        @chmod($target, 0600);
    }

    /**
     * @return resource
     */
    private static function open(string $path, string $mode)
    {
        $handle = @fopen($path, $mode);
        if ($handle === false) {
            throw new RuntimeException("Cannot open {$path}");
        }

        return $handle;
    }

    private static function assertSodium(): void
    {
        if (! function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_push')) {
            throw new RuntimeException('The PHP sodium extension is required for backup encryption.');
        }
    }
}
