<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Maps an RMS API Center record (student code, s_... staff id, group id) to
 * the platform record it was synced into (ACR-INT-01).
 */
class RmsLink extends Model
{
    public const STUDENT = 'STUDENT';

    public const STAFF = 'STAFF';

    public const GROUP = 'GROUP';

    /** @var list<string> */
    protected $fillable = [
        'resource', 'external_id', 'entity_type', 'entity_id', 'payload_hash',
        'first_seen_at', 'last_seen_at', 'missing_since',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entity_id' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'missing_since' => 'datetime',
        ];
    }
}
