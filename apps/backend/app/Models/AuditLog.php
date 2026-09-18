<?php

namespace App\Models;

use App\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Security and administrative audit trail (audit_logs).
 *
 * Immutable append-only audit record.
 */
class AuditLog extends Model
{
    use HasPublicUuid;

    public $timestamps = true;

    const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'uuid',
        'user_id',
        'action',
        'module',
        'entity_type',
        'entity_id',
        'old_values',
        'new_values',
        'metadata',
        'ip_address',
        'user_agent',
        'request_id',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }
}
