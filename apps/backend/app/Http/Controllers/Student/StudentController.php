<?php

namespace App\Http\Controllers\Student;

use App\Enums\StudentAcademicStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Student\StoreStudentRequest;
use App\Http\Requests\Student\UpdateStudentRequest;
use App\Http\Resources\StudentResource;
use App\Models\Classroom;
use App\Models\Department;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Services\Audit\AuditRequestContext;
use App\Services\Student\StudentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Student CRUD/search API (STD-007).
 *
 * Thin HTTP layer over StudentService: creation, generic update, and the list/
 * show read model. Domain rules (Person+profile+history+audit, cross-entity
 * consistency, transactions/row locking) live entirely in StudentService and
 * are never duplicated here. Public identity is the StudentProfile uuid; no
 * internal ids are accepted or exposed. Authorization is by permission
 * middleware (student.view / student.create / student.update). The status
 * transition endpoint is a separate dedicated operation (STD-008).
 */
class StudentController extends Controller
{
    private const SORTABLE = ['student_code', 'academic_status', 'year_level', 'admission_year', 'created_at', 'updated_at'];

    public function __construct(
        private readonly StudentService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'keyword' => ['sometimes', 'string', 'max:255'],
            'student_code' => ['sometimes', 'string', 'max:255'],
            'academic_status' => ['sometimes', 'string', 'in:'.implode(',', array_column(StudentAcademicStatus::cases(), 'value'))],
            'education_level' => ['sometimes', 'string', 'in:VOC_CERT,HIGH_VOC_CERT,SHORT_COURSE,OTHER'],
            'year_level' => ['sometimes', 'integer', 'min:1', 'max:65535'],
            'admission_year' => ['sometimes', 'integer', 'min:1', 'max:65535'],
            'department_uuid' => ['sometimes', 'string', 'exists:departments,uuid'],
            'program_uuid' => ['sometimes', 'string', 'exists:programs,uuid'],
            'classroom_uuid' => ['sometimes', 'string', 'exists:classrooms,uuid'],
            'sort' => ['sometimes', 'string', 'in:'.implode(',', self::SORTABLE)],
            'order' => ['sometimes', 'string', 'in:asc,desc'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 20);
        $sort = $validated['sort'] ?? 'created_at';
        $order = $validated['order'] ?? 'desc';

        // Eager-load the composed resource's relations to avoid N+1.
        $query = StudentProfile::query()->with(['person', 'department', 'program', 'classroom']);

        if (! empty($validated['keyword'])) {
            $keyword = '%'.mb_strtolower($validated['keyword']).'%';
            $query->where(function ($q) use ($keyword) {
                $q->whereRaw('LOWER(student_code) LIKE ?', [$keyword])
                    ->orWhereHas('person', function ($p) use ($keyword) {
                        $p->whereRaw('LOWER(first_name) LIKE ?', [$keyword])
                            ->orWhereRaw('LOWER(last_name) LIKE ?', [$keyword])
                            ->orWhereRaw('LOWER(COALESCE(display_name, \'\')) LIKE ?', [$keyword])
                            ->orWhereRaw('LOWER(COALESCE(person_code, \'\')) LIKE ?', [$keyword]);
                    });
            });
        }

        if (! empty($validated['student_code'])) {
            $query->where('student_code', $validated['student_code']);
        }

        if (! empty($validated['academic_status'])) {
            $query->where('academic_status', $validated['academic_status']);
        }

        if (! empty($validated['education_level'])) {
            $query->where('education_level', $validated['education_level']);
        }

        if (! empty($validated['year_level'])) {
            $query->where('year_level', $validated['year_level']);
        }

        if (! empty($validated['admission_year'])) {
            $query->where('admission_year', $validated['admission_year']);
        }

        foreach ([
            'department_uuid' => [Department::class, 'department_id'],
            'program_uuid' => [Program::class, 'program_id'],
            'classroom_uuid' => [Classroom::class, 'classroom_id'],
        ] as $param => [$model, $column]) {
            if (! empty($validated[$param])) {
                $id = $model::where('uuid', $validated[$param])->value('id');
                $query->where($column, $id);
            }
        }

        $paginator = $query->orderBy($sort, $order)->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => StudentResource::collection($paginator->items()),
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

    public function store(StoreStudentRequest $request): JsonResponse
    {
        $profile = $this->service->createStudent(
            $request->validated(),
            $request->user(),
            AuditRequestContext::fromRequest($request),
        );

        return response()->json([
            'success' => true,
            'message' => 'Student created',
            'data' => new StudentResource($profile->load(['person', 'department', 'program', 'classroom'])),
            'meta' => null,
        ], 201);
    }

    public function show(StudentProfile $student): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => new StudentResource($student->load(['person', 'department', 'program', 'classroom'])),
            'meta' => null,
        ]);
    }

    public function update(UpdateStudentRequest $request, StudentProfile $student): JsonResponse
    {
        $updated = $this->service->updateStudent(
            $student,
            $request->validated(),
            $request->user(),
            AuditRequestContext::fromRequest($request),
        );

        return response()->json([
            'success' => true,
            'message' => 'Student updated',
            'data' => new StudentResource($updated->load(['person', 'department', 'program', 'classroom'])),
            'meta' => null,
        ]);
    }
}
