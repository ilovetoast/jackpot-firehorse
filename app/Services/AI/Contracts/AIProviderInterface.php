<?php

namespace App\Services\AI\Contracts;

/**
 * AI Provider Interface
 *
 * Defines the contract for all AI providers in the system.
 * This abstraction allows switching between providers (OpenAI, future providers)
 * without modifying business logic.
 *
 * Why provider abstraction?
 * - Vendor independence: Switch providers without code changes
 * - Consistent interface: All providers implement the same methods
 * - Easy testing: Mock providers for unit tests
 * - Future extensibility: Add new providers by implementing this interface
 *
 * Implementation requirements:
 * - All providers must implement this interface
 * - Provider-specific logic (API calls, authentication) stays in provider
 * - Business logic (cost calculation, tracking) stays in AIService
 */
interface AIProviderInterface
{
    /**
     * Generate text from a prompt.
     *
     * @param string $prompt The input prompt
     * @param array $options Additional options:
     *   - model: Model name to use (overrides default)
     *   - max_tokens: Maximum tokens in response
     *   - temperature: Sampling temperature (0-1)
     *   - other provider-specific options
     * @return array Response array with:
     *   - text: Generated text response
     *   - tokens_in: Number of input tokens used
     *   - tokens_out: Number of output tokens used
     *   - model: Actual model name used
     *   - metadata: Provider-specific metadata
     * @throws \Exception If the API call fails
     */
    public function generateText(string $prompt, array $options = []): array;

    /**
     * Calculate the estimated cost for a given token usage.
     *
     * @param int $tokensIn Number of input tokens
     * @param int $tokensOut Number of output tokens
     * @param string $model Model identifier (e.g., 'gpt-4-turbo')
     * @return float Estimated cost in USD
     */
    public function calculateCost(int $tokensIn, int $tokensOut, string $model): float;

    /**
     * Get the provider name (e.g., 'openai', 'anthropic').
     *
     * @return string Provider identifier
     */
    public function getProviderName(): string;

    /**
     * Check if a model is available/supported by this provider.
     *
     * @param string $model Model identifier
     * @return bool True if the model is available
     */
    public function isModelAvailable(string $model): bool;
}
