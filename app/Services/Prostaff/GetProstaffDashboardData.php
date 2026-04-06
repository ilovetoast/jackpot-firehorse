<?php

namespace App\Services\Prostaff;

use App\Enums\ApprovalStatus;
use App\Enums\AssetType;
use App\Enums\ThumbnailStatus;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\BrandInvitation;
use App\Models\ProstaffMembership;
use App\Models\ProstaffPeriodStat;
use App\Models\User;
use App\Support\AssetVariant;
use App\Support\DeliveryContext;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Log;

class GetProstaffDashboardData
{
    public function __construct(
        private ResolveProstaffPeriod $resolveProstaffPeriod
    ) {}

    /**
     * @return list<array{
     *     user_id: int,
     *     name: string,
     *     email: string,
     *     target_uploads: int|null,
     *     actual_uploads: int,
     *     completion_percentage: float,
     *     is_on_track: bool,
     *     status: string,
     *     period_type: string,
     *     period_start: string,
     *     period_end: string,
     *     rank: int,
     *     last_upload_at: string|null,
     * }>
     */
    public function managerDashboardRows(Brand $brand): array
    {
        $now = now();

        $memberships = ProstaffMembership::query()
            ->where('brand_id', $brand->id)
            ->where('tenant_id', $brand->tenant_id)
            ->where('status', 'active')
            ->with([
                'user' => static function ($query): void {
                    $query->select(['id', 'first_name', 'last_name', 'email']);
                },
            ])
            ->orderBy('user_id')
            ->get();

        Log::info('prostaff.dashboard.memberships', [
            'brand_id' => $brand->id,
            'tenant_id' => $brand->tenant_id,
            'active_membership_count' => $memberships->count(),
        ]);

        [$periodMetaByMembershipId, $statsByMembershipId] = $this->loadCurrentPeriodStatsForMemberships($memberships, $now);

        $rows = [];
        foreach ($memberships as $membership) {
            $user = $membership->user;
            if ($user === null) {
                continue;
            }

            $meta = $periodMetaByMembershipId[$membership->id]
                ?? $this->resolvePeriodMetaForMembership($membership, $now);

            $stat = $statsByMembershipId->get($membership->id);
            $rows[] = $this->buildMembershipPayload(
                $membership,
                $user,
                $meta['period_type'],
                $meta['bounds'],
                $stat
            );
        }

        $this->attachLastProstaffUploads($brand, $rows);

        Log::info('prostaff.dashboard.rows_built', [
            'brand_id' => $brand->id,
            'row_count' => count($rows),
        ]);

        usort($rows, function (array $a, array $b): int {
            $cmp = $b['completion_percentage'] <=> $a['completion_percentage'];
            if ($cmp !== 0) {
                return $cmp;
            }

            return $a['user_id'] <=> $b['user_id'];
        });

        foreach ($rows as $i => &$row) {
            $row['rank'] = $i + 1;
        }
        unset($row);

        return $rows;
    }

    /**
     * Brand invitations that will assign prostaff on accept (Creator flow).
     *
     * @return list<array{
     *     invitation_id: int,
     *     email: string,
     *     sent_at: string|null,
     *     target_uploads: int|null,
     *     period_type: string,
     *     status: 'pending_invite',
     * }>
     */
    public function pendingCreatorInvitesForBrand(Brand $brand): array
    {
        return $brand->invitations()
            ->whereNull('accepted_at')
            ->orderByDesc('sent_at')
            ->get()
            ->filter(function (BrandInvitation $inv): bool {
                $meta = $inv->metadata ?? [];
                $flag = $meta['assign_prostaff_after_accept'] ?? false;

                return $flag === true || $flag === 1 || $flag === '1';
            })
            ->values()
            ->map(function (BrandInvitation $inv): array {
                $meta = $inv->metadata ?? [];
                $target = $meta['prostaff_target_uploads'] ?? null;

                return [
                    'invitation_id' => (int) $inv->id,
                    'email' => (string) $inv->email,
                    'sent_at' => $inv->sent_at?->toIso8601String(),
                    'target_uploads' => is_numeric($target) ? (int) $target : null,
                    'period_type' => (string) ($meta['prostaff_period_type'] ?? 'month'),
                    'status' => 'pending_invite',
                ];
            })
            ->all();
    }

    /**
     * Single creator row (with rank) from the manager dashboard dataset.
     *
     * @return array<string, mixed>|null
     */
    public function creatorDashboardRowForUser(Brand $brand, int $userId): ?array
    {
        foreach ($this->managerDashboardRows($brand) as $row) {
            if ((int) ($row['user_id'] ?? 0) === $userId) {
                return $row;
            }
        }

        // Fallback: build the row directly (e.g. edge cases where the sorted dashboard list omits a member).
        $membership = ProstaffMembership::query()
            ->where('brand_id', $brand->id)
            ->where('tenant_id', $brand->tenant_id)
            ->where('status', 'active')
            ->where('user_id', $userId)
            ->with([
                'user' => static function ($query): void {
                    $query->select(['id', 'first_name', 'last_name', 'email']);
                },
            ])
            ->first();

        if ($membership === null || $membership->user === null) {
            return null;
        }

        $now = now();
        [$periodMetaByMembershipId, $statsByMembershipId] = $this->loadCurrentPeriodStatsForMemberships(
            new EloquentCollection([$membership]),
            $now
        );

        $meta = $periodMetaByMembershipId[$membership->id]
            ?? $this->resolvePeriodMetaForMembership($membership, $now);
        $stat = $statsByMembershipId->get($membership->id);

        $row = $this->buildMembershipPayload(
            $membership,
            $membership->user,
            $meta['period_type'],
            $meta['bounds'],
            $stat
        );

        $rows = [$row];
        $this->attachLastProstaffUploads($brand, $rows);
        $rows[0]['rank'] = null;

        return $rows[0];
    }

    /**
     * Creator-tagged assets in PENDING approval (waiting on brand manager / approver).
     */
    public function pendingProstaffApprovalCountForUser(Brand $brand, int $prostaffUserId): int
    {
        return (int) Asset::query()
            ->where('tenant_id', $brand->tenant_id)
            ->where('brand_id', $brand->id)
            ->where('type', AssetType::ASSET)
            ->where('submitted_by_prostaff', true)
            ->where('prostaff_user_id', $prostaffUserId)
            ->where('approval_status', ApprovalStatus::PENDING)
            ->whereNull('deleted_at')
            ->count();
    }

    /**
     * Creator pipeline counts for the active brand (self-service dashboard).
     *
     * @return array{awaiting_brand_review: int, rejected: int, approved_published: int}
     */
    public function pipelineCountsForProstaffUser(Brand $brand, int $prostaffUserId): array
    {
        $base = static fn () => Asset::query()
            ->where('tenant_id', $brand->tenant_id)
            ->where('brand_id', $brand->id)
            ->where('type', AssetType::ASSET)
            ->where('submitted_by_prostaff', true)
            ->where('prostaff_user_id', $prostaffUserId)
            ->whereNull('deleted_at');

        return [
            'awaiting_brand_review' => (int) $base()
                ->where('approval_status', ApprovalStatus::PENDING)
                ->count(),
            'rejected' => (int) $base()
                ->where('approval_status', ApprovalStatus::REJECTED)
                ->count(),
            'approved_published' => (int) $base()
                ->where('approval_status', ApprovalStatus::APPROVED)
                ->whereNotNull('published_at')
                ->count(),
        ];
    }

    /**
     * Anonymized peer comparison by upload volume this period (active creators on the same brand only).
     *
     * @return array{cohort_size: int, rank_by_volume: int|null, top_percent: int|null, solo: bool, period_type: string|null}
     */
    public function anonymizedVolumeComparison(Brand $brand, int $userId): array
    {
        $rows = $this->managerDashboardRows($brand);
        $periodType = null;
        foreach ($rows as $row) {
            if ((int) ($row['user_id'] ?? 0) === $userId) {
                $periodType = $row['period_type'] ?? null;
                break;
            }
        }

        $sorted = $rows;
        usort($sorted, function (array $a, array $b): int {
            $c = ($b['actual_uploads'] ?? 0) <=> ($a['actual_uploads'] ?? 0);
            if ($c !== 0) {
                return $c;
            }

            return ($a['user_id'] ?? 0) <=> ($b['user_id'] ?? 0);
        });

        $n = count($sorted);
        $rank = null;
        foreach ($sorted as $i => $row) {
            if ((int) ($row['user_id'] ?? 0) === $userId) {
                $rank = $i + 1;
                break;
            }
        }

        if ($rank === null) {
            return [
                'cohort_size' => 0,
                'rank_by_volume' => null,
                'top_percent' => null,
                'solo' => true,
                'period_type' => $periodType,
            ];
        }

        if ($n <= 1) {
            return [
                'cohort_size' => $n,
                'rank_by_volume' => 1,
                'top_percent' => null,
                'solo' => true,
                'period_type' => $periodType,
            ];
        }

        $topPercent = (int) max(1, min(100, (int) ceil(100 * $rank / $n)));

        return [
            'cohort_size' => $n,
            'rank_by_volume' => $rank,
            'top_percent' => $topPercent,
            'solo' => false,
            'period_type' => $periodType,
        ];
    }

    public function rejectedProstaffUploadsForUser(Brand $brand, int $prostaffUserId): array
    {
        return Asset::query()
            ->where('tenant_id', $brand->tenant_id)
            ->where('brand_id', $brand->id)
            ->where('submitted_by_prostaff', true)
            ->where('prostaff_user_id', $prostaffUserId)
            ->where('approval_status', ApprovalStatus::REJECTED)
            ->orderByDesc('rejected_at')
            ->limit(100)
            ->get(['id', 'title', 'rejection_reason', 'rejected_at'])
            ->map(static fn (Asset $a): array => [
                'id' => (string) $a->id,
                'title' => (string) ($a->title ?? ''),
                'rejection_reason' => $a->rejection_reason !== null && $a->rejection_reason !== ''
                    ? (string) $a->rejection_reason
                    : null,
                'rejected_at' => $a->rejected_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Latest creator-tagged upload per user (any time — engagement signal for managers).
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function attachLastProstaffUploads(Brand $brand, array &$rows): void
    {
        if ($rows === []) {
            return;
        }

        $userIds = array_values(array_unique(array_map(static fn (array $r): int => (int) ($r['user_id'] ?? 0), $rows)));
        $userIds = array_values(array_filter($userIds, static fn (int $id): bool => $id > 0));

        if ($userIds === []) {
            return;
        }

        $aggregates = Asset::query()
            ->where('tenant_id', $brand->tenant_id)
            ->where('brand_id', $brand->id)
            ->where('submitted_by_prostaff', true)
            ->whereIn('prostaff_user_id', $userIds)
            ->selectRaw('prostaff_user_id, MAX(created_at) as last_upload')
            ->groupBy('prostaff_user_id')
            ->get();

        $byUser = [];
        foreach ($aggregates as $agg) {
            $uid = (int) $agg->prostaff_user_id;
            $raw = $agg->last_upload;
            if ($raw === null) {
                continue;
            }
            $byUser[$uid] = Carbon::parse($raw instanceof Carbon ? $raw : (string) $raw)->utc()->toIso8601String();
        }

        foreach ($rows as $i => $row) {
            $uid = (int) ($row['user_id'] ?? 0);
            $rows[$i]['last_upload_at'] = $byUser[$uid] ?? null;
        }
    }

    /**
     * @return array{
     *     target_uploads: int|null,
     *     actual_uploads: int,
     *     completion_percentage: float,
     *     is_on_track: bool,
     *     status: string,
     *     period_type: string,
     *     period_start: string,
     *     period_end: string,
     *     uploads_remaining: int|null,
     *     uploads: list<array{asset_id: string, status: string, created_at: string}>,
     *     pending_assets: list<array{id: string, title: string, thumbnail_url: string|null, uploaded_at: string|null}>,
     *     rejected_assets: list<array{id: string, title: string, thumbnail_url: string|null, uploaded_at: string|null, rejection_reason: string|null}>,
     *     approved_assets: list<array{id: string, title: string, thumbnail_url: string|null, uploaded_at: string|null, published_at: string|null}>,
     *     creator_rank_percentile: int|null,
     *     creator_rank_position: int|null,
     *     total_creators: int,
     * }
     */
    public function currentUserDashboard(User $user, Brand $brand): array
    {
        $membership = $user->activeProstaffMembership($brand);
        if ($membership === null) {
            abort(403, 'Not an active prostaff member for this brand.');
        }

        $membership->loadMissing([
            'user' => static function ($query): void {
                $query->select(['id', 'first_name', 'last_name', 'email']);
            },
        ]);

        $now = now();
        [$periodMetaByMembershipId, $statsByMembershipId] = $this->loadCurrentPeriodStatsForMemberships(
            new EloquentCollection([$membership]),
            $now
        );

        $meta = $periodMetaByMembershipId[$membership->id]
            ?? $this->resolvePeriodMetaForMembership($membership, $now);
        $stat = $statsByMembershipId->get($membership->id);

        $row = $this->buildMembershipPayload(
            $membership,
            $user,
            $meta['period_type'],
            $meta['bounds'],
            $stat
        );

        $uploads = Asset::query()
            ->where('tenant_id', $brand->tenant_id)
            ->where('brand_id', $brand->id)
            ->where('submitted_by_prostaff', true)
            ->where('prostaff_user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['id', 'approval_status', 'created_at']);

        $uploadPayload = [];
        foreach ($uploads as $asset) {
            $uploadPayload[] = [
                'asset_id' => (string) $asset->id,
                'status' => $asset->approval_status instanceof \BackedEnum
                    ? $asset->approval_status->value
                    : (string) $asset->approval_status,
                'created_at' => $asset->created_at?->toIso8601String() ?? '',
            ];
        }

        $target = $row['target_uploads'];
        $actual = (int) $row['actual_uploads'];
        $uploadsRemaining = ($target !== null && (int) $target > 0)
            ? max(0, (int) $target - $actual)
            : null;

        $comparison = $this->anonymizedVolumeComparison($brand, (int) $user->id);
        $totalCreators = (int) ($comparison['cohort_size'] ?? 0);
        $rankPosition = $comparison['solo'] ? null : ($comparison['rank_by_volume'] ?? null);
        $rankPercentile = $comparison['solo'] ? null : ($comparison['top_percent'] ?? null);
        if ($totalCreators <= 1) {
            $rankPercentile = null;
            $rankPosition = null;
        }

        $pendingLimit = 10;
        $rejectedLimit = 10;
        $approvedLimit = 5;

        $pendingModels = $this->prostaffAssetQuery($brand, (int) $user->id)
            ->where('approval_status', ApprovalStatus::PENDING)
            ->orderByDesc('created_at')
            ->limit($pendingLimit)
            ->get();

        $rejectedModels = $this->prostaffAssetQuery($brand, (int) $user->id)
            ->where('approval_status', ApprovalStatus::REJECTED)
            ->orderByDesc('rejected_at')
            ->orderByDesc('created_at')
            ->limit($rejectedLimit)
            ->get();

        $approvedModels = $this->prostaffAssetQuery($brand, (int) $user->id)
            ->where('approval_status', ApprovalStatus::APPROVED)
            ->orderByDesc('published_at')
            ->orderByDesc('updated_at')
            ->limit($approvedLimit)
            ->get();

        return [
            'target_uploads' => $row['target_uploads'],
            'actual_uploads' => $row['actual_uploads'],
            'completion_percentage' => $row['completion_percentage'],
            'is_on_track' => $row['is_on_track'],
            'status' => $row['status'],
            'period_type' => $row['period_type'],
            'period_start' => $row['period_start'],
            'period_end' => $row['period_end'],
            'uploads_remaining' => $uploadsRemaining,
            'uploads' => $uploadPayload,
            'pending_assets' => $pendingModels->map(fn (Asset $a) => $this->mapProstaffHomeAsset($a, false))->all(),
            'rejected_assets' => $rejectedModels->map(fn (Asset $a) => $this->mapProstaffHomeAsset($a, true))->all(),
            'approved_assets' => $approvedModels->map(fn (Asset $a) => $this->mapProstaffHomeAsset($a, false, true))->all(),
            'creator_rank_percentile' => $rankPercentile,
            'creator_rank_position' => $rankPosition,
            'total_creators' => $totalCreators,
        ];
    }

    /**
     * Base query: creator-tagged assets for a user on a brand (non-deleted DAM assets).
     */
    private function prostaffAssetQuery(Brand $brand, int $prostaffUserId)
    {
        return Asset::query()
            ->where('tenant_id', $brand->tenant_id)
            ->where('brand_id', $brand->id)
            ->where('type', AssetType::ASSET)
            ->where('submitted_by_prostaff', true)
            ->where('prostaff_user_id', $prostaffUserId)
            ->whereNull('deleted_at');
    }

    /**
     * @return array{id: string, title: string, thumbnail_url: string|null, uploaded_at: string|null, rejection_reason?: string|null, published_at?: string|null}
     */
    private function mapProstaffHomeAsset(Asset $asset, bool $includeRejection, bool $includePublished = false): array
    {
        $out = [
            'id' => (string) $asset->id,
            'title' => $this->assetDisplayTitle($asset),
            'thumbnail_url' => $this->gridThumbnailUrlForAsset($asset),
            'uploaded_at' => $asset->created_at?->toIso8601String(),
        ];
        if ($includeRejection) {
            $reason = $asset->rejection_reason;
            $out['rejection_reason'] = ($reason !== null && $reason !== '') ? (string) $reason : null;
        }
        if ($includePublished) {
            $out['published_at'] = $asset->published_at?->toIso8601String();
        }

        return $out;
    }

    private function assetDisplayTitle(Asset $asset): string
    {
        $title = $asset->title;
        if ($title !== null && $title !== '' && $title !== 'Unknown' && $title !== 'Untitled Asset') {
            return (string) $title;
        }
        if ($asset->original_filename) {
            $base = pathinfo((string) $asset->original_filename, PATHINFO_FILENAME);

            return $base !== '' ? $base : (string) $asset->original_filename;
        }

        return 'Asset';
    }

    private function gridThumbnailUrlForAsset(Asset $asset): ?string
    {
        $metadata = $asset->metadata ?? [];
        $thumbnailStatus = $asset->thumbnail_status instanceof ThumbnailStatus
            ? $asset->thumbnail_status->value
            : (string) ($asset->thumbnail_status ?? 'pending');

        $previewThumbnailUrl = $asset->deliveryUrl(AssetVariant::THUMB_PREVIEW, DeliveryContext::AUTHENTICATED) ?: null;

        $thumbnailsExistInMetadata = ! empty($metadata['thumbnails']) && isset($metadata['thumbnails']['thumb']);
        if ($thumbnailStatus === ThumbnailStatus::COMPLETED->value || $thumbnailsExistInMetadata) {
            $thumbnailStyle = $asset->thumbnailPathForStyle('medium') ? 'medium' : 'thumb';
            $pathExists = $asset->thumbnailPathForStyle($thumbnailStyle) ?? $asset->thumbnailPathForStyle('thumb');
            if ($pathExists) {
                $variant = $thumbnailStyle === 'medium' ? AssetVariant::THUMB_MEDIUM : AssetVariant::THUMB_SMALL;
                $finalThumbnailUrl = $asset->deliveryUrl($variant, DeliveryContext::AUTHENTICATED) ?: null;
                if ($finalThumbnailUrl) {
                    return $finalThumbnailUrl;
                }
            }
        }

        return $previewThumbnailUrl;
    }

    /**
     * Per-membership period types use different calendar starts, so we batch-match stats with one query
     * (OR of (membership_id, period_type, period_start)) instead of N+1 {@see ProstaffPeriodStat::query()} calls.
     *
     * @return array{0: array<int, array{period_type: string, bounds: array{period_start: Carbon, period_end: Carbon}}>, 1: \Illuminate\Support\Collection<int, ProstaffPeriodStat>}
     */
    private function loadCurrentPeriodStatsForMemberships(EloquentCollection $memberships, Carbon $now): array
    {
        if ($memberships->isEmpty()) {
            return [[], collect()];
        }

        $periodMetaByMembershipId = [];
        foreach ($memberships as $membership) {
            $periodMetaByMembershipId[$membership->id] = $this->resolvePeriodMetaForMembership($membership, $now);
        }

        $statsQuery = ProstaffPeriodStat::query()->where(function ($q) use ($periodMetaByMembershipId): void {
            foreach ($periodMetaByMembershipId as $membershipId => $meta) {
                $q->orWhere(function ($q2) use ($membershipId, $meta): void {
                    $q2->where('prostaff_membership_id', $membershipId)
                        ->where('period_type', $meta['period_type'])
                        ->whereDate('period_start', $meta['bounds']['period_start']->toDateString());
                });
            }
        });

        $statsByMembershipId = $statsQuery->get()->keyBy('prostaff_membership_id');

        return [$periodMetaByMembershipId, $statsByMembershipId];
    }

    /**
     * @return array{period_type: string, bounds: array{period_start: Carbon, period_end: Carbon}}
     */
    private function resolvePeriodMetaForMembership(ProstaffMembership $membership, Carbon $now): array
    {
        $periodType = $this->normalizePeriodType($membership);
        $boundsByType = $this->resolveProstaffPeriod->resolve($membership, $now->copy());
        $bounds = $boundsByType[$periodType];

        return [
            'period_type' => $periodType,
            'bounds' => $bounds,
        ];
    }

    private function normalizePeriodType(ProstaffMembership $membership): string
    {
        $periodType = strtolower((string) ($membership->period_type ?? 'month'));
        if (! in_array($periodType, ['month', 'quarter', 'year'], true)) {
            $periodType = 'month';
        }

        return $periodType;
    }

    /**
     * @param  array{period_start: Carbon, period_end: Carbon}  $bounds
     * @return array{
     *     user_id: int,
     *     name: string,
     *     target_uploads: int|null,
     *     actual_uploads: int,
     *     completion_percentage: float,
     *     is_on_track: bool,
     *     status: string,
     *     period_type: string,
     *     period_start: string,
     *     period_end: string,
     * }
     */
    private function buildMembershipPayload(
        ProstaffMembership $membership,
        User $user,
        string $periodType,
        array $bounds,
        ?ProstaffPeriodStat $stat
    ): array {
        $actual = 0;
        $completion = 0.0;
        $isOnTrack = false;

        if ($stat !== null) {
            $actual = (int) $stat->actual_uploads;
            $completion = (float) $stat->completion_percentage;
            $isOnTrack = $stat->isOnTrack();
        }

        $target = $stat?->target_uploads ?? $membership->target_uploads;
        $periodStartStr = $bounds['period_start']->toDateString();

        return [
            'user_id' => (int) $user->id,
            'name' => (string) $user->name,
            'email' => (string) $user->email,
            'target_uploads' => $target !== null ? (int) $target : null,
            'actual_uploads' => $actual,
            'completion_percentage' => round($completion, 2),
            'is_on_track' => $isOnTrack,
            'status' => self::performanceStatusFromCompletion($completion),
            'period_type' => $periodType,
            'period_start' => $periodStartStr,
            'period_end' => $bounds['period_end']->toDateString(),
        ];
    }

    public static function performanceStatusFromCompletion(float $completionPercentage): string
    {
        if ($completionPercentage >= 100.0) {
            return 'complete';
        }
        if ($completionPercentage >= 50.0) {
            return 'on_track';
        }

        return 'behind';
    }
}
