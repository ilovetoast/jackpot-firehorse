<?php

namespace App\Services\AI\Providers;

use App\Services\AI\Contracts\AIProviderInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * OpenAI Provider
 *
 * Implements AIProviderInterface for OpenAI API integration.
 * Uses HTTP client (Guzzle) to call OpenAI's API directly.
 *
 * Features:
 * - Handles API authentication via OPENAI_API_KEY env var
 * - Implements cost calculation based on OpenAI's pricing model
 * - Error handling and retry logic
 * - Model availability checking
 *
 * Configuration:
 * - OPENAI_API_KEY: Required environment variable
 * - OPENAI_API_BASE_URL: Optional, defaults to https://api.openai.com/v1
 *
 * Cost calculation:
 * - Costs are calculated per model based on config/ai.php pricing
 * - Falls back to provider-specific pricing if config doesn't specify
 * - Pricing in USD per token
 */
class OpenAIProvider implements AIProviderInterface
{
    /**
     * Base URL for OpenAI API.
     */
    protected string $baseUrl;

    /**
     * API key for OpenAI.
     */
    protected string $apiKey;

    /**
     * Supported OpenAI models.
     */
    protected array $supportedModels = [
        'gpt-4-turbo',
        'gpt-4-turbo-preview',
        'gpt-4',
        'gpt-3.5-turbo',
        'gpt-4o',
        'gpt-4o-mini',
    ];

    /**
     * Provider-specific pricing fallback for vision models.
     */
    protected array $visionModelPricing = [
        'gpt-4o' => ['input' => 0.00001, 'output' => 0.00003],
        'gpt-4o-mini' => ['input' => 0.00000015, 'output' => 0.0000006],
    ];

    /**
     * Provider-specific pricing fallback (if config doesn't specify).
     * Prices in USD per token.
     */
    protected array $modelPricing = [
        'gpt-4-turbo' => ['input' => 0.00001, 'output' => 0.00003],
        'gpt-4-turbo-preview' => ['input' => 0.00001, 'output' => 0.00003],
        'gpt-4' => ['input' => 0.00003, 'output' => 0.00006],
        'gpt-3.5-turbo' => ['input' => 0.0000005, 'output' => 0.0000015],
    ];

    public function __construct()
    {
        $this->baseUrl = env('OPENAI_API_BASE_URL', 'https://api.openai.com/v1');
        $this->apiKey = env('OPENAI_API_KEY');

        if (!$this->apiKey) {
            throw new \RuntimeException('OPENAI_API_KEY environment variable is required for OpenAI provider.');
        }
    }

    /**
     * Generate text from a prompt using OpenAI API.
     *
     * @param string $prompt The input prompt
     * @param array $options Additional options:
     *   - model: Model name to use (default: gpt-3.5-turbo)
     *   - max_tokens: Maximum tokens in response (default: 1000)
     *   - temperature: Sampling temperature 0-2 (default: 0.7)
     * @return array Response array with:
     *   - text: Generated text response
     *   - tokens_in: Number of input tokens used
     *   - tokens_out: Number of output tokens used
     *   - model: Actual model name used
     *   - metadata: Provider-specific metadata
     * @throws \Exception If the API call fails
     */
    public function generateText(string $prompt, array $options = []): array
    {
        $model = $options['model'] ?? 'gpt-3.5-turbo';
        $maxTokens = $options['max_tokens'] ?? 1000;
        $temperature = $options['temperature'] ?? 0.7;

        if (!$this->isModelAvailable($model)) {
            throw new \InvalidArgumentException("Model '{$model}' is not available or supported.");
        }

        try {
            $response = Http::timeout(60)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->post("{$this->baseUrl}/chat/completions", [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'max_tokens' => $maxTokens,
                    'temperature' => $temperature,
                ]);

            if ($response->failed()) {
                $error = $response->json();
                $errorMessage = $error['error']['message'] ?? 'Unknown error from OpenAI API';
                $errorCode = $error['error']['code'] ?? null;
                
                // Detect quota exceeded errors
                if (stripos($errorMessage, 'quota') !== false || 
                    stripos($errorMessage, 'exceeded') !== false ||
                    $errorCode === 'insufficient_quota' ||
                    $response->status() === 429) {
                    throw new \App\Exceptions\AIQuotaExceededException(
                        "OpenAI API quota exceeded: {$errorMessage}",
                        'OpenAI'
                    );
                }
                
                throw new \Exception("OpenAI API error: {$errorMessage}", $response->status());
            }

            $data = $response->json();

            if (!isset($data['choices'][0]['message']['content'])) {
                throw new \Exception('Invalid response format from OpenAI API');
            }

            $usage = $data['usage'] ?? [];
            $tokensIn = $usage['prompt_tokens'] ?? 0;
            $tokensOut = $usage['completion_tokens'] ?? 0;
            $actualModel = $data['model'] ?? $model;

            return [
                'text' => $data['choices'][0]['message']['content'],
                'tokens_in' => $tokensIn,
                'tokens_out' => $tokensOut,
                'model' => $actualModel,
                'metadata' => [
                    'finish_reason' => $data['choices'][0]['finish_reason'] ?? null,
                    'response_id' => $data['id'] ?? null,
                ],
            ];
        } catch (\App\Exceptions\AIQuotaExceededException $e) {
            // Re-throw quota exceptions without logging (they'll be handled upstream)
            throw $e;
        } catch (\Exception $e) {
            Log::error('OpenAI API call failed', [
                'model' => $model,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Calculate the estimated cost for a given token usage.
     *
     * First tries to get pricing from config/ai.php, then falls back to
     * provider-specific pricing.
     *
     * @param int $tokensIn Number of input tokens
     * @param int $tokensOut Number of output tokens
     * @param string $model Model identifier (e.g., 'gpt-4-turbo')
     * @return float Estimated cost in USD
     */
    public function calculateCost(int $tokensIn, int $tokensOut, string $model): float
    {
        // Try to get pricing from config first
        $configModels = config('ai.models', []);
        $pricing = null;

        // Find matching model in config (check both key and model_name)
        foreach ($configModels as $key => $modelConfig) {
            if ($key === $model || ($modelConfig['model_name'] ?? null) === $model) {
                $pricing = $modelConfig['default_cost_per_token'] ?? null;
                break;
            }
        }

        // Fall back to provider-specific pricing
        if (!$pricing) {
            // Normalize model name (e.g., 'gpt-4-turbo-preview' -> 'gpt-4-turbo')
            $normalizedModel = $this->normalizeModelName($model);
            
            // Check vision model pricing first (for gpt-4o models)
            if (isset($this->visionModelPricing[$model])) {
                $pricing = $this->visionModelPricing[$model];
            } else {
                $pricing = $this->modelPricing[$normalizedModel] ?? $this->modelPricing['gpt-3.5-turbo'];
            }
        }

        $inputCost = ($tokensIn * ($pricing['input'] ?? 0));
        $outputCost = ($tokensOut * ($pricing['output'] ?? 0));

        return round($inputCost + $outputCost, 6);
    }

    /**
     * Get the provider name.
     *
     * @return string
     */
    public function getProviderName(): string
    {
        return 'openai';
    }

    /**
     * Check if a model is available/supported by this provider.
     *
     * @param string $model Model identifier
     * @return bool True if the model is available
     */
    public function isModelAvailable(string $model): bool
    {
        // Check if model is in supported models list
        if (in_array($model, $this->supportedModels, true)) {
            return true;
        }

        // Check if model is defined in config
        $configModels = config('ai.models', []);
        foreach ($configModels as $key => $modelConfig) {
            if ($key === $model || ($modelConfig['model_name'] ?? null) === $model) {
                // Also check if model's provider matches this provider
                return ($modelConfig['provider'] ?? null) === 'openai';
            }
        }

        return false;
    }

    /**
     * Normalize model name for pricing lookup.
     * Handles variations like 'gpt-4-turbo-preview' -> 'gpt-4-turbo'.
     *
     * @param string $model Model name
     * @return string Normalized model name
     */
    protected function normalizeModelName(string $model): string
    {
        // Map variations to standard names
        $normalizations = [
            'gpt-4-turbo-preview' => 'gpt-4-turbo',
        ];

        return $normalizations[$model] ?? $model;
    }

    /**
     * Analyze an image with a prompt using OpenAI Vision API.
     *
     * Accepts image as base64 data URL (data:image/webp;base64,...).
     * No presigned S3 URLs are passed to OpenAI; images are fetched internally via IAM.
     *
     * @param string $imageBase64DataUrl Base64 data URL (data:image/webp;base64,{base64})
     * @param string $prompt Text prompt describing what to analyze
     * @param array $options Additional options:
     *   - model: Model name to use (default: gpt-4o-mini)
     *   - max_tokens: Maximum tokens in response (default: 1000)
     *   - response_format: Response format (default: ['type' => 'json_object'])
     * @return array Response array with:
     *   - text: Generated text response (typically JSON string)
     *   - tokens_in: Number of input tokens used
     *   - tokens_out: Number of output tokens used
     *   - model: Actual model name used
     *   - metadata: Provider-specific metadata
     * @throws \Exception If the API call fails
     */
    public function analyzeImage(string $imageBase64DataUrl, string $prompt, array $options = []): array
    {
        $model = $options['model'] ?? 'gpt-4o-mini';
        $maxTokens = $options['max_tokens'] ?? 1000;
        $responseFormat = $options['response_format'] ?? ['type' => 'json_object'];

        if (!$this->isModelAvailable($model)) {
            throw new \InvalidArgumentException("Model '{$model}' is not available or supported for vision analysis.");
        }

        try {
            $response = Http::timeout(60)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->post("{$this->baseUrl}/chat/completions", [
                    'model' => $model,
                    'messages' => [
                        [
                            'role' => 'user',
                            'content' => [
                                [
                                    'type' => 'text',
                                    'text' => $prompt,
                                ],
                                [
                                    'type' => 'image_url',
                                    'image_url' => [
                                        'url' => $imageBase64DataUrl,
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'max_tokens' => $maxTokens,
                    'response_format' => $responseFormat,
                ]);

            if ($response->failed()) {
                $error = $response->json();
                $errorMessage = $error['error']['message'] ?? 'Unknown error from OpenAI API';
                $errorCode = $error['error']['code'] ?? null;
                
                // Detect quota exceeded errors
                if (stripos($errorMessage, 'quota') !== false || 
                    stripos($errorMessage, 'exceeded') !== false ||
                    $errorCode === 'insufficient_quota' ||
                    $response->status() === 429) {
                    throw new \App\Exceptions\AIQuotaExceededException(
                        "OpenAI API quota exceeded: {$errorMessage}",
                        'OpenAI'
                    );
                }
                
                throw new \Exception("OpenAI API error: {$errorMessage}", $response->status());
            }

            $data = $response->json();

            if (!isset($data['choices'][0]['message']['content'])) {
                throw new \Exception('Invalid response format from OpenAI API');
            }

            $usage = $data['usage'] ?? [];
            $tokensIn = $usage['prompt_tokens'] ?? 0;
            $tokensOut = $usage['completion_tokens'] ?? 0;
            $actualModel = $data['model'] ?? $model;

            return [
                'text' => $data['choices'][0]['message']['content'],
                'tokens_in' => $tokensIn,
                'tokens_out' => $tokensOut,
                'model' => $actualModel,
                'metadata' => [
                    'finish_reason' => $data['choices'][0]['finish_reason'] ?? null,
                    'response_id' => $data['id'] ?? null,
                ],
            ];
        } catch (\App\Exceptions\AIQuotaExceededException $e) {
            // Re-throw quota exceptions without logging (they'll be handled upstream)
            throw $e;
        } catch (\Exception $e) {
            Log::error('OpenAI Vision API call failed', [
                'model' => $model,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}
