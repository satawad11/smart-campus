<?php

namespace App\Models;

use App\Concerns\HasPublicUuid;
use App\Enums\AiNodeCommandType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A remote operation requested for an AI node (COM-03).
 * PENDING -> DELIVERED (claimed by the node) -> SUCCEEDED | FAILED,
 * or EXPIRED when the node doesn't finish before expires_at.
 */
class AiNodeCommand extends Model
{
    use HasPublicUuid;

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_DELIVERED = 'DELIVERED';

    public const STATUS_SUCCEEDED = 'SUCCEEDED';

    public const STATUS_FAILED = 'FAILED';

    public const STATUS_EXPIRED = 'EXPIRED';

    public const OPEN_STATUSES = [self::STATUS_PENDING, self::STATUS_DELIVERED];

    protected $table = 'ai_node_commands';

    /** @var list<string> */
    protected $hidden = ['id', 'ai_node_id', 'requested_by_user_id'];

    protected $fillable = [
        'ai_node_id',
        'command',
        'status',
        'reason',
        'requested_by_user_id',
        'expires_at',
        'delivered_at',
        'completed_at',
        'result_message',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'command' => AiNodeCommandType::class,
            'expires_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<AiNode, $this>
     */
    public function aiNode(): BelongsTo
    {
        return $this->belongsTo(AiNode::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }
}
