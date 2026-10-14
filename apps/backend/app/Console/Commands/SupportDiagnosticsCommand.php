<?php

namespace App\Console\Commands;

use App\Models\AiNode;
use App\Models\BackupJob;
use App\Models\Camera;
use App\Models\FaceProfile;
use App\Models\Person;
use App\Models\SystemAlert;
use App\Services\License\LicenseService;
use App\Support\HealthChecks;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Support diagnostics bundle (COM-05). A JSON file the institution can send
 * to the vendor. By design it holds configuration checks, health, versions,
 * counts and redacted error lines only: never names, e-mail addresses,
 * student codes, face images, embeddings, IP addresses or credentials.
 */
class SupportDiagnosticsCommand extends Command
{
    protected $signature = 'support:diagnostics
                            {--out= : Output file (default storage/app/diagnostics/diagnostics-<timestamp>.json)}
                            {--log-lines=50 : Recent ERROR/CRITICAL log lines to include (redacted)}';

    protected $description = 'Write a redacted support diagnostics bundle (no personal or biometric data)';

    public function handle(HealthChecks $health, LicenseService $licenses): int
    {
        $bundle = [
            'generated_at' => now()->toIso8601String(),
            'app' => [
                'environment' => app()->environment(),
                'php' => PHP_VERSION,
                'laravel' => app()->version(),
                'database_driver' => DB::getDriverName(),
                'license_enforcement' => $licenses->enforcementMode(),
            ],
            'health' => $this->safe(fn () => [
                'database' => $health->database(),
                'redis' => $health->redis(),
                'storage' => $health->storage(),
                'ai_service' => $health->aiService(),
            ]),
            'preflight' => $this->safe(function () {
                Artisan::call('pilot:preflight', ['--json' => true]);

                return json_decode(Artisan::output(), true);
            }),
            'license' => $this->safe(function () use ($licenses) {
                $state = $licenses->state()->toArray();
                unset($state['license']['licensee_name']);

                return $state;
            }),
            'counts' => $this->safe(fn () => [
                'persons' => Person::count(),
                'face_profiles_active' => FaceProfile::where('status', 'ACTIVE')->count(),
                'cameras' => Camera::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
                'ai_nodes' => AiNode::count(),
            ]),
            'ai_nodes' => $this->safe(fn () => AiNode::query()->orderBy('node_code')->get()->map(fn (AiNode $n) => [
                'node_code' => $n->node_code,
                'status' => $n->status?->value,
                'software_version' => $n->software_version,
                'model' => trim(($n->model_code ?? '').' '.($n->model_version ?? '')) ?: null,
                'model_compatible' => $n->isModelCompatible(),
                'applied_config_revision' => $n->applied_config_revision,
                'last_seen_at' => $n->last_seen_at?->toIso8601String(),
            ])),
            'open_alerts' => $this->safe(fn () => SystemAlert::query()
                ->where('status', '!=', SystemAlert::STATUS_RESOLVED)
                ->selectRaw('type, severity, count(*) as n')->groupBy('type', 'severity')->get()
                ->map(fn ($r) => ['type' => $r->type, 'severity' => $r->severity, 'count' => (int) $r->n])),
            'backups' => $this->safe(fn () => BackupJob::query()->latest('created_at')->limit(10)->get()
                ->map(fn (BackupJob $j) => [
                    'type' => $j->type,
                    'status' => $j->status,
                    'size_bytes' => $j->size_bytes,
                    'encrypted' => (bool) ($j->metadata['encrypted'] ?? false),
                    'created_at' => $j->created_at?->toIso8601String(),
                ])),
            'recent_errors' => $this->recentErrors(max(0, (int) $this->option('log-lines'))),
        ];

        $out = (string) ($this->option('out') ?: storage_path('app/diagnostics/diagnostics-'.now()->format('Ymd_His').'.json'));
        File::ensureDirectoryExists(dirname($out), 0750);
        File::put($out, (string) json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        @chmod($out, 0600);

        $this->info("Diagnostics written to {$out}. Review it before sending; it contains no personal or biometric data by design.");

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function recentErrors(int $limit): array
    {
        $file = storage_path('logs/laravel.log');
        if ($limit === 0 || ! is_file($file)) {
            return [];
        }

        $lines = [];
        foreach (array_reverse(array_slice(file($file, FILE_IGNORE_NEW_LINES) ?: [], -5000)) as $line) {
            if (preg_match('/\.(ERROR|CRITICAL|ALERT|EMERGENCY):/', $line)) {
                $lines[] = self::redact($line);
                if (count($lines) >= $limit) {
                    break;
                }
            }
        }

        return array_reverse($lines);
    }

    /**
     * Remove anything that could identify a person or grant access.
     */
    public static function redact(string $line): string
    {
        $patterns = [
            '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i' => '[email]',
            '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i' => '[uuid]',
            '/\b(?:\d{1,3}\.){3}\d{1,3}\b/' => '[ip]',
            '/(password|passwd|secret|token|key|authorization)(["\']?\s*[:=]\s*)\S+/i' => '$1$2[redacted]',
            '/Bearer\s+\S+/i' => 'Bearer [redacted]',
            '/\[(?:-?\d+(?:\.\d+)?,\s*){8,}-?\d+(?:\.\d+)?\]/' => '[vector]',
            '/\b\d{5,}\b/' => '[number]',
            '/rtsp:\/\/\S+/i' => 'rtsp://[redacted]',
        ];

        return mb_substr((string) preg_replace(array_keys($patterns), array_values($patterns), $line), 0, 500);
    }

    private function safe(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            return ['error' => class_basename($e)];
        }
    }
}
