<?php

namespace App\Models;

use App\Concerns\HasPublicUuid;
use App\Enums\GuardianRelation;
use Database\Factories\GuardianLinkFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A parent/guardian Person linked to a student Person (Module 09, PPL-002).
 */
class GuardianLink extends Model
{
    /** @use HasFactory<GuardianLinkFactory> */
    use HasFactory, HasPublicUuid;

    protected $table = 'guardian_links';

    /** @var list<string> */
    protected $fillable = [
        'guardian_person_id',
        'student_person_id',
        'relation',
        'created_by_user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'relation' => GuardianRelation::class,
        ];
    }

    public function guardian(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'guardian_person_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'student_person_id');
    }
}
