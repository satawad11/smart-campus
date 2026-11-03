<?php

namespace App\Http\Controllers\Integration;

use App\Http\Controllers\Controller;
use App\Jobs\RunRmsSyncJob;
use App\Models\RmsSyncRun;
use App\Services\Integration\Rms\RmsClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * RMS sync runs (ACR-INT-01): list, report, and start a run on the queue.
 * Requires permission:student.sync.
 */
class RmsSyncController extends Controller
{
    public function index(): JsonResponse
    {
        $runs = RmsSyncRun::with('createdBy:id,username')->latest('id')->limit(20)->get();

        return response()->json([
            'data' => $runs->map(fn (RmsSyncRun $run) => $this->present($run, withIssues: false)),
            'meta' => [
                'configured' => RmsClient::fromConfig()->configured(),
                'schedule_enabled' => (bool) config('rms.sync.enabled'),
                'schedule_cron' => config('rms.sync.cron'),
                'running' => RmsSyncRun::where('status', RmsSyncRun::RUNNING)->where('started_at', '>', now()->subHour())->exists(),
            ],
        ]);
    }

    public function show(RmsSyncRun $rmsSyncRun): JsonResponse
    {
        $rmsSyncRun->loadMissing('createdBy:id,username');

        return response()->json(['data' => $this->present($rmsSyncRun, withIssues: true)]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate(['dry_run' => ['required', 'boolean']]);

        RunRmsSyncJob::dispatch((bool) $validated['dry_run'], $request->user()?->id);

        return response()->json(['data' => ['queued' => true, 'dry_run' => (bool) $validated['dry_run']]], 202);
    }

    /** @return array<string, mixed> */
    private function present(RmsSyncRun $run, bool $withIssues): array
    {
        return [
            'uuid' => $run->uuid,
            'status' => $run->status,
            'trigger' => $run->trigger,
            'dry_run' => $run->dry_run,
            'counts' => $run->counts ?? (object) [],
            'issue_summary' => array_map(fn ($i) => ['code' => $i['code'], 'count' => $i['count']], $run->issues ?? []),
            'issues' => $withIssues ? ($run->issues ?? []) : null,
            'error' => $run->error,
            'created_by' => $run->createdBy?->username,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
        ];
    }
}
