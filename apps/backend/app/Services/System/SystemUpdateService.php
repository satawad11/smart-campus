<?php

namespace App\Services\System;

use App\Enums\AuditAction;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\Audit\AuditRequestContext;
use App\Services\Audit\AuditService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Web "System update" (ACR-OPS-01).
 *
 * The backend never runs host commands and never holds the GitHub token. It
 * only reads the token-free JSON the root-run host agent publishes and drops
 * HMAC-signed requests of two allowlisted types ("check", "update") into the
 * agent's inbox. The agent re-validates everything (signature, expiry, replay,
 * tag format, tag newer than installed, kit present) before acting.
 */
class SystemUpdateService
{
    public const TAG_PATTERN = '/^v[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$/';

    private const USER_PATTERN = '/^[A-Za-z0-9._@-]{1,64}$/';

    public function __construct(
        private readonly AuditService $audit,
    ) {}

    /**
     * Everything the page shows. Only fields the agent publishes, never secrets.
     *
     * @return array<string, mixed>
     */
    public function state(): array
    {
        $installed = $this->readState('installed.json');
        $releasesDoc = $this->readState('releases.json');
        $installedVersion = is_array($installed) ? ($installed['version'] ?? null) : null;

        $releases = [];
        $installedIndex = null;
        foreach ((is_array($releasesDoc) ? ($releasesDoc['releases'] ?? []) : []) as $i => $r) {
            if (! is_array($r) || ! is_string($r['tag'] ?? null) || ! preg_match(self::TAG_PATTERN, $r['tag'])) {
                continue;
            }
            if ($r['tag'] === $installedVersion) {
                $installedIndex = count($releases);
            }
            $releases[] = [
                'tag' => $r['tag'],
                'name' => (string) ($r['name'] ?? $r['tag']),
                'published_at' => $r['published_at'] ?? null,
                'prerelease' => (bool) ($r['prerelease'] ?? false),
                'has_kit' => (bool) ($r['has_kit'] ?? false),
                'notes' => (string) ($r['notes'] ?? ''),
            ];
        }
        // GitHub lists releases newest first: newer = listed before the installed one.
        foreach ($releases as $i => &$release) {
            $release['is_installed'] = $i === $installedIndex;
            $release['is_newer'] = $installedIndex !== null && $i < $installedIndex;
        }
        unset($release);

        $history = $this->readState('history.json');

        return [
            'installed' => is_array($installed) ? [
                'version' => $installedVersion,
                'installed_at' => $installed['installed_at'] ?? null,
                'updated_at' => $installed['updated_at'] ?? null,
            ] : null,
            'web_update' => $this->availability(),
            'checked_at' => is_array($releasesDoc) ? ($releasesDoc['checked_at'] ?? null) : null,
            'releases' => $releases,
            'history' => array_slice(is_array($history) ? array_values(array_filter($history, 'is_array')) : [], 0, (int) config('system_update.history_limit', 20)),
            'job' => $this->job(),
            'pending' => $this->pending(),
        ];
    }

    /**
     * @return array{type: string, request_id: string}
     */
    public function requestCheck(User $actor, ?AuditRequestContext $context = null): array
    {
        $this->assertAvailable();
        foreach ($this->pending() as $p) {
            if ($p['type'] === 'check') {
                return ['type' => 'check', 'request_id' => $p['request_id']];
            }
        }
        $id = $this->writeRequest($actor, 'check', null);
        $this->audit->record(AuditAction::SYSTEM_UPDATE_CHECK_REQUESTED, $actor, 'system_update', $id,
            null, null, null, $context, 'system');

        return ['type' => 'check', 'request_id' => $id];
    }

    /**
     * @return array{type: string, request_id: string, version: string}
     *
     * @throws ValidationException|ApiException
     */
    public function requestUpdate(User $actor, string $version, string $password, ?AuditRequestContext $context = null): array
    {
        if (! Hash::check($password, $actor->password)) {
            $this->audit->record(AuditAction::SYSTEM_UPDATE_REJECTED, $actor, 'system_update', null,
                null, ['version' => $version], ['reason' => 'WRONG_PASSWORD'], $context, 'system');
            throw ValidationException::withMessages(['current_password' => ['รหัสผ่านไม่ถูกต้อง']]);
        }

        $this->assertAvailable();

        $state = $this->state();
        $job = $state['job'];
        $pendingUpdate = array_filter($state['pending'], fn ($p) => $p['type'] === 'update');
        if (($job !== null && $job['state'] === 'running' && ! $job['stale']) || $pendingUpdate !== []) {
            throw ApiException::conflict('SYSTEM_UPDATE_IN_PROGRESS', 'An update is already queued or running');
        }

        $target = collect($state['releases'])->firstWhere('tag', $version);
        if ($target === null || ! $target['is_newer'] || ! $target['has_kit']) {
            $this->audit->record(AuditAction::SYSTEM_UPDATE_REJECTED, $actor, 'system_update', null,
                null, ['version' => $version], ['reason' => 'VERSION_NOT_ALLOWED'], $context, 'system');
            throw ApiException::unprocessable('SYSTEM_UPDATE_VERSION_NOT_ALLOWED',
                'Only a newer release with an install kit for this server can be installed from the web');
        }

        $id = $this->writeRequest($actor, 'update', $version);
        $this->audit->record(AuditAction::SYSTEM_UPDATE_REQUESTED, $actor, 'system_update', $id,
            ['version' => $state['installed']['version'] ?? null], ['version' => $version],
            ['prerelease' => $target['prerelease']], $context, 'system');

        return ['type' => 'update', 'request_id' => $id, 'version' => $version];
    }

    /**
     * @return array{enabled: bool, reason: string|null}
     */
    public function availability(): array
    {
        if (! is_array($this->readState('installed.json'))) {
            return ['enabled' => false, 'reason' => 'NOT_KIT_INSTALL'];
        }
        $agent = $this->readState('agent.json');
        if (! is_array($agent) || ! ($agent['enabled'] ?? false)) {
            return ['enabled' => false, 'reason' => is_array($agent) && ($agent['reason'] ?? null) === 'DISABLED_BY_ADMIN' ? 'DISABLED_BY_ADMIN' : 'AGENT_UNAVAILABLE'];
        }
        $inbox = $this->inbox();
        if (strlen((string) config('system_update.agent_key')) < 32 || ! is_dir($inbox) || ! is_writable($inbox)) {
            return ['enabled' => false, 'reason' => 'NOT_CONFIGURED'];
        }

        return ['enabled' => true, 'reason' => null];
    }

    private function assertAvailable(): void
    {
        $availability = $this->availability();
        if (! $availability['enabled']) {
            throw ApiException::conflict('SYSTEM_UPDATE_UNAVAILABLE', 'Updating from the web is not available on this server ('.$availability['reason'].')');
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function job(): ?array
    {
        $status = $this->readState('status.json');
        if (! is_array($status) || ! is_string($status['state'] ?? null)) {
            return null;
        }
        $started = $this->parseTime($status['started_at'] ?? null);
        $stale = $status['state'] === 'running'
            && ($started === null || $started->lt(CarbonImmutable::now()->subHours((int) config('system_update.stale_job_hours', 3))));

        return [
            'request_id' => $status['request_id'] ?? null,
            'type' => $status['type'] ?? null,
            'version' => $status['version'] ?? null,
            'from_version' => $status['from_version'] ?? null,
            'requested_by' => $status['requested_by'] ?? null,
            'state' => $status['state'],
            'message' => (string) ($status['message'] ?? ''),
            'started_at' => $status['started_at'] ?? null,
            'finished_at' => $status['finished_at'] ?? null,
            'log_tail' => array_values(array_filter((array) ($status['log_tail'] ?? []), 'is_string')),
            'stale' => $stale,
        ];
    }

    /**
     * Requests written by this backend that the agent has not picked up yet.
     *
     * @return list<array{request_id: string, type: string, version: string|null, requested_by: string|null, requested_at: string|null}>
     */
    private function pending(): array
    {
        $inbox = $this->inbox();
        if (! is_dir($inbox)) {
            return [];
        }
        $out = [];
        foreach (glob($inbox.'/*.json') ?: [] as $file) {
            $req = json_decode((string) @file_get_contents($file), true);
            if (! is_array($req) || ($req['expires_at'] ?? 0) < time()) {
                continue;
            }
            $out[] = [
                'request_id' => (string) ($req['id'] ?? ''),
                'type' => (string) ($req['type'] ?? ''),
                'version' => $req['version'] ?? null,
                'requested_by' => $req['requested_by'] ?? null,
                'requested_at' => $req['requested_at'] ?? null,
            ];
        }

        return $out;
    }

    private function writeRequest(User $actor, string $type, ?string $version): string
    {
        $id = (string) Str::uuid();
        $requestedBy = preg_match(self::USER_PATTERN, (string) $actor->username) ? (string) $actor->username : (string) $actor->uuid;
        $request = [
            'id' => $id,
            'type' => $type,
            'version' => $version,
            'requested_by' => $requestedBy,
            'requested_at' => CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z'),
            'expires_at' => time() + (int) config('system_update.request_ttl_seconds', 600),
        ];
        // Must match Agent.signing_string in deploy/campus-server/update-agent.py.
        $signed = implode("\n", [$id, $type, $version ?? '', $requestedBy, (string) $request['expires_at']]);
        $request['signature'] = hash_hmac('sha256', $signed, (string) config('system_update.agent_key'));

        // Write outside the watched inbox, then rename (atomic on one filesystem).
        $tmpDir = rtrim((string) config('system_update.request_dir'), '/').'/tmp';
        if (! is_dir($tmpDir)) {
            @mkdir($tmpDir, 0700, true);
        }
        $tmp = $tmpDir.'/'.$id.'.json';
        if (@file_put_contents($tmp, json_encode($request, JSON_UNESCAPED_SLASHES)) === false
            || ! @rename($tmp, $this->inbox().'/'.$id.'.json')) {
            @unlink($tmp);
            throw new ApiException('SYSTEM_UPDATE_REQUEST_FAILED', 'Could not hand the request to the update agent', 500);
        }

        return $id;
    }

    private function inbox(): string
    {
        return rtrim((string) config('system_update.request_dir'), '/').'/inbox';
    }

    private function readState(string $name): mixed
    {
        $path = rtrim((string) config('system_update.state_dir'), '/').'/'.$name;
        if (! is_file($path) || filesize($path) > 2_000_000) {
            return null;
        }

        return json_decode((string) @file_get_contents($path), true);
    }

    private function parseTime(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value)) {
            return null;
        }
        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
