<?php

namespace App\Http\Controllers;

use App\Models\CampaignAlignmentScore;
use App\Models\Collection;
use App\Models\CollectionCampaignIdentity;
use App\Services\BrandDNA\CampaignFontLibrarySyncService;
use App\Services\BrandIntelligence\Campaign\CampaignIdentityPayloadNormalizer;
use App\Services\BrandIntelligence\Campaign\CampaignScoringDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class CampaignIdentityController extends Controller
{
    public function show(Request $request, Collection $collection): InertiaResponse|JsonResponse
    {
        $campaignIdentity = $collection->campaignIdentity;

        $identityPayload = $campaignIdentity ? array_merge(
            $campaignIdentity->only([
                'id', 'campaign_name', 'campaign_slug', 'campaign_status',
                'campaign_goal', 'campaign_description', 'identity_payload',
                'readiness_status', 'scoring_enabled', 'featured_asset_id',
            ]),
            [
                'reference_count' => $campaignIdentity->campaignVisualReferences()->count(),
                'computed_readiness' => $campaignIdentity->computeReadiness(),
            ],
        ) : null;

        if ($request->wantsJson()) {
            return response()->json(['campaign_identity' => $identityPayload]);
        }

        $collectionImages = $collection->assets()
            ->where('assets.status', \App\Enums\AssetStatus::VISIBLE)
            ->whereNull('assets.deleted_at')
            ->where('assets.mime_type', 'like', 'image/%')
            ->orderByDesc('asset_collections.created_at')
            ->limit(50)
            ->get(['assets.id', 'assets.title', 'assets.original_filename', 'assets.metadata', 'assets.thumbnail_status'])
            ->map(fn ($a) => [
                'id' => $a->id,
                'title' => $a->title ?: $a->original_filename,
                'thumbnail_url' => $a->deliveryUrl(\App\Support\AssetVariant::THUMB_SMALL, \App\Support\DeliveryContext::AUTHENTICATED) ?: null,
            ])
            ->filter(fn ($a) => $a['thumbnail_url'])
            ->values()
            ->all();

        return Inertia::render('Collections/CampaignIdentity', [
            'collection' => $collection->only('id', 'name', 'slug', 'brand_id'),
            'campaign_identity' => $identityPayload,
            'collection_images' => $collectionImages,
        ]);
    }

    public function store(Request $request, Collection $collection): JsonResponse
    {
        $validated = $request->validate([
            'campaign_name' => 'required|string|max:255',
            'campaign_slug' => 'nullable|string|max:255',
            'campaign_status' => 'nullable|string|in:draft,active,completed,archived',
            'campaign_goal' => 'nullable|string|max:5000',
            'campaign_description' => 'nullable|string|max:5000',
            'identity_payload' => 'nullable|array',
            'scoring_enabled' => 'nullable|boolean',
            'featured_asset_id' => 'nullable|string|max:36',
        ]);

        $payload = CampaignIdentityPayloadNormalizer::normalize($validated['identity_payload'] ?? []);

        $campaignIdentity = CollectionCampaignIdentity::updateOrCreate(
            ['collection_id' => $collection->id],
            [
                'campaign_name' => $validated['campaign_name'],
                'campaign_slug' => $validated['campaign_slug'] ?? null,
                'campaign_status' => $validated['campaign_status'] ?? 'draft',
                'campaign_goal' => $validated['campaign_goal'] ?? null,
                'campaign_description' => $validated['campaign_description'] ?? null,
                'identity_payload' => $payload,
                'scoring_enabled' => $validated['scoring_enabled'] ?? false,
                'featured_asset_id' => $validated['featured_asset_id'] ?? null,
                'created_by' => $request->user()?->id,
            ]
        );

        $campaignIdentity->refreshReadiness();

        $collection->loadMissing('brand');
        app(CampaignFontLibrarySyncService::class)->syncFromCollection($collection, $payload);

        if ($campaignIdentity->isScorable()) {
            CampaignScoringDispatcher::rescoreCollectionAssets($collection);
        }

        return response()->json([
            'campaign_identity' => array_merge(
                $campaignIdentity->fresh()->only([
                    'id', 'campaign_name', 'campaign_slug', 'campaign_status',
                    'campaign_goal', 'campaign_description', 'identity_payload',
                    'readiness_status', 'scoring_enabled',
                ]),
                ['computed_readiness' => $campaignIdentity->computeReadiness()],
            ),
        ]);
    }

    public function update(Request $request, Collection $collection): JsonResponse
    {
        $campaignIdentity = $collection->campaignIdentity;
        if (! $campaignIdentity) {
            return response()->json(['error' => 'No campaign identity exists for this collection'], 404);
        }

        $validated = $request->validate([
            'campaign_name' => 'sometimes|required|string|max:255',
            'campaign_slug' => 'nullable|string|max:255',
            'campaign_status' => 'nullable|string|in:draft,active,completed,archived',
            'campaign_goal' => 'nullable|string|max:5000',
            'campaign_description' => 'nullable|string|max:5000',
            'identity_payload' => 'nullable|array',
            'scoring_enabled' => 'nullable|boolean',
            'featured_asset_id' => 'nullable|string|max:36',
        ]);

        $updates = [];

        if (array_key_exists('campaign_name', $validated)) {
            $updates['campaign_name'] = $validated['campaign_name'];
        }
        if (array_key_exists('campaign_slug', $validated)) {
            $updates['campaign_slug'] = $validated['campaign_slug'];
        }
        if (array_key_exists('campaign_status', $validated)) {
            $updates['campaign_status'] = $validated['campaign_status'];
        }
        if (array_key_exists('campaign_goal', $validated)) {
            $updates['campaign_goal'] = $validated['campaign_goal'];
        }
        if (array_key_exists('campaign_description', $validated)) {
            $updates['campaign_description'] = $validated['campaign_description'];
        }
        if (array_key_exists('identity_payload', $validated)) {
            $updates['identity_payload'] = CampaignIdentityPayloadNormalizer::normalize($validated['identity_payload'] ?? []);
        }
        if (array_key_exists('scoring_enabled', $validated)) {
            $updates['scoring_enabled'] = (bool) $validated['scoring_enabled'];
        }
        if (array_key_exists('featured_asset_id', $validated)) {
            $updates['featured_asset_id'] = $validated['featured_asset_id'];
        }

        if (! empty($updates)) {
            $campaignIdentity->update($updates);
        }

        $campaignIdentity->refreshReadiness();

        $collection->loadMissing('brand');
        app(CampaignFontLibrarySyncService::class)->syncFromCollection($collection, $campaignIdentity->identity_payload ?? []);

        $payloadChanged = array_key_exists('identity_payload', $updates);
        $scoringChanged = array_key_exists('scoring_enabled', $updates);

        if (($payloadChanged || $scoringChanged) && $campaignIdentity->isScorable()) {
            CampaignScoringDispatcher::rescoreCollectionAssets($collection);
        }

        return response()->json([
            'campaign_identity' => array_merge(
                $campaignIdentity->fresh()->only([
                    'id', 'campaign_name', 'campaign_slug', 'campaign_status',
                    'campaign_goal', 'campaign_description', 'identity_payload',
                    'readiness_status', 'scoring_enabled',
                ]),
                ['computed_readiness' => $campaignIdentity->computeReadiness()],
            ),
        ]);
    }

    /**
     * POST /collections/{collection}/campaign/suggest-field
     * AI-assisted suggestion for a campaign identity field (goal, description).
     */
    public function suggestCampaignField(Request $request, Collection $collection): JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');

        $usageService = app(\App\Services\AiUsageService::class);
        try {
            $usageService->checkUsage($tenant, 'suggestions');
        } catch (\App\Exceptions\PlanLimitExceededException $e) {
            return response()->json(['error' => 'Monthly AI suggestion limit reached for your plan.'], 429);
        }

        $validated = $request->validate([
            'field_path' => 'required|string|in:campaign_goal,campaign_description',
            'mode' => 'nullable|string|in:suggest,improve',
            'current_value' => 'nullable|string|max:5000',
        ]);

        $fieldPath = $validated['field_path'];
        $mode = $validated['mode'] ?? 'suggest';
        $currentValue = $validated['current_value'] ?? null;
        $campaignIdentity = $collection->campaignIdentity;

        $contextParts = array_filter([
            'Collection name' => $collection->name,
            'Brand name' => $brand?->name,
            'Campaign name' => $campaignIdentity?->campaign_name,
            'Campaign status' => $campaignIdentity?->campaign_status,
            'Campaign goal' => $campaignIdentity?->campaign_goal,
            'Campaign description' => $campaignIdentity?->campaign_description,
        ]);

        if ($brand) {
            $brand->loadMissing('brandModel.activeVersion');
            $dna = $brand->brandModel?->activeVersion?->model_payload ?? [];
            $identity = $dna['identity'] ?? [];
            if (! empty($identity['mission'])) {
                $contextParts['Brand mission'] = is_array($identity['mission']) ? ($identity['mission']['value'] ?? '') : $identity['mission'];
            }
            if (! empty($identity['industry'])) {
                $contextParts['Industry'] = is_array($identity['industry']) ? ($identity['industry']['value'] ?? '') : $identity['industry'];
            }
            if (! empty($identity['target_audience'])) {
                $contextParts['Target audience'] = is_array($identity['target_audience']) ? ($identity['target_audience']['value'] ?? '') : $identity['target_audience'];
            }
        }

        $contextBlock = collect($contextParts)
            ->map(fn ($v, $k) => "{$k}: {$v}")
            ->implode("\n");

        $fieldDefs = [
            'campaign_goal' => [
                'label' => 'campaign goal or intent',
                'suggest_instruction' => 'Return ONLY a concise 1-2 sentence campaign goal. No JSON, no quotes. Example: Drive 30% more online sales during the holiday season by targeting existing customers with exclusive early-access deals.',
                'improve_instruction' => 'Return ONLY an improved 1-2 sentence campaign goal. Make it more specific, measurable, and compelling. No JSON, no quotes.',
            ],
            'campaign_description' => [
                'label' => 'campaign description',
                'suggest_instruction' => 'Return ONLY a concise 2-3 sentence campaign description. No JSON, no quotes. Example: A seasonal push leveraging bold holiday visuals and urgency-driven messaging across digital channels. Focuses on limited-time bundles and loyalty rewards to convert existing customers.',
                'improve_instruction' => 'Return ONLY an improved 2-3 sentence campaign description. Sharpen the language, strengthen clarity, and make it more actionable. No JSON, no quotes.',
            ],
        ];

        $def = $fieldDefs[$fieldPath];

        if ($mode === 'improve' && $currentValue) {
            $instruction = $def['improve_instruction'];
            $prompt = <<<PROMPT
You are a senior marketing strategist. The user has written a draft {$def['label']} and wants you to improve it.

CONTEXT:
{$contextBlock}

CURRENT DRAFT:
{$currentValue}

TASK: Improve this {$def['label']}.
{$instruction}
Keep the original intent but make it stronger, clearer, and more professional. Return ONLY the improved value.
PROMPT;
        } else {
            $instruction = $def['suggest_instruction'];
            $prompt = <<<PROMPT
You are a senior marketing strategist. Based on the context below, suggest a {$def['label']} for this campaign.

CONTEXT:
{$contextBlock}

TASK: Suggest a {$def['label']}.
{$instruction}
Be specific and tailored to this brand and campaign. Return ONLY the value.
PROMPT;
        }

        try {
            $ai = app(\App\Services\AI\Providers\OpenAIProvider::class);
            $result = $ai->generateText($prompt, [
                'model' => 'gpt-4o-mini',
                'max_tokens' => 300,
                'temperature' => 0.7,
            ]);
            $text = trim($result['text'] ?? '', " \t\n\r\"'");

            $usageService->trackUsageWithCost(
                $tenant,
                'suggestions',
                1,
                ($result['tokens_in'] ?? 0) * 0.00000015 + ($result['tokens_out'] ?? 0) * 0.0000006,
                $result['tokens_in'] ?? null,
                $result['tokens_out'] ?? null,
                $result['model'] ?? 'gpt-4o-mini'
            );

            return response()->json(['suggestion' => $text]);
        } catch (\App\Exceptions\PlanLimitExceededException $e) {
            return response()->json(['error' => 'AI suggestion limit reached for your plan.'], 429);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['error' => 'Failed to generate suggestion. Please try again.'], 500);
        }
    }

    /**
     * API endpoint for fetching campaign alignment for an asset in a collection context.
     */
    public function assetCampaignAlignment(string $assetId, int $collectionId): JsonResponse
    {
        $asset = \App\Models\Asset::query()->find($assetId);
        if (! $asset) {
            return response()->json(['error' => 'Asset not found'], 404);
        }

        $payload = $asset->campaignAlignmentPayloadForFrontend($collectionId);

        return response()->json($payload);
    }
}
