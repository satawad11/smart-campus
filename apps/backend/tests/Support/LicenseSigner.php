<?php

namespace Tests\Support;

use Illuminate\Support\Str;

/**
 * Test-only Ed25519 signer producing the same envelope as
 * tools/license-issuer/issue.php. The key pair is generated per test run;
 * nothing here is a real vendor key.
 */
class LicenseSigner
{
    public readonly string $keyId;

    private readonly string $secret;

    public readonly string $publicKey;

    public function __construct(string $keyId = 'test-k1')
    {
        $pair = sodium_crypto_sign_keypair();
        $this->keyId = $keyId;
        $this->secret = sodium_crypto_sign_secretkey($pair);
        $this->publicKey = base64_encode(sodium_crypto_sign_publickey($pair));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function license(string $installationId, array $overrides = []): string
    {
        return $this->sign(array_merge([
            'type' => 'license',
            'format_version' => 1,
            'license_id' => (string) Str::uuid(),
            'installation_id' => $installationId,
            'licensee' => ['name' => 'Test Vocational College'],
            'edition' => 'COMMERCIAL',
            'plan' => 'STANDARD',
            'features' => ['face', 'liveness', 'monitor', 'report'],
            'limits' => ['max_cameras' => 2, 'max_ai_nodes' => 1],
            'issued_at' => now()->subDay()->toIso8601String(),
            'not_before' => now()->subDay()->toIso8601String(),
            'expires_at' => now()->addYear()->toIso8601String(),
            'grace_days' => 14,
        ], $overrides));
    }

    /**
     * @param  list<string>  $licenseIds
     */
    public function revocationList(int $sequence, array $licenseIds): string
    {
        return $this->sign([
            'type' => 'revocation_list',
            'format_version' => 1,
            'sequence' => $sequence,
            'issued_at' => now()->toIso8601String(),
            'revoked_license_ids' => $licenseIds,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function sign(array $payload): string
    {
        $bytes = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return json_encode([
            'format' => 'smart-campus-signed/v1',
            'key_id' => $this->keyId,
            'payload' => self::b64url($bytes),
            'signature' => self::b64url(sodium_crypto_sign_detached($bytes, $this->secret)),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public static function b64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
