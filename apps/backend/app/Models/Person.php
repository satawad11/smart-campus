<?php

namespace App\Models;

use App\Concerns\HasPublicUuid;
use App\Enums\PersonStatus;
use App\Enums\PersonType;
use Database\Factories\PersonFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Person master identity (STD-002).
 *
 * The canonical real-world identity. A Person is NOT automatically a User
 * (login account) or a Student (academic profile); those are separate concepts.
 * Future face profiles (Module 03) will reference person_id — never user_id or
 * student_id. No academic status or biometric data lives here.
 */
class Person extends Model
{
    /** @use HasFactory<PersonFactory> */
    use HasFactory, HasPublicUuid, SoftDeletes;

    // Canonical table is `persons` (not Laravel's default "people").
    protected $table = 'persons';

    /** @var list<string> */
    protected $fillable = [
        'person_code',
        'person_type',
        'first_name',
        'last_name',
        'display_name',
        'gender',
        'primary_photo',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'person_type' => PersonType::class,
            'status' => PersonStatus::class,
        ];
    }

    /**
     * The optional login account for this person. Canonical V1 cardinality is
     * optional one-to-one (users.person_id is unique when non-null).
     */
    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    /**
     * The optional academic profile for this person (STD-003). Canonical 0..1:1
     * (student_profiles.person_id is unique).
     */
    public function studentProfile(): HasOne
    {
        return $this->hasOne(StudentProfile::class);
    }

    /**
     * The optional biometric face profile for this person (FACE-002, database blueprint §5).
     * Canonical 0..1:1 (face_profiles.person_id is unique).
     */
    public function faceProfile(): HasOne
    {
        return $this->hasOne(FaceProfile::class);
    }

    /**
     * Vehicle links over time (VEH-002, ACR-VEH-01). Append-only history; use
     * ->current() for links that apply now.
     */
    public function vehicleOwnerships(): HasMany
    {
        return $this->hasMany(VehicleOwnershipHistory::class);
    }

    /**
     * Name to show on screens: display_name when set, otherwise "first last".
     * Many records (imports, RMS sync) have no display_name.
     */
    public function displayLabel(): string
    {
        $display = trim((string) $this->display_name);

        return $display !== '' ? $display : trim($this->first_name.' '.$this->last_name);
    }

    /** Teacher/staff employment profile (Module 09, PPL-002). Canonical 0..1:1. */
    public function staffProfile(): HasOne
    {
        return $this->hasOne(StaffProfile::class);
    }

    /** Visitor/contractor profile (Module 09, PPL-002). Canonical 0..1:1. */
    public function visitorProfile(): HasOne
    {
        return $this->hasOne(VisitorProfile::class);
    }

    /** Students this person is a guardian of (Module 09, PPL-002). */
    public function guardianOf(): HasMany
    {
        return $this->hasMany(GuardianLink::class, 'guardian_person_id');
    }

    /** Guardians of this (student) person (Module 09, PPL-002). */
    public function guardians(): HasMany
    {
        return $this->hasMany(GuardianLink::class, 'student_person_id');
    }

    /** Visits made by this (visitor) person (Module 09, PPL-002). */
    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class, 'visitor_person_id');
    }
}
