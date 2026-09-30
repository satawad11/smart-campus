<?php

namespace App\Console\Commands;

use App\Enums\LocationStatus;
use App\Models\Site;
use App\Models\Zone;
use App\Services\Audit\AuditService;
use Illuminate\Console\Command;

/**
 * Create the pilot site and its zones (V1 audit A-02).
 *
 * V1 has no site/zone management API or UI, yet cameras require a site and
 * zone UUID and SITE/ZONE role scopes reference them. This idempotent,
 * audited command is the supported way to create them on the pilot host.
 */
class ProvisionLocationCommand extends Command
{
    protected $signature = 'locations:provision
                            {site_code : Unique site code}
                            {site_name : Site display name}
                            {--timezone=Asia/Bangkok : Site timezone}
                            {--zone=* : Zone as CODE:Name[:TYPE], repeatable (e.g. GATE-1:Main Gate:GATE)}';

    protected $description = 'Create or confirm a site and its zones (idempotent, audited); prints their UUIDs';

    public function handle(AuditService $audit): int
    {
        $timezone = (string) $this->option('timezone');
        if (! in_array($timezone, timezone_identifiers_list(), true)) {
            $this->error("Invalid timezone: {$timezone}");

            return self::FAILURE;
        }

        $site = Site::where('code', $this->argument('site_code'))->first();
        if ($site === null) {
            $site = Site::create([
                'code' => $this->argument('site_code'),
                'name' => $this->argument('site_name'),
                'timezone' => $timezone,
                'status' => LocationStatus::ACTIVE,
            ]);
            $this->audit($audit, 'location.site_created', 'site', $site->uuid, ['code' => $site->code, 'timezone' => $timezone]);
        }
        $this->line("site {$site->code} {$site->uuid}");

        foreach ((array) $this->option('zone') as $spec) {
            $parts = array_map('trim', explode(':', (string) $spec));
            if (count($parts) < 2 || $parts[0] === '' || $parts[1] === '') {
                $this->error("Invalid --zone '{$spec}', expected CODE:Name[:TYPE]");

                return self::FAILURE;
            }

            $zone = Zone::where('site_id', $site->id)->where('code', $parts[0])->first();
            if ($zone === null) {
                $zone = Zone::create([
                    'site_id' => $site->id,
                    'code' => $parts[0],
                    'name' => $parts[1],
                    'zone_type' => $parts[2] ?? null,
                    'status' => LocationStatus::ACTIVE,
                ]);
                $this->audit($audit, 'location.zone_created', 'zone', $zone->uuid, ['site' => $site->code, 'code' => $zone->code]);
            }
            $this->line("zone {$site->code}/{$zone->code} {$zone->uuid}");
        }

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $values */
    private function audit(AuditService $audit, string $action, string $type, string $uuid, array $values): void
    {
        $audit->record(
            action: $action,
            actor: null,
            subjectType: $type,
            subjectUuid: $uuid,
            oldValues: null,
            newValues: $values,
            metadata: ['source' => 'cli'],
            module: 'location'
        );
    }
}
