<?php

namespace App\Support\Authorization;

use App\Enums\ScopeLevel;

/**
 * Requested authorization scope context (AUTH-009).
 *
 * An immutable, already-resolved (internal-id) scope against which a permission
 * is evaluated. Later domain modules resolve public UUIDs -> internal
 * site_id/zone_id and construct this object; AUTH-009 never queries a
 * sites/zones table (those domains do not exist yet).
 */
final class ScopeContext
{
    public function __construct(
        public readonly ?int $siteId = null,
        public readonly ?int $zoneId = null,
    ) {}

    /** Global context — asks whether the permission is held globally. */
    public static function global(): self
    {
        return new self(null, null);
    }

    /** Site-level context. */
    public static function site(int $siteId): self
    {
        return new self($siteId, null);
    }

    /**
     * Zone-level context. The owning site_id should be supplied so that a
     * site-scoped assignment can cover zones within that site.
     */
    public static function zone(int $zoneId, ?int $siteId = null): self
    {
        return new self($siteId, $zoneId);
    }

    /**
     * The level of this context. If a zone is present it is zone-scoped,
     * regardless of whether the owning site was also supplied.
     */
    public function level(): ScopeLevel
    {
        if ($this->zoneId !== null) {
            return ScopeLevel::ZONE;
        }

        if ($this->siteId !== null) {
            return ScopeLevel::SITE;
        }

        return ScopeLevel::GLOBAL;
    }
}
