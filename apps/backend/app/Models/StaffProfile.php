<?php

namespace App\Models;

use App\Concerns\HasPublicUuid;
use App\Enums\StaffEmploymentStatus;
use Database\Factories\StaffProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Teacher/staff employment profile (Module 09, PPL-002).
 *
 * Belongs to exactly one Person (0..1 : 1), like StudentProfile. Person stays the
 * master identity and face profiles keep referencing person_id. employment_status
 * is registry data, never an access decision (Module 05 policy owns that).
 */
class StaffProfile extends Model
{
    /** @use HasFactory<StaffProfileFactory> */
    use HasFactory, HasPublicUuid;

    protected $table = 'staff_profiles';

    /** @var list<string> */
    protected $fillable = [
        'person_id',
        'staff_code',
        'position',
        'department_id',
        'employment_status',
        'status_start_date',
        'phone',
        'email',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'employment_status' => StaffEmploymentStatus::class,
            'status_start_date' => 'date',
            'metadata' => 'array',
        ];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(StaffStatusHistory::class);
    }
}
