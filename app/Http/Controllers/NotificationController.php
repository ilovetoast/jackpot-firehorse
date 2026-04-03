<?php

namespace App\Http\Controllers;

use App\Models\Download;
use App\Models\Notification;
use App\Models\User;
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

        $notifications = $raw
            ->map(fn (Notification $n) => $this->formatGrouped($n, $user))
            ->filter(fn (array $n) => $n['type'] !== 'download.ready' || ($n['count'] ?? 0) > 0)
            ->values()
            ->all();

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

        $unreadCount = count(array_filter($notifications, fn (array $n) => ($n['is_unread'] ?? false)));

        return response()->json([
            'notifications' => $notifications,
            'unread_count' => $unreadCount,
        ]);
    }

    protected function formatGrouped(Notification $notification, User $viewer): array
    {
        $items = $notification->meta['items'] ?? [];
        $data = $notification->data ?? [];
        if ($notification->type === 'download.ready') {
            $items = $this->filterDownloadReadyItemsForUser($items, $data, $viewer);
        }
        $brands = $this->computeBrandBreakdown($items, $data);
        $count = $notification->type === 'download.ready'
            ? count($items)
            : (int) ($notification->count ?? 1);

        return [
            'id' => $notification->id,
            'type' => $notification->type,
            'count' => $count,
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

    /**
     * Drop grouped download.ready rows that are not ZIPs created by the viewer (legacy / mis-keyed data).
     */
    protected function filterDownloadReadyItemsForUser(array $items, array $data, User $viewer): array
    {
        $ids = [];
        foreach ($items as $it) {
            if (! empty($it['download_id'])) {
                $ids[] = (string) $it['download_id'];
            }
        }
        $ids = array_values(array_unique($ids));
        if ($ids === [] && ! empty($data['download_id'])) {
            $ids = [(string) $data['download_id']];
        }
        if ($ids === []) {
            return [];
        }
        $ownedIds = Download::query()
            ->whereIn('id', $ids)
            ->where('created_by_user_id', $viewer->id)
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();
        $owned = array_flip($ownedIds);
        $filtered = array_values(array_filter($items, function ($it) use ($owned) {
            $did = isset($it['download_id']) ? (string) $it['download_id'] : '';

            return $did !== '' && isset($owned[$did]);
        }));
        if ($filtered === [] && ! empty($data['download_id'])) {
            $did = (string) $data['download_id'];
            if (isset($owned[$did])) {
                $filtered = [[
                    'brand_id' => $data['brand_id'] ?? null,
                    'brand_name' => $data['brand_name'] ?? null,
                    'download_title' => $data['download_title'] ?? null,
                    'download_id' => $did,
                    'tenant_id' => $data['tenant_id'] ?? null,
                    'tenant_name' => $data['tenant_name'] ?? null,
                    'created_at' => $data['created_at'] ?? now()->toISOString(),
                ]];
            }
        }

        return $filtered;
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
