<?php

use App\Models\Site;
use App\Models\User;
use App\Services\Auth\PermissionService;
use App\Support\Authorization\ScopeContext;
use Illuminate\Support\Facades\Broadcast;

/*
| EVT-013/EVT-014: realtime event channels, one private channel per site
| (site.{siteUuid}) — AccessEvent::site_id is never nullable, so every
| broadcast always has exactly one site to key on.
|
| Authorization reuses AUTH-009's existing scope model rather than
| inventing a second one: PermissionService::can() with a SITE-level
| ScopeContext is exactly the same check every other site-scoped
| permission in this app already goes through. A GLOBAL monitor.view
| grant covers every site; a whole-site grant covers that one site; a
| grant scoped only to a zone within the site does NOT cover the site's
| channel (consistent with EffectiveScope::covers()'s existing SITE-level
| semantics — a zone assignment doesn't imply the whole site is in scope).
| An unknown siteUuid is always denied (nothing to be in scope of).
*/
/*
| Personal notification push (unknown-person / denied alerts): each user may only
| listen on their own channel, keyed by their public uuid.
*/
Broadcast::channel('user.{userUuid}', function (User $user, string $userUuid) {
    return $user->uuid === $userUuid && ! $user->must_change_password && ! $user->requiresMfaEnrollment();
});

Broadcast::channel('site.{siteUuid}', function (User $user, string $siteUuid) {
    $site = Site::where('uuid', $siteUuid)->first();

    // V1 audit A-04: no realtime access before a forced password change;
    // nor before a required MFA enrollment (decision 2026-10-14).
    if ($site === null || $user->must_change_password || $user->requiresMfaEnrollment()) {
        return false;
    }

    return app(PermissionService::class)->can($user, 'monitor.view', ScopeContext::site($site->id));
});
