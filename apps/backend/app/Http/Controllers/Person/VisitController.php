<?php

namespace App\Http\Controllers\Person;

use App\Enums\PersonType;
use App\Http\Controllers\Concerns\EnforcesScope;
use App\Http\Controllers\Controller;
use App\Http\Resources\VisitResource;
use App\Models\Person;
use App\Models\Site;
use App\Models\Visit;
use App\Models\Zone;
use App\Services\Audit\AuditRequestContext;
use App\Services\People\VisitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Site-scoped visit planning and gate transitions (PPL-006). */
class VisitController extends Controller
{
    use EnforcesScope;

    public function __construct(private readonly VisitService $service) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', 'string', 'in:SCHEDULED,CHECKED_IN,CHECKED_OUT,EXPIRED,CANCELLED'],
            'site_uuid' => ['sometimes', 'uuid', 'exists:sites,uuid'],
            'visitor_person_uuid' => ['sometimes', 'uuid', 'exists:persons,uuid'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $query = $this->scopeQuery(Visit::query(), $request, 'visit.view')->with(['visitor', 'host', 'site', 'zone']);
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }
        if (! empty($data['site_uuid'])) {
            $siteId = Site::where('uuid', $data['site_uuid'])->value('id');
            $this->assertInScope($request, 'visit.view', $siteId);
            $query->where('site_id', $siteId);
        }
        if (! empty($data['visitor_person_uuid'])) {
            $query->whereHas('visitor', fn ($person) => $person->where('uuid', $data['visitor_person_uuid']));
        }
        if (! empty($data['from'])) {
            $query->where('valid_until', '>=', $data['from']);
        }
        if (! empty($data['to'])) {
            $query->where('valid_from', '<=', $data['to']);
        }
        $page = $query->orderByDesc('valid_from')->paginate((int) ($data['per_page'] ?? 20));

        return response()->json([
            'success' => true, 'message' => 'Success', 'data' => VisitResource::collection($page->items()),
            'meta' => ['pagination' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()]],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateVisit($request, true);
        [$visitor, $host, $site, $zone] = $this->resolveReferences($data);
        $this->assertInScope($request, 'visit.manage', $site->id, $zone?->id);
        $visit = $this->service->create([
            'visitor_person_id' => $visitor->id,
            'host_person_id' => $host?->id,
            'purpose' => $data['purpose'],
            'site_id' => $site->id,
            'zone_id' => $zone?->id,
            'valid_from' => $data['valid_from'],
            'valid_until' => $data['valid_until'],
        ], $request->user(), AuditRequestContext::fromRequest($request));

        return response()->json(['success' => true, 'message' => 'Visit created', 'data' => new VisitResource($visit), 'meta' => null], 201);
    }

    public function show(Request $request, Visit $visit): JsonResponse
    {
        $this->assertInScope($request, 'visit.view', $visit->site_id, $visit->zone_id);

        return response()->json(['success' => true, 'message' => 'Success', 'data' => new VisitResource($visit->load(['visitor', 'host', 'site', 'zone'])), 'meta' => null]);
    }

    public function update(Request $request, Visit $visit): JsonResponse
    {
        $this->assertInScope($request, 'visit.manage', $visit->site_id, $visit->zone_id);
        $data = $this->validateVisit($request, false);
        $data['visitor_person_uuid'] = $visit->visitor->uuid;
        [$visitor, $host, $site, $zone] = $this->resolveReferences($data);
        $this->assertInScope($request, 'visit.manage', $site->id, $zone?->id);
        $visit = $this->service->update($visit, [
            'host_person_id' => $host?->id,
            'purpose' => $data['purpose'],
            'site_id' => $site->id,
            'zone_id' => $zone?->id,
            'valid_from' => $data['valid_from'],
            'valid_until' => $data['valid_until'],
        ], $request->user(), AuditRequestContext::fromRequest($request));

        return response()->json(['success' => true, 'message' => 'Visit updated', 'data' => new VisitResource($visit), 'meta' => null]);
    }

    public function checkIn(Request $request, Visit $visit): JsonResponse
    {
        return $this->transition($request, $visit, fn () => $this->service->checkIn($visit, $request->user(), AuditRequestContext::fromRequest($request)), 'Visitor checked in');
    }

    public function checkOut(Request $request, Visit $visit): JsonResponse
    {
        return $this->transition($request, $visit, fn () => $this->service->checkOut($visit, $request->user(), AuditRequestContext::fromRequest($request)), 'Visitor checked out');
    }

    public function cancel(Request $request, Visit $visit): JsonResponse
    {
        return $this->transition($request, $visit, fn () => $this->service->cancel($visit, $request->user(), AuditRequestContext::fromRequest($request)), 'Visit cancelled');
    }

    /** @param callable(): Visit $action */
    private function transition(Request $request, Visit $visit, callable $action, string $message): JsonResponse
    {
        $this->assertInScope($request, 'visit.manage', $visit->site_id, $visit->zone_id);
        $updated = $action();

        return response()->json(['success' => true, 'message' => $message, 'data' => new VisitResource($updated), 'meta' => null]);
    }

    /** @return array<string, mixed> */
    private function validateVisit(Request $request, bool $creating): array
    {
        return $request->validate([
            'visitor_person_uuid' => [$creating ? 'required' : 'sometimes', 'uuid', 'exists:persons,uuid'],
            'host_person_uuid' => ['nullable', 'uuid', 'exists:persons,uuid'],
            'purpose' => ['required', 'string', 'max:2000'],
            'site_uuid' => ['required', 'uuid', 'exists:sites,uuid'],
            'zone_uuid' => ['nullable', 'uuid', 'exists:zones,uuid'],
            'valid_from' => ['required', 'date'],
            'valid_until' => ['required', 'date', 'after:valid_from'],
        ]);
    }

    /** @param array<string, mixed> $data @return array{Person, ?Person, Site, ?Zone} */
    private function resolveReferences(array $data): array
    {
        $visitor = Person::where('uuid', $data['visitor_person_uuid'])->firstOrFail();
        if (! $visitor || ! in_array($visitor->person_type, [PersonType::VISITOR, PersonType::CONTRACTOR], true) || ! $visitor->visitorProfile()->exists()) {
            throw ValidationException::withMessages(['visitor_person_uuid' => ['Selected person is not a registered visitor or contractor.']]);
        }

        $host = ! empty($data['host_person_uuid']) ? Person::where('uuid', $data['host_person_uuid'])->firstOrFail() : null;
        if ($host && ! in_array($host->person_type, [PersonType::TEACHER, PersonType::STAFF], true)) {
            throw ValidationException::withMessages(['host_person_uuid' => ['Host must be a teacher or staff member.']]);
        }
        $site = Site::where('uuid', $data['site_uuid'])->firstOrFail();
        if (($site->status?->value ?? $site->status) !== 'ACTIVE') {
            throw ValidationException::withMessages(['site_uuid' => ['Site is inactive.']]);
        }
        $zone = ! empty($data['zone_uuid']) ? Zone::where('uuid', $data['zone_uuid'])->firstOrFail() : null;
        if ($zone && ($zone->site_id !== $site->id || ($zone->status?->value ?? $zone->status) !== 'ACTIVE')) {
            throw ValidationException::withMessages(['zone_uuid' => ['Zone must be active and belong to the selected site.']]);
        }

        return [$visitor, $host, $site, $zone];
    }
}
