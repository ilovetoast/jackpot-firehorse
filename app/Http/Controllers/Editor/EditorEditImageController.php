<?php

namespace App\Http\Controllers\Editor;

use App\Enums\AITaskType;
use App\Exceptions\PlanLimitExceededException;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Composition;
use App\Models\Tenant;
use App\Services\AIConfigService;
use App\Services\AIService;
use App\Services\AiUsageService;
use App\Support\EditorAssetOriginalBytesLoader;
use App\Support\EditorGeminiInlineImagePreparer;
use App\Support\EditorOpenAiImageNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * POST /app/api/edit-image — AI edit of an existing editor image (new result URL; original asset unchanged).
 */
class EditorEditImageController extends Controller
{
    private const PROXY_CACHE_PREFIX = 'editor_gen_proxy:';

    public function __construct(
        protected AIService $aiService,
        protected AiUsageService $aiUsageService,
        protected AIConfigService $aiConfigService
    ) {}

    public function edit(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            /** When asset_id is set, the server loads the original file — do not rely on thumbnail URLs. */
            'image_url' => 'nullable|string|max:8192',
            'instruction' => 'required|string|max:8000',
            'brand_context' => 'nullable|array',
            'composition_id' => 'nullable|integer|min:1',
            'asset_id' => 'nullable|uuid',
            'brand_id' => 'nullable|integer',
            /** Registry key from config ai.models (e.g. gpt-image-1, gemini-2.5-flash-image). */
            'model_key' => 'nullable|string|max:64',
        ]);

        $hasAssetId = ! empty($validated['asset_id']);
        $hasImageUrl = isset($validated['image_url']) && trim((string) $validated['image_url']) !== '';
        if (! $hasAssetId && ! $hasImageUrl) {
            return response()->json(['message' => 'Provide either asset_id or image_url.'], 422);
        }

        $instruction = trim((string) $validated['instruction']);
        if ($instruction === '') {
            return response()->json(['message' => 'Instruction is required.'], 422);
        }

        $tenant = app('tenant');
        if (! $tenant instanceof Tenant) {
            return response()->json(['message' => 'Tenant context required.'], 422);
        }

        if (! empty($validated['composition_id'])) {
            $compositionId = (int) $validated['composition_id'];
            $brand = app('brand');
            $scoped = Composition::query()
                ->where('id', $compositionId)
                ->where('tenant_id', $tenant->id);
            if ($brand && isset($brand->id)) {
                $scoped->where('brand_id', $brand->id);
            }
            if (! $scoped->exists()) {
                return response()->json(['message' => 'Invalid composition for this workspace.'], 422);
            }
        }

        $modelKey = trim((string) ($validated['model_key'] ?? ''));
        if ($modelKey === '') {
            $modelKey = 'gpt-image-1';
        }

        // Do not use config("ai.models.{$modelKey}") — Laravel treats dots in keys as nesting
        // (gemini-2.5-flash-image breaks). Same resolution as generate-image via AIConfigService.
        $modelConfig = $this->aiConfigService->getModelConfig($modelKey);
        if (! is_array($modelConfig) || ! ($modelConfig['active'] ?? true)) {
            return response()->json(['message' => "Unknown or inactive model '{$modelKey}'."], 422);
        }

        $allowed = config('ai.generative_editor.allowed_model_keys', []);
        if ($allowed !== [] && ! in_array($modelKey, $allowed, true)) {
            return response()->json(['message' => "Model '{$modelKey}' is not allowed for the editor."], 422);
        }

        $caps = $modelConfig['capabilities'] ?? [];
        if (! in_array('image_generation', $caps, true)) {
            return response()->json(['message' => 'Selected model does not support image generation.'], 422);
        }

        $providerName = (string) ($modelConfig['provider'] ?? 'openai');
        $hasOpenAi = trim((string) config('ai.openai.api_key', '')) !== '';
        $hasGemini = trim((string) config('ai.gemini.api_key', '')) !== '';

        $canRunSelected = ($providerName === 'openai' && $hasOpenAi)
            || ($providerName === 'gemini' && $hasGemini);

        if (! $canRunSelected) {
            if (! $hasOpenAi && ! $hasGemini) {
                return response()->json([
                    'image_url' => $this->stubProxyUrl($instruction),
                ]);
            }

            return response()->json([
                'message' => $providerName === 'gemini'
                    ? 'GEMINI_API_KEY is not configured. Set it in .env or choose GPT Image 1 (OpenAI).'
                    : 'OPENAI_API_KEY is not configured. Set it in .env or choose a Gemini (Nano Banana) model.',
            ], 422);
        }

        $asset = null;
        $imageUrlToLoad = null;

        if ($hasAssetId) {
            $brand = app('brand');
            $assetQuery = Asset::query()
                ->whereKey($validated['asset_id'])
                ->where('tenant_id', $tenant->id);
            if ($brand && isset($brand->id)) {
                $assetQuery->where('brand_id', $brand->id);
            }
            $asset = $assetQuery->first();
            if (! $asset) {
                return response()->json(['message' => 'Asset not found.'], 404);
            }
            Gate::authorize('view', $asset);
        } else {
            $imageUrlToLoad = trim((string) $validated['image_url']);
        }

        try {
            $loaded = $this->loadEditorImageSource($request, $imageUrlToLoad, $asset);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $binary = $loaded['binary'];
        $detectedMime = $loaded['mime'];

        if ($providerName === 'openai') {
            try {
                $binary = EditorOpenAiImageNormalizer::toPngForOpenAiEdits($binary, 0, $detectedMime);
            } catch (\InvalidArgumentException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            } catch (\RuntimeException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
            $mimeForPayload = 'image/png';
        } elseif ($providerName === 'gemini') {
            try {
                $prepared = EditorGeminiInlineImagePreparer::prepare($binary, $detectedMime);
                $binary = $prepared['binary'];
                $mimeForPayload = $prepared['mime_type'];
            } catch (\InvalidArgumentException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            } catch (\RuntimeException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        } else {
            $mimeForPayload = $detectedMime;
        }

        try {
            $this->aiUsageService->checkUsage($tenant, 'generative_editor_images');
        } catch (PlanLimitExceededException $e) {
            return response()->json(['message' => 'Monthly limit reached'], 429);
        }

        $fullPrompt = $this->buildEditPrompt($instruction, $validated['brand_context'] ?? null);

        $options = [
            'model' => $modelKey,
            'image_binary' => $binary,
            'mime_type' => $mimeForPayload,
            'tenant' => $tenant,
            'user' => $user,
            'triggering_context' => 'user',
        ];
        if (isset($validated['brand_id'])) {
            $options['brand_id'] = (int) $validated['brand_id'];
        }
        if (! empty($validated['composition_id'])) {
            $options['composition_id'] = (string) (int) $validated['composition_id'];
        }
        if (! empty($validated['asset_id'])) {
            $options['asset_id'] = $validated['asset_id'];
        }

        try {
            $result = $this->aiService->executeEditorImageEditAgent(
                'editor_edit_image',
                AITaskType::EDITOR_EDIT_IMAGE,
                $fullPrompt,
                $options
            );
        } catch (\Throwable $e) {
            Log::warning('editor.edit_image_failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => $e->getMessage() !== '' ? $e->getMessage() : 'Image edit failed',
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
                $result['resolved_model_key'] ?? 'gpt-image-1'
            );
        } catch (PlanLimitExceededException $e) {
            return response()->json(['message' => 'Monthly limit reached'], 429);
        }

        return response()->json([
            'image_url' => $this->registerProxyUrl((string) $result['image_ref']),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $brandContext
     */
    private function buildEditPrompt(string $userInstruction, ?array $brandContext): string
    {
        $lines = [
            'Modify the provided image according to the instruction below.',
            '',
            'Instruction:',
            $userInstruction,
            '',
            'Rules:',
            '- Keep the subject, identity, pose, and composition EXACTLY the same',
            '- Do NOT change the person or main object',
            '- Only modify the requested elements',
            '- Preserve realism and lighting consistency',
        ];

        if (is_array($brandContext) && $brandContext !== []) {
            $style = isset($brandContext['visual_style']) ? trim((string) $brandContext['visual_style']) : '';
            $tone = $brandContext['tone'] ?? null;
            $toneStr = '';
            if (is_array($tone)) {
                $toneStr = implode(', ', array_map(static fn ($t) => (string) $t, $tone));
            }
            if ($style !== '' || $toneStr !== '') {
                $lines[] = '';
                $lines[] = 'Brand context:';
                if ($style !== '') {
                    $lines[] = '- Style: '.$style;
                }
                if ($toneStr !== '') {
                    $lines[] = '- Tone: '.$toneStr;
                }
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Load bytes (storage for DAM assets, else URL/data/proxy), validate image MIME, then log.
     * Normalizers (GD/Imagick) run in the controller after this returns.
     *
     * @return array{binary: string, mime: string}
     *
     * @throws \InvalidArgumentException
     */
    private function loadEditorImageSource(Request $request, ?string $imageUrl, ?Asset $asset): array
    {
        if ($asset !== null) {
            $binary = EditorAssetOriginalBytesLoader::loadFromStorage($asset);
            $urlForLog = 'storage:'.$asset->storage_root_path;
        } else {
            $binary = $this->loadRawImageBytes($request, (string) $imageUrl);
            $urlForLog = (string) $imageUrl;
        }

        if (strlen($urlForLog) > 4096) {
            $urlForLog = substr($urlForLog, 0, 4096).'…';
        }

        $mime = $this->detectMimeFromBuffer($binary);
        if (! str_starts_with($mime, 'image/')) {
            Log::error('Invalid image MIME', [
                'mime' => $mime,
                'preview' => substr($binary, 0, 100),
            ]);

            throw new \InvalidArgumentException('Invalid image data (not an image)');
        }

        $this->logAiEditImageDebug($urlForLog, $binary);
        Log::info('Detected MIME', ['mime' => $mime]);

        return ['binary' => $binary, 'mime' => $mime];
    }

    private function logAiEditImageDebug(string $urlForLog, string $binary): void
    {
        if (str_starts_with($urlForLog, 'storage:')) {
            $path = substr($urlForLog, strlen('storage:'));
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        } else {
            $path = parse_url($urlForLog, PHP_URL_PATH);
            $ext = is_string($path) ? strtolower(pathinfo($path, PATHINFO_EXTENSION)) : '';
        }
        if ($ext === '' && str_starts_with($urlForLog, 'data:image')) {
            $ext = 'data-url';
        }

        Log::info('AI Edit Image Debug', [
            'url' => $urlForLog,
            'extension' => $ext !== '' ? $ext : '(none)',
            'filesize' => strlen($binary),
        ]);
    }

    private function detectMimeFromBuffer(string $binary): string
    {
        if (class_exists(\finfo::class)) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->buffer($binary);
            if (is_string($mime) && $mime !== '') {
                return $mime === 'image/jpg' ? 'image/jpeg' : $mime;
            }
        }

        return 'application/octet-stream';
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function loadRawImageBytes(Request $request, string $imageUrl): string
    {
        $t = trim($imageUrl);
        if ($t === '') {
            throw new \InvalidArgumentException('Image URL is empty.');
        }

        if (str_starts_with($t, 'data:image')) {
            if (preg_match('#^data:image/[^;]+;base64,(.+)$#', $t, $m)) {
                $binary = base64_decode($m[1], true);
                if ($binary !== false && strlen($binary) > 0) {
                    return $binary;
                }
            }

            throw new \InvalidArgumentException('Invalid data URL image.');
        }

        if (preg_match('#/app/api/generate-image/proxy/([a-f0-9]{32})#', $t, $m)) {
            $payload = Cache::get(self::PROXY_CACHE_PREFIX.$m[1]);
            if (is_string($payload) && str_starts_with($payload, 'data:image')) {
                if (preg_match('#^data:image/[^;]+;base64,(.+)$#', $payload, $m2)) {
                    $binary = base64_decode($m2[1], true);
                    if ($binary !== false && strlen($binary) > 0) {
                        return $binary;
                    }
                }
            }
            if (is_string($payload) && (str_starts_with($payload, 'http://') || str_starts_with($payload, 'https://'))) {
                return $this->fetchRemoteImageBytes($request, $payload);
            }

            throw new \InvalidArgumentException('Could not resolve editor image from proxy URL.');
        }

        if (str_starts_with($t, 'http://') || str_starts_with($t, 'https://')) {
            return $this->fetchRemoteImageBytes($request, $t);
        }

        throw new \InvalidArgumentException('Image URL must be a data URL, generate-image proxy URL, or https URL.');
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function fetchRemoteImageBytes(Request $request, string $url): string
    {
        $this->assertFetchableImageUrl($url);

        $pending = Http::timeout(20)->withHeaders(['Accept' => 'image/*']);
        if ($this->isSameAppUrl($url)) {
            $host = parse_url($url, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                $pending = $pending->withCookies($request->cookies->all(), $host);
            }
        }

        $remote = $pending->get($url);

        if (! $remote->successful()) {
            throw new \InvalidArgumentException('Failed to download image.');
        }

        $contents = $remote->body();
        if ($contents === '') {
            throw new \InvalidArgumentException('Downloaded image is empty.');
        }

        return $contents;
    }

    private function isSameAppUrl(string $url): bool
    {
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        $targetHost = parse_url($url, PHP_URL_HOST);
        $a = is_string($appHost) ? strtolower($appHost) : '';
        $b = is_string($targetHost) ? strtolower($targetHost) : '';

        return $a !== '' && $b !== '' && $a === $b;
    }

    private function assertFetchableImageUrl(string $url): void
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            throw new \InvalidArgumentException('Invalid image URL.');
        }
        $host = strtolower($host);
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        $ok =
            ($appHost && $host === strtolower($appHost))
            || str_contains($host, 'amazonaws.com')
            || str_contains($host, 'cloudfront.net')
            || str_contains($host, 'blob.core.windows.net')
            || str_contains($host, 'openai.com')
            || str_contains($host, 'oaiusercontent.com')
            || str_contains($host, 'googleusercontent.com');

        if (! $ok) {
            throw new \InvalidArgumentException('Image host is not allowed.');
        }
    }

    private function registerProxyUrl(string $urlOrDataUrl): string
    {
        $token = bin2hex(random_bytes(16));
        Cache::put(self::PROXY_CACHE_PREFIX.$token, $urlOrDataUrl, now()->addMinutes(45));

        return route('api.editor.generate-image.proxy', ['token' => $token], absolute: true);
    }

    private function stubProxyUrl(string $instruction): string
    {
        $safe = htmlspecialchars(mb_substr($instruction, 0, 80), ENT_QUOTES | ENT_XML1);
        $svg = <<<SVG
<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" width="1024" height="1024" viewBox="0 0 1024 1024">
  <rect fill="#4f46e5" width="1024" height="1024"/>
  <text x="512" y="480" text-anchor="middle" fill="#ffffff" font-size="36" font-family="system-ui,sans-serif">Edit (stub)</text>
  <text x="512" y="540" text-anchor="middle" fill="#e0e7ff" font-size="22" font-family="system-ui,sans-serif">{$safe}</text>
  <text x="512" y="600" text-anchor="middle" fill="#c7d2fe" font-size="18" font-family="system-ui,sans-serif">Configure OPENAI_API_KEY</text>
</svg>
SVG;

        return $this->registerProxyUrl('data:image/svg+xml;base64,'.base64_encode($svg));
    }
}
