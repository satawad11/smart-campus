<?php

namespace App\Console\Commands;

use App\Services\Report\EventSummaryRefreshService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * RPT-004 — refresh/backfill event_hourly_summaries and
 * event_daily_summaries (RPT-003) from raw AccessEvent/AccessDecision.
 *
 * Two uses:
 *  - Periodic refresh (no options, scheduled every 15 minutes — see
 *    routes/console.php): recomputes a short trailing window (default: the
 *    last 2 hours) so the still-filling current hour/day stays current
 *    without re-scanning the whole event history every run.
 *  - One-off backfill (--from/--to): recomputes an arbitrary historical
 *    range, e.g. after this table was first introduced, or to repair a
 *    gap. Always safe to re-run over any range — refreshHour()/refreshDay()
 *    are pure re-derivations from raw events, idempotent per bucket.
 */
class RefreshEventSummaries extends Command
{
    protected $signature = 'events:refresh-summaries
        {--from= : Start of the range to refresh (default: 2 hours ago)}
        {--to= : End of the range to refresh, exclusive (default: now)}
        {--hourly-only : Skip daily summaries}
        {--daily-only : Skip hourly summaries}';

    protected $description = 'RPT-004: refresh event_hourly_summaries/event_daily_summaries from raw event data';

    public function handle(EventSummaryRefreshService $service): int
    {
        $from = $this->option('from') !== null
            ? Carbon::parse($this->option('from'))
            : now()->subHours(2)->startOfHour();
        $to = $this->option('to') !== null
            ? Carbon::parse($this->option('to'))
            : now();

        if ($from->gte($to)) {
            $this->error('--from must be before --to.');

            return self::FAILURE;
        }

        $hourlyRows = 0;
        $dailyRows = 0;

        if (! $this->option('daily-only')) {
            for ($hour = $from->copy()->startOfHour(); $hour->lt($to); $hour->addHour()) {
                $hourlyRows += $service->refreshHour($hour->copy());
            }
        }

        if (! $this->option('hourly-only')) {
            for ($day = $from->copy()->startOfDay(); $day->lt($to); $day->addDay()) {
                $dailyRows += $service->refreshDay($day->copy());
            }
        }

        $this->info("Refreshed summaries for {$from->toIso8601String()} .. {$to->toIso8601String()}: {$hourlyRows} hourly row(s), {$dailyRows} daily row(s) upserted.");

        return self::SUCCESS;
    }
}
