<?php

namespace Tests\Feature;

use App\Enums\PersonStatus;
use App\Enums\StatusChangeSource;
use App\Enums\StudentAcademicStatus;
use App\Models\StudentProfile;
use App\Models\StudentStatusHistory;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * STD-004 student_status_histories schema + model tests.
 * Active connection: SQLite locally, real PostgreSQL in docker verification.
 */
class StudentStatusHistorySchemaTest extends TestCase
{
    use RefreshDatabase;

    private function isPgsql(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    // 1/8: table + canonical columns + temporal fields.
    public function test_table_and_columns_exist(): void
    {
        $this->assertTrue(Schema::hasTable('student_status_histories'));
        $this->assertTrue(Schema::hasColumns('student_status_histories', [
            'id', 'uuid', 'student_profile_id', 'old_status', 'new_status',
            'effective_from', 'effective_to', 'reason', 'source',
            'changed_by_user_id', 'created_at',
        ]));
        // Immutable created-only record: no updated_at column.
        $this->assertFalse(Schema::hasColumn('student_status_histories', 'updated_at'));
    }

    // 3: references student_profiles, not person/user/student_code directly.
    public function test_references_student_profile_not_person_or_user(): void
    {
        $this->assertTrue(Schema::hasColumn('student_status_histories', 'student_profile_id'));
        foreach (['person_id', 'user_id', 'student_code'] as $col) {
            $this->assertFalse(Schema::hasColumn('student_status_histories', $col));
        }
    }

    // 7/18: no access-decision fields or values represented.
    public function test_no_access_decision_fields(): void
    {
        foreach (['decision', 'access_decision', 'allow', 'deny', 'review'] as $col) {
            $this->assertFalse(Schema::hasColumn('student_status_histories', $col));
        }
    }

    // 2/13/14: relationships + status casts.
    public function test_relationships_and_casts(): void
    {
        $profile = StudentProfile::factory()->create();
        $user = User::factory()->create();

        $history = StudentStatusHistory::factory()->create([
            'student_profile_id' => $profile->id,
            'old_status' => StudentAcademicStatus::UNKNOWN,
            'new_status' => StudentAcademicStatus::ACTIVE,
            'source' => StatusChangeSource::MANUAL,
            'changed_by_user_id' => $user->id,
        ]);

        $this->assertInstanceOf(StudentAcademicStatus::class, $history->old_status);
        $this->assertInstanceOf(StudentAcademicStatus::class, $history->new_status);
        $this->assertInstanceOf(StatusChangeSource::class, $history->source);
        $this->assertInstanceOf(Carbon::class, $history->effective_from);

        $this->assertTrue($history->studentProfile->is($profile));
        $this->assertTrue($history->changedBy->is($user));
        $this->assertTrue($profile->statusHistories->contains($history));
    }

    // 4: canonical academic statuses accepted for new_status.
    public function test_canonical_statuses_accepted(): void
    {
        $profile = StudentProfile::factory()->create();
        foreach (StudentAcademicStatus::cases() as $status) {
            StudentStatusHistory::factory()->create(['student_profile_id' => $profile->id, 'new_status' => $status]);
        }
        $this->assertSame(count(StudentAcademicStatus::cases()), StudentStatusHistory::count());
    }

    // old_status is nullable (e.g., first transition has no prior status).
    public function test_old_status_nullable(): void
    {
        $h = StudentStatusHistory::factory()->create(['old_status' => null]);
        $this->assertNull($h->fresh()->old_status);
    }

    // 5/6/7: invalid / PersonStatus-only / access-decision values rejected (PostgreSQL).
    public function test_invalid_status_values_rejected(): void
    {
        if (! $this->isPgsql()) {
            $this->markTestIncomplete('status CHECK rejection is only enforced on PostgreSQL');
        }

        $profile = StudentProfile::factory()->create();
        foreach (['ARCHIVED', 'INACTIVE', 'ALLOW', 'DENY', 'REVIEW', 'NONSENSE'] as $bad) {
            try {
                DB::table('student_status_histories')->insert([
                    'uuid' => (string) Str::uuid(), 'student_profile_id' => $profile->id,
                    'new_status' => $bad, 'effective_from' => now(), 'source' => 'MANUAL',
                ]);
                $this->fail("new_status '{$bad}' should be rejected");
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
    }

    // 8: academic academic values are distinct from PersonStatus concept.
    public function test_status_enum_distinct_from_person_status(): void
    {
        $academic = array_column(StudentAcademicStatus::cases(), 'value');
        $this->assertContains('SUSPENDED', $academic);
        $this->assertNotContains('ARCHIVED', array_column(StudentAcademicStatus::cases(), 'value'));
        $this->assertNotContains('SUSPENDED', array_column(PersonStatus::cases(), 'value'));
        foreach (['ALLOW', 'REVIEW', 'DENY'] as $d) {
            $this->assertNotContains($d, $academic);
        }
    }

    // 9: effective_from is distinct from created_at.
    public function test_effective_time_distinct_from_created_time(): void
    {
        $past = now()->subMonths(3);
        $h = StudentStatusHistory::factory()->create(['effective_from' => $past]);
        $h->refresh();
        $this->assertTrue($h->effective_from->equalTo($past->startOfSecond()) || $h->effective_from->isSameDay($past));
        $this->assertNotNull($h->created_at);
        // created_at is "now", effective_from is in the past — they are not equated.
        $this->assertTrue($h->created_at->greaterThan($h->effective_from));
    }

    // FK enforced.
    public function test_student_profile_fk_enforced(): void
    {
        $this->expectException(QueryException::class);
        StudentStatusHistory::factory()->create(['student_profile_id' => 999999]);
    }

    // 15: creating history does not mutate the profile's current status.
    public function test_creating_history_does_not_mutate_current_status(): void
    {
        $profile = StudentProfile::factory()->create(['academic_status' => StudentAcademicStatus::ACTIVE]);
        StudentStatusHistory::factory()->create([
            'student_profile_id' => $profile->id,
            'old_status' => StudentAcademicStatus::ACTIVE,
            'new_status' => StudentAcademicStatus::SUSPENDED,
        ]);
        $this->assertSame(StudentAcademicStatus::ACTIVE, $profile->fresh()->academic_status);
    }

    // 16: migration does not backfill any history rows.
    public function test_no_fake_initial_history(): void
    {
        StudentProfile::factory()->create();
        $this->assertSame(0, StudentStatusHistory::count());
    }

    // 17: no standalone status-history resource routes exist.
    public function test_no_status_history_routes(): void
    {
        foreach (Route::getRoutes() as $route) {
            $this->assertNotSame('api/v1/status-histories', $route->uri());
            $this->assertNotSame('status-histories', $route->uri());
            $this->assertStringNotContainsString('student_status', $route->uri());
        }
    }
}
