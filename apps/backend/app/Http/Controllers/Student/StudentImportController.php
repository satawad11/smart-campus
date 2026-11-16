<?php

namespace App\Http\Controllers\Student;

use App\Enums\ImportJobType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Student\UploadStudentImportRequest;
use App\Http\Resources\ImportErrorResource;
use App\Http\Resources\ImportJobResource;
use App\Models\ImportJob;
use App\Services\Audit\AuditRequestContext;
use App\Services\Student\StudentImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Student import intake, validation, and status API (STD-012).
 *
 * Exposes upload, validation progress, row errors, and preview.
 * Authorization is strictly enforced by permission:student.import middleware.
 * Public identity is ImportJob.uuid; internal IDs are never accepted or exposed.
 */
class StudentImportController extends Controller
{
    public function __construct(
        private readonly StudentImportService $service,
    ) {}

    /**
     * Upload a student import file and execute intake validation.
     */
    public function upload(UploadStudentImportRequest $request): JsonResponse
    {
        $actor = $request->user();
        $file = $request->file('file');
        $conflictMode = $request->input('conflict_mode', 'CREATE_ONLY');
        $context = AuditRequestContext::fromRequest($request);

        $job = $this->service->uploadAndValidate($file, $actor, $context, $conflictMode);

        return (new ImportJobResource($job))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Retrieve status, counters, and metadata for an import job.
     */
    public function show(Request $request, ImportJob $importJob): JsonResponse
    {
        $this->assertStudentJob($importJob);
        $importJob->loadMissing('creator');

        return (new ImportJobResource($importJob))
            ->response()
            ->setStatusCode(200);
    }

    /**
     * Retrieve paginated validation and processing errors for an import job.
     */
    public function errors(Request $request, ImportJob $importJob): JsonResponse
    {
        $this->assertStudentJob($importJob);
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 50);

        $errors = $importJob->errors()
            ->orderBy('row_number', 'asc')
            ->orderBy('id', 'asc')
            ->paginate($perPage);

        return ImportErrorResource::collection($errors)
            ->response()
            ->setStatusCode(200);
    }

    /**
     * Retrieve preview data and summary for an import job.
     */
    public function preview(Request $request, ImportJob $importJob): JsonResponse
    {
        $this->assertStudentJob($importJob);
        $preview = $this->service->getPreview($importJob);

        return response()->json($preview, 200);
    }

    /**
     * Trigger background execution of a validated import job (STD-013, STD-014).
     *
     * Invariant: Executes strictly under the persisted conflict_mode from intake validation.
     * Process-time conflict_mode override is prohibited.
     */
    public function process(Request $request, ImportJob $importJob): JsonResponse
    {
        $this->assertStudentJob($importJob);
        $request->validate([
            'conflict_mode' => ['prohibited'],
        ], [
            'conflict_mode.prohibited' => 'conflict_mode cannot be overridden at process time. Revalidation is required to change conflict policy.',
        ]);

        $actor = $request->user();
        $context = AuditRequestContext::fromRequest($request);

        $job = $this->service->dispatchProcess($importJob, $actor, $context);

        return (new ImportJobResource($job))
            ->response()
            ->setStatusCode(202);
    }

    /**
     * Staff import jobs (PPL-004) share import_jobs; they are never reachable
     * through the student import endpoints.
     */
    private function assertStudentJob(ImportJob $job): void
    {
        if ($job->type !== ImportJobType::STUDENT) {
            throw new NotFoundHttpException;
        }
    }
}
