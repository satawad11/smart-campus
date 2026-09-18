<?php

namespace App\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

/**
 * Auto-generates the external `uuid` column while keeping the internal BIGINT
 * primary key (database blueprint §2). Uses Laravel's native HasUuids, scoped
 * to the `uuid` column via uniqueIds(), and binds routes by uuid.
 *
 * Applied to models that expose a public UUID identifier (User, Role, ...).
 */
trait HasPublicUuid
{
    use HasUuids;

    /**
     * Generate UUIDs for the `uuid` column only — not the primary key.
     *
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * Public routes resolve models by their UUID, never the numeric id.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
