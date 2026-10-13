<?php

namespace App\Models;

use App\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An installed commercial license (COM-02). The columns are a readable copy
 * of the signed payload; authority is the `envelope`, re-verified by
 * LicenseService on every evaluation.
 */
class License extends Model
{
    use HasPublicUuid;

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_SUPERSEDED = 'SUPERSEDED';

    protected $table = 'licenses';

    /** @var list<string> */
    protected $hidden = ['id', 'installed_by_user_id', 'envelope'];

    protected $fillable = [
        'license_id',
        'key_id',
        'edition',
        'plan',
        'licensee_name',
        'installation_uuid',
        'features',
        'limits',
        'issued_at',
        'not_before',
        'expires_at',
        'grace_days',
        'envelope',
        'status',
        'installed_by_user_id',
        'last_validated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'features' => 'array',
            'limits' => 'array',
            'issued_at' => 'immutable_datetime',
            'not_before' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'last_validated_at' => 'immutable_datetime',
            'grace_days' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function installedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'installed_by_user_id');
    }
}
