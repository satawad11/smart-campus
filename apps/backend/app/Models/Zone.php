<?php

namespace App\Models;

use App\Concerns\HasPublicUuid;
use App\Enums\LocationStatus;
use Database\Factories\ZoneFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Zone within a Site (CAM-001, database blueprint §6). May nest under a
 * parent zone. zone_type has no canonical value set defined anywhere in the
 * specs, so it is a free-form string rather than an invented enum.
 */
class Zone extends Model
{
    /** @use HasFactory<ZoneFactory> */
    use HasFactory, HasPublicUuid;

    /** @var list<string> */
    protected $fillable = ['site_id', 'parent_zone_id', 'code', 'name', 'zone_type', 'status'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => LocationStatus::class];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function parentZone(): BelongsTo
    {
        return $this->belongsTo(Zone::class, 'parent_zone_id');
    }

    public function childZones(): HasMany
    {
        return $this->hasMany(Zone::class, 'parent_zone_id');
    }

    public function cameras(): HasMany
    {
        return $this->hasMany(Camera::class);
    }
}
