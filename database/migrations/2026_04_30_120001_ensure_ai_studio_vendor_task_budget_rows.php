<?php

use App\Models\AIBudget;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ensures monthly task-type {@see AIBudget} rows exist for Studio vendor-style APIs so
     * Admin → AI → Budgets can show used vs cap (from {@see \App\Models\AIAgentRun} sums).
     */
    public function up(): void
    {
        if (! Schema::hasTable('ai_budgets')) {
            return;
        }

        $tasks = config('ai.budgets.tasks', []);
        if (! is_array($tasks)) {
            return;
        }

        foreach ($tasks as $scopeKey => $cfg) {
            if (! is_array($cfg) || ! is_array($cfg['monthly'] ?? null)) {
                continue;
            }
            $m = $cfg['monthly'];
            AIBudget::query()->firstOrCreate(
                [
                    'budget_type' => 'task_type',
                    'scope_key' => (string) $scopeKey,
                    'period' => 'monthly',
                    'environment' => null,
                ],
                [
                    'amount' => (float) ($m['amount'] ?? 50000),
                    'warning_threshold_percent' => (int) ($m['warning_threshold_percent'] ?? 80),
                    'hard_limit_enabled' => (bool) ($m['hard_limit_enabled'] ?? false),
                ]
            );
        }
    }

    public function down(): void
    {
        // Intentional no-op: do not delete budget rows that may have overrides.
    }
};
