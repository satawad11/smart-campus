<?php

namespace App\Models;

use App\Concerns\HasPublicUuid;
use App\Enums\StaffEmploymentStatus;
use App\Enums\StatusChangeSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Employment status change history (Module 09, PPL-002), the staff counterpart of
 * StudentStatusHistory. Immutable, created-only.
 */
class StaffStatusHistory extends Model
{
    use HasPublicUuid;

    protected $table = 'staff_status_histories';

    const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'staff_profile_id',
        'old_status',
        'new_status',
        'effective_from',
        'effective_to',
        'reason',
        'source',
        'changed_by_user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_status' => StaffEmploymentStatus::class,
            'new_status' => StaffEmploymentStatus::class,
            'source' => StatusChangeSource::class,
            'effective_from' => 'datetime',
            'effective_to' => 'datetime',
        ];
    }

    public function staffProfile(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
