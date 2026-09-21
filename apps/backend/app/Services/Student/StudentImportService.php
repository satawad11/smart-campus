<?php

namespace App\Services\Student;

use App\Enums\AcademicStatus;
use App\Enums\AuditAction;
use App\Enums\ImportConflictMode;
use App\Enums\ImportErrorCode;
use App\Enums\ImportJobStatus;
use App\Enums\ImportJobType;
use App\Enums\StatusChangeSource;
use App\Enums\StudentAcademicStatus;
use App\Exceptions\ApiException;
use App\Http\Resources\ImportErrorResource;
use App\Http\Resources\ImportJobResource;
use App\Jobs\ProcessStudentImportJob;
use App\Models\Classroom;
use App\Models\Department;
use App\Models\ImportError;
use App\Models\ImportJob;
use App\Models\Person;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Audit\AuditRequestContext;
use App\Services\Audit\AuditService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Student import intake, validation, and queued execution service (STD-012, STD-013, STD-014).
 *
 * Implements file upload, parsing, header/row validation, error recording, preview,
 * identity conflict detection, and queued background execution.
 *
 * Domain safety invariant: Student creation delegates exclusively to StudentService.
 * Direct creation of Person/StudentProfile/StudentStatusHistory inside this service
 * is strictly prohibited.
 */
class StudentImportService
{
    private const REQUIRED_HEADERS = ['student_code', 'first_name', 'last_name'];

    private const VALID_GENDERS = ['MALE', 'FEMALE', 'OTHER', 'UNKNOWN'];

    private const VALID_EDUCATION_LEVELS = ['VOC_CERT', 'HIGH_VOC_CERT', 'SHORT_COURSE', 'OTHER'];

    private const MAX_ROWS = 5000;

    public function __construct(
        private readonly AuditService $auditService,
        private readonly StudentService $studentService,
    ) {}

    /**
     * Upload and validate a student import CSV file.
     */
    public function uploadAndValidate(
        UploadedFile $file,
        User $actor,
        ?AuditRequestContext $context = null,
        ImportConflictMode|string $conflictMode = ImportConflictMode::CREATE_ONLY
    ): ImportJob {
        $mode = $conflictMode instanceof ImportConflictMode
            ? $conflictMode
            : (ImportConflictMode::tryFrom((string) $conflictMode) ?? ImportConflictMode::CREATE_ONLY);

        $tempUuid = (string) Str::uuid();
        $storageDir = "imports/students/{$tempUuid}";
        $filename = (string) Str::uuid().'.csv';
        $storedPath = $file->storeAs($storageDir, $filename, 'local');

        $job = null;
        try {
            $job = DB::transaction(function () use ($storedPath, $actor, $tempUuid, $mode) {
                return ImportJob::create([
                    'uuid' => $tempUuid,
                    'type' => ImportJobType::STUDENT,
                    'file_path' => $storedPath,
                    'status' => ImportJobStatus::PENDING,
                    'conflict_mode' => $mode,
                    'total_rows' => 0,
                    'valid_rows' => 0,
                    'invalid_rows' => 0,
                    'success_rows' => 0,
                    'failed_rows' => 0,
                    'skipped_rows' => 0,
                    'created_by' => $actor->id,
                ]);
            });
        } catch (Throwable $e) {
            if ($storedPath && Storage::disk('local')->exists($storedPath)) {
                Storage::disk('local')->delete($storedPath);
            }
            throw $e;
        }

        $this->auditService->record(
            action: AuditAction::STUDENT_IMPORT_UPLOADED,
            actor: $actor,
            subjectType: 'ImportJob',
            subjectUuid: $job->uuid,
            oldValues: null,
            newValues: null,
            metadata: [
                'import_job_uuid' => $job->uuid,
                'status' => ImportJobStatus::PENDING->value,
                'type' => ImportJobType::STUDENT->value,
                'conflict_mode' => $mode->value,
            ],
            context: $context,
            module: 'student'
        );

        // Perform validation
        return $this->validateImportJob($job, $actor, $context);
    }

    /**
     * Validate the uploaded CSV file for an import job (STD-012, STD-014).
     */
    public function validateImportJob(
        ImportJob $job,
        User $actor,
        ?AuditRequestContext $context = null
    ): ImportJob {
        $job->update([
            'status' => ImportJobStatus::VALIDATING,
            'started_at' => now(),
        ]);

        $disk = Storage::disk('local');
        if (! $job->file_path || ! $disk->exists($job->file_path)) {
            $job->update([
                'status' => ImportJobStatus::FAILED,
                'completed_at' => now(),
            ]);

            ImportError::create([
                'import_job_id' => $job->id,
                'row_number' => 1,
                'field' => 'file',
                'value' => null,
                'error_code' => ImportErrorCode::INVALID_FILE_FORMAT->value,
                'error_message' => 'Import file could not be read or does not exist.',
            ]);

            return $job->fresh(['creator', 'errors']);
        }

        $filePath = $disk->path($job->file_path);
        $handle = fopen($filePath, 'r');
        if (! $handle) {
            $job->update([
                'status' => ImportJobStatus::FAILED,
                'completed_at' => now(),
            ]);

            ImportError::create([
                'import_job_id' => $job->id,
                'row_number' => 1,
                'field' => 'file',
                'value' => null,
                'error_code' => ImportErrorCode::INVALID_FILE_FORMAT->value,
                'error_message' => 'Failed to open import file for reading.',
            ]);

            return $job->fresh(['creator', 'errors']);
        }

        // Read header
        $rawHeader = fgetcsv($handle);
        if ($rawHeader === false || empty($rawHeader)) {
            fclose($handle);
            $job->update([
                'status' => ImportJobStatus::FAILED,
                'completed_at' => now(),
            ]);

            ImportError::create([
                'import_job_id' => $job->id,
                'row_number' => 1,
                'field' => 'file',
                'value' => null,
                'error_code' => ImportErrorCode::EMPTY_FILE->value,
                'error_message' => 'The uploaded file is empty.',
            ]);

            $this->auditService->record(
                action: AuditAction::STUDENT_IMPORT_VALIDATION_FAILED,
                actor: $actor,
                subjectType: 'ImportJob',
                subjectUuid: $job->uuid,
                oldValues: null,
                newValues: null,
                metadata: [
                    'import_job_uuid' => $job->uuid,
                    'status' => ImportJobStatus::FAILED->value,
                    'reason' => 'EMPTY_FILE',
                ],
                context: $context,
                module: 'student'
            );

            return $job->fresh(['creator', 'errors']);
        }

        // Strip UTF-8 BOM from the first header if present
        if (isset($rawHeader[0])) {
            $rawHeader[0] = preg_replace('/^\xEF\xBB\xBF/', '', $rawHeader[0]);
        }

        $headers = [];
        $headerErrors = [];
        $seenHeaders = [];
        foreach ($rawHeader as $idx => $h) {
            $cleaned = strtolower(trim((string) $h));
            if ($cleaned === '') {
                continue;
            }
            if (isset($seenHeaders[$cleaned])) {
                $headerErrors[] = "Duplicate header '{$cleaned}' in column ".($idx + 1);
            }
            $seenHeaders[$cleaned] = true;
            $headers[$idx] = $cleaned;
        }

        // Check required headers
        $missingRequired = [];
        $headerMap = array_flip($headers);
        foreach (self::REQUIRED_HEADERS as $req) {
            if (! isset($headerMap[$req])) {
                $missingRequired[] = $req;
            }
        }

        if (! empty($missingRequired) || ! empty($headerErrors)) {
            fclose($handle);
            $errorMsgs = [];
            if (! empty($missingRequired)) {
                $errorMsgs[] = 'Missing required header(s): '.implode(', ', $missingRequired);
            }
            if (! empty($headerErrors)) {
                $errorMsgs = array_merge($errorMsgs, $headerErrors);
            }

            ImportError::create([
                'import_job_id' => $job->id,
                'row_number' => 1,
                'field' => 'headers',
                'value' => implode(',', array_slice($rawHeader, 0, 10)),
                'error_code' => ImportErrorCode::MISSING_REQUIRED_HEADER->value,
                'error_message' => implode('; ', $errorMsgs),
            ]);

            $job->update([
                'status' => ImportJobStatus::FAILED,
                'total_rows' => 0,
                'valid_rows' => 0,
                'invalid_rows' => 1,
                'completed_at' => now(),
            ]);

            $this->auditService->record(
                action: AuditAction::STUDENT_IMPORT_VALIDATION_FAILED,
                actor: $actor,
                subjectType: 'ImportJob',
                subjectUuid: $job->uuid,
                oldValues: null,
                newValues: null,
                metadata: [
                    'import_job_uuid' => $job->uuid,
                    'status' => ImportJobStatus::FAILED->value,
                    'reason' => 'HEADER_VALIDATION_FAILED',
                ],
                context: $context,
                module: 'student'
            );

            return $job->fresh(['creator', 'errors']);
        }

        // Preload existing student profiles with person for duplicate & identity checking (STD-014)
        $existingStudentProfiles = StudentProfile::with('person')->get()->keyBy(fn ($p) => strtoupper($p->student_code));

        // Preload existing persons with person_code for identity conflict checking (STD-014)
        $existingPersonsByCode = Person::with('studentProfile')
            ->whereNotNull('person_code')
            ->where('person_code', '!=', '')
            ->get()
            ->keyBy(fn ($p) => strtoupper($p->person_code));

        // Preload academic master data
        $departmentsByCode = Department::all()->keyBy(fn ($d) => strtoupper($d->code));
        $programsByCode = Program::all()->keyBy(fn ($p) => strtoupper($p->code));
        $classroomsByCode = Classroom::all()->keyBy(fn ($c) => strtoupper($c->code));

        $conflictMode = $job->conflict_mode ?? ImportConflictMode::CREATE_ONLY;

        $seenInFileCodes = [];
        $seenInFilePersonCodes = [];
        $totalRows = 0;
        $validRows = 0;
        $invalidRows = 0;
        $rowNumber = 1; // header is row 1, data starts at row 2

        $batchErrors = [];

        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;

            // Skip entirely empty row
            if (empty(array_filter($row, fn ($val) => trim((string) $val) !== ''))) {
                continue;
            }

            $totalRows++;
            if ($totalRows > self::MAX_ROWS) {
                $batchErrors[] = [
                    'import_job_id' => $job->id,
                    'row_number' => $rowNumber,
                    'field' => 'file',
                    'value' => (string) self::MAX_ROWS,
                    'error_code' => ImportErrorCode::INVALID_FORMAT->value,
                    'error_message' => 'Maximum import limit of '.self::MAX_ROWS.' rows exceeded.',
                    'created_at' => now(),
                ];
                $invalidRows++;
                break;
            }

            $rowData = [];
            foreach ($headers as $idx => $colName) {
                $rowData[$colName] = isset($row[$idx]) ? trim((string) $row[$idx]) : null;
            }

            $rowHasError = false;

            // 1. student_code
            $studentCode = $rowData['student_code'] ?? null;
            $codeUpper = null;
            if ($studentCode === null || $studentCode === '') {
                $batchErrors[] = [
                    'import_job_id' => $job->id,
                    'row_number' => $rowNumber,
                    'field' => 'student_code',
                    'value' => null,
                    'error_code' => ImportErrorCode::REQUIRED_FIELD_MISSING->value,
                    'error_message' => 'student_code is required.',
                    'created_at' => now(),
                ];
                $rowHasError = true;
            } elseif (mb_strlen($studentCode) > 50) {
                $batchErrors[] = [
                    'import_job_id' => $job->id,
                    'row_number' => $rowNumber,
                    'field' => 'student_code',
                    'value' => mb_substr($studentCode, 0, 50),
                    'error_code' => ImportErrorCode::VALUE_TOO_LONG->value,
                    'error_message' => 'student_code must not exceed 50 characters.',
                    'created_at' => now(),
                ];
                $rowHasError = true;
            } else {
                $codeUpper = strtoupper($studentCode);
                if (isset($seenInFileCodes[$codeUpper])) {
                    $batchErrors[] = [
                        'import_job_id' => $job->id,
                        'row_number' => $rowNumber,
                        'field' => 'student_code',
                        'value' => $studentCode,
                        'error_code' => ImportErrorCode::DUPLICATE_IN_FILE->value,
                        'error_message' => "Duplicate student_code '{$studentCode}' in file (previously seen on row {$seenInFileCodes[$codeUpper]}).",
                        'created_at' => now(),
                    ];
                    $rowHasError = true;
                } else {
                    $seenInFileCodes[$codeUpper] = $rowNumber;
                }
            }

            // 2. first_name
            $firstName = $rowData['first_name'] ?? null;
            if ($firstName === null || $firstName === '') {
                $batchErrors[] = [
                    'import_job_id' => $job->id,
                    'row_number' => $rowNumber,
                    'field' => 'first_name',
                    'value' => null,
                    'error_code' => ImportErrorCode::REQUIRED_FIELD_MISSING->value,
                    'error_message' => 'first_name is required.',
                    'created_at' => now(),
                ];
                $rowHasError = true;
            } elseif (mb_strlen($firstName) > 100) {
                $batchErrors[] = [
                    'import_job_id' => $job->id,
                    'row_number' => $rowNumber,
                    'field' => 'first_name',
                    'value' => mb_substr($firstName, 0, 100),
                    'error_code' => ImportErrorCode::VALUE_TOO_LONG->value,
                    'error_message' => 'first_name must not exceed 100 characters.',
                    'created_at' => now(),
                ];
                $rowHasError = true;
            }

            // 3. last_name
            $lastName = $rowData['last_name'] ?? null;
            if ($lastName === null || $lastName === '') {
                $batchErrors[] = [
                    'import_job_id' => $job->id,
                    'row_number' => $rowNumber,
                    'field' => 'last_name',
                    'value' => null,
                    'error_code' => ImportErrorCode::REQUIRED_FIELD_MISSING->value,
                    'error_message' => 'last_name is required.',
                    'created_at' => now(),
                ];
                $rowHasError = true;
            } elseif (mb_strlen($lastName) > 100) {
                $batchErrors[] = [
                    'import_job_id' => $job->id,
                    'row_number' => $rowNumber,
                    'field' => 'last_name',
                    'value' => mb_substr($lastName, 0, 100),
                    'error_code' => ImportErrorCode::VALUE_TOO_LONG->value,
                    'error_message' => 'last_name must not exceed 100 characters.',
                    'created_at' => now(),
                ];
                $rowHasError = true;
            }

            // 4. national_id / person_code validation & intra-file check
            $nationalId = $rowData['national_id'] ?? $rowData['person_code'] ?? null;
            $nationalIdClean = ($nationalId !== null && trim((string) $nationalId) !== '') ? trim((string) $nationalId) : null;
            if ($nationalIdClean !== null) {
                if (mb_strlen($nationalIdClean) > 50) {
                    $batchErrors[] = [
                        'import_job_id' => $job->id,
                        'row_number' => $rowNumber,
                        'field' => 'national_id',
                        'value' => mb_substr($nationalIdClean, 0, 50),
                        'error_code' => ImportErrorCode::VALUE_TOO_LONG->value,
                        'error_message' => 'national_id must not exceed 50 characters.',
                        'created_at' => now(),
                    ];
                    $rowHasError = true;
                } elseif ($codeUpper !== null) {
                    $natUpper = strtoupper($nationalIdClean);
                    if (isset($seenInFilePersonCodes[$natUpper])) {
                        $prev = $seenInFilePersonCodes[$natUpper];
                        if ($prev['student_code'] !== $codeUpper) {
                            $batchErrors[] = [
                                'import_job_id' => $job->id,
                                'row_number' => $rowNumber,
                                'field' => 'national_id',
                                'value' => $nationalIdClean,
                                'error_code' => ImportErrorCode::PERSON_CODE_CONFLICT->value,
                                'error_message' => "Duplicate national_id '{$nationalIdClean}' in file assigned to a different student (previously seen on row {$prev['row']}).",
                                'created_at' => now(),
                            ];
                            $rowHasError = true;
                        }
                    } else {
                        $seenInFilePersonCodes[$natUpper] = ['row' => $rowNumber, 'student_code' => $codeUpper];
                    }
                }
            }

            // 5. Database Identity Conflict / Duplicate Detection (STD-014)
            if ($codeUpper !== null && ! isset($seenInFileCodes[$codeUpper.'_dup_checked'])) {
                $firstNameClean = ($firstName !== null && trim((string) $firstName) !== '') ? trim((string) $firstName) : null;
                $lastNameClean = ($lastName !== null && trim((string) $lastName) !== '') ? trim((string) $lastName) : null;

                $existingProfile = $existingStudentProfiles->get($codeUpper);
                if ($existingProfile) {
                    $existingPerson = $existingProfile->person;
                    $identityMismatch = false;
                    $mismatchReasons = [];

                    // Check national_id / person_code mismatch
                    if ($nationalIdClean !== null && $existingPerson?->person_code !== null && $existingPerson->person_code !== '') {
                        if (strcasecmp($nationalIdClean, $existingPerson->person_code) !== 0) {
                            $identityMismatch = true;
                            $mismatchReasons[] = "national_id '{$nationalIdClean}' does not match registered '{$existingPerson->person_code}'";
                        }
                    }

                    // Check name mismatch
                    if ($firstNameClean !== null && $existingPerson?->first_name !== null) {
                        if (mb_strtolower($firstNameClean) !== mb_strtolower($existingPerson->first_name)) {
                            $identityMismatch = true;
                            $mismatchReasons[] = "first_name '{$firstNameClean}' does not match registered '{$existingPerson->first_name}'";
                        }
                    }
                    if ($lastNameClean !== null && $existingPerson?->last_name !== null) {
                        if (mb_strtolower($lastNameClean) !== mb_strtolower($existingPerson->last_name)) {
                            $identityMismatch = true;
                            $mismatchReasons[] = "last_name '{$lastNameClean}' does not match registered '{$existingPerson->last_name}'";
                        }
                    }

                    if ($identityMismatch) {
                        $batchErrors[] = [
                            'import_job_id' => $job->id,
                            'row_number' => $rowNumber,
                            'field' => 'student_code',
                            'value' => $studentCode,
                            'error_code' => ImportErrorCode::IDENTITY_MISMATCH->value,
                            'error_message' => "Identity conflict on student_code '{$studentCode}': ".implode('; ', $mismatchReasons).'. Row flagged for review.',
                            'created_at' => now(),
                        ];
                        $rowHasError = true;
                    }

                    if ($conflictMode === ImportConflictMode::CREATE_ONLY) {
                        $batchErrors[] = [
                            'import_job_id' => $job->id,
                            'row_number' => $rowNumber,
                            'field' => 'student_code',
                            'value' => $studentCode,
                            'error_code' => ImportErrorCode::DUPLICATE_STUDENT_CODE->value,
                            'error_message' => "Student with code '{$studentCode}' already exists in database.",
                            'created_at' => now(),
                        ];
                        $rowHasError = true;
                    }
                } else {
                    // student_code does not exist in DB, but check if national_id belongs to another person
                    if ($nationalIdClean !== null) {
                        $natUpper = strtoupper($nationalIdClean);
                        $existingPersonWithCode = $existingPersonsByCode->get($natUpper);
                        if ($existingPersonWithCode) {
                            $otherStudent = $existingPersonWithCode->studentProfile;
                            $otherCode = $otherStudent?->student_code;
                            $errorMsg = $otherCode
                                ? "Identity conflict: national_id '{$nationalIdClean}' is already registered to student '{$otherCode}'."
                                : "Identity conflict: national_id '{$nationalIdClean}' is already registered to another person.";

                            $batchErrors[] = [
                                'import_job_id' => $job->id,
                                'row_number' => $rowNumber,
                                'field' => 'national_id',
                                'value' => $nationalIdClean,
                                'error_code' => ImportErrorCode::PERSON_CODE_CONFLICT->value,
                                'error_message' => $errorMsg,
                                'created_at' => now(),
                            ];
                            $rowHasError = true;
                        }
                    }
                }
            }

            // 6. display_name
            $displayName = $rowData['display_name'] ?? null;
            if ($displayName !== null && $displayName !== '' && mb_strlen($displayName) > 150) {
                $batchErrors[] = [
                    'import_job_id' => $job->id,
                    'row_number' => $rowNumber,
                    'field' => 'display_name',
                    'value' => mb_substr($displayName, 0, 150),
                    'error_code' => ImportErrorCode::VALUE_TOO_LONG->value,
                    'error_message' => 'display_name must not exceed 150 characters.',
                    'created_at' => now(),
                ];
                $rowHasError = true;
            }

            // 7. gender
            $gender = $rowData['gender'] ?? null;
            if ($gender !== null && $gender !== '') {
                $genderUpper = strtoupper($gender);
                if (! in_array($genderUpper, self::VALID_GENDERS, true)) {
                    $batchErrors[] = [
                        'import_job_id' => $job->id,
                        'row_number' => $rowNumber,
                        'field' => 'gender',
                        'value' => $gender,
                        'error_code' => ImportErrorCode::INVALID_ENUM->value,
                        'error_message' => 'gender must be one of: '.implode(', ', self::VALID_GENDERS),
                        'created_at' => now(),
                    ];
                    $rowHasError = true;
                }
            }

            // 8. academic_status
            $academicStatus = $rowData['academic_status'] ?? null;
            if ($academicStatus !== null && $academicStatus !== '') {
                $statusUpper = strtoupper($academicStatus);
                $validStatuses = array_column(StudentAcademicStatus::cases(), 'value');
                if (! in_array($statusUpper, $validStatuses, true)) {
                    $batchErrors[] = [
                        'import_job_id' => $job->id,
                        'row_number' => $rowNumber,
                        'field' => 'academic_status',
                        'value' => $academicStatus,
                        'error_code' => ImportErrorCode::INVALID_ENUM->value,
                        'error_message' => 'academic_status must be one of: '.implode(', ', $validStatuses).'. Access decisions (ALLOW, DENY, REVIEW) are prohibited.',
                        'created_at' => now(),
                    ];
                    $rowHasError = true;
                }
            }

            // 9. education_level
            $educationLevel = $rowData['education_level'] ?? null;
            if ($educationLevel !== null && $educationLevel !== '') {
                $eduUpper = strtoupper($educationLevel);
                if (! in_array($eduUpper, self::VALID_EDUCATION_LEVELS, true)) {
                    $batchErrors[] = [
                        'import_job_id' => $job->id,
                        'row_number' => $rowNumber,
                        'field' => 'education_level',
                        'value' => $educationLevel,
                        'error_code' => ImportErrorCode::INVALID_ENUM->value,
                        'error_message' => 'education_level must be one of: '.implode(', ', self::VALID_EDUCATION_LEVELS),
                        'created_at' => now(),
                    ];
                    $rowHasError = true;
                }
            }

            // 10. year_level
            $yearLevel = $rowData['year_level'] ?? null;
            if ($yearLevel !== null && $yearLevel !== '') {
                if (! ctype_digit((string) $yearLevel) || (int) $yearLevel < 1 || (int) $yearLevel > 10) {
                    $batchErrors[] = [
                        'import_job_id' => $job->id,
                        'row_number' => $rowNumber,
                        'field' => 'year_level',
                        'value' => $yearLevel,
                        'error_code' => ImportErrorCode::INVALID_YEAR->value,
                        'error_message' => 'year_level must be an integer between 1 and 10.',
                        'created_at' => now(),
                    ];
                    $rowHasError = true;
                }
            }

            // 11. admission_year
            $admissionYear = $rowData['admission_year'] ?? null;
            if ($admissionYear !== null && $admissionYear !== '') {
                if (! ctype_digit((string) $admissionYear) || (int) $admissionYear < 1900 || (int) $admissionYear > 2100) {
                    $batchErrors[] = [
                        'import_job_id' => $job->id,
                        'row_number' => $rowNumber,
                        'field' => 'admission_year',
                        'value' => $admissionYear,
                        'error_code' => ImportErrorCode::INVALID_YEAR->value,
                        'error_message' => 'admission_year must be a 4-digit year between 1900 and 2100.',
                        'created_at' => now(),
                    ];
                    $rowHasError = true;
                }
            }

            // 12. Academic Master lookups & relational consistency
            $deptCode = $rowData['department_code'] ?? null;
            $progCode = $rowData['program_code'] ?? null;
            $clsCode = $rowData['classroom_code'] ?? null;

            $resolvedDept = null;
            $resolvedProg = null;
            $resolvedCls = null;

            if ($deptCode !== null && $deptCode !== '') {
                $deptUpper = strtoupper($deptCode);
                $resolvedDept = $departmentsByCode->get($deptUpper);
                if (! $resolvedDept) {
                    $batchErrors[] = [
                        'import_job_id' => $job->id,
                        'row_number' => $rowNumber,
                        'field' => 'department_code',
                        'value' => $deptCode,
                        'error_code' => ImportErrorCode::DEPARTMENT_NOT_FOUND->value,
                        'error_message' => "Department with code '{$deptCode}' does not exist.",
                        'created_at' => now(),
                    ];
                    $rowHasError = true;
                } elseif ($resolvedDept->status !== AcademicStatus::ACTIVE) {
                    $batchErrors[] = [
                        'import_job_id' => $job->id,
                        'row_number' => $rowNumber,
                        'field' => 'department_code',
                        'value' => $deptCode,
                        'error_code' => ImportErrorCode::INACTIVE_MASTER_DATA->value,
                        'error_message' => "Department '{$deptCode}' is INACTIVE.",
                        'created_at' => now(),
                    ];
                    $rowHasError = true;
                }
            }

            if ($progCode !== null && $progCode !== '') {
                $progUpper = strtoupper($progCode);
                $resolvedProg = $programsByCode->get($progUpper);
                if (! $resolvedProg) {
                    $batchErrors[] = [
                        'import_job_id' => $job->id,
                        'row_number' => $rowNumber,
                        'field' => 'program_code',
                        'value' => $progCode,
                        'error_code' => ImportErrorCode::PROGRAM_NOT_FOUND->value,
                        'error_message' => "Program with code '{$progCode}' does not exist.",
                        'created_at' => now(),
                    ];
                    $rowHasError = true;
                } elseif ($resolvedProg->status !== AcademicStatus::ACTIVE) {
                    $batchErrors[] = [
                        'import_job_id' => $job->id,
                        'row_number' => $rowNumber,
                        'field' => 'program_code',
                        'value' => $progCode,
                        'error_code' => ImportErrorCode::INACTIVE_MASTER_DATA->value,
                        'error_message' => "Program '{$progCode}' is INACTIVE.",
                        'created_at' => now(),
                    ];
                    $rowHasError = true;
                } else {
                    if ($resolvedDept && $resolvedProg->department_id !== $resolvedDept->id) {
                        $batchErrors[] = [
                            'import_job_id' => $job->id,
                            'row_number' => $rowNumber,
                            'field' => 'program_code',
                            'value' => $progCode,
                            'error_code' => ImportErrorCode::PROGRAM_DEPARTMENT_MISMATCH->value,
                            'error_message' => "Program '{$progCode}' does not belong to Department '{$deptCode}'.",
                            'created_at' => now(),
                        ];
                        $rowHasError = true;
                    }

                    if ($educationLevel !== null && $educationLevel !== '') {
                        $progEdu = $resolvedProg->education_level instanceof \BackedEnum
                            ? $resolvedProg->education_level->value
                            : (string) $resolvedProg->education_level;
                        if (strtoupper($educationLevel) !== strtoupper($progEdu)) {
                            $batchErrors[] = [
                                'import_job_id' => $job->id,
                                'row_number' => $rowNumber,
                                'field' => 'education_level',
                                'value' => $educationLevel,
                                'error_code' => ImportErrorCode::EDUCATION_LEVEL_MISMATCH->value,
                                'error_message' => "education_level '{$educationLevel}' does not match Program education_level '{$progEdu}'.",
                                'created_at' => now(),
                            ];
                            $rowHasError = true;
                        }
                    }
                }
            }

            if ($clsCode !== null && $clsCode !== '') {
                $clsUpper = strtoupper($clsCode);
                $resolvedCls = $classroomsByCode->get($clsUpper);
                if (! $resolvedCls) {
                    $batchErrors[] = [
                        'import_job_id' => $job->id,
                        'row_number' => $rowNumber,
                        'field' => 'classroom_code',
                        'value' => $clsCode,
                        'error_code' => ImportErrorCode::CLASSROOM_NOT_FOUND->value,
                        'error_message' => "Classroom with code '{$clsCode}' does not exist.",
                        'created_at' => now(),
                    ];
                    $rowHasError = true;
                } elseif ($resolvedCls->status !== AcademicStatus::ACTIVE) {
                    $batchErrors[] = [
                        'import_job_id' => $job->id,
                        'row_number' => $rowNumber,
                        'field' => 'classroom_code',
                        'value' => $clsCode,
                        'error_code' => ImportErrorCode::INACTIVE_MASTER_DATA->value,
                        'error_message' => "Classroom '{$clsCode}' is INACTIVE.",
                        'created_at' => now(),
                    ];
                    $rowHasError = true;
                } else {
                    if ($resolvedDept && $resolvedCls->department_id !== $resolvedDept->id) {
                        $batchErrors[] = [
                            'import_job_id' => $job->id,
                            'row_number' => $rowNumber,
                            'field' => 'classroom_code',
                            'value' => $clsCode,
                            'error_code' => ImportErrorCode::CLASSROOM_DEPARTMENT_MISMATCH->value,
                            'error_message' => "Classroom '{$clsCode}' does not belong to Department '{$deptCode}'.",
                            'created_at' => now(),
                        ];
                        $rowHasError = true;
                    }

                    if ($resolvedProg && $resolvedCls->program_id && $resolvedCls->program_id !== $resolvedProg->id) {
                        $batchErrors[] = [
                            'import_job_id' => $job->id,
                            'row_number' => $rowNumber,
                            'field' => 'classroom_code',
                            'value' => $clsCode,
                            'error_code' => ImportErrorCode::CLASSROOM_PROGRAM_MISMATCH->value,
                            'error_message' => "Classroom '{$clsCode}' is assigned to a different Program.",
                            'created_at' => now(),
                        ];
                        $rowHasError = true;
                    }
                }
            }

            if ($rowHasError) {
                $invalidRows++;
            } else {
                $validRows++;
            }

            // Batch insert errors every 100 errors to bound memory
            if (count($batchErrors) >= 100) {
                ImportError::insert($batchErrors);
                $batchErrors = [];
            }
        }

        fclose($handle);

        if (! empty($batchErrors)) {
            ImportError::insert($batchErrors);
        }

        $finalStatus = ($totalRows === 0) ? ImportJobStatus::FAILED : ImportJobStatus::VALIDATED;

        if ($totalRows === 0) {
            ImportError::create([
                'import_job_id' => $job->id,
                'row_number' => 1,
                'field' => 'file',
                'value' => null,
                'error_code' => ImportErrorCode::EMPTY_FILE->value,
                'error_message' => 'The uploaded file contains no data rows.',
            ]);
            $invalidRows = 1;
        }

        $job->update([
            'status' => $finalStatus,
            'total_rows' => $totalRows,
            'valid_rows' => $validRows,
            'invalid_rows' => $invalidRows,
            'success_rows' => 0,
            'failed_rows' => 0,
            'skipped_rows' => 0,
            'completed_at' => now(),
        ]);

        $auditAction = ($finalStatus === ImportJobStatus::VALIDATED)
            ? AuditAction::STUDENT_IMPORT_VALIDATED
            : AuditAction::STUDENT_IMPORT_VALIDATION_FAILED;

        $this->auditService->record(
            action: $auditAction,
            actor: $actor,
            subjectType: 'ImportJob',
            subjectUuid: $job->uuid,
            oldValues: null,
            newValues: null,
            metadata: [
                'import_job_uuid' => $job->uuid,
                'status' => $finalStatus->value,
                'conflict_mode' => $conflictMode->value,
                'total_rows' => $totalRows,
                'valid_rows' => $validRows,
                'invalid_rows' => $invalidRows,
            ],
            context: $context,
            module: 'student'
        );

        return $job->fresh(['creator', 'errors']);
    }

    /**
     * Dispatch queued background execution of a validated import job (STD-013, STD-014).
     *
     * Invariant: Executes strictly under the persisted conflict_mode from intake validation.
     */
    public function dispatchProcess(
        ImportJob $job,
        User $actor,
        ?AuditRequestContext $context = null
    ): ImportJob {
        $lockedJob = DB::transaction(function () use ($job) {
            /** @var ImportJob $locked */
            $locked = ImportJob::whereKey($job->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== ImportJobStatus::VALIDATED) {
                throw ApiException::unprocessable(
                    'IMPORT_JOB_NOT_PROCESSABLE',
                    "Import job cannot be processed in status '{$locked->status->value}'. Only VALIDATED jobs can be processed."
                );
            }

            $locked->update([
                'status' => ImportJobStatus::PROCESSING,
                'started_at' => now(),
                'success_rows' => 0,
                'failed_rows' => 0,
                'skipped_rows' => 0,
            ]);

            return $locked;
        });

        $this->auditService->record(
            action: AuditAction::STUDENT_IMPORT_PROCESSING_STARTED,
            actor: $actor,
            subjectType: 'ImportJob',
            subjectUuid: $lockedJob->uuid,
            oldValues: null,
            newValues: null,
            metadata: [
                'import_job_uuid' => $lockedJob->uuid,
                'status' => ImportJobStatus::PROCESSING->value,
                'conflict_mode' => $lockedJob->conflict_mode->value,
                'total_rows' => $lockedJob->total_rows,
                'valid_rows' => $lockedJob->valid_rows,
                'invalid_rows' => $lockedJob->invalid_rows,
            ],
            context: $context,
            module: 'student'
        );

        ProcessStudentImportJob::dispatch($lockedJob);

        return $lockedJob->fresh(['creator', 'errors']);
    }

    /**
     * Execute processing for a student import job in background worker (STD-013, STD-014).
     *
     * Invariant: Delegates all student operations to StudentService.
     * Never calls Person::create, StudentProfile::create, or StudentStatusHistory::create directly.
     */
    public function processImportJob(ImportJob $job): void
    {
        $disk = Storage::disk('local');
        if (! $job->file_path || ! $disk->exists($job->file_path)) {
            $job->update([
                'status' => ImportJobStatus::FAILED,
                'completed_at' => now(),
            ]);

            ImportError::create([
                'import_job_id' => $job->id,
                'row_number' => 1,
                'field' => 'file',
                'value' => null,
                'error_code' => 'FILE_NOT_FOUND',
                'error_message' => 'Import file not found for processing.',
            ]);

            $this->auditService->record(
                action: AuditAction::STUDENT_IMPORT_FAILED,
                actor: $job->creator,
                subjectType: 'ImportJob',
                subjectUuid: $job->uuid,
                oldValues: null,
                newValues: null,
                metadata: [
                    'import_job_uuid' => $job->uuid,
                    'status' => ImportJobStatus::FAILED->value,
                    'reason' => 'FILE_NOT_FOUND',
                ],
                module: 'student'
            );

            return;
        }

        // Set of previously invalid row numbers from STD-012 pre-validation
        $invalidRowNumbers = $job->errors()->pluck('row_number')->all();
        $invalidRowSet = array_fill_keys($invalidRowNumbers, true);

        $departmentsByCode = Department::all()->keyBy(fn ($d) => strtoupper($d->code));
        $programsByCode = Program::all()->keyBy(fn ($p) => strtoupper($p->code));
        $classroomsByCode = Classroom::all()->keyBy(fn ($c) => strtoupper($c->code));

        $conflictMode = $job->conflict_mode ?? ImportConflictMode::CREATE_ONLY;

        $filePath = $disk->path($job->file_path);
        $handle = fopen($filePath, 'r');
        if (! $handle) {
            $this->handleCatastrophicFailure($job, new \RuntimeException('Failed to open import file for execution.'));

            return;
        }

        $rawHeader = fgetcsv($handle);
        if ($rawHeader === false) {
            fclose($handle);
            $this->handleCatastrophicFailure($job, new \RuntimeException('Empty or unreadable CSV header during execution.'));

            return;
        }

        if (isset($rawHeader[0])) {
            $rawHeader[0] = preg_replace('/^\xEF\xBB\xBF/', '', $rawHeader[0]);
        }
        $headers = [];
        foreach ($rawHeader as $idx => $h) {
            $cleaned = strtolower(trim((string) $h));
            if ($cleaned !== '') {
                $headers[$idx] = $cleaned;
            }
        }

        $rowNumber = 1;
        $successRows = 0;
        $failedRows = 0;
        $skippedRows = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;

            if (empty(array_filter($row, fn ($val) => trim((string) $val) !== ''))) {
                continue;
            }

            // Skip rows that failed STD-012 pre-validation
            if (isset($invalidRowSet[$rowNumber])) {
                continue;
            }

            $rowData = [];
            foreach ($headers as $idx => $colName) {
                $rowData[$colName] = isset($row[$idx]) ? trim((string) $row[$idx]) : null;
            }

            $studentCode = $rowData['student_code'] ?? '';
            $nationalId = $rowData['national_id'] ?? $rowData['person_code'] ?? null;
            $nationalIdClean = ($nationalId !== null && trim((string) $nationalId) !== '') ? trim((string) $nationalId) : null;
            $firstNameClean = trim((string) ($rowData['first_name'] ?? ''));
            $lastNameClean = trim((string) ($rowData['last_name'] ?? ''));

            $deptCode = strtoupper((string) ($rowData['department_code'] ?? ''));
            $progCode = strtoupper((string) ($rowData['program_code'] ?? ''));
            $clsCode = strtoupper((string) ($rowData['classroom_code'] ?? ''));

            $dept = $departmentsByCode->get($deptCode);
            $prog = $progCode !== '' ? $programsByCode->get($progCode) : null;
            $cls = $clsCode !== '' ? $classroomsByCode->get($clsCode) : null;

            if (! $dept) {
                $failedRows++;
                ImportError::create([
                    'import_job_id' => $job->id,
                    'row_number' => $rowNumber,
                    'field' => 'department_code',
                    'value' => $rowData['department_code'] ?? null,
                    'error_code' => ImportErrorCode::DEPARTMENT_NOT_FOUND->value,
                    'error_message' => "Department '{$deptCode}' not found during execution.",
                ]);

                continue;
            }

            $educationLevel = ! empty($rowData['education_level'])
                ? strtoupper($rowData['education_level'])
                : ($prog?->education_level instanceof \BackedEnum ? $prog->education_level->value : (string) ($prog?->education_level ?? 'VOC_CERT'));

            // Check if student exists in database (STD-014 Conflict Handling)
            $existingProfile = StudentProfile::with('person')->where('student_code', $studentCode)->first();
            if ($existingProfile) {
                $existingPerson = $existingProfile->person;
                $identityMismatch = false;
                $mismatchReasons = [];

                if ($nationalIdClean !== null && $existingPerson?->person_code !== null && $existingPerson->person_code !== '') {
                    if (strcasecmp($nationalIdClean, $existingPerson->person_code) !== 0) {
                        $identityMismatch = true;
                        $mismatchReasons[] = "national_id '{$nationalIdClean}' does not match registered '{$existingPerson->person_code}'";
                    }
                }
                if ($firstNameClean !== '' && $existingPerson?->first_name !== null) {
                    if (mb_strtolower($firstNameClean) !== mb_strtolower($existingPerson->first_name)) {
                        $identityMismatch = true;
                        $mismatchReasons[] = "first_name '{$firstNameClean}' does not match registered '{$existingPerson->first_name}'";
                    }
                }
                if ($lastNameClean !== '' && $existingPerson?->last_name !== null) {
                    if (mb_strtolower($lastNameClean) !== mb_strtolower($existingPerson->last_name)) {
                        $identityMismatch = true;
                        $mismatchReasons[] = "last_name '{$lastNameClean}' does not match registered '{$existingPerson->last_name}'";
                    }
                }

                if ($identityMismatch) {
                    $failedRows++;
                    ImportError::create([
                        'import_job_id' => $job->id,
                        'row_number' => $rowNumber,
                        'field' => 'student_code',
                        'value' => $studentCode,
                        'error_code' => ImportErrorCode::IDENTITY_MISMATCH->value,
                        'error_message' => "Identity conflict on student_code '{$studentCode}': ".implode('; ', $mismatchReasons).'. Row rejected.',
                    ]);

                    continue;
                }

                if ($conflictMode === ImportConflictMode::CREATE_ONLY) {
                    $failedRows++;
                    ImportError::create([
                        'import_job_id' => $job->id,
                        'row_number' => $rowNumber,
                        'field' => 'student_code',
                        'value' => $studentCode,
                        'error_code' => ImportErrorCode::DUPLICATE_STUDENT_CODE->value,
                        'error_message' => "Student with code '{$studentCode}' already exists in database.",
                    ]);

                    continue;
                }

                if ($conflictMode === ImportConflictMode::SKIP_EXISTING) {
                    // Invariant: Matching identity in SKIP_EXISTING mode produces no Person/StudentProfile/History mutation
                    $skippedRows++;

                    continue;
                }

                if ($conflictMode === ImportConflictMode::UPDATE_EXISTING) {
                    try {
                        $updatePayload = [
                            'person' => [
                                'first_name' => $firstNameClean,
                                'last_name' => $lastNameClean,
                                'display_name' => $rowData['display_name'] ?? null,
                                'gender' => ! empty($rowData['gender']) ? strtoupper($rowData['gender']) : null,
                            ],
                            'year_level' => ! empty($rowData['year_level']) ? (int) $rowData['year_level'] : $existingProfile->year_level,
                            'admission_year' => ! empty($rowData['admission_year']) ? (int) $rowData['admission_year'] : $existingProfile->admission_year,
                        ];

                        $this->studentService->updateStudent($existingProfile, $updatePayload, $job->creator, null);

                        if (! empty($rowData['academic_status'])) {
                            $targetStatus = StudentAcademicStatus::tryFrom(strtoupper($rowData['academic_status']));
                            if ($targetStatus && $targetStatus !== $existingProfile->academic_status) {
                                $this->studentService->changeAcademicStatusForImport(
                                    $existingProfile,
                                    $targetStatus,
                                    'Updated via student import',
                                    null,
                                    $job->creator,
                                    null
                                );
                            }
                        }
                        $successRows++;
                    } catch (Throwable $e) {
                        $failedRows++;
                        $errorCode = ($e instanceof ApiException) ? $e->errorCode : 'STUDENT_UPDATE_FAILED';
                        $errorMsg = mb_substr($e->getMessage(), 0, 500);

                        ImportError::create([
                            'import_job_id' => $job->id,
                            'row_number' => $rowNumber,
                            'field' => 'student_code',
                            'value' => mb_substr((string) $studentCode, 0, 100),
                            'error_code' => $errorCode,
                            'error_message' => $errorMsg,
                        ]);
                    }

                    continue;
                }
            }

            // New student creation
            $studentPayload = [
                'person' => [
                    'person_code' => $nationalIdClean,
                    'first_name' => $firstNameClean,
                    'last_name' => $lastNameClean,
                    'display_name' => $rowData['display_name'] ?? null,
                    'gender' => ! empty($rowData['gender']) ? strtoupper($rowData['gender']) : null,
                ],
                'student_code' => $studentCode,
                'department_uuid' => $dept->uuid,
                'program_uuid' => $prog?->uuid,
                'classroom_uuid' => $cls?->uuid,
                'education_level' => $educationLevel,
                'year_level' => ! empty($rowData['year_level']) ? (int) $rowData['year_level'] : 1,
                'academic_status' => ! empty($rowData['academic_status']) ? strtoupper($rowData['academic_status']) : 'ACTIVE',
                'admission_year' => ! empty($rowData['admission_year']) ? (int) $rowData['admission_year'] : null,
                'source' => StatusChangeSource::IMPORT->value,
            ];

            try {
                $this->studentService->createStudent($studentPayload, $job->creator, null);
                $successRows++;
            } catch (Throwable $e) {
                $failedRows++;
                $errorCode = ($e instanceof ApiException) ? $e->errorCode : 'STUDENT_CREATION_FAILED';
                $errorMsg = mb_substr($e->getMessage(), 0, 500);

                ImportError::create([
                    'import_job_id' => $job->id,
                    'row_number' => $rowNumber,
                    'field' => 'student_code',
                    'value' => mb_substr((string) ($rowData['student_code'] ?? ''), 0, 100),
                    'error_code' => $errorCode,
                    'error_message' => $errorMsg,
                ]);
            }
        }

        fclose($handle);

        $job->update([
            'status' => ImportJobStatus::COMPLETED,
            'success_rows' => $successRows,
            'failed_rows' => $failedRows,
            'skipped_rows' => $skippedRows,
            'completed_at' => now(),
        ]);

        $this->auditService->record(
            action: AuditAction::STUDENT_IMPORT_COMPLETED,
            actor: $job->creator,
            subjectType: 'ImportJob',
            subjectUuid: $job->uuid,
            oldValues: null,
            newValues: null,
            metadata: [
                'import_job_uuid' => $job->uuid,
                'status' => ImportJobStatus::COMPLETED->value,
                'conflict_mode' => $job->conflict_mode->value,
                'total_rows' => $job->total_rows,
                'valid_rows' => $job->valid_rows,
                'invalid_rows' => $job->invalid_rows,
                'success_rows' => $successRows,
                'failed_rows' => $failedRows,
                'skipped_rows' => $skippedRows,
            ],
            module: 'student'
        );
    }

    /**
     * Handle unexpected / catastrophic background worker failure gracefully.
     */
    public function handleCatastrophicFailure(ImportJob $job, ?Throwable $exception): void
    {
        $job->refresh();
        if ($job->status !== ImportJobStatus::COMPLETED) {
            $job->update([
                'status' => ImportJobStatus::FAILED,
                'completed_at' => now(),
            ]);

            ImportError::create([
                'import_job_id' => $job->id,
                'row_number' => 1,
                'field' => 'system',
                'value' => null,
                'error_code' => 'SYSTEM_ERROR',
                'error_message' => $exception ? mb_substr($exception->getMessage(), 0, 500) : 'An unexpected error occurred during import processing.',
            ]);

            $this->auditService->record(
                action: AuditAction::STUDENT_IMPORT_FAILED,
                actor: $job->creator,
                subjectType: 'ImportJob',
                subjectUuid: $job->uuid,
                oldValues: null,
                newValues: null,
                metadata: [
                    'import_job_uuid' => $job->uuid,
                    'status' => ImportJobStatus::FAILED->value,
                    'reason' => 'CATASTROPHIC_FAILURE',
                ],
                module: 'student'
            );
        }
    }

    /**
     * Get preview rows and summary for an import job (STD-012, STD-014).
     *
     * @return array<string, mixed>
     */
    public function getPreview(ImportJob $job, int $limit = 10): array
    {
        $previewRows = [];
        $disk = Storage::disk('local');

        $jobErrorsByRow = $job->errors()
            ->get()
            ->groupBy('row_number');

        if ($job->file_path && $disk->exists($job->file_path)) {
            $handle = fopen($disk->path($job->file_path), 'r');
            if ($handle) {
                $rawHeader = fgetcsv($handle);
                if ($rawHeader !== false) {
                    if (isset($rawHeader[0])) {
                        $rawHeader[0] = preg_replace('/^\xEF\xBB\xBF/', '', $rawHeader[0]);
                    }
                    $headers = array_map(fn ($h) => strtolower(trim((string) $h)), $rawHeader);

                    $count = 0;
                    $rowNum = 1;
                    while (($row = fgetcsv($handle)) !== false && $count < $limit) {
                        $rowNum++;
                        if (empty(array_filter($row, fn ($val) => trim((string) $val) !== ''))) {
                            continue;
                        }

                        $mapped = [];
                        foreach ($headers as $idx => $col) {
                            $mapped[$col] = $row[$idx] ?? null;
                        }
                        $mapped['_row_number'] = $rowNum;

                        $rowErrors = $jobErrorsByRow->get($rowNum);
                        $identityMismatchError = $rowErrors?->firstWhere('error_code', ImportErrorCode::IDENTITY_MISMATCH->value);
                        $personConflictError = $rowErrors?->firstWhere('error_code', ImportErrorCode::PERSON_CODE_CONFLICT->value);
                        $duplicateCodeError = $rowErrors?->firstWhere('error_code', ImportErrorCode::DUPLICATE_STUDENT_CODE->value);

                        $mapped['_has_conflict'] = ($identityMismatchError || $personConflictError || $duplicateCodeError) !== null;
                        $mapped['_conflict_type'] = $identityMismatchError?->error_code
                            ?? $personConflictError?->error_code
                            ?? $duplicateCodeError?->error_code;

                        $previewRows[] = $mapped;
                        $count++;
                    }
                }
                fclose($handle);
            }
        }

        $sampleErrors = $job->errors()
            ->orderBy('row_number')
            ->limit($limit)
            ->get();

        $conflictRowsCount = $job->errors()
            ->whereIn('error_code', [
                ImportErrorCode::IDENTITY_MISMATCH->value,
                ImportErrorCode::PERSON_CODE_CONFLICT->value,
                ImportErrorCode::DUPLICATE_STUDENT_CODE->value,
            ])
            ->distinct()
            ->count('row_number');

        $identityMismatchesCount = $job->errors()
            ->where('error_code', ImportErrorCode::IDENTITY_MISMATCH->value)
            ->distinct()
            ->count('row_number');

        return [
            'job' => new ImportJobResource($job),
            'summary' => [
                'total_rows' => $job->total_rows,
                'valid_rows' => $job->valid_rows,
                'invalid_rows' => $job->invalid_rows,
                'conflict_rows' => $conflictRowsCount,
                'identity_mismatches' => $identityMismatchesCount,
                'conflict_mode' => $job->conflict_mode instanceof \BackedEnum ? $job->conflict_mode->value : (string) $job->conflict_mode,
                'is_ready_for_execution' => $job->status === ImportJobStatus::VALIDATED && $job->valid_rows > 0,
            ],
            'sample_rows' => $previewRows,
            'sample_errors' => ImportErrorResource::collection($sampleErrors),
        ];
    }
}
