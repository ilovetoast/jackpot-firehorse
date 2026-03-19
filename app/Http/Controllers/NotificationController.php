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
 * Returns grouped format for collapsed/expandable UI.
 */
class NotificationController extends Controller
{
    /** Types that require user action (shown first) */
    protected const ACTIONABLE_TYPES = ['asset.submitted', 'asset.resubmitted'];

    /**
     * Get current user's notifications (grouped).
     *
     * GET /api/notifications
     */
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json(['error' => 'Unauthorized.'], 401);
        }

        $raw = $user->notifications()
            ->orderBy('latest_at', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        $notifications = $raw->map(fn (Notification $n) => $this->formatGrouped($n))->values()->all();

        // Priority: actionable first, then informational
        usort($notifications, function ($a, $b) {
            $aAction = in_array($a['type'], self::ACTIONABLE_TYPES, true);
            $bAction = in_array($b['type'], self::ACTIONABLE_TYPES, true);
            if ($aAction !== $bAction) {
                return $aAction ? -1 : 1;
            }
            $aAt = $a['latest_at'] ?? $a['created_at'] ?? '';
            $bAt = $b['latest_at'] ?? $b['created_at'] ?? '';
            return strcmp($bAt, $aAt);
        });

        $unreadCount = $user->notifications()
            ->whereNull('read_at')
            ->count();

        return response()->json([
            'notifications' => $notifications,
            'unread_count' => $unreadCount,
        ]);
    }

    protected function formatGrouped(Notification $notification): array
    {
        $items = $notification->meta['items'] ?? [];
        $data = $notification->data ?? [];
        $brands = $this->computeBrandBreakdown($items, $data);

        return [
            'id' => $notification->id,
            'type' => $notification->type,
            'count' => (int) ($notification->count ?? 1),
            'latest_at' => ($notification->latest_at ?? $notification->created_at)?->toISOString(),
            'created_at' => $notification->created_at->toISOString(),
            'brands' => $brands,
            'expandable' => count($items) > 1,
            'data' => $data,
            'items' => $items,
            'read_at' => $notification->read_at?->toISOString(),
            'is_unread' => $notification->isUnread(),
        ];
    }

    protected function computeBrandBreakdown(array $items, array $primaryData): array
    {
        $byBrand = [];
        foreach ($items as $item) {
            $name = $item['brand_name'] ?? $primaryData['brand_name'] ?? 'Unknown';
            $byBrand[$name] = ($byBrand[$name] ?? 0) + 1;
        }
        if (empty($byBrand) && !empty($primaryData['brand_name'])) {
            $byBrand[$primaryData['brand_name']] = 1;
        }
        $result = [];
        foreach ($byBrand as $name => $count) {
            $result[] = ['name' => $name, 'count' => $count];
        }
        return $result;
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
