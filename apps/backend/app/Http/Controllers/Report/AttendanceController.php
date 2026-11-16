<?php

namespace App\Http\Controllers\Report;

use App\Http\Controllers\Concerns\EnforcesScope;
use App\Http\Controllers\Controller;
use App\Models\AccessEvent;
use App\Services\Report\AttendanceReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Daily time-in / time-out (GET /reports/attendance). Built on the fly from
 * ALLOW recognition events within the caller's site scope (report.view).
 */
class AttendanceController extends Controller
{
    use EnforcesScope;

    public function __construct(
        private readonly AttendanceReportService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'tz' => ['sometimes', 'timezone:all'],
            'site_uuid' => ['bail', 'sometimes', 'string', 'uuid', 'exists:sites,uuid'],
            'person_type' => ['sometimes', 'string', 'in:STUDENT,TEACHER,STAFF,PARENT,VISITOR,CONTRACTOR,ALUMNI,OTHER'],
            'keyword' => ['sometimes', 'string', 'max:255'],
        ]);

        $scoped = $this->scopeQuery(AccessEvent::query(), $request, 'report.view');
        $report = $this->service->daily($scoped, $validated);

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => $report['rows'],
            'meta' => [
                'date' => $report['date'],
                'timezone' => $report['timezone'],
                'total' => count($report['rows']),
            ],
        ]);
    }
}
