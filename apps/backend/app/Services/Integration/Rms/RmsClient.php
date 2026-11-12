<?php

namespace App\Services\Integration\Rms;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Read-only client for RMS API Center v1 (GET only, v=1, Bearer key in the
 * Authorization header, never in the URL). Lists are paged with
 * page/limit/has_more; all() follows every page. HTTP 429 is retried once
 * after Retry-After (capped). The key is never included in exceptions.
 */
class RmsClient
{
    public function __construct(
        private readonly string $url,
        private readonly ?string $key,
        private readonly int $timeoutSeconds = 30,
        private readonly int $pageLimit = 500,
        private readonly int $maxRetryAfterSeconds = 60,
    ) {}

    /**
     * The connection in effect: values saved from the web app (ACR-INT-02)
     * over .env/config (RmsConnectionSettings).
     */
    public static function fromConfig(): self
    {
        $settings = RmsConnectionSettings::current();

        return new self(
            $settings->url,
            $settings->key,
            $settings->timeoutSeconds,
            (int) config('rms.page_limit', 500),
            (int) config('rms.max_retry_after_seconds', 60),
        );
    }

    public function configured(): bool
    {
        return is_string($this->key) && $this->key !== '';
    }

    /**
     * One call; the decoded answer (ok:true).
     *
     * @param  array<string, scalar>  $query
     * @return array<string, mixed>
     */
    public function get(string $resource, array $query = []): array
    {
        if (! $this->configured()) {
            throw new RmsApiException('no_key', 'The RMS API key is not set.');
        }
        if (! str_starts_with($this->url, 'https://')) {
            throw new RmsApiException('https_required', 'The RMS API URL must use https://.');
        }

        $params = ['v' => 1, 'res' => $resource, ...$query];

        for ($attempt = 1; ; $attempt++) {
            try {
                $response = Http::timeout($this->timeoutSeconds)
                    ->acceptJson()
                    ->withToken((string) $this->key)
                    ->get($this->url, $params);
            } catch (ConnectionException $e) {
                throw new RmsApiException('connection', 'RMS unreachable: '.$e->getMessage());
            }

            if ($response->status() === 429 && $attempt === 1) {
                $wait = (int) ($response->header('Retry-After') ?: 1);
                if ($wait <= $this->maxRetryAfterSeconds) {
                    sleep(max(1, $wait));

                    continue;
                }
            }

            $json = $response->json();
            if (! is_array($json)) {
                throw new RmsApiException('invalid_response', "RMS HTTP {$response->status()}: the answer is not JSON.", $response->status());
            }
            if (($json['ok'] ?? false) !== true) {
                $error = (string) ($json['error'] ?? 'unknown');
                $message = (string) ($json['error_msg'] ?? '');

                throw new RmsApiException($error, "RMS HTTP {$response->status()} {$error}: {$message}", $response->status());
            }

            return $json;
        }
    }

    /**
     * Every page of a list resource (staff, groups, students, timetable).
     *
     * @param  array<string, scalar>  $query
     * @return list<array<string, mixed>>
     */
    public function all(string $resource, array $query = []): array
    {
        $items = [];
        $page = 1;
        do {
            $json = $this->get($resource, [...$query, 'page' => $page, 'limit' => $this->pageLimit]);
            foreach (($json['items'] ?? []) as $item) {
                $items[] = $item;
            }
            $page++;
            if ($page > 10000) {
                throw new RmsApiException('invalid_response', 'RMS paging did not end.');
            }
        } while (($json['has_more'] ?? false) === true);

        return $items;
    }
}
