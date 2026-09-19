<?php

namespace Tests\Feature;

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

    // 9-10: STD-003 / Module 03 tables are NOT created.
    public function test_student_and_face_tables_not_created(): void
    {
        foreach (['student_profiles', 'student_status_histories', 'face_profiles', 'face_samples'] as $table) {
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

    // 12/13: FK references persons.id with SET NULL on delete.
    public function test_user_person_relationship_and_fk_set_null(): void
    {
        $person = Person::factory()->create();
        $user = User::factory()->create(['person_id' => $person->id]);

        $this->assertTrue($user->person->is($person));
        $this->assertTrue($person->users->contains($user));

        // 15: person may exist without a user (already: created independently).
        $lonePerson = Person::factory()->create();
        $this->assertCount(0, $lonePerson->users);

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
}
