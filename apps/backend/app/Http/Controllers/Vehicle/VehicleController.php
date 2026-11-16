<?php

namespace App\Http\Controllers\Vehicle;

use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Vehicle\LinkVehiclePersonRequest;
use App\Http\Requests\Vehicle\StoreVehicleRequest;
use App\Http\Requests\Vehicle\UnlinkVehiclePersonRequest;
use App\Http\Requests\Vehicle\UpdateVehicleRequest;
use App\Http\Resources\VehicleLinkResource;
use App\Http\Resources\VehicleResource;
use App\Models\Vehicle;
use App\Models\VehicleOwnershipHistory;
use App\Services\Audit\AuditRequestContext;
use App\Services\Vehicle\VehicleRegistryService;
use App\Support\Vehicles\ThaiPlate;
use App\Support\Vehicles\ThaiProvince;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Vehicle registry API (VEH-004, ACR-VEH-01).
 *
 * Behind feature:vehicle (off by default) and vehicle.view / vehicle.manage. UUID public
 * identity only. No DELETE: a vehicle is deactivated (status INACTIVE) and a person link is
 * ended, never removed, so history stays intact.
 */
class VehicleController extends Controller
{
    private const SORTABLE = ['plate_number', 'status', 'vehicle_type', 'created_at', 'updated_at'];

    public function __construct(
        private readonly VehicleRegistryService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'keyword' => ['sometimes', 'string', 'max:255'],
            'plate' => ['sometimes', 'string', 'max:32'],
            'province' => ['sometimes', 'string', 'max:64'],
            'status' => ['sometimes', Rule::enum(VehicleStatus::class)],
            'vehicle_type' => ['sometimes', Rule::enum(VehicleType::class)],
            'person_uuid' => ['sometimes', 'uuid'],
            'sort' => ['sometimes', 'string', 'in:'.implode(',', self::SORTABLE)],
            'order' => ['sometimes', 'string', 'in:asc,desc'],
        ]);

        $query = Vehicle::query();

        // Exact plate search, normalised the same way as stored plates ("1กข-1234" finds "1กข1234").
        if (isset($validated['plate'])) {
            $query->where('plate_number', ThaiPlate::tryNormalize($validated['plate']) ?? "\0");
        }
        if (isset($validated['province'])) {
            $query->where('plate_province', ThaiProvince::resolve($validated['province']) ?? "\0");
        }
        if (! empty($validated['keyword'])) {
            $keyword = '%'.mb_strtolower($validated['keyword']).'%';
            $compact = '%'.(string) preg_replace('/[\s\-.]+/u', '', mb_strtolower($validated['keyword'])).'%';
            $query->where(function ($q) use ($keyword, $compact) {
                $q->whereRaw('LOWER(plate_number) LIKE ?', [$compact])
                    ->orWhereRaw('LOWER(brand) LIKE ?', [$keyword])
                    ->orWhereRaw('LOWER(model) LIKE ?', [$keyword])
                    ->orWhereRaw('LOWER(color) LIKE ?', [$keyword]);
            });
        }
        foreach (['status', 'vehicle_type'] as $field) {
            if (isset($validated[$field])) {
                $query->where($field, $validated[$field]);
            }
        }
        if (isset($validated['person_uuid'])) {
            $query->whereHas('currentOwnerships.person', fn ($q) => $q->where('uuid', $validated['person_uuid']));
        }

        $paginator = $query
            ->orderBy($validated['sort'] ?? 'created_at', $validated['order'] ?? 'desc')
            ->paginate((int) ($validated['per_page'] ?? 20));

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => VehicleResource::collection($paginator->items()),
            'meta' => [
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
            ],
        ]);
    }

    public function store(StoreVehicleRequest $request): JsonResponse
    {
        $vehicle = $this->service->create($request->validated(), $request->user(), AuditRequestContext::fromRequest($request));

        return $this->respond(new VehicleResource($vehicle), 'Vehicle registered', 201);
    }

    public function show(Vehicle $vehicle): JsonResponse
    {
        $vehicle->load(['ownershipHistories' => fn ($q) => $q->with('person')->orderByDesc('effective_from')]);

        return $this->respond(new VehicleResource($vehicle));
    }

    public function update(UpdateVehicleRequest $request, Vehicle $vehicle): JsonResponse
    {
        $vehicle = $this->service->update($vehicle, $request->validated(), $request->user(), AuditRequestContext::fromRequest($request));

        return $this->respond(new VehicleResource($vehicle), 'Vehicle updated');
    }

    public function link(LinkVehiclePersonRequest $request, Vehicle $vehicle): JsonResponse
    {
        $link = $this->service->link($vehicle, $request->validated(), $request->user(), AuditRequestContext::fromRequest($request));

        return $this->respond(new VehicleLinkResource($link), 'Person linked', 201);
    }

    public function unlink(UnlinkVehiclePersonRequest $request, Vehicle $vehicle, VehicleOwnershipHistory $link): JsonResponse
    {
        $link = $this->service->unlink($vehicle, $link, $request->validated(), $request->user(), AuditRequestContext::fromRequest($request));

        return $this->respond(new VehicleLinkResource($link), 'Link ended');
    }

    private function respond(mixed $data, string $message = 'Success', int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data, 'meta' => null], $status);
    }
}
