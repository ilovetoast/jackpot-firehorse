<?php

namespace App\Services\AI\Providers;

use App\Services\AI\Contracts\AIProviderInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Black Forest Labs FLUX API — image editing via FLUX.2 [flex] (async submit + poll).
 *
 * @see https://docs.bfl.ai/
 */
class FluxProvider implements AIProviderInterface
{
    protected string $apiKey;

    protected string $baseUrl = 'https://api.bfl.ai';

    /** Registry / API path segments under /v1/{segment} */
    protected array $supportedModels = [
        'flux-2-flex',
    ];

    public function __construct()
    {
        $this->apiKey = trim((string) (config('ai.flux.api_key') ?? ''));
        if ($this->apiKey === '') {
            throw new \RuntimeException('FLUX_API_KEY is required for Flux provider. Set it in .env.');
        }
    }

    public function generateText(string $prompt, array $options = []): array
    {
        throw new \BadMethodCallException('FluxProvider does not support text generation.');
    }

    public function analyzeImage(string $imageBase64DataUrl, string $prompt, array $options = []): array
    {
        throw new \BadMethodCallException('FluxProvider does not support vision analyzeImage.');
    }

    /**
     * Edit an image with a text prompt (FLUX.2 async API).
     *
     * @param  array<string, mixed>  $options
     *   - image_binary: string (required)
     *   - model: API segment e.g. flux-2-flex
     *   - mime_type: optional
     * @return array{text: string, tokens_in: int, tokens_out: int, model: string, metadata: array<string, mixed>}
     */
    public function editImage(string $prompt, array $options = []): array
    {
        $binary = $options['image_binary'] ?? null;
        if (! is_string($binary) || $binary === '') {
            throw new \InvalidArgumentException('image_binary is required for Flux image edit.');
        }

        $model = $options['model'] ?? 'flux-2-flex';
        if (! $this->isModelAvailable($model)) {
            throw new \InvalidArgumentException("Model '{$model}' is not available for Flux image edit.");
        }

        $mime = $options['mime_type'] ?? null;
        if (! is_string($mime) || $mime === '') {
            $mime = $this->guessMimeFromBinary($binary);
        }
        if ($mime === 'image/jpg') {
            $mime = 'image/jpeg';
        }

        $b64 = base64_encode($binary);
        $inputImage = 'data:'.$mime.';base64,'.$b64;

        $body = [
            'prompt' => $prompt,
            'input_image' => $inputImage,
            'output_format' => 'png',
            'safety_tolerance' => 2,
            'prompt_upsampling' => true,
        ];

        $url = rtrim($this->baseUrl, '/').'/v1/'.$model;

        try {
            $submit = Http::timeout(60)
                ->withHeaders([
                    'x-key' => $this->apiKey,
                    'accept' => 'application/json',
                    'content-type' => 'application/json',
                ])
                ->post($url, $body);
        } catch (\Throwable $e) {
            Log::error('[FluxProvider] submit failed', ['model' => $model, 'error' => $e->getMessage()]);
            throw $e;
        }

        if ($submit->failed()) {
            $msg = $submit->json('detail.0.msg')
                ?? $submit->json('message')
                ?? $submit->body()
                ?? 'BFL API error';

            throw new \Exception('BFL submit failed: '.$msg, $submit->status());
        }

        $data = $submit->json();
        $pollingUrl = is_array($data) ? ($data['polling_url'] ?? null) : null;
        if (! is_string($pollingUrl) || $pollingUrl === '') {
            throw new \Exception('BFL response missing polling_url.');
        }

        $bflCost = is_array($data) ? ($data['cost'] ?? null) : null;

        $result = $this->pollUntilReady($pollingUrl);
        $sampleUrl = $result['sample_url'];
        $credits = $result['cost'] ?? $bflCost;

        $tokensIn = max(256, (int) (strlen($prompt) / 4));
        $tokensOut = 2048;

        return [
            'text' => '',
            'tokens_in' => $tokensIn,
            'tokens_out' => $tokensOut,
            'model' => $model,
            'metadata' => [
                'image_ref' => $sampleUrl,
                'bfl_cost_credits' => $credits,
            ],
        ];
    }

    /**
     * @return array{sample_url: string, cost: float|int|null}
     */
    protected function pollUntilReady(string $pollingUrl): array
    {
        $deadline = time() + 180;
        $lastStatus = '';

        while (time() < $deadline) {
            usleep(500_000);

            try {
                $res = Http::timeout(60)
                    ->withHeaders([
                        'x-key' => $this->apiKey,
                        'accept' => 'application/json',
                    ])
                    ->get($pollingUrl);
            } catch (\Throwable $e) {
                Log::warning('[FluxProvider] poll HTTP error', ['error' => $e->getMessage()]);
                continue;
            }

            if ($res->failed()) {
                Log::warning('[FluxProvider] poll failed', ['status' => $res->status(), 'body' => $res->body()]);

                continue;
            }

            $data = $res->json();
            if (! is_array($data)) {
                continue;
            }

            $status = $data['status'] ?? '';
            if (is_string($status)) {
                $lastStatus = $status;
            }

            if ($status === 'Ready') {
                $result = $data['result'] ?? null;
                $sample = null;
                if (is_array($result)) {
                    $sample = $result['sample'] ?? null;
                }
                if (! is_string($sample) || $sample === '') {
                    throw new \Exception('BFL result ready but sample URL missing.');
                }

                $cost = $data['cost'] ?? null;

                return ['sample_url' => $sample, 'cost' => is_numeric($cost) ? (float) $cost : null];
            }

            if (in_array($status, ['Error', 'Failed', 'Cancelled'], true)) {
                $detail = $data['error'] ?? $data['message'] ?? json_encode($data);
                throw new \Exception('BFL generation '.$status.': '.(is_string($detail) ? $detail : json_encode($detail)));
            }
        }

        throw new \Exception('BFL image edit timed out (last status: '.($lastStatus !== '' ? $lastStatus : 'unknown').').');
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
            $pricing = ['input' => 0.00001, 'output' => 0.00004];
        }

        $inputCost = $tokensIn * ($pricing['input'] ?? 0);
        $outputCost = $tokensOut * ($pricing['output'] ?? 0);

        return round($inputCost + $outputCost, 6);
    }

    public function getProviderName(): string
    {
        return 'flux';
    }

    public function isModelAvailable(string $model): bool
    {
        if (in_array($model, $this->supportedModels, true)) {
            return true;
        }

        $configModels = config('ai.models', []);
        foreach ($configModels as $key => $modelConfig) {
            if ($key === $model || ($modelConfig['model_name'] ?? null) === $model) {
                return ($modelConfig['provider'] ?? null) === 'flux';
            }
        }

        return false;
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
}
