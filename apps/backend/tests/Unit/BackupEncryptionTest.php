<?php

namespace Tests\Unit;

use App\Support\BackupEncryption;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * PDPA G-4: streamed authenticated backup encryption.
 */
class BackupEncryptionTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        if (! function_exists('sodium_crypto_secretstream_xchacha20poly1305_keygen')) {
            $this->markTestSkipped('ext-sodium is required (present in the backend image and CI).');
        }
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sc-bk-'.uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function path(string $name): string
    {
        return $this->dir.DIRECTORY_SEPARATOR.$name;
    }

    /** @return array<string, array{int}> */
    public static function sizes(): array
    {
        return ['empty' => [0], 'small' => [10], 'exact chunk' => [65536], 'multi chunk' => [200001]];
    }

    #[DataProvider('sizes')]
    public function test_round_trip(int $size): void
    {
        $key = BackupEncryption::keyFromConfig(BackupEncryption::generateKey());
        $plain = random_bytes(max(1, $size));
        $plain = substr($plain, 0, $size);
        file_put_contents($this->path('in'), $plain);

        BackupEncryption::encryptFile($this->path('in'), $this->path('enc'), $key);
        BackupEncryption::decryptFile($this->path('enc'), $this->path('out'), $key);

        $this->assertTrue(BackupEncryption::isEncrypted($this->path('enc')));
        $this->assertFalse(BackupEncryption::isEncrypted($this->path('in')));
        $this->assertSame($plain, file_get_contents($this->path('out')));
        if ($size > 0) {
            $this->assertStringNotContainsString($plain, file_get_contents($this->path('enc')));
        }
    }

    public function test_wrong_key_fails_and_leaves_no_output(): void
    {
        file_put_contents($this->path('in'), 'CREATE TABLE persons;');
        BackupEncryption::encryptFile($this->path('in'), $this->path('enc'), BackupEncryption::keyFromConfig(BackupEncryption::generateKey()));

        try {
            BackupEncryption::decryptFile($this->path('enc'), $this->path('out'), BackupEncryption::keyFromConfig(BackupEncryption::generateKey()));
            $this->fail('expected failure');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('wrong key', $e->getMessage());
        }
        $this->assertFileDoesNotExist($this->path('out'));
    }

    public function test_tampering_and_truncation_are_detected(): void
    {
        $key = BackupEncryption::keyFromConfig(BackupEncryption::generateKey());
        file_put_contents($this->path('in'), str_repeat('x', 150000));
        BackupEncryption::encryptFile($this->path('in'), $this->path('enc'), $key);
        $cipher = file_get_contents($this->path('enc'));

        $tampered = $cipher;
        $tampered[100] = chr(ord($tampered[100]) ^ 1);
        file_put_contents($this->path('t'), $tampered);
        file_put_contents($this->path('cut'), substr($cipher, 0, strlen($cipher) - 70000));

        foreach (['t', 'cut'] as $name) {
            try {
                BackupEncryption::decryptFile($this->path($name), $this->path('out'), $key);
                $this->fail("{$name} should not decrypt");
            } catch (RuntimeException) {
                $this->assertFileDoesNotExist($this->path('out'));
            }
        }
    }

    public function test_invalid_keys_are_rejected_and_empty_means_disabled(): void
    {
        $this->assertNull(BackupEncryption::keyFromConfig(null));
        $this->assertNull(BackupEncryption::keyFromConfig('  '));

        $this->expectException(RuntimeException::class);
        BackupEncryption::keyFromConfig(base64_encode('too-short'));
    }
}
