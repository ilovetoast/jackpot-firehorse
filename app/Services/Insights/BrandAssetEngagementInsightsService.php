<?php

namespace App\Services\Insights;

use App\Enums\DownloadStatus;
use App\Enums\EventType;
use App\Enums\MetricType;
use App\Models\ActivityEvent;
use App\Models\Asset;
use App\Models\AssetMetric;
use App\Models\Brand;
use App\Models\Download;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Brand-scoped asset engagement for Insights Usage (views, download events, share packages, uploads).
 */
final class BrandAssetEngagementInsightsService
{
    public const MAX_RANGE_DAYS = 366;

    public const TOP_ASSETS_LIMIT = 10;

    public const TOP_UPLOADERS_LIMIT = 8;

    /**
     * @return array{
     *   totals: array{views: int, download_events: int, download_packages: int, uploads_finalized: int},
     *   top_assets: list<array{asset_id: string, title: string, thumbnail_url: string|null, asset_url: string, views: int, download_events: int, engagement: int}>,
     *   top_uploaders: list<array{user_id: int, name: string, uploads: int}>
     * }
     */
    public function summarize(Tenant $tenant, Brand $brand, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $tenantId = $tenant->id;
        $brandId = $brand->id;

        $views = (int) AssetMetric::query()
            ->where('tenant_id', $tenantId)
            ->where('brand_id', $brandId)
            ->where('metric_type', MetricType::VIEW)
            ->whereBetween('created_at', [$start, $end])
            ->count();

        $downloadEvents = (int) AssetMetric::query()
            ->where('tenant_id', $tenantId)
            ->where('brand_id', $brandId)
            ->where('metric_type', MetricType::DOWNLOAD)
            ->whereBetween('created_at', [$start, $end])
            ->count();

        $downloadPackages = (int) Download::query()
            ->where('tenant_id', $tenantId)
            ->where('status', DownloadStatus::READY)
            ->whereBetween('created_at', [$start, $end])
            ->where(function ($q) use ($brandId) {
                $q->where('brand_id', $brandId)
                    ->orWhereHas('assets', fn ($a) => $a->where('brand_id', $brandId));
            })
            ->count();

        $uploadsFinalized = (int) ActivityEvent::query()
            ->where('tenant_id', $tenantId)
            ->where(function ($q) use ($brandId) {
                $q->where('brand_id', $brandId)->orWhereNull('brand_id');
            })
            ->where('event_type', EventType::ASSET_UPLOAD_FINALIZED)
            ->where('actor_type', 'user')
            ->whereNotNull('actor_id')
            ->whereBetween('created_at', [$start, $end])
            ->count();

        $topAssets = $this->topAssets($tenantId, $brandId, $start, $end);
        $topUploaders = $this->topUploaders($tenantId, $brandId, $start, $end);

        return [
            'totals' => [
                'views' => $views,
                'download_events' => $downloadEvents,
                'download_packages' => $downloadPackages,
                'uploads_finalized' => $uploadsFinalized,
            ],
            'top_assets' => $topAssets,
            'top_uploaders' => $topUploaders,
        ];
    }

    /**
     * @return list<array{asset_id: string, title: string, thumbnail_url: string|null, asset_url: string, views: int, download_events: int, engagement: int}>
     */
    private function topAssets(int $tenantId, int $brandId, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = AssetMetric::query()
            ->where('tenant_id', $tenantId)
            ->where('brand_id', $brandId)
            ->whereBetween('created_at', [$start, $end])
            ->select('asset_id')
            ->selectRaw(
                'SUM(CASE WHEN metric_type = ? THEN 1 ELSE 0 END) as views_count',
                [MetricType::VIEW->value]
            )
            ->selectRaw(
                'SUM(CASE WHEN metric_type = ? THEN 1 ELSE 0 END) as downloads_count',
                [MetricType::DOWNLOAD->value]
            )
            ->groupBy('asset_id')
            ->orderByDesc(DB::raw('views_count + downloads_count'))
            ->limit(self::TOP_ASSETS_LIMIT)
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $assetIds = $rows->pluck('asset_id')->filter()->unique()->values()->all();
        $assets = Asset::query()
            ->where('tenant_id', $tenantId)
            ->where('brand_id', $brandId)
            ->whereIn('id', $assetIds)
            ->whereNull('deleted_at')
            ->get(['id', 'title', 'original_filename', 'type', 'mime_type', 'metadata', 'storage_root_path', 'storage_bucket_id'])
            ->keyBy('id');

        $out = [];
        foreach ($rows as $row) {
            $aid = (string) $row->asset_id;
            $asset = $assets->get($aid);
            if ($asset === null) {
                continue;
            }
            $views = (int) $row->views_count;
            $downloads = (int) $row->downloads_count;
            $engagement = $views + $downloads;
            if ($engagement <= 0) {
                continue;
            }
            $title = (string) ($asset->title ?: $asset->original_filename ?: 'Asset');
            $thumb = $asset->hasRenderableApiThumbnail()
                ? url('/app/api/assets/'.$aid.'/thumbnail?style=medium')
                : null;

            $out[] = [
                'asset_id' => $aid,
                'title' => $title,
                'thumbnail_url' => $thumb,
                'asset_url' => route('assets.view', ['asset' => $aid], absolute: false),
                'views' => $views,
                'download_events' => $downloads,
                'engagement' => $engagement,
            ];
        }

        return $out;
    }

    /**
     * @return list<array{user_id: int, name: string, uploads: int}>
     */
    private function topUploaders(int $tenantId, int $brandId, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = ActivityEvent::query()
            ->where('tenant_id', $tenantId)
            ->where(function ($q) use ($brandId) {
                $q->where('brand_id', $brandId)->orWhereNull('brand_id');
            })
            ->where('event_type', EventType::ASSET_UPLOAD_FINALIZED)
            ->where('actor_type', 'user')
            ->whereNotNull('actor_id')
            ->whereBetween('created_at', [$start, $end])
            ->select('actor_id', DB::raw('COUNT(*) as c'))
            ->groupBy('actor_id')
            ->orderByDesc('c')
            ->limit(self::TOP_UPLOADERS_LIMIT)
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $userIds = $rows->pluck('actor_id')->map(fn ($id) => (int) $id)->filter(fn ($id) => $id > 0)->unique()->values()->all();
        $users = User::query()->whereIn('id', $userIds)->get(['id', 'first_name', 'last_name', 'email'])->keyBy('id');

        $out = [];
        foreach ($rows as $row) {
            $uid = (int) $row->actor_id;
            if ($uid <= 0) {
                continue;
            }
            $u = $users->get($uid);
            $name = $u
                ? trim(implode(' ', array_filter([(string) $u->first_name, (string) $u->last_name])))
                : '';
            if ($name === '') {
                $name = $u ? (string) $u->email : 'User #'.$uid;
            }
            $out[] = [
                'user_id' => $uid,
                'name' => $name,
                'uploads' => (int) $row->c,
            ];
        }

        return $out;
    }
}
