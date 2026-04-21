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
        $prompt = <<<'PROMPT'
You are helping crop photography for a digital asset manager.

The image is shown as a thumbnail. Estimate where the MAIN subject is (usually a face, product hero, or key figure).
Return JSON only with this exact shape:
{"x":0.35,"y":0.28}

Rules:
- x and y are normalized 0.0 to 1.0 relative to the image width and height (left/top = 0, right/bottom = 1).
- Place the point on the center of the main subject, biased toward faces when visible.
- If uncertain, choose the visual center of mass of the important content (upper third for portraits).
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
}
