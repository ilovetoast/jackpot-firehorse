<?php

namespace App\Services\Assets;

use App\Enums\AITaskType;
use App\Exceptions\PlanLimitExceededException;
use App\Models\AIAgentRun;
use App\Models\Asset;
use App\Models\Tenant;
use App\Services\AI\Providers\OpenAIProvider;
use App\Services\AiMetadataGenerationService;
use App\Services\AiUsageService;
use App\Services\ImageEmbeddingService;
use Illuminate\Support\Facades\Log;

/**
 * Vision model suggests a normalized focal point (0–1) for photography assets — used for crops / object-position.
 * Uses gpt-4o-mini (config). Records {@see AiUsageService} + {@see AIAgentRun} like other vision calls.
 */
final class ImageFocalPointAiService
{
    public function __construct(
        private AiMetadataGenerationService $aiMetadataGenerationService,
        private OpenAIProvider $openAi,
        private AiUsageService $aiUsageService
    ) {}

    /**
     * Compute focal point from thumbnail and merge into asset metadata (does not save if unchanged).
     *
     * @param  bool  $force  Manual rerun: run even if a focal point already exists (still respects lock).
     * @return bool True if metadata was updated
     */
    public function computeAndStoreIfEligible(Asset $asset, bool $force = false, ?int $triggeredByUserId = null): bool
    {
        $tenant = Tenant::find($asset->tenant_id);
        if (! $tenant) {
            return false;
        }

        if (($tenant->settings['ai_enabled'] ?? true) === false) {
            return false;
        }

        if (config('ai.photography_focal_point.enabled', true) === false) {
            return false;
        }
        if (config('ai.photography_focal_point.require_preferred_thumbnails', false)
            && ! config('assets.thumbnail.preferred.enabled', false)) {
            return false;
        }

        if (! $force && ($tenant->settings['ai_auto_focal_point_photography'] ?? false) !== true) {
            return false;
        }

        if (! ImageEmbeddingService::isImageMimeType($asset->mime_type, $asset->original_filename)) {
            return false;
        }

        $asset->loadMissing('category');
        $slug = $asset->category?->slug;
        if ($slug !== 'photography') {
            return false;
        }

        $meta = $asset->metadata ?? [];
        if (! empty($meta['focal_point_locked'])) {
            return false;
        }
        if (! $force && isset($meta['focal_point']['x'], $meta['focal_point']['y']) && is_numeric($meta['focal_point']['x']) && is_numeric($meta['focal_point']['y'])) {
            return false;
        }

        $imageDataUrl = $this->aiMetadataGenerationService->fetchThumbnailForVisionAnalysis($asset);
        if ($imageDataUrl === null || $imageDataUrl === '') {
            Log::info('[ImageFocalPointAi] Skipped — no thumbnail for vision', ['asset_id' => $asset->id]);

            return false;
        }

        try {
            $this->aiUsageService->checkUsage($tenant, 'photography_focal_point', 1);
        } catch (PlanLimitExceededException $e) {
            Log::info('[ImageFocalPointAi] Skipped — plan / credits', [
                'asset_id' => $asset->id,
                'message' => $e->getMessage(),
            ]);

            return false;
        }

        $modelName = config('ai.models.gpt-4o-mini.model_name', 'gpt-4o-mini');
        $subjectMode = $this->resolveFocalSubjectMode($asset, $tenant);
        $directive = $this->focalDirectiveForMode($subjectMode);
        $fieldsHint = $this->focalMetadataFieldsHint($asset);

        $prompt = <<<PROMPT
You are helping crop photography for a digital asset manager.

The image is shown as a thumbnail. Estimate where the MAIN subject should be for smart crops (object-position).

{$directive}

{$fieldsHint}

Return JSON only with this exact shape:
{"x":0.35,"y":0.28}

Rules:
- x and y are normalized 0.0 to 1.0 relative to the image width and height (left/top = 0, right/bottom = 1).
- Place the point on the visual center of the chosen main subject (not necessarily the geometric center of the frame).
PROMPT;

        $agentRun = AIAgentRun::create([
            'agent_id' => 'photography_focal_point',
            'agent_name' => 'Photography focal point',
            'triggering_context' => 'tenant',
            'environment' => app()->environment(),
            'tenant_id' => $tenant->id,
            'user_id' => $triggeredByUserId,
            'task_type' => AITaskType::PHOTOGRAPHY_FOCAL_POINT,
            'entity_type' => Asset::class,
            'entity_id' => $asset->id,
            'model_used' => $modelName,
            'tokens_in' => 0,
            'tokens_out' => 0,
            'estimated_cost' => 0,
            'status' => 'failed',
            'started_at' => now(),
            'metadata' => [
                'asset_id' => $asset->id,
                'force' => $force,
                'focal_subject_mode' => $subjectMode,
            ],
        ]);

        try {
            $response = $this->openAi->analyzeImage($imageDataUrl, $prompt, [
                'model' => $modelName,
                'max_tokens' => 120,
                'response_format' => ['type' => 'json_object'],
            ]);
        } catch (\Throwable $e) {
            $agentRun->markAsFailed($e->getMessage(), array_merge($agentRun->metadata ?? [], [
                'error_class' => $e::class,
            ]));
            Log::warning('[ImageFocalPointAi] Vision call failed', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        $text = $response['text'] ?? '';
        $decoded = json_decode($text, true);
        if (! is_array($decoded)) {
            $agentRun->markAsFailed('Invalid JSON from model', ['raw_text' => $text]);
            Log::warning('[ImageFocalPointAi] Invalid JSON from model', ['asset_id' => $asset->id, 'text' => $text]);

            return false;
        }

        $x = isset($decoded['x']) ? (float) $decoded['x'] : null;
        $y = isset($decoded['y']) ? (float) $decoded['y'] : null;
        if ($x === null || $y === null) {
            $agentRun->markAsFailed('Missing x/y in model JSON');

            return false;
        }

        $x = max(0.0, min(1.0, $x));
        $y = max(0.0, min(1.0, $y));

        $tokensIn = (int) ($response['tokens_in'] ?? 0);
        $tokensOut = (int) ($response['tokens_out'] ?? 0);
        $actualModel = (string) ($response['model'] ?? $modelName);
        $cost = $this->openAi->calculateCost($tokensIn, $tokensOut, $actualModel);

        $meta = $asset->metadata ?? [];
        $meta['focal_point'] = ['x' => $x, 'y' => $y];
        $meta['focal_point_source'] = 'ai';
        $meta['focal_point_ai_at'] = now()->toIso8601String();

        $asset->update(['metadata' => $meta]);

        $agentRun->markAsSuccessful(
            $tokensIn,
            $tokensOut,
            $cost,
            array_merge($agentRun->metadata ?? [], [
                'asset_id' => $asset->id,
                'resolved_model' => $actualModel,
            ]),
            'info',
            1.0,
            'Photography focal point'
        );

        try {
            $this->aiUsageService->trackUsageWithCost(
                $tenant,
                'photography_focal_point',
                1,
                $cost,
                $tokensIn,
                $tokensOut,
                $actualModel
            );
        } catch (PlanLimitExceededException $e) {
            Log::warning('[ImageFocalPointAi] Usage tracking failed after success (budget race)', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);
        }

        Log::info('[ImageFocalPointAi] Stored focal point', [
            'asset_id' => $asset->id,
            'x' => $x,
            'y' => $y,
            'cost_usd' => $cost,
            'agent_run_id' => $agentRun->id,
        ]);

        return true;
    }

    /**
     * How to prioritize product vs people: auto (infer), product, or people.
     * Order: per-asset metadata → tenant default → Subject / Photo Type fields → balanced.
     */
    private function resolveFocalSubjectMode(Asset $asset, Tenant $tenant): string
    {
        $meta = $asset->metadata ?? [];
        $override = $meta['focal_point_ai_subject'] ?? null;
        if (in_array($override, ['product', 'people'], true)) {
            return $override;
        }

        $tenantDefault = $tenant->settings['ai_focal_point_subject'] ?? 'auto';
        if (in_array($tenantDefault, ['product', 'people'], true)) {
            return $tenantDefault;
        }

        $fields = $this->metadataFieldsArray($asset);
        $subject = strtolower((string) ($this->metadataFieldString($fields, 'subject_type') ?? ''));
        $photo = strtolower((string) ($this->metadataFieldString($fields, 'photo_type') ?? ''));

        // Photo Type is usually the stronger shoot-intent signal than Subject alone.
        if ($photo === 'portrait') {
            return 'people';
        }
        if (in_array($photo, ['product', 'flat_lay', 'macro_detail'], true)) {
            return 'product';
        }

        if ($subject === 'person') {
            return 'people';
        }
        if (in_array($subject, ['product', 'food', 'object', 'texture'], true)) {
            return 'product';
        }

        return 'balanced';
    }

    /**
     * @return 'product'|'people'|'balanced'
     */
    private function focalDirectiveForMode(string $mode): string
    {
        return match ($mode) {
            'product' => 'Directive: PRODUCT / MERCHANDISE FIRST. Center on the product, apparel, packaging, or key object being sold or featured. If a model is visible, prefer the product (e.g. garment, item held, worn piece) over the face unless the face alone is clearly the hero.',
            'people' => 'Directive: PEOPLE FIRST. When faces or bodies are visible, center on the face/eyes or the primary person. If the shot is clearly portrait-style, favor the face.',
            default => 'Directive: BALANCED. Infer the commercial hero for this image. Do not assume faces: for catalog, e-commerce, or product-on-model shots, center on the product when it is the main subject. For portraits or people-first lifestyle shots, center on people. For ambiguous mixed scenes, choose the most important center of interest for a marketing thumbnail.',
        };
    }

    private function focalMetadataFieldsHint(Asset $asset): string
    {
        $fields = $this->metadataFieldsArray($asset);
        $subject = $this->metadataFieldString($fields, 'subject_type');
        $photo = $this->metadataFieldString($fields, 'photo_type');
        if ($subject === null && $photo === null) {
            return 'Catalog hints: none (no Subject / Photo Type on the asset yet).';
        }
        $parts = [];
        if ($subject !== null && $subject !== '') {
            $parts[] = 'Subject field='.$subject;
        }
        if ($photo !== null && $photo !== '') {
            $parts[] = 'Photo Type field='.$photo;
        }

        return 'Catalog hints (from metadata if set): '.implode(', ', $parts).'.';
    }

    /**
     * @return array<string, mixed>
     */
    private function metadataFieldsArray(Asset $asset): array
    {
        $meta = $asset->metadata ?? [];
        $fields = $meta['fields'] ?? [];

        return is_array($fields) ? $fields : [];
    }

    private function metadataFieldString(array $fields, string $key): ?string
    {
        $val = $fields[$key] ?? null;
        if (is_string($val) && $val !== '') {
            return $val;
        }
        if (is_array($val) && isset($val['value'])) {
            return is_string($val['value']) && $val['value'] !== '' ? $val['value'] : null;
        }

        return null;
    }
}
