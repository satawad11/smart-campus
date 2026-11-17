<?php

namespace App\Services\Vehicle;

use App\Enums\AuditAction;
use App\Services\Audit\AuditRequestContext;
use App\Services\Audit\AuditService;
use App\Support\Vehicles\ThaiPlate;
use Illuminate\Http\Request;

/**
 * VEH-013 (ACR-VEH-01, PDPA): records who searched for which licence plate. A plate linked to a
 * person is personal data, and a plate search can reveal where a person's car has been, so every
 * search by plate is written to the audit log (module `vehicle`).
 */
class PlateSearchAuditor
{
    public function __construct(
        private readonly AuditService $audit,
    ) {}

    /**
     * @param  string  $source  where the search ran: events | history | vehicles
     */
    public function record(Request $request, string $source, string $searched): void
    {
        $searched = trim($searched);
        if ($searched === '') {
            return;
        }

        $this->audit->record(
            AuditAction::PLATE_SEARCHED,
            $request->user(),
            'PLATE_SEARCH',
            null,
            newValues: [
                'source' => $source,
                'searched' => mb_substr($searched, 0, 64),
                'plate_number' => ThaiPlate::tryNormalize($searched),
            ],
            context: AuditRequestContext::fromRequest($request),
            module: 'vehicle',
        );
    }
}
