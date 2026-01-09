<?php

namespace App\Services;

use App\Models\AIAgentOverride;
use App\Models\AIAutomationOverride;
use App\Models\AIBudget;
use App\Models\AIBudgetOverride;
use App\Models\AIModelOverride;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * AI Config Service
 *
 * Handles reading and writing database-backed AI configuration overrides.
 * Merges config file definitions with database overrides to provide effective configuration.
 *
 * Key Design:
 * - Config files remain the source of truth for base definitions
 * - Database overrides layer on top (merge logic)
 * - Environment-aware override resolution
 * - All override changes are audit-logged
 */
class AIConfigService
{
    /**
     * Get merged model configuration (config + DB override).
     *
     * @param string $modelKey Model key from config/ai.php
     * @param string|null $environment Environment name (null = current environment)
     * @return array|null Merged configuration or null if model not found
     */
    public function getModelConfig(string $modelKey, ?string $environment = null): ?array
    {
        $baseConfig = config("ai.models.{$modelKey}");
        if (!$baseConfig) {
            return null;
        }

        $environment = $environment ?? app()->environment();

        // Get override for this environment (or global if null)
        $override = AIModelOverride::where('model_key', $modelKey)
            ->byEnvironment($environment)
            ->first();

        if (!$override) {
            return $baseConfig;
        }

        return $override->mergeWithConfig($baseConfig);
    }

    /**
     * Get merged agent configuration (config + DB override).
     *
     * @param string $agentId Agent ID from config/ai.php
     * @param string|null $environment Environment name (null = current environment)
     * @return array|null Merged configuration or null if agent not found
     */
    public function getAgentConfig(string $agentId, ?string $environment = null): ?array
    {
        $baseConfig = config("ai.agents.{$agentId}");
        if (!$baseConfig) {
            return null;
        }

        $environment = $environment ?? app()->environment();

        // Get override for this environment (or global if null)
        $override = AIAgentOverride::where('agent_id', $agentId)
            ->byEnvironment($environment)
            ->first();

        if (!$override) {
            return $baseConfig;
        }

        return $override->mergeWithConfig($baseConfig);
    }

    /**
     * Get merged automation configuration (config + DB override).
     *
     * @param string $triggerKey Trigger key from config/automation.php
     * @param string|null $environment Environment name (null = current environment)
     * @return array|null Merged configuration or null if trigger not found
     */
    public function getAutomationConfig(string $triggerKey, ?string $environment = null): ?array
    {
        $baseConfig = config("automation.triggers.{$triggerKey}");
        if (!$baseConfig) {
            return null;
        }

        $environment = $environment ?? app()->environment();

        // Get override for this environment (or global if null)
        $override = AIAutomationOverride::where('trigger_key', $triggerKey)
            ->byEnvironment($environment)
            ->first();

        if (!$override) {
            return $baseConfig;
        }

        return $override->mergeWithConfig($baseConfig);
    }

    /**
     * Update or create a model override.
     *
     * @param string $modelKey Model key
     * @param array $data Override data (active, default_for_tasks, environment)
     * @param User $user User making the change
     * @return AIModelOverride
     */
    public function updateModelOverride(string $modelKey, array $data, User $user): AIModelOverride
    {
        return DB::transaction(function () use ($modelKey, $data, $user) {
            $override = AIModelOverride::firstOrNew([
                'model_key' => $modelKey,
                'environment' => $data['environment'] ?? null,
            ]);

            $override->fill([
                'active' => $data['active'] ?? null,
                'default_for_tasks' => $data['default_for_tasks'] ?? null,
                'environment' => $data['environment'] ?? null,
            ]);

            if (!$override->exists) {
                $override->created_by_user_id = $user->id;
            }
            $override->updated_by_user_id = $user->id;

            $override->save();

            return $override;
        });
    }

    /**
     * Update or create an agent override.
     *
     * @param string $agentId Agent ID
     * @param array $data Override data (active, default_model, environment)
     * @param User $user User making the change
     * @return AIAgentOverride
     */
    public function updateAgentOverride(string $agentId, array $data, User $user): AIAgentOverride
    {
        return DB::transaction(function () use ($agentId, $data, $user) {
            $override = AIAgentOverride::firstOrNew([
                'agent_id' => $agentId,
                'environment' => $data['environment'] ?? null,
            ]);

            $override->fill([
                'active' => $data['active'] ?? null,
                'default_model' => $data['default_model'] ?? null,
                'environment' => $data['environment'] ?? null,
            ]);

            if (!$override->exists) {
                $override->created_by_user_id = $user->id;
            }
            $override->updated_by_user_id = $user->id;

            $override->save();

            return $override;
        });
    }

    /**
     * Update or create an automation override.
     *
     * @param string $triggerKey Trigger key
     * @param array $data Override data (enabled, thresholds, environment)
     * @param User $user User making the change
     * @return AIAutomationOverride
     */
    public function updateAutomationOverride(string $triggerKey, array $data, User $user): AIAutomationOverride
    {
        return DB::transaction(function () use ($triggerKey, $data, $user) {
            $override = AIAutomationOverride::firstOrNew([
                'trigger_key' => $triggerKey,
                'environment' => $data['environment'] ?? null,
            ]);

            $override->fill([
                'enabled' => $data['enabled'] ?? null,
                'thresholds' => $data['thresholds'] ?? null,
                'environment' => $data['environment'] ?? null,
            ]);

            if (!$override->exists) {
                $override->created_by_user_id = $user->id;
            }
            $override->updated_by_user_id = $user->id;

            $override->save();

            return $override;
        });
    }

    /**
     * Get all models with override status.
     *
     * @param string|null $environment Environment name (null = current environment)
     * @return array Array of models with override information
     */
    public function getAllModelsWithOverrides(?string $environment = null): array
    {
        $environment = $environment ?? app()->environment();
        $models = config('ai.models', []);
        $overrides = AIModelOverride::byEnvironment($environment)->get()->keyBy('model_key');

        $result = [];
        foreach ($models as $modelKey => $config) {
            $override = $overrides->get($modelKey);
            $result[] = [
                'key' => $modelKey,
                'config' => $config,
                'override' => $override,
                'effective' => $override ? $override->mergeWithConfig($config) : $config,
                'has_override' => $override !== null,
                'source' => $override ? 'override' : 'config',
            ];
        }

        return $result;
    }

    /**
     * Get all agents with override status.
     *
     * @param string|null $environment Environment name (null = current environment)
     * @return array Array of agents with override information
     */
    public function getAllAgentsWithOverrides(?string $environment = null): array
    {
        $environment = $environment ?? app()->environment();
        $agents = config('ai.agents', []);
        $overrides = AIAgentOverride::byEnvironment($environment)->get()->keyBy('agent_id');

        $result = [];
        foreach ($agents as $agentId => $config) {
            $override = $overrides->get($agentId);
            $result[] = [
                'id' => $agentId,
                'config' => $config,
                'override' => $override,
                'effective' => $override ? $override->mergeWithConfig($config) : $config,
                'has_override' => $override !== null,
                'source' => $override ? 'override' : 'config',
            ];
        }

        return $result;
    }

    /**
     * Get all automations with override status.
     *
     * @param string|null $environment Environment name (null = current environment)
     * @return array Array of automations with override information
     */
    public function getAllAutomationsWithOverrides(?string $environment = null): array
    {
        $environment = $environment ?? app()->environment();
        $triggers = config('automation.triggers', []);
        $overrides = AIAutomationOverride::byEnvironment($environment)->get()->keyBy('trigger_key');

        $result = [];
        foreach ($triggers as $triggerKey => $config) {
            $override = $overrides->get($triggerKey);
            $result[] = [
                'key' => $triggerKey,
                'name' => ucfirst(str_replace('_', ' ', $triggerKey)), // Generate name from key
                'description' => $this->getTriggerDescription($triggerKey),
                'config' => $config,
                'override' => $override,
                'effective' => $override ? $override->mergeWithConfig($config) : $config,
                'has_override' => $override !== null,
                'source' => $override ? 'override' : 'config',
            ];
        }

        return $result;
    }

    /**
     * Get human-readable description for a trigger key.
     */
    protected function getTriggerDescription(string $triggerKey): string
    {
        $descriptions = [
            'ticket_summarization' => 'Automatically summarize ticket conversations when message threshold is reached',
            'ticket_classification' => 'Suggest category, severity, and component for tickets',
            'sla_risk_detection' => 'Analyze tickets for SLA breach risk',
            'error_pattern_detection' => 'Detect error patterns and suggest internal tickets',
            'duplicate_detection' => 'Detect potential duplicate tickets',
        ];

        return $descriptions[$triggerKey] ?? 'Automation trigger';
    }

    /**
     * Get budget configuration (config + DB override).
     *
     * @param string $budgetType Budget type ('system', 'agent', 'task_type')
     * @param string|null $scopeKey Scope key (agent_id or task_type, null for system)
     * @param string|null $environment Environment name (null = current environment)
     * @return array|null Merged configuration or null if budget not found
     */
    public function getBudgetConfig(string $budgetType, ?string $scopeKey = null, ?string $environment = null): ?array
    {
        $configKey = match ($budgetType) {
            'system' => 'ai.budgets.system.monthly',
            'agent' => "ai.budgets.agents.{$scopeKey}.monthly",
            'task_type' => "ai.budgets.tasks.{$scopeKey}.monthly",
            default => null,
        };

        if (!$configKey) {
            return null;
        }

        $baseConfig = config($configKey);
        if (!$baseConfig) {
            return null;
        }

        $environment = $environment ?? app()->environment();

        // Find budget in database
        $budget = AIBudget::where('budget_type', $budgetType)
            ->where('scope_key', $scopeKey)
            ->byEnvironment($environment)
            ->first();

        if (!$budget) {
            return $baseConfig;
        }

        // Get override for this environment
        $override = $budget->overrides()
            ->byEnvironment($environment)
            ->first();

        if (!$override) {
            return $baseConfig;
        }

        return $override->mergeWithConfig($baseConfig);
    }

    /**
     * Update or create a budget override.
     *
     * @param int $budgetId Budget ID
     * @param array $data Override data (amount, warning_threshold_percent, hard_limit_enabled, environment)
     * @param User $user User making the change
     * @return AIBudgetOverride
     */
    public function updateBudgetOverride(int $budgetId, array $data, User $user): AIBudgetOverride
    {
        return DB::transaction(function () use ($budgetId, $data, $user) {
            $override = AIBudgetOverride::firstOrNew([
                'budget_id' => $budgetId,
                'environment' => $data['environment'] ?? null,
            ]);

            $override->fill([
                'amount' => $data['amount'] ?? null,
                'warning_threshold_percent' => $data['warning_threshold_percent'] ?? null,
                'hard_limit_enabled' => $data['hard_limit_enabled'] ?? null,
                'environment' => $data['environment'] ?? null,
            ]);

            if (!$override->exists) {
                $override->created_by_user_id = $user->id;
            }
            $override->updated_by_user_id = $user->id;

            $override->save();

            return $override;
        });
    }

    /**
     * Get all budgets with override status.
     *
     * @param string|null $environment Environment name (null = current environment)
     * @return array Array of budgets with override information
     */
    public function getAllBudgetsWithOverrides(?string $environment = null): array
    {
        $environment = $environment ?? app()->environment();
        $budgets = [];

        // System budget
        $systemConfig = config('ai.budgets.system.monthly');
        if ($systemConfig) {
            $systemBudget = AIBudget::system()->monthly()->byEnvironment($environment)->first();
            $override = $systemBudget?->overrides()->byEnvironment($environment)->first();

            $budgets[] = [
                'id' => $systemBudget?->id,
                'budget_type' => 'system',
                'scope_key' => null,
                'name' => 'System-wide Monthly Budget',
                'config' => $systemConfig,
                'override' => $override ? [
                    'id' => $override->id,
                    'amount' => $override->amount,
                    'warning_threshold_percent' => $override->warning_threshold_percent,
                    'hard_limit_enabled' => $override->hard_limit_enabled,
                    'environment' => $override->environment,
                    'created_by' => $override->createdBy?->name,
                    'updated_by' => $override->updatedBy?->name,
                    'created_at' => $override->created_at,
                    'updated_at' => $override->updated_at,
                ] : null,
                'source' => $systemBudget ? 'database' : 'config',
            ];
        }

        // Agent budgets
        $agentConfigs = config('ai.budgets.agents', []);
        foreach ($agentConfigs as $agentId => $agentConfig) {
            $agentBudget = AIBudget::forAgent($agentId)->monthly()->byEnvironment($environment)->first();
            $override = $agentBudget?->overrides()->byEnvironment($environment)->first();

            $budgets[] = [
                'id' => $agentBudget?->id,
                'budget_type' => 'agent',
                'scope_key' => $agentId,
                'name' => "Agent: {$agentId}",
                'config' => $agentConfig['monthly'] ?? null,
                'override' => $override ? [
                    'id' => $override->id,
                    'amount' => $override->amount,
                    'warning_threshold_percent' => $override->warning_threshold_percent,
                    'hard_limit_enabled' => $override->hard_limit_enabled,
                    'environment' => $override->environment,
                    'created_by' => $override->createdBy?->name,
                    'updated_by' => $override->updatedBy?->name,
                    'created_at' => $override->created_at,
                    'updated_at' => $override->updated_at,
                ] : null,
                'source' => $agentBudget ? 'database' : 'config',
            ];
        }

        // Task budgets
        $taskConfigs = config('ai.budgets.tasks', []);
        foreach ($taskConfigs as $taskType => $taskConfig) {
            $taskBudget = AIBudget::forTask($taskType)->monthly()->byEnvironment($environment)->first();
            $override = $taskBudget?->overrides()->byEnvironment($environment)->first();

            $budgets[] = [
                'id' => $taskBudget?->id,
                'budget_type' => 'task_type',
                'scope_key' => $taskType,
                'name' => "Task Type: {$taskType}",
                'config' => $taskConfig['monthly'] ?? null,
                'override' => $override ? [
                    'id' => $override->id,
                    'amount' => $override->amount,
                    'warning_threshold_percent' => $override->warning_threshold_percent,
                    'hard_limit_enabled' => $override->hard_limit_enabled,
                    'environment' => $override->environment,
                    'created_by' => $override->createdBy?->name,
                    'updated_by' => $override->updatedBy?->name,
                    'created_at' => $override->created_at,
                    'updated_at' => $override->updated_at,
                ] : null,
                'source' => $taskBudget ? 'database' : 'config',
            ];
        }

        return $budgets;
    }
}
