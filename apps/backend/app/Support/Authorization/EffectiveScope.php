<?php

namespace App\Support\Authorization;

use App\Enums\ScopeLevel;

/**
 * A user's effective authorization scope for a single permission (AUTH-009).
 *
 * One of:
 *   - GLOBAL: the permission applies everywhere (a GLOBAL role assignment or a
 *     direct ALLOW override, which is currently unscoped).
 *   - SCOPED: limited to specific site ids (whole-site grants) and/or zone ids.
 *   - NONE:   the permission is not effectively granted (or globally DENIED).
 *
 * This is server-side authorization state and must not be exposed through
 * public API responses.
 */
final class EffectiveScope
{
    private const GLOBAL = 'GLOBAL';

    private const SCOPED = 'SCOPED';

    private const NONE = 'NONE';

    /**
     * @param  list<int>  $siteIds
     * @param  list<int>  $zoneIds
     */
    private function __construct(
        private readonly string $type,
        private readonly array $siteIds = [],
        private readonly array $zoneIds = [],
    ) {}

    public static function global(): self
    {
        return new self(self::GLOBAL);
    }

    public static function none(): self
    {
        return new self(self::NONE);
    }

    /**
     * @param  list<int>  $siteIds
     * @param  list<int>  $zoneIds
     */
    public static function scoped(array $siteIds, array $zoneIds): self
    {
        $siteIds = array_values(array_unique(array_map('intval', $siteIds)));
        $zoneIds = array_values(array_unique(array_map('intval', $zoneIds)));

        if ($siteIds === [] && $zoneIds === []) {
            return self::none();
        }

        return new self(self::SCOPED, $siteIds, $zoneIds);
    }

    public function isGlobal(): bool
    {
        return $this->type === self::GLOBAL;
    }

    public function isNone(): bool
    {
        return $this->type === self::NONE;
    }

    /** @return list<int> */
    public function siteIds(): array
    {
        return $this->siteIds;
    }

    /** @return list<int> */
    public function zoneIds(): array
    {
        return $this->zoneIds;
    }

    /**
     * Does this effective scope cover the requested context?
     */
    public function covers(ScopeContext $context): bool
    {
        if ($this->isNone()) {
            return false;
        }

        if ($this->isGlobal()) {
            return true;
        }

        // SCOPED coverage.
        return match ($context->level()) {
            // A scoped grant never satisfies a global (site/zone-less) request.
            ScopeLevel::GLOBAL => false,
            // Whole-site grant covers the site.
            ScopeLevel::SITE => in_array($context->siteId, $this->siteIds, true),
            // Zone request covered by an exact zone grant, or by a whole-site
            // grant for the zone's owning site (when that site was supplied).
            ScopeLevel::ZONE => in_array($context->zoneId, $this->zoneIds, true)
                || ($context->siteId !== null && in_array($context->siteId, $this->siteIds, true)),
        };
    }

    /**
     * Normalized, server-side representation (never returned to clients as-is).
     *
     * @return array{scope: string, site_ids: list<int>, zone_ids: list<int>}
     */
    public function toArray(): array
    {
        return [
            'scope' => $this->type,
            'site_ids' => $this->siteIds,
            'zone_ids' => $this->zoneIds,
        ];
    }
}
