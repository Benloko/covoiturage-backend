<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $limit = (int) ($validated['limit'] ?? 50);

        $notifications = UserNotification::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $unreadCount = UserNotification::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->count();

        return response()->json([
            'data' => $notifications->map(fn (UserNotification $notification): array => $this->serializeNotification($notification))->all(),
            'meta' => [
                'total' => $notifications->count(),
                'unread_count' => $unreadCount,
            ],
        ]);
    }

    public function markRead(Request $request, UserNotification $notification): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($notification->user_id !== $user->id) {
            return response()->json([
                'message' => 'Cette notification ne vous appartient pas.',
            ], 403);
        }

        if ($notification->read_at === null) {
            $notification->forceFill([
                'read_at' => now(),
            ])->save();
        }

        return response()->json([
            'message' => 'Notification marquee comme lue.',
            'notification' => $this->serializeNotification($notification->fresh()),
            'unread_count' => $this->unreadCount($user),
        ]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $updated = UserNotification::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->update([
                'read_at' => now(),
                'updated_at' => now(),
            ]);

        return response()->json([
            'message' => 'Toutes les notifications sont marquees comme lues.',
            'updated' => $updated,
            'unread_count' => 0,
        ]);
    }

    public function clear(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $deleted = UserNotification::query()
            ->where('user_id', $user->id)
            ->delete();

        return response()->json([
            'message' => 'Notifications supprimees.',
            'deleted' => $deleted,
            'unread_count' => 0,
        ]);
    }

    private function serializeNotification(UserNotification $notification): array
    {
        return [
            'id' => $notification->id,
            'type' => $notification->type,
            'title' => $notification->title,
            'message' => $notification->message,
            'data' => $notification->data,
            'read_at' => $notification->read_at,
            'created_at' => $notification->created_at,
        ];
    }

    private function unreadCount(User $user): int
    {
        return UserNotification::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->count();
    }
}

