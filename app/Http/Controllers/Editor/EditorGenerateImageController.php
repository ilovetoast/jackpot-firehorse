<?php

namespace App\Http\Controllers\Editor;

use App\Enums\AITaskType;
use App\Exceptions\PlanLimitExceededException;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\AiUsageService;
use App\Services\AIService;
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

    public function __construct(
        protected PlanService $planService,
        protected AIService $aiService,
        protected AiUsageService $aiUsageService
    ) {}

    public function usage(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $tenant = app('tenant');
        if (! $tenant instanceof Tenant) {
            return $this->usageFallbackNoTenant($request);
        }

        $slug = $this->planService->getCurrentPlan($tenant);
        $planName = (string) (config("plans.{$slug}.name") ?? $slug);
        $limits = config("plans.{$slug}.limits", []);
        $rawMax = $limits['max_editor_generative_images_per_month'] ?? null;

        $used = $this->aiUsageService->getMonthlyUsage($tenant, 'generative_editor_images');

        if ($rawMax === -1 || $slug === 'enterprise') {
            return response()->json([
                'remaining' => -1,
                'limit' => -1,
                'plan' => $slug,
                'plan_name' => $planName,
            ]);
        }

        $cap = $this->aiUsageService->getMonthlyCap($tenant, 'generative_editor_images');
        if ($cap === 0) {
            return response()->json([
                'remaining' => -1,
                'limit' => -1,
                'plan' => $slug,
                'plan_name' => $planName,
            ]);
        }

        $remaining = max(0, $cap - $used);

        return response()->json([
            'remaining' => $remaining,
            'limit' => $cap,
            'plan' => $slug,
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
            'model_override' => 'nullable|string|max:128',
            'size' => 'nullable|string|max:32',
            'brand_context' => 'nullable|array',
            'references' => 'sometimes|array',
            'references.*' => 'string|max:64',
            'composition_id' => 'nullable|uuid',
            'asset_id' => 'nullable|uuid',
            'brand_id' => 'nullable|integer',
        ]);

        $tenant = app('tenant');
        if (! $tenant instanceof Tenant) {
            return response()->json(['message' => 'Tenant context required.'], 422);
        }

        $prompt = $this->resolvePromptString($validated);

        if ($prompt === '') {
            return response()->json(['message' => 'Prompt is required.'], 422);
        }

        try {
            $registryKey = $this->resolveRegistryModelKey($validated);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $provider = strtolower((string) $validated['model']['provider']);
        $model = (string) ($validated['model']['model'] ?? 'gpt-image-1');

        Log::info('editor.generate_image', [
            'user_id' => $user->id,
            'provider' => $provider,
            'model' => $model,
            'registry_key' => $registryKey,
            'model_key' => $validated['model_key'] ?? null,
            'model_override' => $validated['model_override'] ?? null,
            'size' => $validated['size'] ?? null,
            'references_count' => isset($validated['references']) ? count($validated['references']) : 0,
            'has_brand_context' => ! empty($validated['brand_context']),
        ]);

        try {
            $this->aiUsageService->checkUsage($tenant, 'generative_editor_images');
        } catch (PlanLimitExceededException $e) {
            return response()->json(['message' => 'Monthly limit reached'], 429);
        }

        $options = [
            'model' => $registryKey,
            'image_size' => $validated['size'] ?? null,
            'size' => $validated['size'] ?? null,
            'tenant' => $tenant,
            'user' => $user,
            'triggering_context' => 'user',
        ];

        if (isset($validated['brand_id'])) {
            $options['brand_id'] = (int) $validated['brand_id'];
        }
        if (! empty($validated['composition_id'])) {
            $options['composition_id'] = $validated['composition_id'];
        }
        if (! empty($validated['asset_id'])) {
            $options['asset_id'] = $validated['asset_id'];
        }

        try {
            $result = $this->aiService->executeGenerativeImageAgent(
                'editor_generative_image',
                AITaskType::EDITOR_GENERATIVE_IMAGE,
                $prompt,
                $options
            );
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

        try {
            $this->aiUsageService->trackUsageWithCost(
                $tenant,
                'generative_editor_images',
                1,
                (float) ($result['cost'] ?? 0.0),
                isset($result['tokens_in']) ? (int) $result['tokens_in'] : null,
                isset($result['tokens_out']) ? (int) $result['tokens_out'] : null,
                $result['resolved_model_key'] ?? $registryKey
            );
        } catch (PlanLimitExceededException $e) {
            Log::warning('editor.generate_image_usage_track_failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Monthly limit reached'], 429);
        }

        return response()->json([
            'image_url' => $this->registerProxyUrl((string) $result['image_ref']),
            'resolved_model_key' => $result['resolved_model_key'] ?? $registryKey,
            'model_display_name' => $result['model_display_name'] ?? $registryKey,
            'agent_run_id' => $result['agent_run_id'],
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
    private function resolveRegistryModelKey(array $validated): string
    {
        $override = $validated['model_override'] ?? null;
        if (is_string($override) && trim($override) !== '') {
            return trim($override);
        }

        $provider = strtolower((string) $validated['model']['provider']);
        $apiModel = (string) ($validated['model']['model'] ?? '');

        foreach (config('ai.models', []) as $key => $cfg) {
            if (($cfg['provider'] ?? '') === $provider && ($cfg['model_name'] ?? '') === $apiModel) {
                return $key;
            }
        }

        throw new \InvalidArgumentException('Unknown model mapping for generative image.');
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

    private function usageFallbackNoTenant(Request $request): JsonResponse
    {
        $plan = (string) env('EDITOR_GENERATIVE_PLAN', 'free');
        $name = match ($plan) {
            'enterprise' => 'Enterprise',
            'paid' => 'Paid',
            default => 'Free',
        };

        if ($plan === 'enterprise') {
            return response()->json([
                'remaining' => -1,
                'limit' => -1,
                'plan' => 'enterprise',
                'plan_name' => $name,
            ]);
        }

        $limit = $plan === 'paid'
            ? (int) env('EDITOR_GENERATIVE_LIMIT_PAID', 100)
            : (int) env('EDITOR_GENERATIVE_LIMIT_FREE', 3);

        return response()->json([
            'remaining' => $limit,
            'limit' => $limit,
            'plan' => $plan,
            'plan_name' => $name,
        ]);
    }
}
