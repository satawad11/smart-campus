<?php

namespace App\Http\Resources;

use App\Models\Permission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Read-only permission catalog entry (AUTH-011). Addressed publicly by stable
 * `code`; internal numeric ids are never exposed.
 *
 * @mixin Permission
 */
class PermissionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            'module' => $this->module,
            'name' => $this->name,
            'description' => $this->description,
        ];
    }
}
