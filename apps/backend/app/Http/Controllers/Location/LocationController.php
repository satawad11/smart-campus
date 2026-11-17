<?php

namespace App\Http\Controllers\Location;

use App\Enums\LocationStatus;
use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\Zone;
use App\Services\Audit\AuditRequestContext;
use App\Services\Audit\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Sites and zones from the web (UIX-002). Until now they could only be created
 * with `php artisan locations:provision`. Reads need system.view, writes
 * system.manage. Codes are fixed after creation (cameras, policies and reports
 * refer to them); records are deactivated, never deleted. Audit actions match
 * the CLI: location.site_created / location.zone_created, plus *_updated.
 */
class LocationController extends Controller
{
    public function __construct(
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', Rule::enum(LocationStatus::class)],
        ]);

        $sites = Site::query()
            ->withCount('zones')
            ->with(['zones' => fn ($q) => $q->withCount('cameras')->orderBy('code')])
            ->when($validated['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderBy('code')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => $sites->map(fn (Site $site) => $this->site($site))->all(),
            'meta' => null,
        ]);
    }

    public function storeSite(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/', 'unique:sites,code'],
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'timezone' => ['sometimes', 'timezone:all'],
            'status' => ['sometimes', Rule::enum(LocationStatus::class)],
        ]);

        $site = Site::create($data + ['timezone' => 'Asia/Bangkok', 'status' => LocationStatus::ACTIVE->value]);
        $this->record($request, 'location.site_created', 'site', $site->uuid, null, $site->only(['code', 'name', 'timezone', 'status']));

        return $this->respondSite($site, 'Site created', 201);
    }

    public function updateSite(Request $request, string $uuid): JsonResponse
    {
        $site = Site::where('uuid', $uuid)->firstOrFail();
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'timezone' => ['sometimes', 'timezone:all'],
            'status' => ['sometimes', Rule::enum(LocationStatus::class)],
            'code' => ['prohibited'],
            'uuid' => ['prohibited'],
            'id' => ['prohibited'],
        ]);

        $old = $site->only(array_keys($data));
        $site->fill($data)->save();
        if ($site->wasChanged()) {
            $this->record($request, 'location.site_updated', 'site', $site->uuid, $this->plain($old), $this->plain($site->only(array_keys($data))));
        }

        return $this->respondSite($site, 'Site updated');
    }

    public function storeZone(Request $request, string $uuid): JsonResponse
    {
        $site = Site::where('uuid', $uuid)->firstOrFail();
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('zones', 'code')->where('site_id', $site->id)],
            'name' => ['required', 'string', 'max:255'],
            'zone_type' => ['nullable', 'string', 'max:50'],
            'status' => ['sometimes', Rule::enum(LocationStatus::class)],
        ]);

        $zone = $site->zones()->create($data + ['status' => LocationStatus::ACTIVE->value]);
        $this->record($request, 'location.zone_created', 'zone', $zone->uuid, null, ['site' => $site->code] + $zone->only(['code', 'name', 'zone_type', 'status']));

        return $this->respondSite($site, 'Zone created', 201);
    }

    public function updateZone(Request $request, string $uuid): JsonResponse
    {
        $zone = Zone::where('uuid', $uuid)->firstOrFail();
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'zone_type' => ['sometimes', 'nullable', 'string', 'max:50'],
            'status' => ['sometimes', Rule::enum(LocationStatus::class)],
            'code' => ['prohibited'],
            'site_uuid' => ['prohibited'],
            'site_id' => ['prohibited'],
            'uuid' => ['prohibited'],
            'id' => ['prohibited'],
        ]);

        $old = $zone->only(array_keys($data));
        $zone->fill($data)->save();
        if ($zone->wasChanged()) {
            $this->record($request, 'location.zone_updated', 'zone', $zone->uuid, $this->plain($old), $this->plain($zone->only(array_keys($data))));
        }

        return $this->respondSite($zone->site, 'Zone updated');
    }

    private function respondSite(Site $site, string $message, int $status = 200): JsonResponse
    {
        $site->loadCount('zones')->load(['zones' => fn ($q) => $q->withCount('cameras')->orderBy('code')]);

        return response()->json(['success' => true, 'message' => $message, 'data' => $this->site($site), 'meta' => null], $status);
    }

    /**
     * @return array<string, mixed>
     */
    private function site(Site $site): array
    {
        return [
            'uuid' => $site->uuid,
            'code' => $site->code,
            'name' => $site->name,
            'address' => $site->address,
            'timezone' => $site->timezone,
            'status' => $site->status?->value ?? $site->status,
            'zones_count' => $site->zones_count,
            'zones' => $site->zones->map(fn (Zone $zone) => [
                'uuid' => $zone->uuid,
                'code' => $zone->code,
                'name' => $zone->name,
                'zone_type' => $zone->zone_type,
                'status' => $zone->status?->value ?? $zone->status,
                'cameras_count' => $zone->cameras_count ?? 0,
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function plain(array $values): array
    {
        return array_map(fn ($v) => $v instanceof \BackedEnum ? $v->value : $v, $values);
    }

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    private function record(Request $request, string $action, string $type, string $uuid, ?array $old, ?array $new): void
    {
        $this->audit->record(
            action: $action,
            actor: $request->user(),
            subjectType: $type,
            subjectUuid: $uuid,
            oldValues: $old,
            newValues: $new === null ? null : $this->plain($new),
            context: AuditRequestContext::fromRequest($request),
            module: 'location',
        );
    }
}
