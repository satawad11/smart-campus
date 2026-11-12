<?php

namespace App\Services\Integration\Rms;

use App\Models\IntegrationConnection;
use Throwable;

/**
 * The RMS connection in effect (ACR-INT-02): values saved from the web app
 * (integration_connections, code RMS) win; anything not saved there comes
 * from .env/config/rms.php. Read on every use, so a change made in the web
 * app applies to the next call, sync and scheduler minute without a restart.
 * When the table is not there yet (before migrate) the config is used.
 */
final class RmsConnectionSettings
{
    public const SOURCE_DATABASE = 'database';

    public const SOURCE_ENV = 'env';

    private function __construct(
        public readonly string $url,
        public readonly ?string $key,
        public readonly ?string $keySource,
        public readonly ?string $keyHint,
        public readonly bool $keyUnreadable,
        public readonly int $timeoutSeconds,
        public readonly bool $syncEnabled,
        public readonly string $syncCron,
        public readonly ?string $syncTime,
        public readonly ?IntegrationConnection $row,
    ) {}

    public static function current(): self
    {
        $row = null;
        try {
            $row = IntegrationConnection::where('code', IntegrationConnection::RMS)->first();
        } catch (Throwable) {
            // integration_connections not migrated yet: .env only.
        }

        $settings = $row?->settings ?? [];

        $dbKey = $row?->apiKey();
        $envKey = config('rms.key');
        $envKey = is_string($envKey) && $envKey !== '' ? $envKey : null;

        if ($dbKey !== null) {
            [$key, $source, $hint] = [$dbKey, self::SOURCE_DATABASE, $row->api_key_hint];
        } elseif ($envKey !== null) {
            [$key, $source, $hint] = [$envKey, self::SOURCE_ENV, IntegrationConnection::hintFor($envKey)];
        } else {
            [$key, $source, $hint] = [null, null, null];
        }

        $syncTime = isset($settings['sync_time']) && is_string($settings['sync_time']) ? $settings['sync_time'] : null;
        $cron = $syncTime !== null ? self::cronForTime($syncTime) : (string) config('rms.sync.cron', '30 5 * * *');

        return new self(
            url: (string) ($row?->base_url ?: config('rms.url')),
            key: $key,
            keySource: $source,
            keyHint: $hint,
            keyUnreadable: $row?->hasUnreadableKey() ?? false,
            timeoutSeconds: (int) ($settings['timeout_seconds'] ?? config('rms.timeout_seconds', 30)),
            syncEnabled: (bool) ($settings['sync_enabled'] ?? config('rms.sync.enabled', false)),
            syncCron: $cron,
            syncTime: $syncTime ?? self::timeForCron($cron),
            row: $row,
        );
    }

    public function configured(): bool
    {
        return $this->key !== null;
    }

    /** "05:30" -> "30 5 * * *" */
    public static function cronForTime(string $time): string
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return "{$minute} {$hour} * * *";
    }

    /** "30 5 * * *" -> "05:30"; null for any other cron form. */
    public static function timeForCron(string $cron): ?string
    {
        if (preg_match('/^(\d{1,2}) (\d{1,2}) \* \* \*$/', trim($cron), $m) !== 1) {
            return null;
        }
        if ((int) $m[1] > 59 || (int) $m[2] > 23) {
            return null;
        }

        return sprintf('%02d:%02d', (int) $m[2], (int) $m[1]);
    }
}
