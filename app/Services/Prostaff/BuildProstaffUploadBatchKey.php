<?php

namespace App\Services\Prostaff;

use Carbon\Carbon;

class BuildProstaffUploadBatchKey
{
    public function __invoke(int $tenantId, int $brandId, int $prostaffUserId, ?Carbon $at = null): string
    {
        $at = $at ?? now();
        $window = max(1, (int) config('prostaff.batch_window_minutes', 5));
        $slot = $this->slotStart($at, $window);

        return sprintf(
            't%d_b%d_u%d_%s',
            $tenantId,
            $brandId,
            $prostaffUserId,
            $slot->copy()->utc()->format('Y-m-d-H:i')
        );
    }

    /**
     * Start of the fixed window containing {@see $at} (UTC date + floored minutes).
     */
    public function slotStart(Carbon $at, int $windowMinutes): Carbon
    {
        $c = $at->copy()->utc();
        $totalMinutes = ($c->hour * 60) + $c->minute;
        $floored = (int) (floor($totalMinutes / $windowMinutes) * $windowMinutes);

        return $c->copy()->startOfDay()->addMinutes($floored);
    }
}
