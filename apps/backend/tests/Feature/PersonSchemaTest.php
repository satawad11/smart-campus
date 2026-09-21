<?php

namespace Tests\Feature;

use App\Enums\PersonStatus;
use App\Enums\PersonType;
use App\Models\Person;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * STD-002 Person master schema + model tests.
 *
 * Runs on the active connection (SQLite locally, real PostgreSQL in docker
 * verification). PostgreSQL-specific behavior (enum CHECK, FK SET NULL) is
 * asserted directly where it differs from SQLite.
 */
class PersonSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function isPgsql(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    // 1-5: table, PK, uuid, canonical fields, timestamps.
    public function test_persons_table_and_canonical_columns(): void
    {
        $this->assertTrue(Schema::hasTable('persons'));
        $this->assertTrue(Schema::hasColumns('persons', [
            'id', 'uuid', 'person_code', 'person_type', 'first_name', 'last_name',
            'display_name', 'gender', 'primary_photo', 'status',
            'created_at', 'updated_at', 'deleted_at',
        ]));
    }

    // 7: academic fields are NOT duplicated into persons.
    public function test_persons_has_no_academic_fields(): void
    {
        foreach (['student_number', 'student_code', 'department_id', 'program_id', 'classroom_id', 'academic_status'] as $col) {
            $this->assertFalse(Schema::hasColumn('persons', $col), "persons must not contain {$col}");
        }
    }

    // 8: biometric fields are absent.
    public function test_persons_has_no_biometric_fields(): void
    {
        foreach (['embedding', 'face_template', 'face_image', 'face_status', 'liveness_score', 'recognition_score'] as $col) {
            $this->assertFalse(Schema::hasColumn('persons', $col), "persons must not contain {$col}");
        }
    }

    // Camera/AINode schema (CAM-001) is now a legitimate Module 04 artifact;
    // later Module 04 domains (actual RTSP stream tracking, CAM-006+) are not.
    public function test_future_rtsp_stream_tables_not_created(): void
    {
        foreach (['rtsp_streams'] as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} must not exist yet");
        }
    }

    public function test_uuid_is_unique(): void
    {
        $a = Person::factory()->create();
        $this->expectException(QueryException::class);
        Person::factory()->create(['uuid' => $a->uuid]);
    }

    public function test_person_code_is_unique(): void
    {
        Person::factory()->create(['person_code' => 'PC_DUP']);
        $this->expectException(QueryException::class);
        Person::factory()->create(['person_code' => 'PC_DUP']);
    }

    public function test_person_code_nullable_allows_multiple_nulls(): void
    {
        Person::factory()->create(['person_code' => null]);
        Person::factory()->create(['person_code' => null]);
        $this->assertSame(2, Person::whereNull('person_code')->count());
    }

    // 3 + model: uuid auto-generated; soft delete; person_type cast.
    public function test_model_behavior(): void
    {
        $person = Person::factory()->create(['person_type' => PersonType::STUDENT]);
        $this->assertNotEmpty($person->uuid);
        $this->assertInstanceOf(PersonType::class, $person->person_type);
        $this->assertSame('uuid', $person->getRouteKeyName());

        $id = $person->id;
        $person->delete();
        $this->assertSoftDeleted('persons', ['id' => $id]);
    }

    public function test_person_type_domain(): void
    {
        foreach (PersonType::cases() as $type) {
            Person::factory()->create(['person_type' => $type]);
        }
        $this->assertSame(count(PersonType::cases()), Person::count());

        if (! $this->isPgsql()) {
            $this->markTestIncomplete('enum CHECK rejection is only enforced on PostgreSQL');
        }

        $this->expectException(QueryException::class);
        DB::table('persons')->insert([
            'uuid' => (string) Str::uuid(), 'person_type' => 'ROBOT',
            'first_name' => 'X', 'last_name' => 'Y', 'status' => 'ACTIVE',
        ]);
    }

    // 11/14: users.person_id nullable; user may exist without a person.
    public function test_user_person_id_is_nullable(): void
    {
        $user = User::factory()->create();
        $this->assertNull($user->person_id);
        $this->assertNull($user->person);
    }

    // 13/14/16/17: optional one-to-one relationship + SET NULL on delete.
    public function test_user_person_relationship_and_fk_set_null(): void
    {
        $person = Person::factory()->create();
        $user = User::factory()->create(['person_id' => $person->id]);

        $this->assertTrue($user->person->is($person));   // User::person()
        $this->assertTrue($person->user->is($user));      // Person::user() hasOne

        // 16: person may exist without a user.
        $lonePerson = Person::factory()->create();
        $this->assertNull($lonePerson->user);

        // 13: hard-deleting the referenced person nulls the FK, never deletes the user.
        Person::withoutEvents(fn () => Person::whereKey($person->id)->forceDelete());
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertNull($user->fresh()->person_id);
    }

    // Invalid person_id rejected by the FK.
    public function test_user_person_id_foreign_key_enforced(): void
    {
        $this->expectException(QueryException::class);
        User::factory()->create(['person_id' => 999999]);
    }

    // 1: status defaults to ACTIVE.
    public function test_status_defaults_to_active(): void
    {
        $id = DB::table('persons')->insertGetId([
            'uuid' => (string) Str::uuid(), 'person_type' => 'STUDENT',
            'first_name' => 'A', 'last_name' => 'B',
        ]);
        $this->assertSame('ACTIVE', DB::table('persons')->where('id', $id)->value('status'));
        $this->assertSame(PersonStatus::ACTIVE, Person::find($id)->status);
    }

    // 2-4: canonical statuses accepted and cast.
    public function test_status_accepts_canonical_values(): void
    {
        foreach ([PersonStatus::ACTIVE, PersonStatus::INACTIVE, PersonStatus::ARCHIVED] as $status) {
            $person = Person::factory()->create(['status' => $status]);
            $this->assertSame($status, $person->fresh()->status);
        }
    }

    // 5-6: arbitrary / academic statuses rejected (PostgreSQL CHECK).
    public function test_status_rejects_non_canonical_values(): void
    {
        if (! $this->isPgsql()) {
            $this->markTestIncomplete('status CHECK rejection is only enforced on PostgreSQL');
        }

        foreach (['SOMETHING', 'SUSPENDED', 'GRADUATED'] as $bad) {
            try {
                DB::table('persons')->insert([
                    'uuid' => (string) Str::uuid(), 'person_type' => 'STUDENT',
                    'first_name' => 'A', 'last_name' => 'B', 'status' => $bad,
                ]);
                $this->fail("status '{$bad}' should be rejected");
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
    }

    // 8: multiple users with NULL person_id are allowed.
    public function test_multiple_users_may_have_null_person(): void
    {
        User::factory()->create(['person_id' => null]);
        User::factory()->create(['person_id' => null]);
        $this->assertSame(2, User::whereNull('person_id')->count());
    }

    // 9-10: one user per person; a second user cannot reference the same person.
    public function test_person_id_is_unique_when_non_null(): void
    {
        $person = Person::factory()->create();
        User::factory()->create(['person_id' => $person->id]);

        $this->expectException(QueryException::class);
        User::factory()->create(['person_id' => $person->id]);
    }

    // 15: Person::users() is no longer part of the canonical model API.
    public function test_person_users_relationship_removed(): void
    {
        $this->assertFalse(method_exists(Person::class, 'users'));
        $this->assertTrue(method_exists(Person::class, 'user'));
    }
}
