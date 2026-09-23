<?php

namespace App\Http\Controllers\Notification;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Personal notification inbox (EVT-012, docs/02-api-contract.md §17).
 * Always scoped to the authenticated user — there is no permission to
 * browse another user's notifications, by design: this is not a shared
 * resource like AccessEvent, it is each recipient's own inbox.
 */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'unread_only' => ['sometimes', 'boolean'],
        ]);

        $query = Notification::query()->where('user_id', $request->user()->id);

        if (! empty($validated['unread_only'])) {
            $query->whereNull('read_at');
        }

        $paginator = $query->orderByDesc('created_at')->paginate((int) ($validated['per_page'] ?? 20));

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => NotificationResource::collection($paginator->items()),
            'meta' => [
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
                'unread_count' => Notification::where('user_id', $request->user()->id)->whereNull('read_at')->count(),
            ],
        ]);
    }

    public function markRead(Request $request, Notification $notification): JsonResponse
    {
        if ($notification->user_id !== $request->user()->id) {
            // A notification uuid that exists but belongs to someone else is
            // indistinguishable from one that doesn't exist at all — never
            // leak that another user's notification exists.
            abort(404);
        }

        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return response()->json([
            'success' => true,
            'message' => 'Notification marked as read.',
            'data' => new NotificationResource($notification),
            'meta' => null,
        ]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $updated = Notification::where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json([
            'success' => true,
            'message' => 'All notifications marked as read.',
            'data' => ['updated_count' => $updated],
            'meta' => null,
        ]);
    }
}
