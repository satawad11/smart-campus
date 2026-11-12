<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;

/**
 * External API connection set from the web app (ACR-INT-02). Null columns
 * fall back to .env/config. The API key is encrypted at rest and hidden from
 * every serialization; only api_key_hint (the non-secret key id) is shown.
 *
 * @property int $id
 * @property string $code
 * @property string|null $base_url
 * @property string|null $api_key_encrypted
 * @property string|null $api_key_hint
 * @property array<string, mixed>|null $settings
 * @property Carbon|null $last_tested_at
 * @property bool|null $last_test_ok
 * @property string|null $last_test_message
 * @property int|null $updated_by_user_id
 */
class IntegrationConnection extends Model
{
    public const RMS = 'RMS';

    protected $fillable = [
        'code',
        'base_url',
        'settings',
        'last_tested_at',
        'last_test_ok',
        'last_test_message',
        'updated_by_user_id',
    ];

    protected $hidden = [
        'api_key_encrypted',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'last_tested_at' => 'datetime',
            'last_test_ok' => 'boolean',
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function setApiKey(?string $plain): void
    {
        if ($plain === null || $plain === '') {
            $this->api_key_encrypted = null;
            $this->api_key_hint = null;

            return;
        }

        $this->api_key_encrypted = Crypt::encryptString($plain);
        $this->api_key_hint = self::hintFor($plain);
    }

    /** The stored key, or null when none is stored or it cannot be decrypted (APP_KEY changed). */
    public function apiKey(): ?string
    {
        if ($this->api_key_encrypted === null || $this->api_key_encrypted === '') {
            return null;
        }

        try {
            return Crypt::decryptString($this->api_key_encrypted);
        } catch (DecryptException) {
            return null;
        }
    }

    public function hasUnreadableKey(): bool
    {
        return $this->api_key_encrypted !== null && $this->api_key_encrypted !== '' && $this->apiKey() === null;
    }

    /**
     * Non-secret part of a key for display: the RMS key id ("rmsk_9bcdb909_")
     * or, for other formats, the first four characters.
     */
    public static function hintFor(string $key): string
    {
        if (preg_match('/^(rmsk_[A-Za-z0-9]{1,16}_)/', $key, $m) === 1) {
            return $m[1].'…';
        }

        return mb_substr($key, 0, 4).'…';
    }
}
