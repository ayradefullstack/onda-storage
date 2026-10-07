<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * The header bell's data source. JSON inside the Inertia session, like the
 * upload endpoints — not an API, no tokens.
 *
 * Neither role-owned: an author and an admin both have a bell, and each
 * reads only their own rows (the query is always scoped to
 * `$request->user()`, never to an id from the request).
 *
 * Rows are returned with their stored payload untouched — an i18n key,
 * its parameters and a label source. The bell renders them through
 * vue-i18n in the *reader's* locale; see OeuvreNotification for why the
 * sentence is not rendered at write time.
 */
final class NotificationController extends Controller
{
    /**
     * Enough for the popover without paginating it. The unread count is
     * computed over the whole table, not over this slice, so a reader with
     * sixty unread notifications sees "60" and the newest fifteen.
     */
    private const LIMIT = 15;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'unread_count' => $user->unreadNotifications()->count(),
            'notifications' => $user->notifications()
                ->latest()
                ->limit(self::LIMIT)
                ->get()
                ->map(fn (DatabaseNotification $notification): array => [
                    'id' => $notification->id,
                    'data' => $notification->data,
                    'read_at' => $notification->read_at?->toIso8601String(),
                    'created_at' => $notification->created_at?->toIso8601String(),
                ])
                ->values(),
        ]);
    }

    /**
     * Scoped through the relation, so a notification id belonging to
     * someone else resolves to nothing rather than being marked read.
     */
    public function read(Request $request, string $notification): JsonResponse
    {
        $request->user()->notifications()
            ->whereKey($notification)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return $this->index($request);
    }

    public function readAll(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return $this->index($request);
    }
}
