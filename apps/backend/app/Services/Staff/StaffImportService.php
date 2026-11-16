<?php

namespace App\Services\Staff;

use App\Enums\AuditAction;
use App\Enums\ImportConflictMode;
use App\Enums\ImportErrorCode;
use App\Enums\ImportJobStatus;
use App\Enums\ImportJobType;
use App\Enums\StaffEmploymentStatus;
use App\Enums\StatusChangeSource;
use App\Exceptions\ApiException;
use App\Http\Resources\ImportErrorResource;
use App\Http\Resources\ImportJobResource;
use App\Jobs\ProcessStaffImportJob;
use App\Models\Department;
use App\Models\ImportError;
use App\Models\ImportJob;
use App\Models\Person;
use App\Models\StaffProfile;
use App\Models\User;
use App\Services\Audit\AuditRequestContext;
use App\Services\Audit\AuditService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Teacher/staff CSV import (Module 09, PPL-004).
 *
 * Same flow and storage as the student import (STD-011..014): upload -> intake
 * validation with row-level errors -> preview -> queued processing under the
 * conflict mode chosen at upload. Every write goes through StaffService, so each
 * imported row gets the same Person + profile + history + audit as a manual create.
 *
 * Columns (header row, case-insensitive):
 *   required  staff_code, first_name, last_name
 *   optional  person_type (TEACHER|STAFF, default STAFF), display_name, gender, person_code,
 *             position, department_code, phone, email, employment_status
 */
class StaffImportService
{
    private const REQUIRED_HEADERS = ['staff_code', 'first_name', 'last_name'];

    private const VALID_GENDERS = ['MALE', 'FEMALE', 'OTHER', 'UNKNOWN'];

    private const MAX_ROWS = 5000;

    public function __construct(
        private readonly AuditService $audit,
        private readonly StaffService $staffService,
    ) {}

    public function uploadAndValidate(UploadedFile $file, User $actor, ?AuditRequestContext $context, string $conflictMode): ImportJob
    {
        $mode = ImportConflictMode::tryFrom($conflictMode) ?? ImportConflictMode::CREATE_ONLY;
        $uuid = (string) Str::uuid();
        $path = $file->storeAs("imports/staff/{$uuid}", Str::uuid().'.csv', 'local');

        $job = ImportJob::create([
            'uuid' => $uuid,
            'type' => ImportJobType::STAFF,
            'file_path' => $path,
            'status' => ImportJobStatus::PENDING,
            'conflict_mode' => $mode,
            'created_by' => $actor->id,
        ]);

        $this->record(AuditAction::STAFF_IMPORT_UPLOADED, $job, $actor, $context, ['conflict_mode' => $mode->value]);

        return $this->validate($job, $actor, $context);
    }

    public function validate(ImportJob $job, User $actor, ?AuditRequestContext $context): ImportJob
    {
        $job->update(['status' => ImportJobStatus::VALIDATING, 'started_at' => now()]);

        $rows = $this->readRows($job);
        if (is_string($rows)) {
            return $this->failIntake($job, $actor, $context, ImportErrorCode::from($rows), $this->intakeMessage($rows));
        }
        [$headers, $data] = $rows;

        $missing = array_values(array_diff(self::REQUIRED_HEADERS, $headers));
        if ($missing !== []) {
            return $this->failIntake($job, $actor, $context, ImportErrorCode::MISSING_REQUIRED_HEADER,
                'Missing required header(s): '.implode(', ', $missing));
        }
        if ($data === []) {
            return $this->failIntake($job, $actor, $context, ImportErrorCode::EMPTY_FILE, 'The uploaded file contains no data rows.');
        }

        $mode = $job->conflict_mode ?? ImportConflictMode::CREATE_ONLY;
        $existing = StaffProfile::with('person')->get()->keyBy(fn ($p) => strtoupper($p->staff_code));
        $personsByCode = Person::whereNotNull('person_code')->where('person_code', '!=', '')
            ->get()->keyBy(fn ($p) => strtoupper($p->person_code));
        $departments = Department::all()->keyBy(fn ($d) => strtoupper($d->code));

        $errors = [];
        $seen = [];
        $valid = 0;
        $invalid = 0;

        foreach ($data as $rowNumber => $row) {
            if (count($seen) >= self::MAX_ROWS) {
                $errors[] = $this->error($job, $rowNumber, 'file', (string) self::MAX_ROWS, ImportErrorCode::INVALID_FORMAT,
                    'Maximum import limit of '.self::MAX_ROWS.' rows exceeded.');
                $invalid++;
                break;
            }

            $rowErrors = $this->validateRow($job, $rowNumber, $row, $seen, $existing, $personsByCode, $departments, $mode);
            $errors = array_merge($errors, $rowErrors);
            $rowErrors === [] ? $valid++ : $invalid++;
        }

        foreach (array_chunk($errors, 100) as $chunk) {
            ImportError::insert($chunk);
        }

        $job->update([
            'status' => ImportJobStatus::VALIDATED,
            'total_rows' => count($data),
            'valid_rows' => $valid,
            'invalid_rows' => $invalid,
            'success_rows' => 0,
            'failed_rows' => 0,
            'skipped_rows' => 0,
            'completed_at' => now(),
        ]);

        $this->record(AuditAction::STAFF_IMPORT_VALIDATED, $job, $actor, $context, [
            'conflict_mode' => $mode->value,
            'total_rows' => count($data),
            'valid_rows' => $valid,
            'invalid_rows' => $invalid,
        ]);

        return $job->fresh(['creator', 'errors']);
    }

    public function dispatchProcess(ImportJob $job, User $actor, ?AuditRequestContext $context): ImportJob
    {
        $locked = DB::transaction(function () use ($job) {
            /** @var ImportJob $locked */
            $locked = ImportJob::whereKey($job->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== ImportJobStatus::VALIDATED) {
                throw ApiException::unprocessable('IMPORT_JOB_NOT_PROCESSABLE',
                    "Import job cannot be processed in status '{$locked->status->value}'. Only VALIDATED jobs can be processed.");
            }

            $locked->update(['status' => ImportJobStatus::PROCESSING, 'started_at' => now()]);

            return $locked;
        });

        $this->record(AuditAction::STAFF_IMPORT_PROCESSING_STARTED, $locked, $actor, $context, [
            'conflict_mode' => $locked->conflict_mode->value,
            'valid_rows' => $locked->valid_rows,
        ]);

        ProcessStaffImportJob::dispatch($locked);

        return $locked->fresh(['creator', 'errors']);
    }

    /** Queued: writes every row that passed intake validation through StaffService. */
    public function process(ImportJob $job): void
    {
        $rows = $this->readRows($job);
        if (is_string($rows)) {
            $this->handleFailure($job, new \RuntimeException('Import file could not be read for processing.'));

            return;
        }
        [, $data] = $rows;

        $invalidRows = array_fill_keys($job->errors()->pluck('row_number')->all(), true);
        $departments = Department::all()->keyBy(fn ($d) => strtoupper($d->code));
        $mode = $job->conflict_mode ?? ImportConflictMode::CREATE_ONLY;
        $success = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($data as $rowNumber => $row) {
            if (isset($invalidRows[$rowNumber])) {
                continue;
            }

            try {
                $existing = StaffProfile::where('staff_code', $row['staff_code'])->first();

                if ($existing !== null && $mode === ImportConflictMode::SKIP_EXISTING) {
                    $skipped++;

                    continue;
                }

                $status = ($row['employment_status'] ?? '') !== ''
                    ? StaffEmploymentStatus::from(strtoupper($row['employment_status']))
                    : null;
                $department = ($row['department_code'] ?? '') !== ''
                    ? $departments->get(strtoupper($row['department_code']))
                    : null;

                if ($existing !== null) {
                    // UPDATE_EXISTING (CREATE_ONLY duplicates were rejected at validation).
                    $this->staffService->updateStaff($existing, array_filter([
                        'person' => array_filter([
                            'first_name' => $row['first_name'],
                            'last_name' => $row['last_name'],
                            'display_name' => $row['display_name'] ?? null,
                            'gender' => isset($row['gender']) && $row['gender'] !== '' ? strtoupper($row['gender']) : null,
                        ], fn ($v) => $v !== null && $v !== ''),
                        'position' => $row['position'] ?? null,
                        'department_uuid' => $department?->uuid,
                        'phone' => $row['phone'] ?? null,
                        'email' => $row['email'] ?? null,
                    ], fn ($v) => $v !== null && $v !== '' && $v !== []), $job->creator, null);
                    $profile = $existing->fresh();
                } else {
                    $profile = $this->staffService->createStaff([
                        'person' => [
                            'person_code' => ($row['person_code'] ?? '') !== '' ? $row['person_code'] : null,
                            'first_name' => $row['first_name'],
                            'last_name' => $row['last_name'],
                            'display_name' => ($row['display_name'] ?? '') !== '' ? $row['display_name'] : null,
                            'gender' => ($row['gender'] ?? '') !== '' ? strtoupper($row['gender']) : null,
                        ],
                        'person_type' => ($row['person_type'] ?? '') !== '' ? strtoupper($row['person_type']) : 'STAFF',
                        'staff_code' => $row['staff_code'],
                        'position' => ($row['position'] ?? '') !== '' ? $row['position'] : null,
                        'department_uuid' => $department?->uuid,
                        'phone' => ($row['phone'] ?? '') !== '' ? $row['phone'] : null,
                        'email' => ($row['email'] ?? '') !== '' ? $row['email'] : null,
                    ], $job->creator, null, StatusChangeSource::IMPORT);
                }

                if ($status !== null && $status !== $profile->employment_status) {
                    $this->staffService->changeEmploymentStatus($profile, $status, 'Updated via staff import',
                        $job->creator, null, StatusChangeSource::IMPORT);
                }

                $success++;
            } catch (Throwable $e) {
                $failed++;
                ImportError::create($this->error($job, $rowNumber, 'staff_code', $row['staff_code'] ?? null,
                    $e instanceof ApiException ? $e->errorCode : 'STAFF_IMPORT_ROW_FAILED', mb_substr($e->getMessage(), 0, 500)));
            }
        }

        $job->update([
            'status' => ImportJobStatus::COMPLETED,
            'success_rows' => $success,
            'failed_rows' => $failed,
            'skipped_rows' => $skipped,
            'completed_at' => now(),
        ]);

        $this->record(AuditAction::STAFF_IMPORT_COMPLETED, $job, $job->creator, null, [
            'conflict_mode' => $mode->value,
            'success_rows' => $success,
            'failed_rows' => $failed,
            'skipped_rows' => $skipped,
        ]);
    }

    public function handleFailure(ImportJob $job, ?Throwable $exception): void
    {
        $job->refresh();
        if ($job->status === ImportJobStatus::COMPLETED) {
            return;
        }

        $job->update(['status' => ImportJobStatus::FAILED, 'completed_at' => now()]);
        ImportError::create($this->error($job, 1, 'system', null, 'SYSTEM_ERROR',
            $exception ? mb_substr($exception->getMessage(), 0, 500) : 'An unexpected error occurred during import processing.'));
        $this->record(AuditAction::STAFF_IMPORT_FAILED, $job, $job->creator, null, ['reason' => 'CATASTROPHIC_FAILURE']);
    }

    /**
     * @return array<string, mixed>
     */
    public function preview(ImportJob $job, int $limit = 10): array
    {
        $rows = $this->readRows($job);
        $errorsByRow = $job->errors()->get()->groupBy('row_number');
        $sample = [];

        if (! is_string($rows)) {
            foreach (array_slice($rows[1], 0, $limit, true) as $rowNumber => $row) {
                $codes = $errorsByRow->get($rowNumber)?->pluck('error_code')->all() ?? [];
                $sample[] = $row + [
                    '_row_number' => $rowNumber,
                    '_has_conflict' => array_intersect($codes, [ImportErrorCode::DUPLICATE_STAFF_CODE->value, ImportErrorCode::IDENTITY_MISMATCH->value, ImportErrorCode::PERSON_CODE_CONFLICT->value]) !== [],
                    '_errors' => $codes,
                ];
            }
        }

        return [
            'job' => new ImportJobResource($job),
            'summary' => [
                'total_rows' => $job->total_rows,
                'valid_rows' => $job->valid_rows,
                'invalid_rows' => $job->invalid_rows,
                'conflict_mode' => $job->conflict_mode?->value,
                'is_ready_for_execution' => $job->status === ImportJobStatus::VALIDATED && $job->valid_rows > 0,
            ],
            'sample_rows' => $sample,
            'sample_errors' => ImportErrorResource::collection($job->errors()->orderBy('row_number')->limit($limit)->get()),
        ];
    }

    /**
     * @param  array<string, string|null>  $row
     * @param  array<string, int>  $seen
     * @return list<array<string, mixed>>
     */
    private function validateRow(
        ImportJob $job,
        int $rowNumber,
        array $row,
        array &$seen,
        Collection $existing,
        Collection $personsByCode,
        Collection $departments,
        ImportConflictMode $mode,
    ): array {
        $errors = [];
        $add = function (string $field, ?string $value, ImportErrorCode $code, string $message) use (&$errors, $job, $rowNumber) {
            $errors[] = $this->error($job, $rowNumber, $field, $value, $code, $message);
        };

        foreach (['staff_code' => 50, 'first_name' => 100, 'last_name' => 100] as $field => $max) {
            $value = $row[$field] ?? '';
            if ($value === '') {
                $add($field, null, ImportErrorCode::REQUIRED_FIELD_MISSING, "{$field} is required.");
            } elseif (mb_strlen($value) > $max) {
                $add($field, mb_substr($value, 0, $max), ImportErrorCode::VALUE_TOO_LONG, "{$field} must not exceed {$max} characters.");
            }
        }

        foreach (['display_name' => 255, 'position' => 255, 'person_code' => 255, 'phone' => 50, 'email' => 255] as $field => $max) {
            if (mb_strlen($row[$field] ?? '') > $max) {
                $add($field, mb_substr($row[$field], 0, $max), ImportErrorCode::VALUE_TOO_LONG, "{$field} must not exceed {$max} characters.");
            }
        }

        $enumChecks = [
            'person_type' => ['TEACHER', 'STAFF'],
            'gender' => self::VALID_GENDERS,
            'employment_status' => array_column(StaffEmploymentStatus::cases(), 'value'),
        ];
        foreach ($enumChecks as $field => $allowed) {
            $value = strtoupper($row[$field] ?? '');
            if ($value !== '' && ! in_array($value, $allowed, true)) {
                $add($field, $row[$field], ImportErrorCode::INVALID_ENUM, "{$field} must be one of: ".implode(', ', $allowed).'.');
            }
        }

        if (($row['email'] ?? '') !== '' && filter_var($row['email'], FILTER_VALIDATE_EMAIL) === false) {
            $add('email', $row['email'], ImportErrorCode::INVALID_FORMAT, 'email is not a valid email address.');
        }

        if (($row['department_code'] ?? '') !== '') {
            $department = $departments->get(strtoupper($row['department_code']));
            if ($department === null) {
                $add('department_code', $row['department_code'], ImportErrorCode::DEPARTMENT_NOT_FOUND, "Department '{$row['department_code']}' not found.");
            } elseif ($department->status->value !== 'ACTIVE') {
                $add('department_code', $row['department_code'], ImportErrorCode::INACTIVE_MASTER_DATA, "Department '{$row['department_code']}' is not active.");
            }
        }

        $code = strtoupper($row['staff_code'] ?? '');
        if ($code === '') {
            return $errors;
        }

        if (isset($seen[$code])) {
            $add('staff_code', $row['staff_code'], ImportErrorCode::DUPLICATE_IN_FILE,
                "Duplicate staff_code '{$row['staff_code']}' in file (previously seen on row {$seen[$code]}).");

            return $errors;
        }
        $seen[$code] = $rowNumber;

        $current = $existing->get($code);
        if ($current !== null) {
            $sameName = mb_strtolower($current->person->first_name) === mb_strtolower($row['first_name'] ?? '')
                && mb_strtolower($current->person->last_name) === mb_strtolower($row['last_name'] ?? '');
            if (! $sameName) {
                $add('staff_code', $row['staff_code'], ImportErrorCode::IDENTITY_MISMATCH,
                    "staff_code '{$row['staff_code']}' belongs to {$current->person->first_name} {$current->person->last_name}; the names in this row differ.");
            } elseif ($mode === ImportConflictMode::CREATE_ONLY) {
                $add('staff_code', $row['staff_code'], ImportErrorCode::DUPLICATE_STAFF_CODE, "Staff with code '{$row['staff_code']}' already exists.");
            }
        } elseif (($row['person_code'] ?? '') !== '' && $personsByCode->has(strtoupper($row['person_code']))) {
            $add('person_code', $row['person_code'], ImportErrorCode::PERSON_CODE_CONFLICT, "person_code '{$row['person_code']}' is already used by another person.");
        }

        return $errors;
    }

    /**
     * @return array{0: list<string>, 1: array<int, array<string, string|null>>}|string Headers and rows keyed by
     *                                                                                  file row number, or an error code
     */
    private function readRows(ImportJob $job): array|string
    {
        $disk = Storage::disk('local');
        if (! $job->file_path || ! $disk->exists($job->file_path)) {
            return ImportErrorCode::INVALID_FILE_FORMAT->value;
        }

        $handle = fopen($disk->path($job->file_path), 'r');
        if (! $handle) {
            return ImportErrorCode::INVALID_FILE_FORMAT->value;
        }

        $rawHeader = fgetcsv($handle);
        if ($rawHeader === false || $rawHeader === [null]) {
            fclose($handle);

            return ImportErrorCode::EMPTY_FILE->value;
        }
        $rawHeader[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $rawHeader[0]);
        $headers = array_map(fn ($h) => strtolower(trim((string) $h)), $rawHeader);

        $rows = [];
        $rowNumber = 1;
        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;
            if (array_filter($row, fn ($v) => trim((string) $v) !== '') === []) {
                continue;
            }
            $mapped = [];
            foreach ($headers as $idx => $name) {
                if ($name !== '') {
                    $mapped[$name] = isset($row[$idx]) ? trim((string) $row[$idx]) : null;
                }
            }
            $rows[$rowNumber] = $mapped;
        }
        fclose($handle);

        return [array_values(array_filter($headers)), $rows];
    }

    private function failIntake(ImportJob $job, User $actor, ?AuditRequestContext $context, ImportErrorCode $code, string $message): ImportJob
    {
        ImportError::create($this->error($job, 1, 'file', null, $code, $message));
        $job->update(['status' => ImportJobStatus::FAILED, 'invalid_rows' => 1, 'completed_at' => now()]);
        $this->record(AuditAction::STAFF_IMPORT_VALIDATION_FAILED, $job, $actor, $context, ['reason' => $code->value]);

        return $job->fresh(['creator', 'errors']);
    }

    private function intakeMessage(string $code): string
    {
        return $code === ImportErrorCode::EMPTY_FILE->value
            ? 'The uploaded file is empty.'
            : 'Import file could not be read or does not exist.';
    }

    /**
     * @return array<string, mixed>
     */
    private function error(ImportJob $job, int $row, string $field, ?string $value, ImportErrorCode|string $code, string $message): array
    {
        return [
            'import_job_id' => $job->id,
            'row_number' => $row,
            'field' => $field,
            'value' => $value === null ? null : mb_substr($value, 0, 255),
            'error_code' => $code instanceof ImportErrorCode ? $code->value : $code,
            'error_message' => $message,
            'created_at' => now(),
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function record(AuditAction $action, ImportJob $job, ?User $actor, ?AuditRequestContext $context, array $metadata = []): void
    {
        $this->audit->record(
            action: $action,
            actor: $actor,
            subjectType: 'ImportJob',
            subjectUuid: $job->uuid,
            oldValues: null,
            newValues: null,
            metadata: ['import_job_uuid' => $job->uuid, 'type' => ImportJobType::STAFF->value, 'status' => $job->status->value] + $metadata,
            context: $context,
            module: 'staff',
        );
    }
}
