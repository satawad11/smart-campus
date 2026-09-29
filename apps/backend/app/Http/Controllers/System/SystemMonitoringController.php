<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Services\System\AlertLifecycleService;
use App\Services\System\IncidentService;
use App\Services\System\SystemHealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SystemMonitoringController extends Controller
{
    public function __construct(
        protected SystemHealthService $healthService,
        protected AlertLifecycleService $alertService,
        protected IncidentService $incidentService
    ) {}

    /**
     * GET /api/v1/system/health
     */
    public function health(): JsonResponse
    {
        $health = $this->healthService->getAggregateHealth();

        return response()->json([
            'success' => true,
            'message' => 'System health status retrieved',
            'data' => $health,
        ]);
    }

    /**
     * GET /api/v1/system/alerts
     */
    public function alerts(Request $request): JsonResponse
    {
        $alerts = $this->alertService->listAlerts(
            $request->query('status'),
            $request->query('severity')
        );

        return response()->json([
            'success' => true,
            'message' => 'System alerts retrieved',
            'data' => $alerts,
        ]);
    }

    /**
     * POST /api/v1/system/alerts/{alert}/acknowledge
     */
    public function acknowledgeAlert(Request $request, string $alertId): JsonResponse
    {
        $alert = $this->alertService->acknowledgeAlert($alertId, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'System alert acknowledged',
            'data' => $alert,
        ]);
    }

    /**
     * POST /api/v1/system/alerts/{alert}/resolve
     */
    public function resolveAlert(Request $request, string $alertId): JsonResponse
    {
        $request->validate(['notes' => 'nullable|string|max:1000']);
        $alert = $this->alertService->resolveAlert($alertId, $request->user(), $request->input('notes'));

        return response()->json([
            'success' => true,
            'message' => 'System alert resolved',
            'data' => $alert,
        ]);
    }

    /**
     * GET /api/v1/system/incidents
     */
    public function incidents(Request $request): JsonResponse
    {
        $incidents = $this->incidentService->listIncidents($request->query('status'));

        return response()->json([
            'success' => true,
            'message' => 'System incidents retrieved',
            'data' => $incidents,
        ]);
    }

    /**
     * POST /api/v1/system/incidents
     */
    public function storeIncident(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'severity' => 'required|in:SEV1,SEV2,SEV3',
            'description' => 'required|string',
            'impact_summary' => 'nullable|string',
        ]);

        $incident = $this->incidentService->createIncident($validated, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'System incident created',
            'data' => $incident,
        ], 201);
    }

    /**
     * PUT /api/v1/system/incidents/{incident}
     */
    public function updateIncident(Request $request, string $incidentId): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'severity' => 'sometimes|in:SEV1,SEV2,SEV3',
            'status' => 'sometimes|in:OPEN,INVESTIGATING,MITIGATED,RESOLVED,POST_MORTEM',
            'description' => 'sometimes|string',
            'impact_summary' => 'nullable|string',
            'root_cause' => 'nullable|string',
            'resolution_summary' => 'nullable|string',
            'action_items' => 'nullable|array',
        ]);

        $incident = $this->incidentService->updateIncident($incidentId, $validated, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'System incident updated',
            'data' => $incident,
        ]);
    }
}
