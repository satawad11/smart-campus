<?php

use App\Models\User;
use App\Services\Auth\PermissionService;
use Illuminate\Support\Facades\Broadcast;

/*
| EVT-013: realtime event channels, one private channel per site
| (site.{siteUuid}) — AccessEvent::site_id is never nullable, so every
| broadcast always has exactly one site to key on.
|
| This authorization is deliberately coarse: it only checks that the
| subscriber currently holds monitor.view at all, not that monitor.view
| covers this specific site. Narrowing to the subscriber's actual
| site/zone scope is EVT-014's job — see docs/09 "Channel authorization
| must enforce scope" and TASKS.md's separate EVT-013/EVT-014 split.
*/
Broadcast::channel('site.{siteUuid}', function (User $user, string $siteUuid) {
    return app(PermissionService::class)->hasPermission($user, 'monitor.view');
});
