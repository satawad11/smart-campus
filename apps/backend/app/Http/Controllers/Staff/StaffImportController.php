<?php

namespace App\Http\Controllers\Staff;

use App\Enums\ImportJobType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\UploadStaffImportRequest;
use App\Http\Resources\ImportErrorResource;
use App\Http\Resources\ImportJobResource;
use App\Models\ImportJob;
use App\Services\Audit\AuditRequestContext;
use App\Services\Staff\StaffImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Staff CSV import API (Module 09, PPL-004), the counterpart of the student import.
 * Only STAFF import jobs are reachable here; a student job's uuid answers 404.
 */
class StaffImportController extends Controller
{
    public function __construct(
        private readonly StaffImportService $service,
    ) {}

    public function upload(UploadStaffImportRequest $request): JsonResponse
    {
        $job = $this->service->uploadAndValidate(
            $request->file('file'),
            $request->user(),
            AuditRequestContext::fromRequest($request),
            (string) $request->input('conflict_mode', 'CREATE_ONLY'),
        );

        return (new ImportJobResource($job))->response()->setStatusCode(201);
    }

    public function show(ImportJob $importJob): JsonResponse
    {
        $this->assertStaffJob($importJob);

        return (new ImportJobResource($importJob->loadMissing('creator')))->response();
    }

    public function errors(Request $request, ImportJob $importJob): JsonResponse
    {
        $this->assertStaffJob($importJob);
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $errors = $importJob->errors()
            ->orderBy('row_number')
            ->orderBy('id')
            ->paginate((int) ($validated['per_page'] ?? 50));

        return ImportErrorResource::collection($errors)->response();
    }

    public function preview(ImportJob $importJob): JsonResponse
    {
        $this->assertStaffJob($importJob);

        return response()->json($this->service->preview($importJob));
    }

    public function process(Request $request, ImportJob $importJob): JsonResponse
    {
        $this->assertStaffJob($importJob);
        $request->validate(['conflict_mode' => ['prohibited']], [
            'conflict_mode.prohibited' => 'conflict_mode cannot be overridden at process time. Revalidation is required to change conflict policy.',
        ]);

        $job = $this->service->dispatchProcess($importJob, $request->user(), AuditRequestContext::fromRequest($request));

        return (new ImportJobResource($job))->response()->setStatusCode(202);
    }

    private function assertStaffJob(ImportJob $job): void
    {
        if ($job->type !== ImportJobType::STAFF) {
            throw new NotFoundHttpException;
        }
    }
}
