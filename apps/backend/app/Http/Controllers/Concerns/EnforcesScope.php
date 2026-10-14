<?php

namespace App\Http\Controllers\Concerns;

use App\Exceptions\ApiException;
use App\Models\Site;
use App\Services\Auth\PermissionService;
use App\Support\Authorization\ScopeContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * GLOBAL / SITE / ZONE data scope for site-owned resources (AUTH-009).
 *
 * V1 audit A-05: PermissionService::applyScope() existed but no REST endpoint
 * used it, so a SITE- or ZONE-scoped role behaved like GLOBAL for events,
 * history, reports, cameras and AI nodes. Out-of-scope records answer 404
 * (not 403) so their existence is not disclosed.
 */
trait EnforcesScope
{
    private function scopes(): PermissionService
    {
        return app(PermissionService::class);
    }

    /**
     * Constrain a list query to the caller's scope. Pass $zoneColumn = null for
     * tables that have no zone dimension (zone-only grants then see nothing).
     */
    protected function scopeQuery($query, Request $request, string $permission, string $siteColumn = 'site_id', ?string $zoneColumn = 'zone_id')
    {
        if ($zoneColumn !== null) {
            return $this->scopes()->applyScope($query, $request->user(), $permission, $siteColumn, $zoneColumn);
        }

        $siteIds = $this->scopes()->accessibleSiteIds($request->user(), $permission);
        if ($siteIds === null) {
            return $query;
        }

        return $siteIds === [] ? $query->whereRaw('1 = 0') : $query->whereIn($siteColumn, $siteIds);
    }

    /**
     * Abort with 404 unless the record's site/zone is within the caller's scope.
     * A record without a site (e.g. an unassigned AI node) needs GLOBAL scope.
     */
    protected function assertInScope(Request $request, string $permission, ?int $siteId, ?int $zoneId = null): void
    {
        $context = match (true) {
            $zoneId !== null => ScopeContext::zone($zoneId, $siteId),
            $siteId !== null => ScopeContext::site($siteId),
            default => ScopeContext::global(),
        };

        if (! $this->scopes()->can($request->user(), $permission, $context)) {
            throw new NotFoundHttpException;
        }
    }

    /**
     * Site filter for site-level aggregates (dashboard, reports, exports).
     * GLOBAL: the requested site or all sites. SITE-scoped: the requested site
     * must be in scope; without one, the single accessible site is used, and
     * several accessible sites require an explicit choice. Zone-only grants do
     * not cover whole-site aggregates.
     */
    protected function resolveScopedSiteId(Request $request, string $permission, ?string $siteUuid): ?int
    {
        $siteId = $siteUuid ? Site::where('uuid', $siteUuid)->value('id') : null;
        $allowed = $this->scopes()->accessibleSiteIds($request->user(), $permission);

        if ($allowed === null) {
            return $siteId;
        }
        if ($siteId !== null) {
            if (! in_array($siteId, $allowed, true)) {
                throw new NotFoundHttpException;
            }

            return $siteId;
        }
        if (count($allowed) === 1) {
            return $allowed[0];
        }
        if ($allowed === []) {
            throw new NotFoundHttpException;
        }

        throw ApiException::unprocessable('SITE_REQUIRED', 'Select a site within your scope (site_uuid).');
    }

    /**
     * The first of $permissions the caller holds (route middleware already
     * required at least one), so scope is resolved for a grant that exists.
     *
     * @param  list<string>  $permissions
     */
    protected function heldPermission(Request $request, array $permissions): string
    {
        foreach ($permissions as $permission) {
            if ($this->scopes()->hasPermission($request->user(), $permission)) {
                return $permission;
            }
        }

        return $permissions[0];
    }

    /** resolveScopedSiteId() expressed as a site UUID, for code that filters by site_uuid. */
    protected function scopedSiteUuid(Request $request, string $permission, ?string $siteUuid): ?string
    {
        $siteId = $this->resolveScopedSiteId($request, $permission, $siteUuid);

        return $siteId === null ? null : Site::whereKey($siteId)->value('uuid');
    }
}
