<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Phase AF-3: Notification Controller
 * 
 * Handles in-app notification API endpoints.
 */
class NotificationController extends Controller
{
    /**
     * Get current user's notifications.
     * 
     * GET /api/notifications
     */
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();
        
        if (!$user) {
            return response()->json(['error' => 'Unauthorized.'], 401);
        }

        $notifications = $user->notifications()
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function (Notification $notification) {
                return [
                    'id' => $notification->id,
                    'type' => $notification->type,
                    'data' => $notification->data,
                    'read_at' => $notification->read_at?->toISOString(),
                    'created_at' => $notification->created_at->toISOString(),
                    'is_unread' => $notification->isUnread(),
                ];
            });

        $unreadCount = $user->notifications()
            ->whereNull('read_at')
            ->count();

        return response()->json([
            'notifications' => $notifications,
            'unread_count' => $unreadCount,
        ]);
    }

    /**
     * Mark notification as read.
     * 
     * POST /api/notifications/{notification}/read
     */
    public function markAsRead(Request $request, Notification $notification): JsonResponse
    {
        $user = Auth::user();
        
        if (!$user) {
            return response()->json(['error' => 'Unauthorized.'], 401);
        }

        // Verify notification belongs to user
        if ($notification->user_id !== $user->id) {
            return response()->json(['error' => 'Notification not found.'], 404);
        }

        $notification->markAsRead();

        return response()->json([
            'message' => 'Notification marked as read.',
            'notification' => [
                'id' => $notification->id,
                'read_at' => $notification->read_at->toISOString(),
            ],
        ]);
    }
}
