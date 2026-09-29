<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

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

