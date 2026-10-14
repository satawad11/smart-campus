<?php

namespace App\Models;

use App\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One immutable revision of an AI node's runtime settings (COM-03).
 */
class AiNodeConfigRevision extends Model
{
    use HasPublicUuid;

    protected $table = 'ai_node_config_revisions';

    /** @var list<string> */
    protected $hidden = ['id', 'ai_node_id', 'created_by_user_id'];

    protected $fillable = [
        'ai_node_id',
        'revision',
        'settings',
        'note',
        'rolled_back_from_revision',
        'created_by_user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'settings' => 'array',
            'rolled_back_from_revision' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
