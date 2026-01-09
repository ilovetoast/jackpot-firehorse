<?php

namespace App\Services;

use App\Enums\AITaskType;
use App\Exceptions\AIBudgetExceededException;
use App\Models\AIAgentRun;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AI\Contracts\AIProviderInterface;
use App\Services\AI\Providers\OpenAIProvider;
use Illuminate\Support\Facades\Log;

/**
 * AI Service
 *
 * Centralized entry point for all AI operations in the system.
 * This service orchestrates AI agent execution, permission checks, cost tracking,
 * and provides a provider-agnostic interface for AI operations.
 *
 * Why centralized?
 * - Single point of control for all AI operations
 * - Consistent permission enforcement
 * - Unified cost tracking and attribution
 * - Provider abstraction (switch providers without code changes)
 * - Audit trail for all AI operations
 *
 * Architecture:
 * - Agents are defined in config/ai.php (with optional database overrides later)
 * - Models are defined in config/ai.php registry
 * - Provider abstraction allows switching providers easily
 * - All operations create agent runs for tracking
 *
 * Usage:
 * ```php
 * $aiService = app(AIService::class);
 * $result = $aiService->executeAgent('ticket_analyzer', AITaskType::SUPPORT_TICKET_SUMMARY, 'Analyze this ticket...');
 * ```
 */
class AIService
{
    /**
     * Map of provider names to provider instances.
     */
    protected array $providers = [];

    /**
     * Default provider instance.
     */
    protected ?AIProviderInterface $defaultProvider = null;

    /**
     * AI Config Service for resolving config with overrides.
     */
    protected AIConfigService $configService;

    /**
     * AI Budget Service for budget checks and usage recording.
     */
    protected AIBudgetService $budgetService;

    public function __construct(AIConfigService $configService, AIBudgetService $budgetService)
    {
        $this->configService = $configService;
        $this->budgetService = $budgetService;
        // Initialize providers
        $this->initializeProviders();
    }

    /**
     * Initialize AI providers.
     * Currently only OpenAI, but designed for extensibility.
     */
    protected function initializeProviders(): void
    {
        $defaultProviderName = config('ai.default_provider', 'openai');

        // Register OpenAI provider
        $openAIProvider = new OpenAIProvider();
        $this->providers['openai'] = $openAIProvider;

        // Set default provider
        if (isset($this->providers[$defaultProviderName])) {
            $this->defaultProvider = $this->providers[$defaultProviderName];
        } else {
            // Fallback to first available provider
            $this->defaultProvider = reset($this->providers);
        }
    }

    /**
     * Execute an AI agent with a task.
     *
     * This is the main entry point for all AI operations. It:
     * 1. Validates agent exists and is active
     * 2. Checks permissions based on context
     * 3. Validates tenant boundaries if applicable
     * 4. Creates agent run record
     * 5. Executes the AI task via provider
     * 6. Tracks costs and updates agent run
     * 7. Optionally logs prompts/responses if enabled
     *
     * @param string $agentId Agent identifier from config/ai.php
     * @param string $taskType Task type from AITaskType enum
     * @param string $prompt The prompt to send to the AI
     * @param array $options Additional options:
     *   - model: Override default model
     *   - tenant: Tenant instance (required for tenant-scoped agents)
     *   - user: User instance (optional, for user context)
     *   - triggering_context: Override context ('system', 'tenant', 'user')
     *   - Other provider-specific options (max_tokens, temperature, etc.)
     * @return array Response array:
     *   - text: Generated text response
     *   - agent_run_id: ID of the created agent run
     *   - cost: Estimated cost in USD
     *   - tokens_in: Input tokens used
     *   - tokens_out: Output tokens used
     * @throws \Exception If agent doesn't exist, permissions fail, or API call fails
     */
    public function executeAgent(string $agentId, string $taskType, string $prompt, array $options = []): array
    {
        // Validate task type
        if (!AITaskType::isValid($taskType)) {
            throw new \InvalidArgumentException("Invalid task type: {$taskType}");
        }

        // Determine triggering context and environment
        $triggeringContext = $options['triggering_context'] ?? $this->determineContext($options);
        $environment = $options['environment'] ?? app()->environment();

        // Get agent configuration with overrides
        $agentConfig = $this->getAgentConfig($agentId, $environment);
        if (!$agentConfig) {
            throw new \InvalidArgumentException("Agent '{$agentId}' not found in configuration.");
        }

        // Get tenant and user from options or context
        $tenant = $options['tenant'] ?? null;
        if (!$tenant && isset($options['tenant_id']) && $options['tenant_id']) {
            $tenant = Tenant::find($options['tenant_id']);
        }
        
        $user = $options['user'] ?? null;
        if (!$user && isset($options['user_id']) && $options['user_id']) {
            $user = User::find($options['user_id']);
        }

        // Get system user for system context actions
        $systemUser = null;
        if ($triggeringContext === 'system') {
            $systemUser = User::where('email', 'system@internal')->first();
            if (!$systemUser) {
                Log::warning('System user not found, using user ID 1 as fallback');
                $systemUser = User::find(1);
            }
        }

        // Enforce permissions and tenant boundaries
        $this->enforcePermissions($agentConfig, $triggeringContext, $tenant, $user ?? $systemUser);

        // Validate tenant boundaries for tenant-scoped agents
        if ($agentConfig['scope'] === 'tenant' && !$tenant) {
            throw new \InvalidArgumentException("Tenant-scoped agent '{$agentId}' requires a tenant.");
        }

        // Get model to use
        $modelKey = $options['model'] ?? $agentConfig['default_model'];
        $modelConfig = $this->getModelConfig($modelKey, $environment);
        if (!$modelConfig || !($modelConfig['active'] ?? true)) {
            throw new \InvalidArgumentException("Model '{$modelKey}' is not available or inactive.");
        }

        // Get provider for this model
        $provider = $this->getProviderForModel($modelConfig);
        $actualModelName = $modelConfig['model_name'] ?? $modelKey;

        // Estimate cost for budget checks (rough estimate based on prompt length)
        // This is a conservative estimate - actual cost will be calculated after execution
        $estimatedTokensIn = (int) (strlen($prompt) / 4); // Rough estimate: 4 chars per token
        $estimatedTokensOut = 500; // Conservative estimate for output
        $estimatedCost = $provider->calculateCost($estimatedTokensIn, $estimatedTokensOut, $actualModelName);

        // Get budgets for this execution
        $systemBudget = $this->budgetService->getSystemBudget($environment);
        $agentBudget = $this->budgetService->getAgentBudget($agentId, $environment);
        $taskBudget = $this->budgetService->getTaskBudget($taskType, $environment);

        // Check budgets before execution
        try {
            if ($systemBudget) {
                $this->budgetService->checkBudget($systemBudget, $estimatedCost, $environment);
            }

            if ($agentBudget) {
                $this->budgetService->checkBudget($agentBudget, $estimatedCost, $environment);
            }

            if ($taskBudget) {
                $this->budgetService->checkBudget($taskBudget, $estimatedCost, $environment);
            }
        } catch (AIBudgetExceededException $e) {
            // Create agent run record with blocked status
            $agentRun = AIAgentRun::create([
                'agent_id' => $agentId,
                'triggering_context' => $triggeringContext,
                'environment' => $environment,
                'tenant_id' => $tenant?->id,
                'user_id' => ($user ?? $systemUser)?->id,
                'task_type' => $taskType,
                'model_used' => $actualModelName,
                'tokens_in' => 0,
                'tokens_out' => 0,
                'estimated_cost' => 0,
                'status' => 'failed',
                'blocked_reason' => $e->getMessage(),
                'started_at' => now(),
                'completed_at' => now(),
                'metadata' => $this->buildMetadata($prompt, $options, $agentConfig, $triggeringContext),
            ]);

            Log::warning('AI execution blocked by budget limit', [
                'agent_id' => $agentId,
                'task_type' => $taskType,
                'agent_run_id' => $agentRun->id,
                'estimated_cost' => $estimatedCost,
                'budget_exception' => $e->getMessage(),
            ]);

            throw $e;
        }

        // Create agent run record (started)
        $agentRun = AIAgentRun::create([
            'agent_id' => $agentId,
            'triggering_context' => $triggeringContext,
            'environment' => app()->environment(), // Set environment from APP_ENV
            'tenant_id' => $tenant?->id,
            'user_id' => ($user ?? $systemUser)?->id,
            'task_type' => $taskType,
            'model_used' => $actualModelName,
            'tokens_in' => 0,
            'tokens_out' => 0,
            'estimated_cost' => 0,
            'status' => 'failed', // Will be updated on success
            'started_at' => now(),
            'metadata' => $this->buildMetadata($prompt, $options, $agentConfig, $triggeringContext),
        ]);

        try {
            // Prepare provider options
            $providerOptions = array_merge($options, [
                'model' => $actualModelName,
            ]);

            // Execute AI task via provider
            $response = $provider->generateText($prompt, $providerOptions);

            // Calculate cost
            $cost = $provider->calculateCost(
                $response['tokens_in'],
                $response['tokens_out'],
                $actualModelName
            );

            // Update metadata with response if logging enabled
            $metadata = $agentRun->metadata ?? [];
            if (config('ai.logging.store_prompts', false)) {
                $metadata['prompt'] = $prompt;
                $metadata['response'] = $response['text'];
                $metadata['response_metadata'] = $response['metadata'] ?? [];
            }

            // Mark agent run as successful
            $agentRun->markAsSuccessful(
                $response['tokens_in'],
                $response['tokens_out'],
                $cost,
                $metadata
            );

            // Record usage against budgets
            if ($systemBudget) {
                $this->budgetService->recordUsage($systemBudget, $cost, $environment);
            }
            if ($agentBudget) {
                $this->budgetService->recordUsage($agentBudget, $cost, $environment);
            }
            if ($taskBudget) {
                $this->budgetService->recordUsage($taskBudget, $cost, $environment);
            }

            Log::info('AI agent executed successfully', [
                'agent_id' => $agentId,
                'task_type' => $taskType,
                'agent_run_id' => $agentRun->id,
                'cost' => $cost,
                'tokens' => $response['tokens_in'] + $response['tokens_out'],
            ]);

            return [
                'text' => $response['text'],
                'agent_run_id' => $agentRun->id,
                'cost' => $cost,
                'tokens_in' => $response['tokens_in'],
                'tokens_out' => $response['tokens_out'],
                'model' => $actualModelName,
            ];
        } catch (\Exception $e) {
            // Mark agent run as failed
            $agentRun->markAsFailed($e->getMessage());

            Log::error('AI agent execution failed', [
                'agent_id' => $agentId,
                'task_type' => $taskType,
                'agent_run_id' => $agentRun->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Get agent configuration from config with DB overrides.
     *
     * @param string $agentId Agent identifier
     * @param string|null $environment Environment name (null = current environment)
     * @return array|null Agent configuration or null if not found
     */
    protected function getAgentConfig(string $agentId, ?string $environment = null): ?array
    {
        return $this->configService->getAgentConfig($agentId, $environment);
    }

    /**
     * Get model configuration from config with DB overrides.
     *
     * @param string $modelKey Model key from config
     * @param string|null $environment Environment name (null = current environment)
     * @return array|null Model configuration or null if not found
     */
    protected function getModelConfig(string $modelKey, ?string $environment = null): ?array
    {
        return $this->configService->getModelConfig($modelKey, $environment);
    }

    /**
     * Get provider for a model configuration.
     *
     * @param array $modelConfig Model configuration
     * @return AIProviderInterface Provider instance
     * @throws \Exception If provider is not available
     */
    protected function getProviderForModel(array $modelConfig): AIProviderInterface
    {
        $providerName = $modelConfig['provider'] ?? config('ai.default_provider', 'openai');

        if (!isset($this->providers[$providerName])) {
            throw new \Exception("Provider '{$providerName}' is not available.");
        }

        return $this->providers[$providerName];
    }

    /**
     * Determine triggering context from options.
     *
     * @param array $options Execution options
     * @return string Context ('system', 'tenant', 'user')
     */
    protected function determineContext(array $options): string
    {
        if (isset($options['user']) || isset($options['user_id'])) {
            return 'user';
        }

        if (isset($options['tenant']) || isset($options['tenant_id'])) {
            return 'tenant';
        }

        return 'system';
    }

    /**
     * Build metadata for agent run.
     *
     * @param string $prompt The prompt
     * @param array $options Execution options
     * @param array $agentConfig Agent configuration
     * @param string $triggeringContext Triggering context
     * @return array Metadata array
     */
    protected function buildMetadata(string $prompt, array $options, array $agentConfig, string $triggeringContext): array
    {
        $metadata = [
            'agent_name' => $agentConfig['name'] ?? null,
            'agent_scope' => $agentConfig['scope'] ?? null,
            'triggering_context' => $triggeringContext,
        ];

        // Add prompt if logging enabled (will be overwritten with response later on success)
        if (config('ai.logging.store_prompts', false)) {
            $metadata['prompt'] = $prompt;
        }

        // Add any additional options that aren't provider-specific
        $metadata['options'] = array_diff_key($options, array_flip([
            'model', 'tenant', 'tenant_id', 'user', 'user_id', 'triggering_context',
            'max_tokens', 'temperature', // Provider-specific options
        ]));

        return $metadata;
    }

    /**
     * Enforce permissions for agent execution.
     *
     * Checks agent permissions against the user/context:
     * - System-scoped agents: Require system permissions
     * - Tenant-scoped agents: Require tenant-specific permissions
     * - Uses existing Spatie permission system
     *
     * @param array $agentConfig Agent configuration
     * @param string $triggeringContext Triggering context
     * @param Tenant|null $tenant Tenant instance (if applicable)
     * @param User|null $user User instance (system user for system context)
     * @return void
     * @throws \Exception If permissions check fails
     */
    protected function enforcePermissions(array $agentConfig, string $triggeringContext, ?Tenant $tenant, ?User $user): void
    {
        $permissions = $agentConfig['permissions'] ?? [];

        // No permissions required - allow
        if (empty($permissions)) {
            return;
        }

        // System context: Check permissions on system user
        if ($triggeringContext === 'system') {
            // System context operations bypass permission checks
            // These are automated system actions that should always be allowed
            // The system user is used for attribution/auditing, not authorization
            return;
        }

        // Tenant/User context: Check permissions on user
        if (!$user) {
            throw new \Exception('Tenant or user context requires a user for permission checks.');
        }

        // Validate user belongs to tenant if tenant is provided
        if ($tenant && !$user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            throw new \Exception('User does not belong to the specified tenant.');
        }

        // Check permissions (tenant-scoped permissions will be checked by Spatie)
        foreach ($permissions as $permission) {
            if (!$user->can($permission)) {
                throw new \Exception("Agent '{$agentConfig['name']}' requires permission '{$permission}' which is not granted.");
            }
        }
    }

    /**
     * Get a provider instance by name.
     *
     * @param string $providerName Provider name
     * @return AIProviderInterface|null Provider instance or null if not found
     */
    public function getProvider(string $providerName): ?AIProviderInterface
    {
        return $this->providers[$providerName] ?? null;
    }

    /**
     * Get all available providers.
     *
     * @return array<string, AIProviderInterface> Array of provider name => provider instance
     */
    public function getProviders(): array
    {
        return $this->providers;
    }

    /**
     * Get default provider.
     *
     * @return AIProviderInterface|null Default provider instance
     */
    public function getDefaultProvider(): ?AIProviderInterface
    {
        return $this->defaultProvider;
    }
}
