<?php

namespace App\Services\AI\Providers;

use App\Services\AI\Contracts\AIProviderInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Anthropic Claude provider. Supports native PDF analysis via base64 document blocks.
 */
class AnthropicProvider implements AIProviderInterface
{
    protected string $apiKey;

    protected string $baseUrl = 'https://api.anthropic.com/v1';

    protected string $apiVersion = '2023-06-01';

    protected array $supportedModels = [
        'claude-sonnet-4-20250514',
        'claude-3-5-sonnet-20241022',
        'claude-3-haiku-20240307',
    ];

    protected array $modelPricing = [
        'claude-sonnet-4-20250514' => ['input' => 0.000003, 'output' => 0.000015],
        'claude-3-5-sonnet-20241022' => ['input' => 0.000003, 'output' => 0.000015],
        'claude-3-haiku-20240307' => ['input' => 0.00000025, 'output' => 0.00000125],
    ];

    public function __construct()
    {
        $this->apiKey = config('ai.anthropic.api_key') ?? '';
        if ($this->apiKey === '') {
            throw new \RuntimeException('ANTHROPIC_API_KEY is required. Set it in .env.');
        }
    }

    public function generateText(string $prompt, array $options = []): array
    {
        $model = $options['model'] ?? config('ai.anthropic.model', 'claude-sonnet-4-20250514');
        $maxTokens = $options['max_tokens'] ?? 4096;

        $body = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ];

        return $this->sendRequest($body, $model);
    }

    /**
     * Analyze a PDF document with a prompt. Claude supports native PDF via base64 document blocks.
     *
     * @param string $pdfBase64 Raw base64-encoded PDF bytes (NOT a data URL)
     * @param string $prompt Text prompt describing what to extract
     * @param array $options model, max_tokens
     */
    public function analyzePdf(string $pdfBase64, string $prompt, array $options = []): array
    {
        $model = $options['model'] ?? config('ai.anthropic.model', 'claude-sonnet-4-20250514');
        $maxTokens = $options['max_tokens'] ?? 8192;

        $body = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'document',
                            'source' => [
                                'type' => 'base64',
                                'media_type' => 'application/pdf',
                                'data' => $pdfBase64,
                            ],
                        ],
                        [
                            'type' => 'text',
                            'text' => $prompt,
                        ],
                    ],
                ],
            ],
        ];

        return $this->sendRequest($body, $model);
    }

    public function analyzeImage(string $imageBase64DataUrl, string $prompt, array $options = []): array
    {
        $model = $options['model'] ?? config('ai.anthropic.model', 'claude-sonnet-4-20250514');
        $maxTokens = $options['max_tokens'] ?? 4096;

        if (preg_match('#^data:(image/[^;]+);base64,(.+)$#', $imageBase64DataUrl, $m)) {
            $mediaType = $m[1];
            $data = $m[2];
        } else {
            throw new \InvalidArgumentException('Image must be a base64 data URL (data:image/...;base64,...)');
        }

        $body = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'image',
                            'source' => [
                                'type' => 'base64',
                                'media_type' => $mediaType,
                                'data' => $data,
                            ],
                        ],
                        [
                            'type' => 'text',
                            'text' => $prompt,
                        ],
                    ],
                ],
            ],
        ];

        return $this->sendRequest($body, $model);
    }

    public function calculateCost(int $tokensIn, int $tokensOut, string $model): float
    {
        $pricing = $this->modelPricing[$model] ?? $this->modelPricing['claude-sonnet-4-20250514'];

        return round(($tokensIn * $pricing['input']) + ($tokensOut * $pricing['output']), 6);
    }

    public function getProviderName(): string
    {
        return 'anthropic';
    }

    public function isModelAvailable(string $model): bool
    {
        return in_array($model, $this->supportedModels, true);
    }

    protected function sendRequest(array $body, string $model): array
    {
        try {
            $response = Http::timeout(180)
                ->withHeaders([
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => $this->apiVersion,
                    'content-type' => 'application/json',
                ])
                ->post("{$this->baseUrl}/messages", $body);

            if ($response->failed()) {
                $error = $response->json();
                $errorMessage = $error['error']['message'] ?? 'Unknown Anthropic API error';
                $errorType = $error['error']['type'] ?? null;

                if ($response->status() === 429 || $errorType === 'rate_limit_error') {
                    throw new \App\Exceptions\AIQuotaExceededException(
                        "Anthropic rate limit: {$errorMessage}",
                        'Anthropic'
                    );
                }

                throw new \Exception("Anthropic API error: {$errorMessage}", $response->status());
            }

            $data = $response->json();
            $textBlocks = array_filter($data['content'] ?? [], fn ($b) => ($b['type'] ?? '') === 'text');
            $text = implode('', array_column($textBlocks, 'text'));

            $usage = $data['usage'] ?? [];

            return [
                'text' => $text,
                'tokens_in' => $usage['input_tokens'] ?? 0,
                'tokens_out' => $usage['output_tokens'] ?? 0,
                'model' => $data['model'] ?? $model,
                'metadata' => [
                    'stop_reason' => $data['stop_reason'] ?? null,
                    'message_id' => $data['id'] ?? null,
                ],
            ];
        } catch (\App\Exceptions\AIQuotaExceededException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('[AnthropicProvider] API call failed', [
                'model' => $model,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
