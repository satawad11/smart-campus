<?php

namespace App\Models;

use Database\Factories\EventTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Canonical event-code reference/lookup table (EVT-001, database blueprint
 * §7). access_events.event_type foreign-keys to this table's `code`
 * column (not `id`) — a raw event row is self-describing without a join.
 */
class EventType extends Model
{
    /** @use HasFactory<EventTypeFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['code', 'name_th', 'name_en', 'category', 'severity', 'active'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function events(): HasMany
    {
        return $this->hasMany(AccessEvent::class, 'event_type', 'code');
    }
}
