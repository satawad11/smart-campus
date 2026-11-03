<?php

use App\Services\Camera\AiNodeCommandService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// V1 audit A-11: keep the scheduler's withoutOverlapping() mutexes out of
// Redis. With the default (Redis) cache store a Redis outage stopped every
// guarded job, including system:monitor (so no REDIS_HEALTH alert) and the
// scheduled backups. The database cache table exists in every environment.
Schedule::useCache(env('SCHEDULE_CACHE_STORE', 'database'));

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// CAM-014: stale-heartbeat -> OFFLINE detection. Runs more often than the
// default stale_after_seconds window so OFFLINE is flagged promptly.
Schedule::command('ai-nodes:detect-stale')->everyMinute();

// RPT-004: keep event_hourly_summaries/event_daily_summaries current. The
// command's own default window (trailing 2 hours) re-covers the
// still-filling current hour/day on every run rather than needing a
// separate "close out the previous hour" pass.
Schedule::command('events:refresh-summaries')->everyFifteenMinutes();

// SEC-010: prune old telemetry, snapshots, and exports according to retention settings
Schedule::command('retention:cleanup')->dailyAt('02:00');

// Pilot pre-deployment: automatic alerts for failed dependencies, stale
// cameras and queue backlog (SEC-013 alert table).
Schedule::command('system:monitor')->everyMinute()->withoutOverlapping(5);

// COM-03: unfinished remote node commands past their deadline become EXPIRED.
Schedule::call(fn () => app(AiNodeCommandService::class)->expireStale())
    ->name('ai-node-commands:expire')->everyMinute()->withoutOverlapping(5);

// COM-02: advance the license clock high-water mark and raise expiry/limit alerts.
Schedule::command('license:check')->hourly()->withoutOverlapping(10);

// Pilot backup policy (docs/pilot/PILOT-BACKUP-RETENTION-POLICY.md). Off by
// default so development stacks do not accumulate backups.
if (config('backup.schedule.enabled')) {
    Schedule::command('backup:run --type=POSTGRESQL --verify')
        ->cron(config('backup.schedule.database_cron'))->withoutOverlapping(60);
    Schedule::command('backup:run --type=STORAGE --verify')
        ->cron(config('backup.schedule.storage_cron'))->withoutOverlapping(120);
    Schedule::command('backup:prune')
        ->cron(config('backup.schedule.prune_cron'))->withoutOverlapping(60);
}

// ACR-INT-01: daily RMS student/staff sync. Off until the operator has
// reviewed a dry run (rms:sync --dry-run) and set RMS_SYNC_ENABLED=true.
if (config('rms.sync.enabled')) {
    Schedule::command('rms:sync --scheduled')
        ->cron(config('rms.sync.cron'))->withoutOverlapping(60);
}
