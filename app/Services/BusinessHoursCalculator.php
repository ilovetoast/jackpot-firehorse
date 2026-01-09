<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * BusinessHoursCalculator Service
 *
 * Utility service for calculating business hours and deadlines.
 * Handles timezone conversion and respects support hours configuration.
 *
 * Support hours format:
 * - days: Array of day numbers (1=Monday, 7=Sunday)
 * - start: Start time in HH:MM format
 * - end: End time in HH:MM format
 * - timezone: Timezone identifier
 */
class BusinessHoursCalculator
{
    /**
     * Add business minutes to a start time, respecting business hours.
     * Skips weekends and hours outside business hours.
     *
     * @param \DateTime $start Start time (UTC)
     * @param int $minutes Minutes to add (business hours only)
     * @param array $supportHours Support hours configuration
     * @param string $timezone Tenant timezone
     * @return \DateTime Deadline in UTC
     */
    public function addBusinessMinutes(\DateTime $start, int $minutes, array $supportHours, string $timezone): \DateTime
    {
        $current = Carbon::instance($start)->setTimezone($timezone);
        $days = $supportHours['days'] ?? [1, 2, 3, 4, 5];
        $startTime = $supportHours['start'] ?? '09:00';
        $endTime = $supportHours['end'] ?? '17:00';
        $remainingMinutes = $minutes;

        // Parse start and end times
        [$startHour, $startMinute] = explode(':', $startTime);
        [$endHour, $endMinute] = explode(':', $endTime);
        $startMinutes = (int) $startHour * 60 + (int) $startMinute;
        $endMinutes = (int) $endHour * 60 + (int) $endMinute;

        // If current time is before business hours, move to start of business hours
        $currentMinutes = $current->hour * 60 + $current->minute;
        if ($currentMinutes < $startMinutes) {
            $current->setTime((int) $startHour, (int) $startMinute, 0);
        }

        // If current time is after business hours, move to start of next business day
        if ($currentMinutes >= $endMinutes) {
            $current = $this->getNextBusinessDay($current, $supportHours, $timezone);
        }

        // Ensure we start on a business day
        while (!in_array($current->dayOfWeekIso, $days)) {
            $current = $this->getNextBusinessDay($current, $supportHours, $timezone);
        }

        // Add minutes, skipping non-business hours
        while ($remainingMinutes > 0) {
            $currentMinutes = $current->hour * 60 + $current->minute;
            $minutesUntilEndOfDay = $endMinutes - $currentMinutes;

            if ($remainingMinutes <= $minutesUntilEndOfDay) {
                // Can complete within current business day
                $current->addMinutes($remainingMinutes);
                $remainingMinutes = 0;
            } else {
                // Move to start of next business day
                $remainingMinutes -= $minutesUntilEndOfDay;
                $current = $this->getNextBusinessDay($current, $supportHours, $timezone);
            }
        }

        // Convert back to UTC
        return $current->setTimezone('UTC')->toDateTime();
    }

    /**
     * Check if a time is within business hours.
     *
     * @param \DateTime $time Time to check (UTC)
     * @param array $supportHours Support hours configuration
     * @param string $timezone Tenant timezone
     * @return bool
     */
    public function isWithinBusinessHours(\DateTime $time, array $supportHours, string $timezone): bool
    {
        $checkTime = Carbon::instance($time)->setTimezone($timezone);
        $days = $supportHours['days'] ?? [1, 2, 3, 4, 5];
        $startTime = $supportHours['start'] ?? '09:00';
        $endTime = $supportHours['end'] ?? '17:00';

        // Check if day is a business day
        if (!in_array($checkTime->dayOfWeekIso, $days)) {
            return false;
        }

        // Check if time is within business hours
        [$startHour, $startMinute] = explode(':', $startTime);
        [$endHour, $endMinute] = explode(':', $endTime);

        $checkMinutes = $checkTime->hour * 60 + $checkTime->minute;
        $startMinutes = (int) $startHour * 60 + (int) $startMinute;
        $endMinutes = (int) $endHour * 60 + (int) $endMinute;

        return $checkMinutes >= $startMinutes && $checkMinutes < $endMinutes;
    }

    /**
     * Get the next business day start time.
     *
     * @param \DateTime $time Current time (in tenant timezone)
     * @param array $supportHours Support hours configuration
     * @param string $timezone Tenant timezone
     * @return Carbon Next business day start time (in tenant timezone)
     */
    public function getNextBusinessDay(\DateTime $time, array $supportHours, string $timezone): Carbon
    {
        $current = Carbon::instance($time)->setTimezone($timezone);
        $days = $supportHours['days'] ?? [1, 2, 3, 4, 5];
        $startTime = $supportHours['start'] ?? '09:00';
        [$startHour, $startMinute] = explode(':', $startTime);

        // Move to next day
        $current->addDay();
        $current->setTime((int) $startHour, (int) $startMinute, 0);

        // Find next business day
        while (!in_array($current->dayOfWeekIso, $days)) {
            $current->addDay();
        }

        return $current;
    }
}
