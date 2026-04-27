<?php

namespace App\Services\AI\Providers;

use App\Exceptions\AIProviderException;
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
            if (preg_match('#^data:(image/[^;]+);base64,(.+)$#', $refUrl, $rm)) {
                $content[] = [
                    'type' => 'image',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => $rm[1],
                        'data' => $rm[2],
                    ],
                ];
            }
        }
        if ($refUrls !== []) {
            $content[] = [
                'type' => 'text',
                'text' => 'The last image after this line is the primary asset; apply the task only to that final image.',
            ];
        }

        if (preg_match('#^data:(image/[^;]+);base64,(.+)$#', $imageBase64DataUrl, $m)) {
            $mediaType = $m[1];
            $data = $m[2];
        } else {
            throw new \InvalidArgumentException('Image must be a base64 data URL (data:image/...;base64,...)');
        }

        $content[] = [
            'type' => 'image',
            'source' => [
                'type' => 'base64',
                'media_type' => $mediaType,
                'data' => $data,
            ],
        ];

        $body = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $content,
                ],
            ],
        ];

        return $this->sendRequest($body, $model);
    }

    /**
     * Analyze a PDF via Anthropic Files API (for large PDFs that exceed the 32MB inline limit).
     * Uploads the file, sends the message referencing file_id, then deletes the file.
     */
    public function analyzePdfViaFilesApi(string $tempFilePath, string $prompt, array $options = []): array
    {
        $model = $options['model'] ?? config('ai.anthropic.model', 'claude-sonnet-4-20250514');
        $maxTokens = $options['max_tokens'] ?? 8192;

        $fileId = $this->uploadFileToAnthropicApi($tempFilePath);

        try {
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
                                    'type' => 'file',
                                    'file_id' => $fileId,
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

            return $this->sendRequest($body, $model, ['anthropic-beta' => 'files-api-2025-04-14']);
        } finally {
            $this->deleteAnthropicFile($fileId);
        }
    }

    protected function uploadFileToAnthropicApi(string $filePath): string
    {
        $filename = basename($filePath);
        if (! str_ends_with(strtolower($filename), '.pdf')) {
            $filename .= '.pdf';
        }

        $response = Http::timeout(120)
            ->withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => $this->apiVersion,
                'anthropic-beta' => 'files-api-2025-04-14',
            ])
            ->attach('file', file_get_contents($filePath), $filename, ['Content-Type' => 'application/pdf'])
            ->post("{$this->baseUrl}/files");

        if ($response->failed()) {
            $error = $response->json();
            $msg = $error['error']['message'] ?? 'File upload failed';
            throw new \RuntimeException("Anthropic Files API upload error: {$msg}");
        }

        $fileId = $response->json('id');
        if (! $fileId) {
            throw new \RuntimeException('Anthropic Files API: no file_id in upload response');
        }

        Log::info('[AnthropicProvider] File uploaded to Anthropic', [
            'file_id' => $fileId,
            'size_bytes' => filesize($filePath),
        ]);

        return $fileId;
    }

    protected function deleteAnthropicFile(string $fileId): void
    {
        try {
            Http::timeout(15)
                ->withHeaders([
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => $this->apiVersion,
                    'anthropic-beta' => 'files-api-2025-04-14',
                ])
                ->delete("{$this->baseUrl}/files/{$fileId}");
        } catch (\Throwable $e) {
            Log::warning('[AnthropicProvider] Failed to delete Anthropic file (non-critical)', [
                'file_id' => $fileId,
                'error' => $e->getMessage(),
            ]);
        }
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

    protected function sendRequest(array $body, string $model, array $extraHeaders = []): array
    {
        try {
            $headers = array_merge([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => $this->apiVersion,
                'content-type' => 'application/json',
            ], $extraHeaders);

            $response = Http::timeout(180)
                ->withHeaders($headers)
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

                [$publicMessage, $httpStatus] = $this->publicMessageAndStatusForAnthropicFailure(
                    $response->status(),
                    $errorMessage,
                    $errorType
                );
                $internalMessage = "Anthropic API error: {$errorMessage}";

                throw new AIProviderException($publicMessage, $internalMessage, $httpStatus);
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
        } catch (AIProviderException $e) {
            Log::warning('[AnthropicProvider] API rejected request', [
                'model' => $model,
                'internal' => $e->getMessage(),
                'public' => $e->getPublicMessage(),
                'status' => $e->getStatusCode(),
            ]);
            throw $e;
        } catch (\Exception $e) {
            Log::error('[AnthropicProvider] API call failed', [
                'model' => $model,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * User-safe copy + HTTP status; internal detail stays in {@see AIProviderException::getMessage()}.
     *
     * @return array{0: string, 1: int} [publicMessage, httpStatus]
     */
    protected function publicMessageAndStatusForAnthropicFailure(int $status, string $errorMessage, ?string $errorType): array
    {
        $lower = strtolower($errorMessage);
        $typeLower = $errorType !== null ? strtolower($errorType) : '';

        if (str_contains($lower, 'overloaded')
            || str_contains($typeLower, 'overloaded')
            || $status === 529) {
            return [
                'The AI service is busy right now. Please try again in a moment.',
                503,
            ];
        }

        if ($status >= 500) {
            return [
                'The AI service is temporarily unavailable. Please try again shortly.',
                503,
            ];
        }

        if ($status >= 400) {
            return [
                'We couldn\'t complete this AI request. Please try again.',
                502,
            ];
        }

        return [
            'Something went wrong with the AI service. Please try again.',
            503,
        ];
    }
}
