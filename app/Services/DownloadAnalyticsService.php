<?php

namespace App\Services;

use App\Enums\DownloadAccessMode;
use App\Enums\DownloadSource;
use App\Models\ActivityEvent;
use App\Models\Download;
use App\Models\User;
use Carbon\Carbon;
/**
 * D9 — Download Analytics (Internal)
 *
 * Read-only aggregation from existing data: activity_events (download.access.granted),
 * downloads, download_asset pivot. No schema changes. Admin/Owner/Manager only.
 */
class DownloadAnalyticsService
{
    public const ACCESS_GRANTED_EVENT = 'download.access.granted';

    public const LANDING_PAGE_VIEWED_EVENT = 'download.landing.page.viewed';

    /**
     * Summary stats for a download: total downloads, unique users (null for public), first/last, source breakdown, landing page views.
     */
    public function summaryForDownload(Download $download): array
    {
        $events = ActivityEvent::query()
            ->where('subject_type', $download->getMorphClass())
            ->where('subject_id', (string) $download->id)
            ->where('event_type', self::ACCESS_GRANTED_EVENT)
            ->get();

        $totalDownloads = $events->count();
        $uniqueUsers = null;
        if ($download->access_mode !== null && $download->access_mode !== DownloadAccessMode::PUBLIC) {
            $userIds = $events->where('actor_type', User::class)
                ->pluck('actor_id')
                ->filter()
                ->unique();
            $uniqueUsers = $userIds->count();
        }

        $firstDownloadedAt = $events->min('created_at');
        $lastDownloadedAt = $events->max('created_at');

        $sourceBreakdown = [
            'zip' => 0,
            'single_asset' => 0,
        ];
        foreach ($events as $e) {
            $context = $e->metadata['context'] ?? null;
            if ($context === 'single_asset') {
                $sourceBreakdown['single_asset']++;
            } else {
                $sourceBreakdown['zip']++;
            }
        }
        if ($totalDownloads > 0 && $sourceBreakdown['zip'] === 0 && $sourceBreakdown['single_asset'] === 0) {
            $sourceBreakdown[$download->source === DownloadSource::SINGLE_ASSET ? 'single_asset' : 'zip'] = $totalDownloads;
        }

        $landingPageViews = ActivityEvent::query()
            ->where('subject_type', $download->getMorphClass())
            ->where('subject_id', (string) $download->id)
            ->where('event_type', self::LANDING_PAGE_VIEWED_EVENT)
            ->count();

        return [
            'total_downloads' => $totalDownloads,
            'landing_page_views' => $landingPageViews,
            'unique_users' => $uniqueUsers,
            'first_downloaded_at' => $firstDownloadedAt ? Carbon::parse($firstDownloadedAt) : null,
            'last_downloaded_at' => $lastDownloadedAt ? Carbon::parse($lastDownloadedAt) : null,
            'source_breakdown' => $sourceBreakdown,
        ];
    }

    /**
     * Recent activity for a download (max 10): at, user or "External user", ip_hash, user_agent, event.
     */
    public function recentActivityForDownload(Download $download, int $limit = 10): array
    {
        $isPublic = $download->access_mode === null || $download->access_mode === DownloadAccessMode::PUBLIC;

        $events = ActivityEvent::query()
            ->where('subject_type', $download->getMorphClass())
            ->where('subject_id', (string) $download->id)
            ->where('event_type', self::ACCESS_GRANTED_EVENT)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        $out = [];
        foreach ($events as $e) {
            $user = null;
            if (! $isPublic && ($e->actor_type === User::class || $e->actor_type === 'user') && $e->actor_id) {
                $u = User::find($e->actor_id);
                $user = $u ? [
                    'id' => $u->id,
                    'name' => $u->name ?? $u->email,
                    'avatar_url' => $u->avatar_url ?? null,
                ] : null;
            }
            if ($user === null) {
                $user = ['id' => null, 'name' => 'External user', 'avatar_url' => null];
            }

            $ipHash = $e->metadata['ip_hash'] ?? hash('sha256', ($e->ip_address ?? '') . config('app.key'));
            $out[] = [
                'at' => Carbon::parse($e->created_at),
                'user' => $user,
                'ip_hash' => $ipHash,
                'user_agent' => $e->user_agent ?? '',
                'event' => 'downloaded',
            ];
        }

        return $out;
    }

    /**
     * Asset breakdown: assets in this download with thumbnail, name, download_count (same for all = total accesses).
     */
    public function assetBreakdownForDownload(Download $download): array
    {
        $summary = $this->summaryForDownload($download);
        $totalDownloads = $summary['total_downloads'];

        $assets = $download->assets()
            ->select('assets.id', 'assets.original_filename', 'assets.metadata', 'assets.thumbnail_status')
            ->get();

        $out = [];
        foreach ($assets as $asset) {
            $thumbnailUrl = null;
            if (($asset->thumbnail_status ?? '') === 'completed') {
                $thumbnailUrl = $asset->deliveryUrl(\App\Support\AssetVariant::THUMB_SMALL, \App\Support\DeliveryContext::AUTHENTICATED) ?: null;
            }
            $out[] = [
                'asset_id' => $asset->id,
                'name' => $asset->original_filename ?? 'Asset',
                'thumbnail_url' => $thumbnailUrl,
                'download_count' => $totalDownloads,
            ];
        }

        usort($out, fn ($a, $b) => $b['download_count'] <=> $a['download_count']);
        return array_slice($out, 0, 10);
    }
}
