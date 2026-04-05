<?php

namespace App\Services\Prostaff;

use App\Models\Asset;
use App\Models\Brand;
use App\Models\ProstaffMembership;
use App\Models\ProstaffPeriodStat;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class GetProstaffDashboardData
{
    public function __construct(
        private ResolveProstaffPeriod $resolveProstaffPeriod
    ) {}

    /**
     * @return list<array{
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
     *     rank: int,
     * }>
     */
    public function managerDashboardRows(Brand $brand): array
    {
        $now = now();

        $memberships = ProstaffMembership::query()
            ->where('brand_id', $brand->id)
            ->where('status', 'active')
            ->with([
                'user' => static function ($query): void {
                    $query->select(['id', 'first_name', 'last_name', 'email']);
                },
            ])
            ->orderBy('user_id')
            ->get();

        [$periodMetaByMembershipId, $statsByMembershipId] = $this->loadCurrentPeriodStatsForMemberships($memberships, $now);

        $rows = [];
        foreach ($memberships as $membership) {
            $user = $membership->user;
            if ($user === null) {
                continue;
            }

            $meta = $periodMetaByMembershipId[$membership->id] ?? null;
            if ($meta === null) {
                continue;
            }

            $stat = $statsByMembershipId->get($membership->id);
            $rows[] = $this->buildMembershipPayload(
                $membership,
                $user,
                $meta['period_type'],
                $meta['bounds'],
                $stat
            );
        }

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
     * @return array{
     *     target_uploads: int|null,
     *     actual_uploads: int,
     *     completion_percentage: float,
     *     is_on_track: bool,
     *     status: string,
     *     period_type: string,
     *     period_start: string,
     *     period_end: string,
     *     uploads: list<array{asset_id: string, status: string, created_at: string}>,
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

        return [
            'target_uploads' => $row['target_uploads'],
            'actual_uploads' => $row['actual_uploads'],
            'completion_percentage' => $row['completion_percentage'],
            'is_on_track' => $row['is_on_track'],
            'status' => $row['status'],
            'period_type' => $row['period_type'],
            'period_start' => $row['period_start'],
            'period_end' => $row['period_end'],
            'uploads' => $uploadPayload,
        ];
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
