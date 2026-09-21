<?php

namespace App\Models;

use App\Concerns\HasPublicUuid;
use App\Enums\AiNodeCredentialStatus;
use Database\Factories\AiNodeCredentialFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-node machine credential (CAM-004). Only a one-way hash of the
 * plaintext token is ever stored; the plaintext exists only in the issuance
 * response and is never recoverable afterward. $hidden as defense in depth
 * against an accidental future serialization of this model.
 */
class AiNodeCredential extends Model
{
    /** @use HasFactory<AiNodeCredentialFactory> */
    use HasFactory, HasPublicUuid;

    /** @var list<string> */
    protected $fillable = ['ai_node_id', 'token_hash', 'status', 'last_used_at', 'revoked_at'];

    /** @var list<string> */
    protected $hidden = ['token_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AiNodeCredentialStatus::class,
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function aiNode(): BelongsTo
    {
        return $this->belongsTo(AiNode::class);
    }
}
