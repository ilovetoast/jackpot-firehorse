<?php

use App\Models\AIBudget;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Without an {@see AIBudget} row, {@see AIBudgetService::getSystemBudget} returns null and
     * {@see AIBudgetService::recordUsage} never runs for the system cap — the Budgets UI showed $0 used.
     */
    public function up(): void
    {
        if (! Schema::hasTable('ai_budgets')) {
            return;
        }

        $config = config('ai.budgets.system.monthly');
        if (! is_array($config)) {
            return;
        }

        AIBudget::query()->firstOrCreate(
            [
                'budget_type' => 'system',
                'scope_key' => null,
                'period' => 'monthly',
                'environment' => null,
            ],
            [
                'amount' => (float) ($config['amount'] ?? 1000),
                'warning_threshold_percent' => (int) ($config['warning_threshold_percent'] ?? 80),
                'hard_limit_enabled' => (bool) ($config['hard_limit_enabled'] ?? false),
            ]
        );
    }

    public function down(): void
    {
        // Do not delete: row may have overrides / usage; safe no-op.
    }
};
