<?php

namespace App\Models;

use App\Concerns\HasPublicUuid;
use App\Enums\PersonType;
use Database\Factories\PersonFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        ];
    }

    /**
     * Optional login accounts for this person. Cardinality is not constrained
     * to 1:1 (see STD-002 report); modeled as hasMany until confirmed.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
