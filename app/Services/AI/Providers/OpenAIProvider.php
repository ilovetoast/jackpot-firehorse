<?php

namespace App\Services\AI\Providers;

use App\Services\AI\Contracts\AIProviderInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
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
        'gpt-4',
        'gpt-3.5-turbo',
        'gpt-4o',
        'gpt-4o-mini',
        'gpt-image-1',
    ];

    /**
     * gpt-image-1 / images/generations supported `size` values (API rejects legacy 1792× buckets).
     *
     * @see https://platform.openai.com/docs/api-reference/images/create
     */
    private const OPENAI_IMAGE_SIZES = ['1024x1024', '1024x1536', '1536x1024', 'auto'];

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
        $this->baseUrl = config('ai.openai.base_url', 'https://api.openai.com/v1');
        $this->apiKey = config('ai.openai.api_key') ?? '';

        if ($this->apiKey === '') {
            throw new \RuntimeException('OPENAI_API_KEY is required for OpenAI provider. Set it in .env or config/ai.php.');
        }
    }

    /**
     * Generate text from a prompt using OpenAI API.
     *
     * @param  string  $prompt  The input prompt
     * @param  array  $options  Additional options:
     *                          - model: Model name to use (default: gpt-3.5-turbo)
     *                          - max_tokens: Maximum tokens in response (default: 1000)
     *                          - temperature: Sampling temperature 0-2 (default: 0.7)
     * @return array Response array with:
     *               - text: Generated text response
     *               - tokens_in: Number of input tokens used
     *               - tokens_out: Number of output tokens used
     *               - model: Actual model name used
     *               - metadata: Provider-specific metadata
     *
     * @throws \Exception If the API call fails
     */
    public function generateText(string $prompt, array $options = []): array
    {
        $requestedModel = (string) ($options['model'] ?? 'gpt-3.5-turbo');
        $model = $this->resolveTextModelAlias($requestedModel);
        $maxTokens = $options['max_tokens'] ?? 1000;
        $temperature = $options['temperature'] ?? 0.7;
        $responseFormat = $options['response_format'] ?? null;

        if (! $this->isModelAvailable($model)) {
            throw new \InvalidArgumentException("Model '{$model}' is not available or supported.");
        }

        $body = [
            'model' => $model,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
            'max_tokens' => $maxTokens,
            'temperature' => $temperature,
        ];
        if ($responseFormat !== null) {
            $body['response_format'] = $responseFormat;
        }

        try {
            $response = $this->sendChatCompletionsRequest($body);

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

            if (! isset($data['choices'][0]['message']['content'])) {
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
                    'requested_model' => $requestedModel,
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
     * POST /chat/completions with retries for transient TLS / network failures (e.g. cURL 56, errno 104).
     *
     * @param  array<string, mixed>  $body
     */
    private function sendChatCompletionsRequest(array $body, int $timeoutSeconds = 60): Response
    {
        $maxAttempts = (int) config('ai.openai.chat_completions_max_retries', 5);
        $baseMs = (int) config('ai.openai.chat_completions_retry_base_ms', 400);
        $maxSleepMs = (int) config('ai.openai.chat_completions_retry_max_sleep_ms', 8000);
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                return Http::timeout($timeoutSeconds)
                    ->connectTimeout(15)
                    ->withHeaders([
                        'Authorization' => 'Bearer '.$this->apiKey,
                        'Content-Type' => 'application/json',
                    ])
                    ->post("{$this->baseUrl}/chat/completions", $body);
            } catch (\Throwable $e) {
                $lastException = $e;
                if ($attempt >= $maxAttempts || ! $this->isTransientOpenAiConnectionFailure($e)) {
                    throw $e;
                }

                $sleepMs = min($maxSleepMs, (int) ($baseMs * (2 ** ($attempt - 1))));
                $sleepMs += random_int(0, min(400, (int) ceil($sleepMs * 0.2)));
                Log::warning('OpenAI chat/completions transient connection failure, retrying', [
                    'attempt' => $attempt,
                    'max_attempts' => $maxAttempts,
                    'sleep_ms' => $sleepMs,
                    'error' => $e->getMessage(),
                ]);
                usleep($sleepMs * 1000);
            }
        }

        throw $lastException ?? new \RuntimeException('OpenAI chat/completions request failed after retries.');
    }

    private function isTransientOpenAiConnectionFailure(\Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        $msg = strtolower($e->getMessage());
        if (str_contains($msg, 'curl error 56')
            || str_contains($msg, 'errno 104')
            || str_contains($msg, 'connection reset by peer')
            || str_contains($msg, 'ssl_read')
            || str_contains($msg, 'connection timed out')
            || str_contains($msg, 'operation timed out')) {
            return true;
        }

        $prev = $e->getPrevious();
        if ($prev instanceof \Throwable) {
            return $this->isTransientOpenAiConnectionFailure($prev);
        }

        return false;
    }

    /**
     * Generate an image via OpenAI Images API (e.g. gpt-image-1).
     *
     * @return array{text: string, tokens_in: int, tokens_out: int, model: string, metadata: array<string, mixed>}
     */
    public function generateImage(string $prompt, array $options = []): array
    {
        $model = $options['model'] ?? 'gpt-image-1';
        $size = $this->normalizeOpenAiImageSize($options['image_size'] ?? $options['size'] ?? null);

        if (! $this->isModelAvailable($model)) {
            throw new \InvalidArgumentException("Model '{$model}' is not available for OpenAI image generation.");
        }

        try {
            $response = Http::withToken($this->apiKey)
                ->timeout(180)
                ->acceptJson()
                ->post("{$this->baseUrl}/images/generations", [
                    'model' => $model,
                    'prompt' => $prompt,
                    'n' => 1,
                    'size' => $size,
                ]);

            if ($response->failed()) {
                $error = $response->json();
                $errorMessage = is_array($error['error'] ?? null)
                    ? ($error['error']['message'] ?? 'OpenAI image generation failed')
                    : ($response->body() ?: 'OpenAI image generation failed');

                if (stripos((string) $errorMessage, 'quota') !== false
                    || stripos((string) $errorMessage, 'exceeded') !== false
                    || $response->status() === 429) {
                    throw new \App\Exceptions\AIQuotaExceededException(
                        "OpenAI API quota exceeded: {$errorMessage}",
                        'OpenAI'
                    );
                }

                throw new \Exception('OpenAI image generation failed: '.(is_string($errorMessage) ? $errorMessage : json_encode($error)), $response->status());
            }

            $data = $response->json();
            $actualModel = is_array($data) ? ($data['model'] ?? $model) : $model;

            $imageRef = null;
            if (is_array($data)) {
                $url = $data['data'][0]['url'] ?? null;
                if (is_string($url) && str_starts_with($url, 'http')) {
                    $this->assertSafeRemoteImageUrl($url);
                    $imageRef = $url;
                }
                $b64 = $data['data'][0]['b64_json'] ?? null;
                if ($imageRef === null && is_string($b64) && $b64 !== '') {
                    $imageRef = 'data:image/png;base64,'.$b64;
                }
            }

            if ($imageRef === null || $imageRef === '') {
                throw new \Exception('OpenAI returned no image URL or base64 data.');
            }

            $usage = is_array($data) ? ($data['usage'] ?? []) : [];
            $tokensIn = 0;
            $tokensOut = 0;
            $estimated = false;
            if (is_array($usage) && $usage !== []) {
                $tokensIn = (int) ($usage['input_tokens'] ?? $usage['prompt_tokens'] ?? 0);
                $tokensOut = (int) ($usage['output_tokens'] ?? $usage['completion_tokens'] ?? 0);
            }
            if ($tokensIn === 0 && $tokensOut === 0) {
                $estimated = true;
                $tokensIn = max(1, (int) ceil(strlen($prompt) / 4));
                $tokensOut = max(1, 1024);
            }

            return [
                'text' => '',
                'tokens_in' => $tokensIn,
                'tokens_out' => $tokensOut,
                'model' => is_string($actualModel) ? $actualModel : $model,
                'metadata' => [
                    'image_ref' => $imageRef,
                    'image_size' => $size,
                    'estimated_tokens' => $estimated,
                ],
            ];
        } catch (\App\Exceptions\AIQuotaExceededException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('OpenAI image generation failed', [
                'model' => $model,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Edit an image via OpenAI Images API (multipart /v1/images/edits).
     *
     * @param  array<string, mixed>  $options
     *                                         - image_binary: string (raw image bytes, required)
     *                                         - filename: optional filename hint for multipart (e.g. source.png)
     *                                         - model: e.g. gpt-image-1
     *                                         - openai_image_edit: optional array for GPT Image edits only: background (transparent|opaque|auto), output_format (png|jpeg|webp), quality, size
     * @return array{text: string, tokens_in: int, tokens_out: int, model: string, metadata: array<string, mixed>}
     */
    public function editImage(string $prompt, array $options = []): array
    {
        $binary = $options['image_binary'] ?? null;
        if (! is_string($binary) || $binary === '') {
            throw new \InvalidArgumentException('image_binary is required for image edit.');
        }

        $model = $options['model'] ?? 'gpt-image-1';
        if (! $this->isModelAvailable($model)) {
            throw new \InvalidArgumentException("Model '{$model}' is not available for OpenAI image edit.");
        }

        $filename = $options['filename'] ?? $this->guessImageFilename($binary);

        $payload = [
            'model' => $model,
            'prompt' => $prompt,
            'n' => 1,
        ];

        $editExtras = $options['openai_image_edit'] ?? null;
        if (is_array($editExtras) && $this->isGptImageModelForEdits($model)) {
            foreach (['background', 'output_format', 'quality', 'size'] as $key) {
                if (! isset($editExtras[$key]) || ! is_string($editExtras[$key]) || $editExtras[$key] === '') {
                    continue;
                }
                $value = $editExtras[$key];
                if ($key === 'background' && ! in_array($value, ['transparent', 'opaque', 'auto'], true)) {
                    continue;
                }
                if ($key === 'output_format' && ! in_array($value, ['png', 'jpeg', 'webp'], true)) {
                    continue;
                }
                if ($key === 'quality' && ! in_array($value, ['low', 'medium', 'high', 'auto'], true)) {
                    continue;
                }
                if ($key === 'size' && ! in_array($value, self::OPENAI_IMAGE_SIZES, true)) {
                    continue;
                }
                $payload[$key] = $value;
            }
        }

        try {
            $response = Http::withToken($this->apiKey)
                ->timeout(180)
                ->attach('image', $binary, $filename)
                ->post("{$this->baseUrl}/images/edits", $payload);

            if ($response->failed()) {
                $error = $response->json();
                $errorMessage = is_array($error['error'] ?? null)
                    ? ($error['error']['message'] ?? 'OpenAI image edit failed')
                    : ($response->body() ?: 'OpenAI image edit failed');

                if (stripos((string) $errorMessage, 'quota') !== false
                    || stripos((string) $errorMessage, 'exceeded') !== false
                    || $response->status() === 429) {
                    throw new \App\Exceptions\AIQuotaExceededException(
                        "OpenAI API quota exceeded: {$errorMessage}",
                        'OpenAI'
                    );
                }

                throw new \Exception('OpenAI image edit failed: '.(is_string($errorMessage) ? $errorMessage : json_encode($error)), $response->status());
            }

            $data = $response->json();
            $actualModel = is_array($data) ? ($data['model'] ?? $model) : $model;

            $imageRef = null;
            if (is_array($data)) {
                $url = $data['data'][0]['url'] ?? null;
                if (is_string($url) && str_starts_with($url, 'http')) {
                    $this->assertSafeRemoteImageUrl($url);
                    $imageRef = $url;
                }
                $b64 = $data['data'][0]['b64_json'] ?? null;
                if ($imageRef === null && is_string($b64) && $b64 !== '') {
                    $imageRef = 'data:image/png;base64,'.$b64;
                }
            }

            if ($imageRef === null || $imageRef === '') {
                throw new \Exception('OpenAI returned no edited image URL or base64 data.');
            }

            $usage = is_array($data) ? ($data['usage'] ?? []) : [];
            $tokensIn = 0;
            $tokensOut = 0;
            $estimated = false;
            if (is_array($usage) && $usage !== []) {
                $tokensIn = (int) ($usage['input_tokens'] ?? $usage['prompt_tokens'] ?? 0);
                $tokensOut = (int) ($usage['output_tokens'] ?? $usage['completion_tokens'] ?? 0);
            }
            if ($tokensIn === 0 && $tokensOut === 0) {
                $estimated = true;
                $tokensIn = max(1, (int) ceil(strlen($prompt) / 4));
                $tokensOut = max(1, 1024);
            }

            return [
                'text' => '',
                'tokens_in' => $tokensIn,
                'tokens_out' => $tokensOut,
                'model' => is_string($actualModel) ? $actualModel : $model,
                'metadata' => [
                    'image_ref' => $imageRef,
                    'estimated_tokens' => $estimated,
                ],
            ];
        } catch (\App\Exceptions\AIQuotaExceededException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('OpenAI image edit failed', [
                'model' => $model,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * GPT Image family models accept `background`, `output_format`, etc. on /v1/images/edits.
     * DALL·E 2/3 do not; omit those fields to avoid API errors.
     */
    private function isGptImageModelForEdits(string $model): bool
    {
        $m = strtolower($model);
        if (str_starts_with($m, 'dall-e')) {
            return false;
        }

        return str_contains($m, 'gpt-image') || str_contains($m, 'chatgpt-image');
    }

    private function guessImageFilename(string $binary): string
    {
        if (strlen($binary) >= 3 && $binary[0] === "\xff" && $binary[1] === "\xd8") {
            return 'source.jpg';
        }
        if (strlen($binary) >= 8 && substr($binary, 0, 8) === "\x89PNG\r\n\x1a\n") {
            return 'source.png';
        }
        if (strlen($binary) >= 6 && strtoupper(substr($binary, 0, 6)) === 'GIF87a'
            || strtoupper(substr($binary, 0, 6)) === 'GIF89a') {
            return 'source.gif';
        }
        if (strlen($binary) >= 12 && substr($binary, 0, 4) === 'RIFF' && substr($binary, 8, 4) === 'WEBP') {
            return 'source.webp';
        }

        return 'source.png';
    }

    private function normalizeOpenAiImageSize(?string $size): string
    {
        $s = is_string($size) ? trim($size) : '1024x1024';
        if ($s === '') {
            $s = '1024x1024';
        }

        if (in_array($s, self::OPENAI_IMAGE_SIZES, true)) {
            return $s;
        }

        // Legacy DALL·E 3 style buckets → current gpt-image-1 sizes
        if ($s === '1792x1024') {
            return '1536x1024';
        }
        if ($s === '1024x1792') {
            return '1024x1536';
        }

        return '1024x1024';
    }

    /**
     * Only allow fetching images from known OpenAI / Azure blob hosts (mitigate SSRF on proxy).
     */
    private function assertSafeRemoteImageUrl(string $url): void
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            throw new \InvalidArgumentException('Invalid remote image URL.');
        }

        $host = strtolower($host);
        $ok =
            str_contains($host, 'blob.core.windows.net')
            || str_contains($host, 'openai.com')
            || str_contains($host, 'oaiusercontent.com');

        if (! $ok) {
            throw new \InvalidArgumentException('Remote image host is not allowed.');
        }
    }

    /**
     * Calculate the estimated cost for a given token usage.
     *
     * First tries to get pricing from config/ai.php, then falls back to
     * provider-specific pricing.
     *
     * @param  int  $tokensIn  Number of input tokens
     * @param  int  $tokensOut  Number of output tokens
     * @param  string  $model  Model identifier (e.g., 'gpt-4-turbo')
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
        if (! $pricing) {
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
     */
    public function getProviderName(): string
    {
        return 'openai';
    }

    /**
     * Check if a model is available/supported by this provider.
     *
     * @param  string  $model  Model identifier
     * @return bool True if the model is available
     */
    public function isModelAvailable(string $model): bool
    {
        $resolvedModel = $this->resolveTextModelAlias($model);

        // Check if model is in supported models list
        if (in_array($resolvedModel, $this->supportedModels, true)) {
            return true;
        }

        // Check if model is defined in config
        $configModels = config('ai.models', []);
        foreach ($configModels as $key => $modelConfig) {
            $configModelName = (string) ($modelConfig['model_name'] ?? '');
            if (
                $key === $model
                || $configModelName === $model
                || $key === $resolvedModel
                || $configModelName === $resolvedModel
            ) {
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
     * @param  string  $model  Model name
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
     * Normalize legacy text model aliases to currently available OpenAI models.
     * Keeps old config keys working after upstream model retirements.
     */
    protected function resolveTextModelAlias(string $model): string
    {
        $aliases = [
            'gpt-4-turbo-preview' => 'gpt-4o',
            'gpt-4-turbo' => 'gpt-4o',
        ];

        return $aliases[$model] ?? $model;
    }

    /**
     * Analyze an image with a prompt using OpenAI Vision API.
     *
     * Accepts image as base64 data URL (data:image/webp;base64,...).
     * No presigned S3 URLs are passed to OpenAI; images are fetched internally via IAM.
     *
     * @param  string  $imageBase64DataUrl  Base64 data URL (data:image/webp;base64,{base64})
     * @param  string  $prompt  Text prompt describing what to analyze
     * @param  array  $options  Additional options:
     *                          - model: Model name to use (default: gpt-4o-mini)
     *                          - max_tokens: Maximum tokens in response (default: 1000)
     *                          - response_format: Response format (default: ['type' => 'json_object'])
     *                          - reference_image_data_urls: optional extra data URLs before the primary image
     * @return array Response array with:
     *               - text: Generated text response (typically JSON string)
     *               - tokens_in: Number of input tokens used
     *               - tokens_out: Number of output tokens used
     *               - model: Actual model name used
     *               - metadata: Provider-specific metadata
     *
     * @throws \Exception If the API call fails
     */
    public function analyzeImage(string $imageBase64DataUrl, string $prompt, array $options = []): array
    {
        $requestedModel = (string) ($options['model'] ?? 'gpt-4o-mini');
        $model = $this->resolveTextModelAlias($requestedModel);
        $maxTokens = $options['max_tokens'] ?? 1000;
        $responseFormat = $options['response_format'] ?? ['type' => 'json_object'];

        if (! $this->isModelAvailable($model)) {
            throw new \InvalidArgumentException("Model '{$model}' is not available or supported for vision analysis.");
        }

        try {
            $refUrls = $options['reference_image_data_urls'] ?? [];
            if (! is_array($refUrls)) {
                $refUrls = [];
            }
            $refUrls = array_values(array_filter($refUrls, static fn ($u) => is_string($u) && $u !== ''));

            $content = [
                [
                    'type' => 'text',
                    'text' => $prompt,
                ],
            ];
            foreach ($refUrls as $refUrl) {
                $content[] = [
                    'type' => 'image_url',
                    'image_url' => [
                        'url' => $refUrl,
                    ],
                ];
            }
            if ($refUrls !== []) {
                $content[] = [
                    'type' => 'text',
                    'text' => 'The last image in this message (after the above reference images) is the primary asset; apply structured fields and tags only to that final image unless the instruction text already says otherwise.',
                ];
            }
            $content[] = [
                'type' => 'image_url',
                'image_url' => [
                    'url' => $imageBase64DataUrl,
                ],
            ];

            $response = $this->sendChatCompletionsRequest([
                'model' => $model,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $content,
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

            if (! isset($data['choices'][0]['message']['content'])) {
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
                    'requested_model' => $requestedModel,
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
