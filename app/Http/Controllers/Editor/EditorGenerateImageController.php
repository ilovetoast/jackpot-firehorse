<?php

namespace App\Http\Controllers\Editor;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\AI\Providers\GeminiProvider;
use App\Services\PlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Generative image API + plan-based usage for the asset editor.
 * Limits follow the current tenant’s subscription (see {@see PlanService::getCurrentPlan}).
 *
 * Images are returned via a same-origin proxy URL so html-to-image export is not tainted by CORS.
 */
class EditorGenerateImageController extends Controller
{
    private const PROXY_CACHE_PREFIX = 'editor_gen_proxy:';

    private const OPENAI_IMAGE_SIZES = ['1024x1024', '1024x1792', '1792x1024'];

    public function __construct(
        protected PlanService $planService
    ) {}

    public function usage(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        [$limit, $planSlug, $planName] = $this->resolveLimitAndPlan();

        if ($limit < 0) {
            return response()->json([
                'remaining' => -1,
                'limit' => -1,
                'plan' => $planSlug,
                'plan_name' => $planName,
            ]);
        }

        $tenant = app('tenant');
        $used = (int) Cache::get($this->usageCacheKey($tenant, $user), 0);
        $remaining = max(0, $limit - $used);

        return response()->json([
            'remaining' => $remaining,
            'limit' => $limit,
            'plan' => $planSlug,
            'plan_name' => $planName,
        ]);
    }

    /**
     * POST /app/api/generate-image
     */
    public function generate(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'prompt' => 'required|array',
            'prompt_string' => 'nullable|string|max:64000',
            'negative_prompt' => 'sometimes|array',
            'negative_prompt.*' => 'string|max:2000',
            'model' => 'required|array',
            'model.provider' => 'required|string|max:64',
            'model.model' => 'required|string|max:128',
            'model_key' => 'nullable|string|max:32',
            'size' => 'nullable|string|max:32',
            'brand_context' => 'nullable|array',
            'references' => 'sometimes|array',
            'references.*' => 'string|max:64',
        ]);

        $provider = strtolower((string) $validated['model']['provider']);
        $model = (string) ($validated['model']['model'] ?? 'gpt-image-1');
        $prompt = $this->resolvePromptString($validated);

        if ($prompt === '') {
            return response()->json(['message' => 'Prompt is required.'], 422);
        }

        Log::info('editor.generate_image', [
            'user_id' => $user->id,
            'provider' => $provider,
            'model' => $model,
            'model_key' => $validated['model_key'] ?? null,
            'size' => $validated['size'] ?? null,
            'references_count' => isset($validated['references']) ? count($validated['references']) : 0,
            'has_brand_context' => ! empty($validated['brand_context']),
        ]);

        [$limit] = $this->resolveLimitAndPlan();
        $tenant = app('tenant');
        $usageKey = $this->usageCacheKey($tenant, $user);

        if ($limit >= 0) {
            $used = (int) Cache::get($usageKey, 0);
            if ($used >= $limit) {
                return response()->json(['message' => 'Monthly limit reached'], 429);
            }
        }

        try {
            $rawImageRef = match ($provider) {
                'openai' => $this->generateOpenAiImage($prompt, $model, $validated),
                'gemini' => $this->generateGeminiImage($prompt, $model),
                default => throw new \InvalidArgumentException("Unsupported image provider: {$provider}"),
            };
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::warning('editor.generate_image_failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => $e->getMessage() !== '' ? $e->getMessage() : 'Image generation failed',
            ], 502);
        }

        // Count usage only after a successful provider response (stub no longer consumes quota).
        if ($limit >= 0) {
            $used = (int) Cache::get($usageKey, 0);
            Cache::put($usageKey, $used + 1, now()->endOfMonth());
        }

        return response()->json([
            'image_url' => $this->registerProxyUrl($rawImageRef),
        ]);
    }

    /**
     * GET /app/api/generate-image/proxy/{token}
     *
     * Streams a remote OpenAI image URL or a cached data URL through our origin (avoids CORS tainting html-to-image).
     */
    public function proxyImage(Request $request, string $token): Response|JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if (! preg_match('/^[a-f0-9]{32}$/', $token)) {
            return response()->json(['message' => 'Invalid token'], 400);
        }

        $payload = Cache::get(self::PROXY_CACHE_PREFIX.$token);
        if (! is_string($payload) || $payload === '') {
            return response()->json(['message' => 'Image not found or expired'], 410);
        }

        if (str_starts_with($payload, 'data:image')) {
            if (preg_match('#^data:image/[^;]+;base64,(.+)$#', $payload, $m)) {
                $binary = base64_decode($m[1], true);
                if ($binary !== false && strlen($binary) > 0) {
                    return response($binary, 200, [
                        'Content-Type' => 'image/png',
                        'Cache-Control' => 'private, max-age=3600',
                    ]);
                }
            }

            return response()->json(['message' => 'Invalid image payload'], 502);
        }

        if (! str_starts_with($payload, 'http://') && ! str_starts_with($payload, 'https://')) {
            return response()->json(['message' => 'Invalid image reference'], 502);
        }

        $this->assertSafeRemoteImageUrl($payload);

        $remote = Http::timeout(120)
            ->withHeaders(['Accept' => 'image/*'])
            ->get($payload);

        if ($remote->failed()) {
            return response()->json(['message' => 'Failed to fetch generated image'], 502);
        }

        $contentType = $remote->header('Content-Type') ?? 'image/png';

        return response($remote->body(), 200, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /**
     * GET /app/api/generate-image/stub — legacy placeholder (optional local testing without API keys).
     */
    public function stub(Request $request): Response
    {
        $v = (string) $request->query('v', '0');
        $hash = substr(sha1($v), 0, 6);
        $fill = ctype_xdigit($hash) ? '#'.$hash : '#6366f1';

        $svg = <<<SVG
<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" width="1024" height="1024" viewBox="0 0 1024 1024">
  <rect fill="{$fill}" width="1024" height="1024"/>
  <text x="512" y="500" text-anchor="middle" fill="#ffffff" font-size="42" font-family="system-ui,sans-serif">Generated (stub)</text>
  <text x="512" y="560" text-anchor="middle" fill="#e0e7ff" font-size="22" font-family="system-ui,sans-serif">Configure OPENAI_API_KEY / GEMINI_API_KEY</text>
</svg>
SVG;

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function resolvePromptString(array $validated): string
    {
        $p = trim((string) ($validated['prompt_string'] ?? ''));
        if ($p !== '') {
            $neg = $validated['negative_prompt'] ?? [];
            if (is_array($neg) && $neg !== []) {
                $n = implode(', ', array_map(static fn ($x) => (string) $x, $neg));
                if ($n !== '') {
                    $p .= "\n\nAvoid: {$n}";
                }
            }

            return $p;
        }

        $enc = json_encode($validated['prompt'] ?? [], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return $enc;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function generateOpenAiImage(string $prompt, string $model, array $validated): string
    {
        $apiKey = config('ai.openai.api_key');
        if (empty($apiKey)) {
            throw new \RuntimeException('OpenAI API key is not configured (OPENAI_API_KEY).');
        }

        $size = $this->normalizeOpenAiSize($validated['size'] ?? null);
        $baseUrl = rtrim((string) config('ai.openai.base_url', 'https://api.openai.com/v1'), '/');

        // gpt-image-1 and newer image models reject legacy `response_format`; omit it and accept url or b64 in the response.
        $response = Http::withToken($apiKey)
            ->timeout(180)
            ->acceptJson()
            ->post("{$baseUrl}/images/generations", [
                'model' => $model,
                'prompt' => $prompt,
                'n' => 1,
                'size' => $size,
            ]);

        if ($response->failed()) {
            $msg = $response->json('error.message') ?? $response->body() ?? 'OpenAI image generation failed';

            throw new \RuntimeException(is_string($msg) ? $msg : 'OpenAI image generation failed');
        }

        $url = $response->json('data.0.url');
        if (is_string($url) && str_starts_with($url, 'http')) {
            $this->assertSafeRemoteImageUrl($url);

            return $url;
        }

        $b64 = $response->json('data.0.b64_json');
        if (is_string($b64) && $b64 !== '') {
            return 'data:image/png;base64,'.$b64;
        }

        throw new \RuntimeException('OpenAI returned no image URL or base64 data.');
    }

    private function generateGeminiImage(string $prompt, string $model): string
    {
        if (empty(config('ai.gemini.api_key'))) {
            throw new \RuntimeException('Gemini API key is not configured (GEMINI_API_KEY).');
        }

        $provider = new GeminiProvider;
        $result = $provider->generateText($prompt, ['model' => $model]);
        $inline = $result['metadata']['inline_images'][0] ?? null;
        if (! is_string($inline) || $inline === '') {
            throw new \RuntimeException('Gemini did not return an image for this model.');
        }

        return $inline;
    }

    private function normalizeOpenAiSize(?string $size): string
    {
        $s = is_string($size) ? $size : '1024x1024';

        return in_array($s, self::OPENAI_IMAGE_SIZES, true) ? $s : '1024x1024';
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

    private function registerProxyUrl(string $urlOrDataUrl): string
    {
        $token = bin2hex(random_bytes(16));
        Cache::put(self::PROXY_CACHE_PREFIX.$token, $urlOrDataUrl, now()->addMinutes(45));

        return route('api.editor.generate-image.proxy', ['token' => $token], absolute: true);
    }

    /**
     * @return array{0: int, 1: string, 2: string} limit (-1 = unlimited), plan slug, display name
     */
    private function resolveLimitAndPlan(): array
    {
        $tenant = app('tenant');
        if (! $tenant instanceof Tenant) {
            return $this->fallbackEnvLimitAndPlan();
        }

        $slug = $this->planService->getCurrentPlan($tenant);
        $limits = config("plans.{$slug}.limits", []);
        $max = $limits['max_editor_generative_images_per_month'] ?? null;

        $planName = (string) (config("plans.{$slug}.name") ?? $slug);

        if ($max === -1 || $slug === 'enterprise') {
            return [-1, $slug, $planName];
        }

        if ($max !== null && is_numeric($max) && (int) $max >= 0) {
            return [(int) $max, $slug, $planName];
        }

        $fallback = match ($slug) {
            'free' => 3,
            'starter' => 100,
            'pro' => 300,
            'premium' => 5000,
            default => (int) env('EDITOR_GENERATIVE_LIMIT_PAID', 100),
        };

        return [$fallback, $slug, $planName];
    }

    /**
     * @return array{0: int, 1: string, 2: string}
     */
    private function fallbackEnvLimitAndPlan(): array
    {
        $plan = (string) env('EDITOR_GENERATIVE_PLAN', 'free');
        $name = match ($plan) {
            'enterprise' => 'Enterprise',
            'paid' => 'Paid',
            default => 'Free',
        };

        return match ($plan) {
            'enterprise' => [-1, 'enterprise', $name],
            'paid' => [(int) env('EDITOR_GENERATIVE_LIMIT_PAID', 100), 'paid', $name],
            default => [(int) env('EDITOR_GENERATIVE_LIMIT_FREE', 3), 'free', $name],
        };
    }

    private function usageCacheKey(?Tenant $tenant, object $user): string
    {
        $month = now()->format('Y-m');
        if ($tenant instanceof Tenant) {
            return 'editor_gen_usage:tenant:'.$tenant->getKey().':'.$month;
        }

        return 'editor_gen_usage:user:'.$user->getAuthIdentifier().':'.$month;
    }
}
