<?php

namespace App\Services\AI;

use App\Exceptions\AIBudgetExceededException;
use App\Models\AIBudget;
use App\Services\AIBudgetService;
use Illuminate\Support\Facades\Log;

/**
 * Optional monthly USD caps for Studio-related {@see AIBudget} task rows.
 *
 * When {@see AIBudget::isHardLimitEnabled} is false, overage is allowed (monitoring only).
 */
final class StudioTaskBudgetGate
{
    public function __construct(
        protected AIBudgetService $budgetService,
    ) {}

    /**
     * @throws AIBudgetExceededException When hard cap would be exceeded
     */
    public function assertTaskAllowsEstimatedSpend(string $taskType, float $estimatedUsd, ?string $environment = null): void
    {
        $budget = $this->budgetService->getTaskBudget($taskType, $environment);
        if (! $budget instanceof AIBudget) {
            return;
        }

        $environment = $environment ?? app()->environment();

        if (! $budget->isHardLimitEnabled($environment)) {
            $this->logSoftCapIfOver($budget, $estimatedUsd, $environment);

            return;
        }

        $this->budgetService->checkBudget($budget, $estimatedUsd, $environment);
    }

    private function logSoftCapIfOver(AIBudget $budget, float $estimatedUsd, string $environment): void
    {
        $cap = $budget->getEffectiveAmount($environment);
        if ($cap <= 0) {
            return;
        }
        $current = $budget->getCurrentUsage($environment);
        if ($current + $estimatedUsd <= $cap) {
            return;
        }
        Log::info('[StudioTaskBudgetGate] Task monthly USD soft cap exceeded (execution allowed)', [
            'budget_id' => $budget->id,
            'task_type' => $budget->scope_key,
            'cap_usd' => $cap,
            'current_usd' => $current,
            'estimated_usd' => $estimatedUsd,
        ]);
    }
}
