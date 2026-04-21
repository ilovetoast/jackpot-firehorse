<?php

namespace App\Services\Editor;

use App\Enums\AITaskType;
use App\Exceptions\PlanLimitExceededException;
use App\Models\Asset;
use App\Models\Composition;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AIConfigService;
use App\Services\AIService;
use App\Services\AiUsageService;
use App\Services\EditorGenerativeImagePersistService;
use App\Support\EditorAssetOriginalBytesLoader;
use App\Support\EditorGeminiInlineImagePreparer;
use App\Support\EditorOpenAiImageNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Shared orchestration for POST /app/api/edit-image — used by {@see \App\Http\Controllers\Editor\EditorEditImageController}
 * and by {@see \App\Services\Studio\StudioCreativeSetItemProcessor} (no synthetic Request to a controller).
 */
final class EditorGenerativeImageEditService
{
    public function __construct(
        protected AIService $aiService,
        protected AiUsageService $aiUsageService,
        protected AIConfigService $aiConfigService,
        protected EditorGenerativeImagePersistService $generativeImagePersistService,
        protected EditorGenerativeImageOutputFinalizer $outputFinalizer,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     *                                           Same shape as validated HTTP input: image_url?, instruction, brand_context?, composition_id?,
     *                                           asset_id?, brand_id?, model_key?, generative_layer_uuid?
     */
    public function editFromValidated(User $user, Tenant $tenant, array $validated, ?Request $httpContext = null): EditorGenerativeImageEditOutcome
    {
        $hasAssetId = ! empty($validated['asset_id']);
        $hasImageUrl = isset($validated['image_url']) && trim((string) $validated['image_url']) !== '';
        if (! $hasAssetId && ! $hasImageUrl) {
            return new EditorGenerativeImageEditOutcome(422, ['message' => 'Provide either asset_id or image_url.']);
        }

        $instruction = trim((string) $validated['instruction']);
        if ($instruction === '') {
            return new EditorGenerativeImageEditOutcome(422, ['message' => 'Instruction is required.']);
        }

        if (! empty($validated['composition_id'])) {
            $compositionId = (int) $validated['composition_id'];
            $brand = app('brand');
            $q = Composition::query()
                ->where('id', $compositionId)
                ->where('tenant_id', $tenant->id);
            if ($brand && isset($brand->id)) {
                $q->where('brand_id', $brand->id);
            }
            $composition = $q->first();
            if ($composition === null || ! $composition->isVisibleToUser($user)) {
                return new EditorGenerativeImageEditOutcome(422, ['message' => 'Invalid composition for this workspace.']);
            }
        }

        $modelKey = trim((string) ($validated['model_key'] ?? ''));
        if ($modelKey === '') {
            $modelKey = 'gpt-image-1';
        }

        $modelConfig = $this->aiConfigService->getModelConfig($modelKey);
        if (! is_array($modelConfig) || ! ($modelConfig['active'] ?? true)) {
            return new EditorGenerativeImageEditOutcome(422, ['message' => "Unknown or inactive model '{$modelKey}'."]);
        }

        $allowed = config('ai.generative_editor.edit_allowed_model_keys', []);
        if ($allowed === []) {
            $allowed = config('ai.generative_editor.allowed_model_keys', []);
        }
        if ($allowed !== [] && ! in_array($modelKey, $allowed, true)) {
            return new EditorGenerativeImageEditOutcome(422, ['message' => "Model '{$modelKey}' is not allowed for image modification."]);
        }

        $caps = $modelConfig['capabilities'] ?? [];
        if (! in_array('image_generation', $caps, true)) {
            return new EditorGenerativeImageEditOutcome(422, ['message' => 'Selected model does not support image generation.']);
        }

        $providerName = (string) ($modelConfig['provider'] ?? 'openai');
        $hasOpenAi = trim((string) config('ai.openai.api_key', '')) !== '';
        $hasGemini = trim((string) config('ai.gemini.api_key', '')) !== '';
        $hasFlux = trim((string) config('ai.flux.api_key', '')) !== '';

        $canRunSelected = ($providerName === 'openai' && $hasOpenAi)
            || ($providerName === 'gemini' && $hasGemini)
            || ($providerName === 'flux' && $hasFlux);

        if (! $canRunSelected) {
            if (! $hasOpenAi && ! $hasGemini && ! $hasFlux) {
                return new EditorGenerativeImageEditOutcome(200, [
                    'image_url' => $this->stubProxyUrl($instruction),
                ]);
            }

            $missingKey = match ($providerName) {
                'gemini' => 'GEMINI_API_KEY is not configured. Set it in .env or choose another edit model.',
                'flux' => 'FLUX_API_KEY is not configured. Set it in .env or choose another edit model.',
                default => 'OPENAI_API_KEY is not configured. Set it in .env or choose another edit model.',
            };

            return new EditorGenerativeImageEditOutcome(422, ['message' => $missingKey]);
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
                return new EditorGenerativeImageEditOutcome(404, ['message' => 'Asset not found.']);
            }
            Gate::authorize('view', $asset);
        } else {
            $imageUrlToLoad = trim((string) $validated['image_url']);
        }

        try {
            $loaded = $this->loadEditorImageSource($httpContext, $imageUrlToLoad, $asset);
        } catch (\InvalidArgumentException $e) {
            return new EditorGenerativeImageEditOutcome(422, ['message' => $e->getMessage()]);
        }

        $binary = $loaded['binary'];
        $detectedMime = $loaded['mime'];

        if ($providerName === 'openai') {
            try {
                $binary = EditorOpenAiImageNormalizer::toPngForOpenAiEdits($binary, 0, $detectedMime);
            } catch (\InvalidArgumentException|\RuntimeException $e) {
                return new EditorGenerativeImageEditOutcome(422, ['message' => $e->getMessage()]);
            }
            $mimeForPayload = 'image/png';
        } elseif ($providerName === 'gemini' || $providerName === 'flux') {
            try {
                $prepared = EditorGeminiInlineImagePreparer::prepare($binary, $detectedMime);
                $binary = $prepared['binary'];
                $mimeForPayload = $prepared['mime_type'];
            } catch (\InvalidArgumentException|\RuntimeException $e) {
                return new EditorGenerativeImageEditOutcome(422, ['message' => $e->getMessage()]);
            }
        } else {
            $mimeForPayload = $detectedMime;
        }

        try {
            $this->aiUsageService->checkUsage($tenant, 'generative_editor_edits');
        } catch (PlanLimitExceededException $e) {
            return new EditorGenerativeImageEditOutcome(429, ['message' => 'Monthly limit reached']);
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
        if (! empty($validated['brand_context'])) {
            $options['brand_context'] = $validated['brand_context'];
        }
        if (! empty($validated['generative_layer_uuid'])) {
            $options['generative_layer_uuid'] = $validated['generative_layer_uuid'];
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

            return new EditorGenerativeImageEditOutcome(502, [
                'message' => $e->getMessage() !== '' ? $e->getMessage() : 'Image edit failed',
            ]);
        }

        try {
            $this->aiUsageService->trackUsageWithCost(
                $tenant,
                'generative_editor_edits',
                1,
                (float) ($result['cost'] ?? 0.0),
                isset($result['tokens_in']) ? (int) $result['tokens_in'] : null,
                isset($result['tokens_out']) ? (int) $result['tokens_out'] : null,
                $result['resolved_model_key'] ?? 'gpt-image-1'
            );
        } catch (PlanLimitExceededException $e) {
            return new EditorGenerativeImageEditOutcome(429, ['message' => 'Monthly limit reached']);
        }

        $persistContext = array_filter([
            'operation' => 'generative_edit',
            'composition_id' => ! empty($validated['composition_id']) ? (string) (int) $validated['composition_id'] : null,
            'generative_layer_uuid' => isset($validated['generative_layer_uuid']) ? (string) $validated['generative_layer_uuid'] : null,
            'source_asset_id' => ! empty($validated['asset_id']) ? (string) $validated['asset_id'] : null,
            'resolved_model_key' => $result['resolved_model_key'] ?? $modelKey,
            'model_key' => $modelKey,
            'brand_context_applied' => ! empty($validated['brand_context']) ? true : null,
        ], static fn ($v) => $v !== null && $v !== '');

        $final = $this->outputFinalizer->finalize(
            (string) $result['image_ref'],
            $tenant,
            $user,
            $this->generativeImagePersistService,
            $persistContext
        );

        $payload = ['image_url' => $final['image_url']];
        if ($final['asset_id'] !== null) {
            $payload['asset_id'] = $final['asset_id'];
        }

        return new EditorGenerativeImageEditOutcome(200, $payload);
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

        $hint = strtolower($userInstruction);
        if (str_contains($hint, 'transparent')
            || str_contains($hint, 'alpha')
            || str_contains($hint, 'cutout')
            || (str_contains($hint, 'background') && (str_contains($hint, 'remove') || str_contains($hint, 'white') || str_contains($hint, 'studio')))) {
            $lines[] = '- When the result should be transparent: use a real alpha channel only. Do not paint transparency as a gray-and-white checkerboard, grid, hatch, or watermark in the pixels.';
        }

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
     * @return array{binary: string, mime: string}
     *
     * @throws \InvalidArgumentException
     */
    private function loadEditorImageSource(?Request $request, ?string $imageUrl, ?Asset $asset): array
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
    private function loadRawImageBytes(?Request $request, string $imageUrl): string
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
            $payload = Cache::get(EditorGenerativeImageOutputFinalizer::PROXY_CACHE_PREFIX.$m[1]);
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
    private function fetchRemoteImageBytes(?Request $request, string $url): string
    {
        $this->assertFetchableImageUrl($url);

        $pending = Http::timeout(20)->withHeaders(['Accept' => 'image/*']);
        if ($request instanceof Request && $this->isSameAppUrl($url)) {
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

        $token = bin2hex(random_bytes(16));
        Cache::put(EditorGenerativeImageOutputFinalizer::PROXY_CACHE_PREFIX.$token, 'data:image/svg+xml;base64,'.base64_encode($svg), now()->addMinutes(45));

        return route('api.editor.generate-image.proxy', ['token' => $token], absolute: true);
    }
}
