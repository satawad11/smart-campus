<?php

namespace App\Services\License;

use App\Exceptions\LicenseException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Verifies vendor-signed documents (licenses and revocation lists) offline
 * against the trusted Ed25519 public keys in config/license.php (COM-02).
 *
 * Envelope (UTF-8 JSON):
 *   {"format":"smart-campus-signed/v1","key_id":"…","payload":"<base64url>","signature":"<base64url>"}
 *
 * The signature covers the exact decoded payload bytes, so no JSON
 * canonicalisation is involved. The payload's `type` separates licenses from
 * revocation lists: one can never be accepted as the other.
 */
class LicenseVerifier
{
    public const FORMAT = 'smart-campus-signed/v1';

    public const TYPE_LICENSE = 'license';

    public const TYPE_REVOCATION_LIST = 'revocation_list';

    /** Envelopes are small; anything larger is refused before parsing. */
    private const MAX_ENVELOPE_BYTES = 65536;

    /**
     * @return array{key_id: string, payload: array<string, mixed>}
     *
     * @throws LicenseException
     */
    public function verify(string $envelope, string $expectedType): array
    {
        if (! function_exists('sodium_crypto_sign_verify_detached')) {
            throw LicenseException::rejected('LICENSE_CRYPTO_UNAVAILABLE', 'The PHP sodium extension is required to verify licenses.');
        }

        if (strlen($envelope) > self::MAX_ENVELOPE_BYTES) {
            throw LicenseException::rejected('LICENSE_MALFORMED', 'The license document is too large.');
        }

        $outer = json_decode(trim($envelope), true);
        if (! is_array($outer)
            || ($outer['format'] ?? null) !== self::FORMAT
            || ! is_string($outer['key_id'] ?? null)
            || ! is_string($outer['payload'] ?? null)
            || ! is_string($outer['signature'] ?? null)) {
            throw LicenseException::rejected('LICENSE_MALFORMED', 'The license document is not a valid signed envelope.');
        }

        $keyId = $outer['key_id'];
        $publicKey = $this->trustedKey($keyId);

        $payloadBytes = $this->base64UrlDecode($outer['payload']);
        $signature = $this->base64UrlDecode($outer['signature']);
        if ($payloadBytes === null || $signature === null || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw LicenseException::rejected('LICENSE_MALFORMED', 'The license payload or signature is not valid base64url.');
        }

        try {
            $valid = sodium_crypto_sign_verify_detached($signature, $payloadBytes, $publicKey);
        } catch (Throwable) {
            $valid = false;
        }
        if (! $valid) {
            throw LicenseException::rejected('LICENSE_SIGNATURE_INVALID', 'The license signature does not verify.');
        }

        $payload = json_decode($payloadBytes, true);
        if (! is_array($payload) || ($payload['type'] ?? null) !== $expectedType) {
            throw LicenseException::rejected('LICENSE_WRONG_TYPE', "The signed document is not a {$expectedType}.");
        }
        if (($payload['format_version'] ?? null) !== 1) {
            throw LicenseException::rejected('LICENSE_MALFORMED', 'Unsupported license format version.');
        }

        return ['key_id' => $keyId, 'payload' => $payload];
    }

    /**
     * Validates the license payload schema and normalises it.
     *
     * @param  array<string, mixed>  $payload
     * @return array{license_id: string, licensee_name: string, installation_id: string, edition: string, plan: string, features: list<string>, limits: array{max_cameras: ?int, max_ai_nodes: ?int}, issued_at: CarbonImmutable, not_before: CarbonImmutable, expires_at: CarbonImmutable, grace_days: int}
     *
     * @throws LicenseException
     */
    public function normaliseLicense(array $payload): array
    {
        $fail = fn (string $why) => LicenseException::rejected('LICENSE_MALFORMED', "Invalid license payload: {$why}.");

        foreach (['license_id', 'installation_id'] as $field) {
            if (! is_string($payload[$field] ?? null) || ! Str::isUuid($payload[$field])) {
                throw $fail("{$field} must be a UUID");
            }
        }
        $name = $payload['licensee']['name'] ?? null;
        if (! is_string($name) || trim($name) === '' || mb_strlen($name) > 255) {
            throw $fail('licensee.name is required');
        }
        foreach (['edition', 'plan'] as $field) {
            if (! is_string($payload[$field] ?? null) || ! preg_match('/^[A-Z0-9_]{1,64}$/', $payload[$field])) {
                throw $fail("{$field} must be an upper-case code");
            }
        }

        $features = $payload['features'] ?? null;
        if (! is_array($features) || ! array_is_list($features)) {
            throw $fail('features must be a list');
        }
        foreach ($features as $feature) {
            if (! is_string($feature) || ! preg_match('/^[a-z0-9_]{1,64}$/', $feature)) {
                throw $fail('features must be lower-case codes');
            }
        }

        $limits = $payload['limits'] ?? null;
        if (! is_array($limits)) {
            throw $fail('limits must be an object');
        }
        $normalisedLimits = [];
        foreach (['max_cameras', 'max_ai_nodes'] as $limit) {
            $value = $limits[$limit] ?? null;
            if ($value !== null && (! is_int($value) || $value < 0)) {
                throw $fail("limits.{$limit} must be a non-negative integer or null");
            }
            $normalisedLimits[$limit] = $value;
        }

        $dates = [];
        foreach (['issued_at', 'not_before', 'expires_at'] as $field) {
            if (! is_string($payload[$field] ?? null)) {
                throw $fail("{$field} is required");
            }
            try {
                $dates[$field] = CarbonImmutable::parse($payload[$field])->utc();
            } catch (Throwable) {
                throw $fail("{$field} is not a date");
            }
        }
        if ($dates['expires_at'] <= $dates['not_before']) {
            throw $fail('expires_at must be after not_before');
        }

        $grace = $payload['grace_days'] ?? null;
        if (! is_int($grace) || $grace < 0 || $grace > 90) {
            throw $fail('grace_days must be an integer between 0 and 90');
        }

        return [
            'license_id' => strtolower($payload['license_id']),
            'licensee_name' => trim($name),
            'installation_id' => strtolower($payload['installation_id']),
            'edition' => $payload['edition'],
            'plan' => $payload['plan'],
            'features' => array_values(array_unique($features)),
            'limits' => $normalisedLimits,
            'issued_at' => $dates['issued_at'],
            'not_before' => $dates['not_before'],
            'expires_at' => $dates['expires_at'],
            'grace_days' => $grace,
        ];
    }

    private function trustedKey(string $keyId): string
    {
        $encoded = preg_match('/^[A-Za-z0-9_-]{1,64}$/', $keyId) ? config('license.trusted_keys.'.$keyId) : null;
        $key = is_string($encoded) ? base64_decode($encoded, true) : false;

        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw LicenseException::rejected('LICENSE_UNTRUSTED_KEY', 'The license was signed with an untrusted key.');
        }

        return $key;
    }

    private function base64UrlDecode(string $value): ?string
    {
        if ($value === '' || preg_match('/[^A-Za-z0-9_-]/', $value)) {
            return null;
        }
        $decoded = base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4), true);

        return $decoded === false ? null : $decoded;
    }
}
