<?php

namespace App\Services\AI\Providers;

use App\Services\AI\Contracts\AIProviderInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Google Gemini API (Generative Language API) — native image generation (Nano Banana family)
 * and multimodal requests via :generateContent.
 *
 * @see https://ai.google.dev/gemini-api/docs/image-generation
 */
class GeminiProvider implements AIProviderInterface
{
    protected string $apiKey;

    protected string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta';

    /**
     * Models this provider handles (keys must match config ai.models entries with provider gemini).
     */
    protected array $supportedModels = [
        'gemini-3-pro-image-preview',
        'gemini-3.1-flash-image-preview',
        'gemini-2.5-flash-image',
    ];

    public function __construct()
    {
        $this->apiKey = config('ai.gemini.api_key') ?? '';
        if ($this->apiKey === '') {
            throw new \RuntimeException('GEMINI_API_KEY is required for Gemini provider. Set it in .env.');
        }
    }

    public function generateText(string $prompt, array $options = []): array
    {
        $model = $options['model'] ?? 'gemini-3.1-flash-image-preview';

        if (! $this->isModelAvailable($model)) {
            throw new \InvalidArgumentException("Model '{$model}' is not available for Gemini.");
        }

        return $this->generateContent(
            [
                [
                    'parts' => [
                        ['text' => $prompt],
                    ],
                ],
            ],
            $model
        );
    }

    public function analyzeImage(string $imageBase64DataUrl, string $prompt, array $options = []): array
    {
        $model = $options['model'] ?? 'gemini-3.1-flash-image-preview';

        if (! $this->isModelAvailable($model)) {
            throw new \InvalidArgumentException("Model '{$model}' is not available for Gemini vision/image.");
        }

        $refUrls = $options['reference_image_data_urls'] ?? [];
        if (! is_array($refUrls)) {
            $refUrls = [];
        }
        $refUrls = array_values(array_filter($refUrls, static fn ($u) => is_string($u) && $u !== ''));

        $parts = [
            ['text' => $prompt],
        ];
        foreach ($refUrls as $refUrl) {
            if (preg_match('#^data:(image/[^;]+);base64,(.+)$#', $refUrl, $rm)) {
                $parts[] = [
                    'inlineData' => [
                        'mimeType' => $rm[1],
                        'data' => $rm[2],
                    ],
                ];
            }
        }
        if ($refUrls !== []) {
            $parts[] = ['text' => 'The last image below is the primary asset; follow the instruction for that image only.'];
        }

        if (! preg_match('#^data:(image/[^;]+);base64,(.+)$#', $imageBase64DataUrl, $m)) {
            throw new \InvalidArgumentException('Image must be a base64 data URL (data:image/...;base64,...).');
        }

        $mimeType = $m[1];
        $data = $m[2];
        $parts[] = [
            'inlineData' => [
                'mimeType' => $mimeType,
                'data' => $data,
            ],
        ];

        return $this->generateContent(
            [
                [
                    'parts' => $parts,
                ],
            ],
            $model
        );
    }

    /**
     * Edit / transform an image from raw bytes + instruction (Nano Banana image models).
     * Skips PHP GD — sends bytes as inlineData with detected MIME type (handles AVIF, etc. when API accepts).
     *
     * @param  array<string, mixed>  $options
     *   - image_binary: string (required)
     *   - model: API model id (e.g. gemini-2.5-flash-image)
     *   - mime_type: optional hint (e.g. image/jpeg)
     * @return array{text: string, tokens_in: int, tokens_out: int, model: string, metadata: array<string, mixed>}
     */
    public function editImage(string $prompt, array $options = []): array
    {
        $binary = $options['image_binary'] ?? null;
        if (! is_string($binary) || $binary === '') {
            throw new \InvalidArgumentException('image_binary is required for Gemini image edit.');
        }

        $model = $options['model'] ?? 'gemini-2.5-flash-image';
        if (! $this->isModelAvailable($model)) {
            throw new \InvalidArgumentException("Model '{$model}' is not available for Gemini image edit.");
        }

        $mime = $options['mime_type'] ?? null;
        if (! is_string($mime) || $mime === '') {
            $mime = $this->guessMimeFromBinary($binary);
        }
        if ($mime === 'image/jpg') {
            $mime = 'image/jpeg';
        }

        $b64 = base64_encode($binary);
        $contents = [
            [
                'parts' => [
                    [
                        'inlineData' => [
                            'mimeType' => $mime,
                            'data' => $b64,
                        ],
                    ],
                    ['text' => $prompt],
                ],
            ],
        ];

        return $this->generateContent($contents, $model, [
            'generationConfig' => [
                'responseModalities' => ['TEXT', 'IMAGE'],
            ],
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $contents
     * @param  array<string, mixed>  $extraRootKeys Merged into POST JSON (e.g. generationConfig for image models)
     * @return array{text: string, tokens_in: int, tokens_out: int, model: string, metadata: array}
     */
    protected function generateContent(array $contents, string $model, array $extraRootKeys = []): array
    {
        $url = "{$this->baseUrl}/models/{$model}:generateContent";

        $body = array_merge(['contents' => $contents], $extraRootKeys);

        try {
            $response = Http::timeout(180)
                ->withHeaders([
                    'x-goog-api-key' => $this->apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->post($url, $body);

            if ($response->failed()) {
                $error = $response->json();
                $errorMessage = $error['error']['message'] ?? 'Unknown Gemini API error';
                $status = $response->status();

                if ($status === 429) {
                    throw new \App\Exceptions\AIQuotaExceededException(
                        "Gemini API rate limit or quota: {$errorMessage}",
                        'Gemini'
                    );
                }

                throw new \Exception("Gemini API error: {$errorMessage}", $status);
            }

            $data = $response->json();

            return $this->parseGenerateContentResponse($data, $model);
        } catch (\App\Exceptions\AIQuotaExceededException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('[GeminiProvider] generateContent failed', [
                'model' => $model,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function parseGenerateContentResponse(array $data, string $model): array
    {
        $candidates = $data['candidates'] ?? [];
        if ($candidates === []) {
            $promptFeedback = $data['promptFeedback'] ?? null;
            $block = is_array($promptFeedback) ? json_encode($promptFeedback) : 'empty candidates';
            throw new \Exception('Gemini returned no candidates: '.$block);
        }

        $parts = $candidates[0]['content']['parts'] ?? [];
        $textParts = [];
        $inlineImages = [];

        foreach ($parts as $part) {
            if (isset($part['text'])) {
                $textParts[] = $part['text'];
            }
            $inline = $part['inlineData'] ?? $part['inline_data'] ?? null;
            if (is_array($inline)) {
                $mime = $inline['mimeType'] ?? $inline['mime_type'] ?? 'image/png';
                $b64 = $inline['data'] ?? '';
                if ($b64 !== '') {
                    $inlineImages[] = 'data:'.$mime.';base64,'.$b64;
                }
            }
        }

        $usage = $data['usageMetadata'] ?? $data['usage_metadata'] ?? [];
        $tokensIn = (int) ($usage['promptTokenCount'] ?? $usage['prompt_token_count'] ?? 0);
        $tokensOut = (int) ($usage['candidatesTokenCount'] ?? $usage['candidates_token_count'] ?? 0);
        if ($tokensIn === 0 && $tokensOut === 0 && isset($usage['totalTokenCount'])) {
            $total = (int) $usage['totalTokenCount'];
            $tokensIn = (int) ($usage['promptTokenCount'] ?? $total);
            $tokensOut = max(0, $total - $tokensIn);
        }

        $text = implode('', $textParts);

        $metadata = [
            'finish_reason' => $candidates[0]['finishReason'] ?? $candidates[0]['finish_reason'] ?? null,
        ];
        if ($inlineImages !== []) {
            $metadata['inline_images'] = $inlineImages;
        }

        return [
            'text' => $text,
            'tokens_in' => $tokensIn,
            'tokens_out' => $tokensOut,
            'model' => $data['model'] ?? $model,
            'metadata' => $metadata,
        ];
    }

    public function calculateCost(int $tokensIn, int $tokensOut, string $model): float
    {
        $configModels = config('ai.models', []);
        $pricing = null;

        foreach ($configModels as $key => $modelConfig) {
            if ($key === $model || ($modelConfig['model_name'] ?? null) === $model) {
                $pricing = $modelConfig['default_cost_per_token'] ?? null;
                break;
            }
        }

        if (! $pricing) {
            $pricing = ['input' => 0.000002, 'output' => 0.000012];
        }

        $inputCost = $tokensIn * ($pricing['input'] ?? 0);
        $outputCost = $tokensOut * ($pricing['output'] ?? 0);

        return round($inputCost + $outputCost, 6);
    }

    public function getProviderName(): string
    {
        return 'gemini';
    }

    private function guessMimeFromBinary(string $binary): string
    {
        if (function_exists('finfo_open')) {
            $f = finfo_open(FILEINFO_MIME_TYPE);
            if ($f !== false) {
                $mime = finfo_buffer($f, $binary);
                finfo_close($f);
                if (is_string($mime) && str_starts_with($mime, 'image/')) {
                    return $mime === 'image/jpg' ? 'image/jpeg' : $mime;
                }
            }
        }

        return 'image/png';
    }

    public function isModelAvailable(string $model): bool
    {
        if (in_array($model, $this->supportedModels, true)) {
            return true;
        }

        $configModels = config('ai.models', []);
        foreach ($configModels as $key => $modelConfig) {
            if ($key === $model || ($modelConfig['model_name'] ?? null) === $model) {
                return ($modelConfig['provider'] ?? null) === 'gemini';
            }
        }

        return false;
    }
}
