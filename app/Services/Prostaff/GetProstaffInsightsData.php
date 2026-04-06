<?php

namespace App\Services\Prostaff;

use App\Enums\ApprovalStatus;
use App\Enums\AssetType;
use App\Enums\MetricType;
use App\Models\Brand;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Aggregated creator (prostaff) metrics for Insights → Overview.
 */
class GetProstaffInsightsData
{
    public function __construct(
        private GetProstaffDashboardData $dashboardData
    ) {}

    /**
     * @return array{
     *     total_uploads: int,
     *     approved_uploads: int,
     *     rejected_uploads: int,
     *     approval_rate: float,
     *     avg_downloads_per_asset: float,
     *     avg_rating: float|null,
     *     top_creator: array{user_id: int, name: string, completion_percentage: float}|null,
     *     most_active_creator: array{user_id: int, name: string, upload_count: int}|null,
     *     most_downloaded_asset: array{asset_id: string, title: string, download_count: int, prostaff_user_id: int|null}|null,
     *     highest_rated_asset: array{asset_id: string, title: string, rating: float, prostaff_user_id: int|null}|null,
     *     creators_behind: int,
     *     creators_on_track_percentage: float,
     *     has_activity: bool,
     * }
     */
    public function forBrand(Brand $brand): array
    {
        $tenantId = (int) $brand->tenant_id;
        $brandId = (int) $brand->id;

        $rows = $this->dashboardData->managerDashboardRows($brand);
        $totalCreators = count($rows);
        $creatorsBehind = count(array_filter($rows, static fn (array $r): bool => ($r['status'] ?? '') === 'behind'));
        $onTrackOrComplete = count(array_filter(
            $rows,
            static fn (array $r): bool => in_array($r['status'] ?? '', ['on_track', 'complete'], true)
        ));
        $creatorsOnTrackPct = $totalCreators > 0
            ? round(100 * $onTrackOrComplete / $totalCreators, 2)
            : 0.0;

        $counts = DB::table('assets')
            ->where('tenant_id', $tenantId)
            ->where('brand_id', $brandId)
            ->where('type', AssetType::ASSET->value)
            ->where('submitted_by_prostaff', true)
            ->whereNotNull('prostaff_user_id')
            ->whereNull('deleted_at')
            ->selectRaw(
                'COUNT(*) as total_uploads, '.
                'SUM(CASE WHEN approval_status = ? THEN 1 ELSE 0 END) as approved_uploads, '.
                'SUM(CASE WHEN approval_status = ? THEN 1 ELSE 0 END) as rejected_uploads',
                [ApprovalStatus::APPROVED->value, ApprovalStatus::REJECTED->value]
            )
            ->first();

        $totalUploads = (int) ($counts->total_uploads ?? 0);
        $approvedUploads = (int) ($counts->approved_uploads ?? 0);
        $rejectedUploads = (int) ($counts->rejected_uploads ?? 0);
        $decided = $approvedUploads + $rejectedUploads;
        $approvalRate = $decided > 0 ? round($approvedUploads / $decided, 4) : 0.0;

        $downloadAgg = DB::table('asset_metrics as am')
            ->join('assets as a', 'am.asset_id', '=', 'a.id')
            ->where('am.tenant_id', $tenantId)
            ->where('am.brand_id', $brandId)
            ->where('am.metric_type', MetricType::DOWNLOAD->value)
            ->where('a.type', AssetType::ASSET->value)
            ->where('a.submitted_by_prostaff', true)
            ->whereNotNull('a.prostaff_user_id')
            ->whereNull('a.deleted_at')
            ->selectRaw('COUNT(am.id) as download_events')
            ->first();

        $downloadEvents = (int) ($downloadAgg->download_events ?? 0);
        $avgDownloadsPerAsset = $totalUploads > 0
            ? round($downloadEvents / $totalUploads, 4)
            : 0.0;

        $ratingAgg = DB::table('brand_intelligence_scores as bis')
            ->join('assets as a', 'bis.asset_id', '=', 'a.id')
            ->whereNull('bis.execution_id')
            ->where('bis.brand_id', $brandId)
            ->where('a.tenant_id', $tenantId)
            ->where('a.brand_id', $brandId)
            ->where('a.type', AssetType::ASSET->value)
            ->where('a.submitted_by_prostaff', true)
            ->whereNotNull('a.prostaff_user_id')
            ->whereNull('a.deleted_at')
            ->selectRaw('AVG(bis.overall_score) as avg_rating, COUNT(DISTINCT bis.asset_id) as scored_assets')
            ->first();

        $avgRating = null;
        if ($ratingAgg && (float) ($ratingAgg->avg_rating ?? 0) > 0 && (int) ($ratingAgg->scored_assets ?? 0) > 0) {
            $avgRating = round((float) $ratingAgg->avg_rating, 2);
        }

        $topCreator = $this->queryTopCreatorByMaxCompletion($brandId);

        $mostActive = $this->queryMostActiveCreator($tenantId, $brandId);

        $mostDownloaded = $this->queryMostDownloadedAsset($tenantId, $brandId);

        $highestRated = $this->queryHighestRatedAsset($tenantId, $brandId);

        // Surface empty state only when no enrolled creators and no prostaff assets.
        $hasActivity = ! ($totalUploads === 0 && $totalCreators === 0);

        return [
            'total_uploads' => $totalUploads,
            'approved_uploads' => $approvedUploads,
            'rejected_uploads' => $rejectedUploads,
            'approval_rate' => $approvalRate,
            'avg_downloads_per_asset' => $avgDownloadsPerAsset,
            'avg_rating' => $avgRating,
            'top_creator' => $topCreator,
            'most_active_creator' => $mostActive,
            'most_downloaded_asset' => $mostDownloaded,
            'highest_rated_asset' => $highestRated,
            'creators_behind' => $creatorsBehind,
            'creators_on_track_percentage' => $creatorsOnTrackPct,
            'has_activity' => $hasActivity,
        ];
    }

    /**
     * @return array{user_id: int, name: string, completion_percentage: float}|null
     */
    private function queryTopCreatorByMaxCompletion(int $brandId): ?array
    {
        $row = DB::table('prostaff_period_stats as pps')
            ->join('prostaff_memberships as pm', 'pps.prostaff_membership_id', '=', 'pm.id')
            ->join('users as u', 'pm.user_id', '=', 'u.id')
            ->where('pm.brand_id', $brandId)
            ->where('pm.status', 'active')
            ->groupBy('u.id', 'u.first_name', 'u.last_name', 'u.email')
            ->selectRaw('u.id as user_id, MAX(pps.completion_percentage) as max_completion')
            ->orderByDesc('max_completion')
            ->first();

        if ($row === null) {
            return null;
        }

        $user = User::query()->find((int) $row->user_id);
        if ($user === null) {
            return null;
        }

        return [
            'user_id' => (int) $user->id,
            'name' => $user->name,
            'completion_percentage' => round((float) $row->max_completion, 2),
        ];
    }

    /**
     * @return array{user_id: int, name: string, upload_count: int}|null
     */
    private function queryMostActiveCreator(int $tenantId, int $brandId): ?array
    {
        $row = DB::table('assets')
            ->where('tenant_id', $tenantId)
            ->where('brand_id', $brandId)
            ->where('type', AssetType::ASSET->value)
            ->where('submitted_by_prostaff', true)
            ->whereNotNull('prostaff_user_id')
            ->whereNull('deleted_at')
            ->selectRaw('prostaff_user_id, COUNT(*) as upload_count')
            ->groupBy('prostaff_user_id')
            ->orderByDesc('upload_count')
            ->first();

        if ($row === null) {
            return null;
        }

        $user = User::query()->find((int) $row->prostaff_user_id);
        if ($user === null) {
            return null;
        }

        return [
            'user_id' => (int) $user->id,
            'name' => $user->name,
            'upload_count' => (int) $row->upload_count,
        ];
    }

    /**
     * @return array{asset_id: string, title: string, download_count: int, prostaff_user_id: int|null}|null
     */
    private function queryMostDownloadedAsset(int $tenantId, int $brandId): ?array
    {
        $row = DB::table('assets as a')
            ->leftJoin('asset_metrics as am', function ($join) use ($tenantId, $brandId): void {
                $join->on('am.asset_id', '=', 'a.id')
                    ->where('am.tenant_id', '=', $tenantId)
                    ->where('am.brand_id', '=', $brandId)
                    ->where('am.metric_type', '=', MetricType::DOWNLOAD->value);
            })
            ->where('a.tenant_id', $tenantId)
            ->where('a.brand_id', $brandId)
            ->where('a.type', AssetType::ASSET->value)
            ->where('a.submitted_by_prostaff', true)
            ->whereNotNull('a.prostaff_user_id')
            ->whereNull('a.deleted_at')
            ->groupBy('a.id', 'a.title', 'a.prostaff_user_id')
            ->selectRaw('a.id as asset_id, a.title, a.prostaff_user_id, COUNT(am.id) as download_count')
            ->havingRaw('COUNT(am.id) > 0')
            ->orderByDesc('download_count')
            ->first();

        if ($row === null) {
            return null;
        }

        return [
            'asset_id' => (string) $row->asset_id,
            'title' => (string) ($row->title ?? ''),
            'download_count' => (int) $row->download_count,
            'prostaff_user_id' => $row->prostaff_user_id !== null ? (int) $row->prostaff_user_id : null,
        ];
    }

    /**
     * @return array{asset_id: string, title: string, rating: float, prostaff_user_id: int|null}|null
     */
    private function queryHighestRatedAsset(int $tenantId, int $brandId): ?array
    {
        $row = DB::table('brand_intelligence_scores as bis')
            ->join('assets as a', 'bis.asset_id', '=', 'a.id')
            ->whereNull('bis.execution_id')
            ->where('bis.brand_id', $brandId)
            ->where('a.tenant_id', $tenantId)
            ->where('a.brand_id', $brandId)
            ->where('a.type', AssetType::ASSET->value)
            ->where('a.submitted_by_prostaff', true)
            ->whereNotNull('a.prostaff_user_id')
            ->whereNull('a.deleted_at')
            ->whereNotNull('bis.overall_score')
            ->orderByDesc('bis.overall_score')
            ->select('a.id as asset_id', 'a.title', 'a.prostaff_user_id', 'bis.overall_score as rating')
            ->first();

        if ($row === null) {
            return null;
        }

        return [
            'asset_id' => (string) $row->asset_id,
            'title' => (string) ($row->title ?? ''),
            'rating' => round((float) $row->rating, 2),
            'prostaff_user_id' => $row->prostaff_user_id !== null ? (int) $row->prostaff_user_id : null,
        ];
    }
}
