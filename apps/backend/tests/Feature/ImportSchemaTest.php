<?php

namespace Tests\Feature;

use App\Enums\ImportJobStatus;
use App\Enums\ImportJobType;
use App\Models\ImportError;
use App\Models\ImportJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * STD-011 import_jobs and import_errors schema + model tests.
 */
class ImportSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_jobs_table_and_columns_exist(): void
    {
        $this->assertTrue(Schema::hasTable('import_jobs'));
        $this->assertTrue(Schema::hasColumns('import_jobs', [
            'id',
            'uuid',
            'type',
            'file_path',
            'status',
            'total_rows',
            'valid_rows',
            'invalid_rows',
            'success_rows',
            'failed_rows',
            'created_by',
            'started_at',
            'completed_at',
            'created_at',
            'updated_at',
        ]));
    }

    public function test_import_errors_table_and_columns_exist(): void
    {
        $this->assertTrue(Schema::hasTable('import_errors'));
        $this->assertTrue(Schema::hasColumns('import_errors', [
            'id',
            'import_job_id',
            'row_number',
            'field',
            'value',
            'error_code',
            'error_message',
            'created_at',
        ]));

        // Immutable error log: no updated_at column
        $this->assertFalse(Schema::hasColumn('import_errors', 'updated_at'));
    }

    public function test_no_biometric_face_or_access_decision_columns(): void
    {
        $forbidden = [
            'face_id',
            'face_embedding',
            'biometric',
            'embedding',
            'vector',
            'liveness',
            'decision',
            'access_decision',
        ];

        foreach ($forbidden as $col) {
            $this->assertFalse(Schema::hasColumn('import_jobs', $col));
            $this->assertFalse(Schema::hasColumn('import_errors', $col));
        }
    }

    public function test_uuid_unique_and_auto_generated(): void
    {
        $job1 = ImportJob::factory()->create();
        $job2 = ImportJob::factory()->create();

        $this->assertNotNull($job1->uuid);
        $this->assertNotNull($job2->uuid);
        $this->assertTrue(Str::isUuid($job1->uuid));
        $this->assertTrue(Str::isUuid($job2->uuid));
        $this->assertNotEquals($job1->uuid, $job2->uuid);
    }

    public function test_default_counters_and_status(): void
    {
        $job = ImportJob::create([
            'type' => ImportJobType::STUDENT,
        ]);

        $this->assertEquals(ImportJobStatus::PENDING, $job->status);
        $this->assertSame(0, $job->total_rows);
        $this->assertSame(0, $job->valid_rows);
        $this->assertSame(0, $job->invalid_rows);
        $this->assertSame(0, $job->success_rows);
        $this->assertSame(0, $job->failed_rows);
        $this->assertNull($job->started_at);
        $this->assertNull($job->completed_at);
    }

    public function test_relationships_and_casts(): void
    {
        $user = User::factory()->create();

        $job = ImportJob::factory()->create([
            'type' => ImportJobType::STUDENT,
            'status' => ImportJobStatus::VALIDATING,
            'total_rows' => 100,
            'valid_rows' => 90,
            'invalid_rows' => 10,
            'created_by' => $user->id,
            'started_at' => now(),
        ]);

        $error = ImportError::factory()->create([
            'import_job_id' => $job->id,
            'row_number' => 12,
            'field' => 'national_id',
            'value' => '12345',
            'error_code' => 'INVALID_NATIONAL_ID',
            'error_message' => 'National ID format is invalid.',
        ]);

        // Model casts
        $this->assertInstanceOf(ImportJobType::class, $job->type);
        $this->assertInstanceOf(ImportJobStatus::class, $job->status);
        $this->assertIsInt($job->total_rows);
        $this->assertInstanceOf(Carbon::class, $job->started_at);
        $this->assertIsInt($error->row_number);

        // Model relationships
        $this->assertTrue($job->creator->is($user));
        $this->assertTrue($user->importJobs->contains($job));
        $this->assertTrue($job->errors->contains($error));
        $this->assertTrue($error->importJob->is($job));
        $this->assertTrue($error->job->is($job));
    }

    public function test_cascading_delete_on_import_errors(): void
    {
        $job = ImportJob::factory()->create();
        $error = ImportError::factory()->create(['import_job_id' => $job->id]);

        $this->assertDatabaseHas('import_errors', ['id' => $error->id]);

        $job->delete();

        $this->assertDatabaseMissing('import_errors', ['id' => $error->id]);
    }

    public function test_creator_deletion_sets_null(): void
    {
        $user = User::factory()->create();
        $job = ImportJob::factory()->create(['created_by' => $user->id]);

        $this->assertEquals($user->id, $job->created_by);

        $user->forceDelete();

        $this->assertNull($job->fresh()->created_by);
    }

    public function test_all_canonical_statuses_accepted(): void
    {
        foreach (ImportJobStatus::cases() as $status) {
            $job = ImportJob::factory()->create(['status' => $status]);
            $this->assertEquals($status, $job->fresh()->status);
        }

        $this->assertSame(count(ImportJobStatus::cases()), ImportJob::count());
    }
}
