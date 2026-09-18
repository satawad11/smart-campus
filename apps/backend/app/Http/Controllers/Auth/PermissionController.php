<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\PermissionResource;
use App\Models\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only permission catalog (AUTH-011). No create/update/delete — permissions
 * are canonical system capabilities seeded from contracts.
 */
class PermissionController extends Controller
{
    private const SORTABLE = ['code', 'module', 'name'];

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'module' => ['sometimes', 'string', 'max:255'],
            'keyword' => ['sometimes', 'string', 'max:255'],
            'sort' => ['sometimes', 'string', 'in:'.implode(',', self::SORTABLE)],
            'order' => ['sometimes', 'string', 'in:asc,desc'],
        ]);

        $sort = $validated['sort'] ?? 'code';
        $order = $validated['order'] ?? 'asc';

        $query = Permission::query();

        if (! empty($validated['module'])) {
            $query->where('module', $validated['module']);
        }

        if (! empty($validated['keyword'])) {
            $keyword = '%'.mb_strtolower($validated['keyword']).'%';
            $query->where(function ($q) use ($keyword) {
                $q->whereRaw('LOWER(code) LIKE ?', [$keyword])
                    ->orWhereRaw('LOWER(name) LIKE ?', [$keyword]);
            });
        }

        $permissions = $query->orderBy($sort, $order)->get();

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => PermissionResource::collection($permissions),
            'meta' => null,
        ]);
    }
}
