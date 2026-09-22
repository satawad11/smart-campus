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
