<?php

namespace App\Services\Prostaff;

use App\Models\ProstaffMembership;
use Carbon\Carbon;
use InvalidArgumentException;

class ResolveProstaffPeriod
{
    /**
     * Calendar boundaries for month, quarter, and year containing {@see $date}.
     * {@see ProstaffMembership} is included for future fiscal / brand calendar rules.
     *
     * @return array{
     *     month: array{period_start: Carbon, period_end: Carbon},
     *     quarter: array{period_start: Carbon, period_end: Carbon},
     *     year: array{period_start: Carbon, period_end: Carbon},
     * }
     */
    public function resolve(ProstaffMembership $membership, Carbon $date): array
    {
        return $this->calendarBoundsFor($date);
    }

    /**
     * @return array{
     *     month: array{period_start: Carbon, period_end: Carbon},
     *     quarter: array{period_start: Carbon, period_end: Carbon},
     *     year: array{period_start: Carbon, period_end: Carbon},
     * }
     */
    private function calendarBoundsFor(Carbon $date): array
    {
        return [
            'month' => $this->boundsFor($date, 'month'),
            'quarter' => $this->boundsFor($date, 'quarter'),
            'year' => $this->boundsFor($date, 'year'),
        ];
    }

    /**
     * @return array{period_start: Carbon, period_end: Carbon}
     */
    private function boundsFor(Carbon $date, string $periodType): array
    {
        $periodType = strtolower($periodType);
        $d = $date->copy();

        return match ($periodType) {
            'month' => [
                'period_start' => $d->copy()->startOfMonth()->startOfDay(),
                'period_end' => $d->copy()->endOfMonth()->endOfDay(),
            ],
            'quarter' => [
                'period_start' => $d->copy()->startOfQuarter()->startOfDay(),
                'period_end' => $d->copy()->endOfQuarter()->endOfDay(),
            ],
            'year' => [
                'period_start' => $d->copy()->startOfYear()->startOfDay(),
                'period_end' => $d->copy()->endOfYear()->endOfDay(),
            ],
            default => throw new InvalidArgumentException("Unknown prostaff period_type: {$periodType}"),
        };
    }
}
