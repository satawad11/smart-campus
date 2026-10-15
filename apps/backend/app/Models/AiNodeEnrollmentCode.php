<?php

namespace App\Models;

use App\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One-time AI node enrollment code (COM-03e). Only a hash of the code is
 * stored; the plaintext exists only in the issuance response.
 */
class AiNodeEnrollmentCode extends Model
{
    use HasPublicUuid;

    /** @var list<string> */
    protected $fillable = [
        'ai_node_id', 'code_hash', 'created_by_user_id', 'expires_at',
        'used_at', 'used_from_ip', 'invalidated_at',
    ];

    /** @var list<string> */
    protected $hidden = ['code_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'invalidated_at' => 'datetime',
        ];
    }

    public function aiNode(): BelongsTo
    {
        return $this->belongsTo(AiNode::class);
    }

    public function isUsable(): bool
    {
        return $this->used_at === null
            && $this->invalidated_at === null
            && $this->expires_at->isFuture();
    }
}
