<?php

namespace App\Models;

use App\Concerns\HasPublicUuid;
use App\Enums\VisitStatus;
use Database\Factories\VisitFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One planned or actual visit (Module 09, PPL-002). Whether a visitor is allowed in
 * is decided by Module 05 policy from this record (PPL-008), never by the AI node.
 */
class Visit extends Model
{
    /** @use HasFactory<VisitFactory> */
    use HasFactory, HasPublicUuid;

    protected $table = 'visits';

    /** @var list<string> */
    protected $fillable = [
        'visitor_person_id',
        'host_person_id',
        'purpose',
        'site_id',
        'zone_id',
        'valid_from',
        'valid_until',
        'status',
        'checked_in_at',
        'checked_out_at',
        'created_by_user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => VisitStatus::class,
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
            'checked_in_at' => 'datetime',
            'checked_out_at' => 'datetime',
        ];
    }

    public function visitor(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'visitor_person_id');
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'host_person_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }
}
