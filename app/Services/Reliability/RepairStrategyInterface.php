<?php

namespace App\Services\Reliability;

use App\Models\SystemIncident;

/**
 * Strategy for attempting repair of a system incident.
 */
interface RepairStrategyInterface
{
    /**
     * Whether this strategy supports the given incident.
     */
    public function supports(SystemIncident $incident): bool;

    /**
     * Attempt to repair the incident.
     *
     * @return RepairResult
     */
    public function attempt(SystemIncident $incident): RepairResult;
}
