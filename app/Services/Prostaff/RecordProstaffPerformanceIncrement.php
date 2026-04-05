<?php

namespace App\Services\Prostaff;

use App\Models\Brand;
use App\Models\ProstaffMembership;
use App\Models\ProstaffPeriodStat;
use App\Models\User;
use Carbon\Carbon;

class RecordProstaffPerformanceIncrement
{
    public function __construct(
        private ResolveProstaffPeriod $resolveProstaffPeriod
    ) {}

    /**
     * Increment rolling counters for month, quarter, and year containing {@see $at}.
     * Does not scan assets; does not rewrite historical rows when membership targets change.
     */
    public function record(User $user, Brand $brand, Carbon $at): void
    {
        $membership = ProstaffMembership::query()
            ->where('user_id', $user->id)
            ->where('brand_id', $brand->id)
            ->where('status', 'active')
            ->lockForUpdate()
            ->first();

        if ($membership === null) {
            return;
        }

        $boundsByType = $this->resolveProstaffPeriod->resolve($membership, $at);

        foreach (['month', 'quarter', 'year'] as $periodType) {
            $bounds = $boundsByType[$periodType];
            $this->incrementRow(
                $membership,
                $periodType,
                $bounds['period_start']->toDateString(),
                $bounds['period_end']->toDateString()
            );
        }
    }

    private function incrementRow(
        ProstaffMembership $membership,
        string $periodType,
        string $periodStart,
        string $periodEnd
    ): void {
        $stat = ProstaffPeriodStat::query()
            ->where('prostaff_membership_id', $membership->id)
            ->where('period_type', $periodType)
            ->whereDate('period_start', $periodStart)
            ->lockForUpdate()
            ->first();

        if ($stat === null) {
            $target = $this->targetForNewRow($membership, $periodType);

            $stat = new ProstaffPeriodStat([
                'prostaff_membership_id' => $membership->id,
                'period_type' => $periodType,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'target_uploads' => $target,
                'actual_uploads' => 0,
                'approved_uploads' => 0,
                'rejected_uploads' => 0,
                'completion_percentage' => '0.00',
            ]);
            $stat->save();
        }

        $locked = ProstaffPeriodStat::query()
            ->whereKey($stat->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        $locked->actual_uploads = (int) $locked->actual_uploads + 1;
        $locked->completion_percentage = $locked->completionFromCountsForRow();
        $locked->last_calculated_at = now();
        $locked->save();
    }

    /**
     * Snapshot target only when the row is first created. Changing membership.target_uploads later
     * does not alter existing period rows (Phase 4 rule).
     */
    private function targetForNewRow(ProstaffMembership $membership, string $periodType): ?int
    {
        $configured = $membership->period_type !== null
            ? strtolower((string) $membership->period_type)
            : null;

        if ($configured === null || $configured === $periodType) {
            return $membership->target_uploads !== null ? (int) $membership->target_uploads : null;
        }

        return null;
    }
}
