<?php

namespace App\Services\Gate;

use App\Enums\AuditAction;
use App\Enums\GateCameraRole;
use App\Enums\LocationStatus;
use App\Exceptions\ApiException;
use App\Models\Camera;
use App\Models\Gate;
use App\Models\GateCamera;
use App\Models\Site;
use App\Models\User;
use App\Models\Zone;
use App\Services\Audit\AuditRequestContext;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;

/**
 * Smart Gate configuration service (GATE-002, ACR-VEH-01 Phase 5).
 *
 * Owns the gate rules, camera assignment and audit. Configuration only: nothing here decides
 * access or drives a barrier (decision D5). Face first (D2): a gate can be ACTIVE only while at
 * least one of its cameras reads faces. The site and code of a gate are fixed after creation.
 */
class GateService
{
    private const MUTABLE = ['name', 'direction', 'correlation_window_seconds', 'status', 'notes'];

    public function __construct(
        private readonly AuditService $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated StoreGateRequest data
     */
    public function create(array $data, ?User $actor, ?AuditRequestContext $context): Gate
    {
        $site = Site::where('uuid', $data['site_uuid'])->first()
            ?? throw ApiException::unprocessable('SITE_NOT_FOUND', 'Site not found.');
        $zone = $this->zoneInSite($data['zone_uuid'], $site->id);
        $status = $data['status'] ?? LocationStatus::INACTIVE->value;

        return DB::transaction(function () use ($data, $site, $zone, $status, $actor, $context) {
            $exists = Gate::where('site_id', $site->id)->where('code', $data['code'])->lockForUpdate()->exists();
            if ($exists) {
                throw ApiException::conflict('GATE_CODE_EXISTS', 'A gate with this code already exists at this site.');
            }

            $gate = Gate::create([
                'site_id' => $site->id,
                'zone_id' => $zone->id,
                'code' => $data['code'],
                'name' => $data['name'],
                'direction' => $data['direction'] ?? 'BOTH',
                'correlation_window_seconds' => $data['correlation_window_seconds'] ?? 10,
                'mode' => 'FACE_PRIMARY',
                'status' => LocationStatus::INACTIVE->value,
                'notes' => $data['notes'] ?? null,
                'created_by_user_id' => $actor?->id,
            ]);

            $cameras = $this->writeCameras($gate, $data['cameras'] ?? []);
            if ($status === LocationStatus::ACTIVE->value) {
                $this->assertCanBeActive($cameras);
                $gate->update(['status' => $status]);
            }

            $this->audit->record(
                AuditAction::GATE_CREATED, $actor, 'GATE', $gate->uuid,
                newValues: [
                    'site_uuid' => $site->uuid,
                    'zone_uuid' => $zone->uuid,
                    'code' => $gate->code,
                    'name' => $gate->name,
                    'direction' => $gate->direction->value,
                    'correlation_window_seconds' => $gate->correlation_window_seconds,
                    'status' => $status,
                    'cameras' => $this->cameraSummary($cameras),
                ],
                context: $context, module: 'gate',
            );

            return $this->loadDetail($gate);
        });
    }

    /**
     * @param  array<string, mixed>  $data  validated UpdateGateRequest data
     */
    public function update(Gate $gate, array $data, ?User $actor, ?AuditRequestContext $context): Gate
    {
        return DB::transaction(function () use ($gate, $data, $actor, $context) {
            $old = [];
            $new = [];

            if (isset($data['zone_uuid'])) {
                $zone = $this->zoneInSite($data['zone_uuid'], $gate->site_id);
                if ($zone->id !== $gate->zone_id) {
                    $old['zone_uuid'] = $gate->zone?->uuid;
                    $new['zone_uuid'] = $zone->uuid;
                    $gate->zone_id = $zone->id;
                }
            }

            foreach (self::MUTABLE as $field) {
                if (! array_key_exists($field, $data)) {
                    continue;
                }
                $current = $gate->{$field};
                $currentValue = $current instanceof \BackedEnum ? $current->value : $current;
                if ($currentValue !== $data[$field]) {
                    $old[$field] = $currentValue;
                    $new[$field] = $data[$field];
                    $gate->{$field} = $data[$field];
                }
            }

            if (($new['status'] ?? null) === LocationStatus::ACTIVE->value) {
                $this->assertCanBeActive($gate->gateCameras()->get()->all());
            }

            if ($new !== []) {
                $gate->save();
                $this->audit->record(
                    AuditAction::GATE_UPDATED, $actor, 'GATE', $gate->uuid,
                    oldValues: $old, newValues: $new + ['code' => $gate->code],
                    context: $context, module: 'gate',
                );
            }

            return $this->loadDetail($gate->refresh());
        });
    }

    /**
     * Replace the gate's camera set.
     *
     * @param  list<array{camera_uuid:string, role:string}>  $cameras
     */
    public function replaceCameras(Gate $gate, array $cameras, ?User $actor, ?AuditRequestContext $context): Gate
    {
        return DB::transaction(function () use ($gate, $cameras, $actor, $context) {
            $before = $this->cameraSummary($gate->gateCameras()->with('camera')->get()->all());

            $gate->gateCameras()->delete();
            $links = $this->writeCameras($gate, $cameras);

            if ($gate->status === LocationStatus::ACTIVE) {
                $this->assertCanBeActive($links);
            }

            $after = $this->cameraSummary($links);
            if ($before !== $after) {
                $this->audit->record(
                    AuditAction::GATE_CAMERAS_CHANGED, $actor, 'GATE', $gate->uuid,
                    oldValues: ['cameras' => $before], newValues: ['cameras' => $after, 'code' => $gate->code],
                    context: $context, module: 'gate',
                );
            }

            return $this->loadDetail($gate->refresh());
        });
    }

    public function loadDetail(Gate $gate): Gate
    {
        return $gate->load(['site', 'zone', 'gateCameras.camera']);
    }

    private function zoneInSite(string $zoneUuid, int $siteId): Zone
    {
        $zone = Zone::where('uuid', $zoneUuid)->first()
            ?? throw ApiException::unprocessable('ZONE_NOT_FOUND', 'Zone not found.');
        if ($zone->site_id !== $siteId) {
            throw ApiException::unprocessable('GATE_ZONE_SITE_MISMATCH', 'The zone is not in the gate site.');
        }

        return $zone;
    }

    /**
     * @param  list<array{camera_uuid:string, role:string}>  $cameras
     * @return list<GateCamera>
     */
    private function writeCameras(Gate $gate, array $cameras): array
    {
        $uuids = array_column($cameras, 'camera_uuid');
        if (count($uuids) !== count(array_unique($uuids))) {
            throw ApiException::unprocessable('GATE_CAMERA_DUPLICATE', 'A camera is listed more than once.');
        }

        $links = [];
        foreach ($cameras as $item) {
            $camera = Camera::where('uuid', $item['camera_uuid'])->lockForUpdate()->first()
                ?? throw ApiException::unprocessable('CAMERA_NOT_FOUND', 'Camera not found.');
            if ($camera->site_id !== $gate->site_id) {
                throw ApiException::unprocessable('GATE_CAMERA_SITE_MISMATCH', 'The camera is not in the gate site.');
            }
            $taken = GateCamera::where('camera_id', $camera->id)->where('gate_id', '!=', $gate->id)->exists();
            if ($taken) {
                throw ApiException::conflict('GATE_CAMERA_IN_USE', 'The camera is already assigned to another gate.');
            }

            $link = GateCamera::create(['gate_id' => $gate->id, 'camera_id' => $camera->id, 'role' => $item['role']]);
            $link->setRelation('camera', $camera);
            $links[] = $link;
        }

        return $links;
    }

    /**
     * Face first (D2): an active gate needs at least one camera that reads faces.
     *
     * @param  list<GateCamera>  $links
     */
    private function assertCanBeActive(array $links): void
    {
        foreach ($links as $link) {
            if ($link->role instanceof GateCameraRole && $link->role->readsFaces()) {
                return;
            }
        }

        throw ApiException::unprocessable('GATE_NEEDS_FACE_CAMERA', 'An active gate needs at least one camera that reads faces.');
    }

    /**
     * @param  list<GateCamera>  $links
     * @return list<array{camera_uuid:?string, role:string}>
     */
    private function cameraSummary(array $links): array
    {
        $summary = array_map(fn (GateCamera $l) => [
            'camera_uuid' => $l->camera?->uuid,
            'role' => $l->role instanceof GateCameraRole ? $l->role->value : (string) $l->role,
        ], $links);
        usort($summary, fn ($a, $b) => strcmp((string) $a['camera_uuid'], (string) $b['camera_uuid']));

        return $summary;
    }
}
