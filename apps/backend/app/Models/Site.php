<?php

namespace App\Models;

use App\Concerns\HasPublicUuid;
use App\Enums\LocationStatus;
use Database\Factories\SiteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Physical site (CAM-001, database blueprint §6; system blueprint §12
 * hierarchy Site -> Zone -> Camera). Created here because no earlier task
 * owns it — AUTH-009 deliberately deferred it to "domain modules"; see the
 * CAM-001 implementation report for the recorded assumption.
 */
class Site extends Model
{
    /** @use HasFactory<SiteFactory> */
    use HasFactory, HasPublicUuid;

    /** @var list<string> */
    protected $fillable = ['code', 'name', 'address', 'timezone', 'status'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => LocationStatus::class];
    }

    public function zones(): HasMany
    {
        return $this->hasMany(Zone::class);
    }

    public function cameras(): HasMany
    {
        return $this->hasMany(Camera::class);
    }

    public function aiNodes(): HasMany
    {
        return $this->hasMany(AiNode::class);
    }
}
