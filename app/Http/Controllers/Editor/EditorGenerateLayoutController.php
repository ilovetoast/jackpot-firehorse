<?php

namespace App\Http\Controllers\Editor;

use App\Enums\AITaskType;
use App\Enums\AssetType;
use App\Exceptions\AIBudgetExceededException;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\Category;
use App\Models\StudioAnimationJob;
use App\Models\Tenant;
use App\Services\AIService;
use App\Services\AiUsageService;
use App\Studio\Animation\Enums\StudioAnimationStatus;
use App\Support\AssetVariant;
use App\Support\DeliveryContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * POST /app/api/generate-layout — AI layout recommendation for the generative editor.
 *
 * Assembles rich brand context (DNA, logos, scored + tagged assets grouped by role),
 * sends a structured prompt to the AI creative director agent, validates the response,
 * and returns a ready-to-render composition spec for the frontend.
 *
 * All calls go through {@see AIService::executeAgent} so tokens and cost are tracked
 * in ai_agent_runs with full audit metadata.
 */
class EditorGenerateLayoutController extends Controller
{
    private const VALID_LAYOUT_STYLES = ['product_focused', 'brand_focused', 'lifestyle', 'special'];

    private const FORMAT_REGISTRY = [
        'fb_feed_square' => ['name' => 'Facebook Feed Post', 'w' => 1080, 'h' => 1080, 'cat' => 'social_media', 'platform' => 'Facebook'],
        'fb_feed_portrait' => ['name' => 'Facebook Feed Portrait', 'w' => 1080, 'h' => 1350, 'cat' => 'social_media', 'platform' => 'Facebook'],
        'fb_stories' => ['name' => 'Facebook Stories & Reels', 'w' => 1080, 'h' => 1920, 'cat' => 'social_media', 'platform' => 'Facebook'],
        'fb_carousel' => ['name' => 'Facebook Carousel Ad', 'w' => 1080, 'h' => 1080, 'cat' => 'social_media', 'platform' => 'Facebook'],
        'fb_cover' => ['name' => 'Facebook Cover Photo', 'w' => 820, 'h' => 312, 'cat' => 'social_media', 'platform' => 'Facebook'],
        'fb_ad' => ['name' => 'Facebook Link Ad', 'w' => 1200, 'h' => 628, 'cat' => 'social_media', 'platform' => 'Facebook'],
        'ig_feed_square' => ['name' => 'Instagram Feed Post', 'w' => 1080, 'h' => 1080, 'cat' => 'social_media', 'platform' => 'Instagram'],
        'ig_feed_portrait' => ['name' => 'Instagram Portrait Post', 'w' => 1080, 'h' => 1350, 'cat' => 'social_media', 'platform' => 'Instagram'],
        'ig_stories' => ['name' => 'Instagram Stories & Reels', 'w' => 1080, 'h' => 1920, 'cat' => 'social_media', 'platform' => 'Instagram'],
        'ig_carousel' => ['name' => 'Instagram Carousel', 'w' => 1080, 'h' => 1080, 'cat' => 'social_media', 'platform' => 'Instagram'],
        'ig_landscape' => ['name' => 'Instagram Landscape Post', 'w' => 1080, 'h' => 566, 'cat' => 'social_media', 'platform' => 'Instagram'],
        'tw_post' => ['name' => 'X Post Image', 'w' => 1600, 'h' => 900, 'cat' => 'social_media', 'platform' => 'X (Twitter)'],
        'tw_card' => ['name' => 'X Card Image', 'w' => 800, 'h' => 418, 'cat' => 'social_media', 'platform' => 'X (Twitter)'],
        'tw_header' => ['name' => 'X Header Photo', 'w' => 1500, 'h' => 500, 'cat' => 'social_media', 'platform' => 'X (Twitter)'],
        'li_post' => ['name' => 'LinkedIn Post Image', 'w' => 1200, 'h' => 627, 'cat' => 'social_media', 'platform' => 'LinkedIn'],
        'li_stories' => ['name' => 'LinkedIn Stories', 'w' => 1080, 'h' => 1920, 'cat' => 'social_media', 'platform' => 'LinkedIn'],
        'li_cover' => ['name' => 'LinkedIn Company Cover', 'w' => 1128, 'h' => 191, 'cat' => 'social_media', 'platform' => 'LinkedIn'],
        'li_carousel' => ['name' => 'LinkedIn Carousel Slide', 'w' => 1080, 'h' => 1080, 'cat' => 'social_media', 'platform' => 'LinkedIn'],
        'tt_post' => ['name' => 'TikTok Post / Cover', 'w' => 1080, 'h' => 1920, 'cat' => 'social_media', 'platform' => 'TikTok'],
        'pin_standard' => ['name' => 'Pinterest Standard Pin', 'w' => 1000, 'h' => 1500, 'cat' => 'social_media', 'platform' => 'Pinterest'],
        'pin_square' => ['name' => 'Pinterest Square Pin', 'w' => 1000, 'h' => 1000, 'cat' => 'social_media', 'platform' => 'Pinterest'],
        'yt_thumbnail' => ['name' => 'YouTube Thumbnail', 'w' => 1280, 'h' => 720, 'cat' => 'social_media', 'platform' => 'YouTube'],
        'yt_banner' => ['name' => 'YouTube Channel Banner', 'w' => 2560, 'h' => 1440, 'cat' => 'social_media', 'platform' => 'YouTube'],
        'ad_leaderboard' => ['name' => 'Leaderboard', 'w' => 728, 'h' => 90, 'cat' => 'web_banners', 'platform' => 'Display Ads'],
        'ad_medium_rect' => ['name' => 'Medium Rectangle', 'w' => 300, 'h' => 250, 'cat' => 'web_banners', 'platform' => 'Display Ads'],
        'ad_large_rect' => ['name' => 'Large Rectangle', 'w' => 336, 'h' => 280, 'cat' => 'web_banners', 'platform' => 'Display Ads'],
        'ad_half_page' => ['name' => 'Half Page', 'w' => 300, 'h' => 600, 'cat' => 'web_banners', 'platform' => 'Display Ads'],
        'ad_billboard' => ['name' => 'Billboard', 'w' => 970, 'h' => 250, 'cat' => 'web_banners', 'platform' => 'Display Ads'],
        'ad_skyscraper' => ['name' => 'Wide Skyscraper', 'w' => 160, 'h' => 600, 'cat' => 'web_banners', 'platform' => 'Display Ads'],
        'ad_mobile' => ['name' => 'Mobile Banner', 'w' => 320, 'h' => 50, 'cat' => 'web_banners', 'platform' => 'Display Ads'],
        'email_header' => ['name' => 'Email Header', 'w' => 600, 'h' => 200, 'cat' => 'web_banners', 'platform' => 'Email'],
        'email_hero' => ['name' => 'Email Hero', 'w' => 600, 'h' => 400, 'cat' => 'web_banners', 'platform' => 'Email'],
        'hero_full' => ['name' => 'Hero Banner', 'w' => 1920, 'h' => 600, 'cat' => 'web_banners', 'platform' => 'Website'],
        'hero_mobile' => ['name' => 'Mobile Hero', 'w' => 750, 'h' => 1000, 'cat' => 'web_banners', 'platform' => 'Website'],
        'og_image' => ['name' => 'OG / Share Image', 'w' => 1200, 'h' => 630, 'cat' => 'web_banners', 'platform' => 'Website'],
        'slide_16_9' => ['name' => 'Widescreen (16:9)', 'w' => 1920, 'h' => 1080, 'cat' => 'presentation', 'platform' => 'Slides'],
        'slide_4_3' => ['name' => 'Standard (4:3)', 'w' => 1024, 'h' => 768, 'cat' => 'presentation', 'platform' => 'Slides'],
        'slide_16_10' => ['name' => 'Widescreen (16:10)', 'w' => 1920, 'h' => 1200, 'cat' => 'presentation', 'platform' => 'Slides'],
    ];

    private const PRODUCT_PHOTO_TYPES = ['product', 'studio', 'flat_lay', 'macro_detail'];

    private const LIFESTYLE_PHOTO_TYPES = ['lifestyle', 'editorial', 'event'];

    /**
     * Wizard background pool: "studio" is a first-class photo_type (studio lighting /
     * set) and must not be treated as synonymous with catalog product-on-white.
     * Deprioritize using explicit product / packshot signals instead.
     */
    private const BACKGROUND_DEPRIORITIZED_PHOTO_TYPES = ['product', 'flat_lay', 'macro_detail', 'macro'];

    public function __construct(
        protected AIService $aiService,
        protected AiUsageService $aiUsageService
    ) {}

    /**
     * GET /app/api/editor/ai-credit-status — lightweight monthly credit snapshot
     * for display in the editor top bar. Refreshed after each AI generation.
     *
     * Optional query param:
     *   composition_id — when present, also returns `this_composition_used`, the
     *   weighted credit total for AIAgentRun rows tied to that composition in the
     *   current calendar month. Drives the "this composition" counter in the pill.
     */
    public function creditStatus(Request $request): JsonResponse
    {
        $tenant = app('tenant');
        if (! $tenant instanceof Tenant) {
            return response()->json(['message' => 'Tenant context required.'], 422);
        }

        $status = $this->aiUsageService->getUsageStatus($tenant);
        $perFeature = $status['per_feature'] ?? [];
        $generativeEditorUsed = ($perFeature['generative_editor_layout']['credits_used'] ?? 0)
            + ($perFeature['generative_editor_images']['credits_used'] ?? 0)
            + ($perFeature['generative_editor_edits']['credits_used'] ?? 0)
            + ($perFeature['studio_animation']['credits_used'] ?? 0);

        $thisCompositionUsed = null;
        $compositionIdRaw = $request->query('composition_id');
        if ($compositionIdRaw !== null && $compositionIdRaw !== '') {
            // Compositions use bigint primary keys; accept stringified ids from the client.
            $compositionId = (string) $compositionIdRaw;
            if (ctype_digit($compositionId)) {
                $thisCompositionUsed = $this->computeCompositionCreditsThisMonth($tenant, $compositionId);
            }
        }

        return response()->json([
            'credits_used' => (int) ($status['credits_used'] ?? 0),
            'credits_cap' => (int) ($status['credits_cap'] ?? 0),
            'credits_remaining' => (int) ($status['credits_remaining'] ?? 0),
            'credits_percentage' => (float) ($status['credits_percentage'] ?? 0),
            'is_unlimited' => (bool) ($status['is_unlimited'] ?? false),
            'is_exceeded' => (bool) ($status['is_exceeded'] ?? false),
            'warning_level' => $status['warning_level'] ?? 'ok',
            'generative_editor_used' => (int) $generativeEditorUsed,
            'this_composition_used' => $thisCompositionUsed,
        ]);
    }

    /**
     * Sum weighted credits for AIAgentRun rows tied to a composition this month.
     *
     * Filtering rules:
     *   - tenant scoped (tenant_id must match)
     *   - run is tied to the composition by either:
     *       - entity_type='composition' with matching entity_id, or
     *       - metadata.generative_audit.composition_id (legacy / asset-only rows). Studio
     *         composition animation is excluded here — those credits are summed from
     *         {@see StudioAnimationJob} so we do not double-count the companion ai_agent_runs row.
     *   - started_at within current calendar month
     *   - only successful runs count — failed/skipped shouldn't charge the user
     *   - task_type mapped to its ai_credits.weights entry
     *
     * Returns 0 when no runs exist, keeping the UI simple (render "0 credits").
     */
    private function computeCompositionCreditsThisMonth(Tenant $tenant, string $compositionId): int
    {
        $start = now()->startOfMonth();
        $end = now()->endOfMonth();

        $taskToFeature = [
            AITaskType::EDITOR_LAYOUT_GENERATION => 'generative_editor_layout',
            AITaskType::EDITOR_GENERATIVE_IMAGE => 'generative_editor_images',
            AITaskType::EDITOR_EDIT_IMAGE => 'generative_editor_edits',
        ];

        $rows = DB::table('ai_agent_runs')
            ->where('tenant_id', $tenant->id)
            ->where('status', 'success')
            ->whereIn('task_type', array_keys($taskToFeature))
            ->whereBetween('started_at', [$start, $end])
            ->where(function ($outer) use ($compositionId) {
                $outer->where(function ($q) use ($compositionId) {
                    $q->where('entity_type', 'composition')
                        ->where('entity_id', $compositionId);
                })->orWhere(function ($q) use ($compositionId) {
                    // Companion rows for studio animation use generative_audit.composition_id but
                    // credits are rolled up from studio_animation_jobs below.
                    $q->where('task_type', '!=', AITaskType::STUDIO_COMPOSITION_ANIMATION)
                        ->whereRaw(
                            'CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata, \'$.generative_audit.composition_id\')) AS CHAR) = ?',
                            [$compositionId]
                        );
                });
            })
            ->select('task_type', DB::raw('COUNT(*) as call_count'))
            ->groupBy('task_type')
            ->get();

        $total = 0;
        foreach ($rows as $row) {
            $feature = $taskToFeature[$row->task_type] ?? null;
            if ($feature === null) {
                continue;
            }
            $weight = $this->aiUsageService->getCreditWeight($feature);
            $total += (int) $row->call_count * $weight;
        }

        $animationJobs = StudioAnimationJob::query()
            ->where('tenant_id', $tenant->id)
            ->where('composition_id', (int) $compositionId)
            ->where('status', StudioAnimationStatus::Complete->value)
            ->whereBetween('completed_at', [$start, $end])
            ->get(['settings_json']);

        foreach ($animationJobs as $job) {
            $settings = $job->settings_json ?? [];
            $total += (int) ($settings['credits_charged'] ?? 0);
        }

        return $total;
    }

    /**
     * GET /app/api/editor/wizard-defaults
     *
     * Returns the brand's primary logo (for any `role=logo` layers) and up to 8
     * photography candidates (for `role=background|hero_image` image layers) that
     * the Create-from-Template wizard auto-applies in step 3.
     *
     * Background candidates prefer the Photography DAM category (slug photography
     * or photographs) and scene-friendly metadata/tags; the pool also includes tag
     * matches (background, hero, photo, photography, lifestyle) and legacy
     * photo_type fallbacks when this brand has no tag hits. If nothing in that pool
     * is deliverable, the API falls back to any brand raster (including AI outputs,
     * ranked below uploads, then filename-based detection when mime_type is missing).
     * Results are ranked so lifestyle/editorial/studio (non-product) surface ahead
     * of packshots; the wizard still picks deterministically from the returned set
     * (seeded by composition id) so the output stays varied.
     *
     * Shape is aligned with DamPickerAsset (id / name / file_url / thumbnail_url /
     * width / height) so the wizard can feed results straight into the same
     * replace-image path used by the asset picker.
     */
    public function wizardDefaults(Request $request): JsonResponse
    {
        $tenant = app('tenant');
        if (! $tenant instanceof Tenant) {
            return response()->json(['message' => 'Tenant context required.'], 422);
        }

        $brand = app('brand');
        if (! $brand instanceof Brand) {
            return response()->json([
                'logo' => null,
                'background_candidates' => [],
            ]);
        }

        $logoPayload = $this->resolvePrimaryLogoPayload($brand);
        $backgroundCandidates = $this->fetchBackgroundCandidates($tenant, $brand);

        return response()->json([
            'logo' => $logoPayload,
            'background_candidates' => $backgroundCandidates,
        ]);
    }

    /**
     * Look up the brand's primary logo (logo_id) and hydrate it into a picker-
     * friendly payload. Returns null when the brand has no primary logo set.
     *
     * We resolve the Asset through `Asset::find` rather than trusting the brand's
     * `logo_path` accessor so we get the real mime type + dimensions (needed by
     * the frontend's replace-image path, which measures the image to avoid
     * aspect-ratio surprises).
     */
    private function resolvePrimaryLogoPayload(Brand $brand): ?array
    {
        $logoId = $brand->logoAssetIdForSurface('primary');
        if (! $logoId) {
            return null;
        }

        $asset = Asset::query()
            ->where('tenant_id', $brand->tenant_id)
            ->where('id', $logoId)
            ->first();
        if (! $asset) {
            return null;
        }

        $fileUrl = $asset->deliveryUrl(AssetVariant::ORIGINAL, DeliveryContext::AUTHENTICATED);
        $thumbnailUrl = $asset->deliveryUrl(AssetVariant::THUMB_MEDIUM, DeliveryContext::AUTHENTICATED)
            ?: $asset->deliveryUrl(AssetVariant::THUMB_SMALL, DeliveryContext::AUTHENTICATED)
            ?: $fileUrl;

        if (! $fileUrl) {
            return null;
        }

        return [
            'id' => (string) $asset->id,
            'name' => $asset->title ?? $asset->original_filename ?? 'Brand Logo',
            'file_url' => $fileUrl,
            'thumbnail_url' => $thumbnailUrl,
            'width' => $asset->width ? (int) $asset->width : null,
            'height' => $asset->height ? (int) $asset->height : null,
        ];
    }

    /**
     * Fetch up to 8 photography candidates suitable for a "background" layer.
     *
     * Query strategy:
     *   1. Scope to tenant + brand + image mime types.
     *   2. Union: (a) assets on this brand tagged background|hero|photo|photography|lifestyle
     *      (case-insensitive), OR (b) any asset in the Photography DAM category for
     *      this brand (so the wizard always has library photography to pick from),
     *      OR (c) when this brand has none of those tags, legacy metadata fallbacks
     *      (lifestyle/editorial/event/hero/scene) as before.
     *   3. Rank in PHP: prefer Photography category + lifestyle/editorial/event/action,
     *      studio shots that are not catalog product, and hero/background tags;
     *      deprioritize subject_type product, packshots (product/flat_lay/macro), and
     *      cutout-style tags — then take the top rows for the candidate list.
     *   4. Merge: assets tagged "photography" (any case) for this brand are merged in,
     *      then the combined list is re-ranked so the seeded wizard pick skews toward
     *      scene photography instead of product-on-white when both exist.
     *   5. Exclude logos (category_slug='logos') defensively in case a brand
     *      mistagged its logo as "hero".
     *   6. If the scored pool still yields no deliverable URLs, broaden twice:
     *      (a) same as pass 3 but include `ai_generated` image rows (ranked below
     *      uploads), then (b) allow null mime_type when the filename looks like a
     *      raster extension — fixes libraries where ingestion never set mime_type.
     *   7. If the first ranked+merged list is still empty: a single **random**
     *      deliverable still from a **Photography** DAM category (JSON category_id
     *      match, like the asset grid) and/or the `photography` / `photo` tag
     *      fallbacks, before any broad brand-raster+AI pass.
     *
     * Each returned row matches {@see \App\Pages\Editor\documentModel.DamPickerAsset}
     * so it drops into the existing replace-image path.
     *
     * @return list<array{id:string,name:string,file_url:?string,thumbnail_url:?string,width:?int,height:?int,tags:list<string>}>
     */
    private function fetchBackgroundCandidates(Tenant $tenant, Brand $brand): array
    {
        $matchTags = ['background', 'hero', 'photo', 'photography', 'lifestyle'];

        try {
            // Pass 1: tag-matched on this brand only (tenant-wide tag hits used to
            // suppress the legacy metadata OR-branch incorrectly for other brands).
            $lowerTags = array_map('strtolower', $matchTags);
            $brandTagIds = DB::table('asset_tags')
                ->join('assets', 'assets.id', '=', 'asset_tags.asset_id')
                ->where('assets.tenant_id', $tenant->id)
                ->where('assets.brand_id', $brand->id)
                ->whereNull('assets.deleted_at')
                ->where(function ($q) use ($lowerTags) {
                    foreach ($lowerTags as $lt) {
                        $q->orWhereRaw('LOWER(asset_tags.tag) = ?', [$lt]);
                    }
                })
                ->pluck('assets.id')
                ->map(fn ($v) => (string) $v)
                ->unique()
                ->values()
                ->all();

            // Do not use visible() here — brands may "hide" Photography in the
            // nav while assets are still filed there; the grid and wizard must
            // still match those files. Slugs vary by tenant: `photo`, `photos`,
            // `photography`, custom `photograph-*`, etc.
            $photographyCategories = $this->resolvePhotographyDamCategories($tenant, $brand);

            // Real-photography-only filter shared across all three passes.
            // Generative / AI-edited output is persisted back to the DAM as
            // `type = ai_generated` (see EditorGenerativeImagePersistService)
            // and composition previews / WIP exports get flagged in metadata.
            // Both categories were leaking into the wizard's background pool —
            // a brand with one tagged hero photo plus a dozen AI iterations
            // would spin the wheel and land on an AI image 12 / 13 times.
            // Product rule: templates use the library's real photography.
            // AI is reserved for the Feeling Lucky / generative flow, not as
            // a silent fallback on user uploads.
            $applyPhotoOnlyFilter = function ($q) {
                $q->whereIn('type', [AssetType::ASSET, AssetType::DELIVERABLE])
                    ->excludeCompositionTagged();
            };

            $query = Asset::query()
                ->where('tenant_id', $tenant->id)
                ->where('brand_id', $brand->id)
                ->whereNotNull('mime_type')
                ->where('mime_type', 'like', 'image/%')
                ->normalIntakeOnly()
                ->excludeBuilderStaged()
                ->tap($applyPhotoOnlyFilter)
                ->with(['latestBrandIntelligenceScore', 'category']);

            $query->where(function ($outer) use ($brandTagIds, $photographyCategories) {
                if ($brandTagIds !== []) {
                    $outer->whereIn('id', $brandTagIds);
                }

                if ($photographyCategories->isNotEmpty()) {
                    // Any image filed under a Photography-like DAM category — use the
                    // same JSON cast the asset grid uses (works on MySQL + PostgreSQL).
                    $catIds = $photographyCategories->pluck('id')->map(fn ($id) => (int) $id)->all();
                    $outer->orWhere(function ($q) use ($catIds) {
                        $q->whereNotNull('metadata')
                            ->whereIn(DB::raw(Asset::categoryIdMetadataCastExpression()), $catIds);
                    });
                }

                // When this brand has no library hits for the wizard tag set, keep the
                // legacy MySQL metadata-based pool. (JSON_EXTRACT is not portable — it
                // would throw on PostgreSQL and the whole background list would be empty.)
                if ($brandTagIds === [] && DB::getDriverName() === 'mysql') {
                    $outer->orWhere(function ($q) {
                        $q->whereRaw("JSON_EXTRACT(metadata, '$.fields.photo_type') IN ('\"lifestyle\"', '\"editorial\"', '\"event\"', '\"hero\"')")
                            ->orWhereRaw("JSON_EXTRACT(metadata, '$.fields.subject_type') = '\"scene\"'");
                    });
                }
            });

            $assets = $query
                ->orderByDesc('created_at')
                ->limit(32) // oversample; we'll trim to 8 after scoring
                ->get();

            // Filter out anything categorized as a logo even if it matched tags —
            // brands occasionally tag a logo as "hero". Keeps logo slot + bg slot
            // from ever resolving to the same asset.
            $assets = $assets->filter(function (Asset $a) {
                $slug = $a->category?->slug;

                return $slug !== 'logos';
            });

            // Pass 3 fallback — any brand image.
            //
            // Brands without careful tagging (the majority, early on) were
            // landing in the generative-AI path purely because passes 1 + 2
            // returned no candidates. That broke the "templates use real
            // photography by default" product rule. If we made it here with
            // nothing, broaden to every image asset on the brand, still
            // excluding logos via category slug *and* AI-generated output.
            // Fresh brands with zero uploads return an empty list — template
            // BG stays empty and the user is prompted to add a photo from
            // the library.
            if ($assets->isEmpty()) {
                $assets = $this->queryBrandRasterAssetsForWizardFallback(
                    $tenant,
                    $brand,
                    [AssetType::ASSET, AssetType::DELIVERABLE],
                    false
                );
            }

            $tagsByAssetId = $this->loadTagsForAssets($assets->pluck('id')->all());

            $scored = $assets->map(function (Asset $a) use ($tagsByAssetId) {
                $tags = array_values(array_map('strval', $tagsByAssetId[(string) $a->id] ?? []));
                $score = (float) ($a->latestBrandIntelligenceScore?->overall_score ?? 0);
                $pref = $this->rankAssetForBackgroundWizard($a, $tags);

                return [
                    'asset' => $a,
                    'score' => $score,
                    'pref' => $pref,
                    'tags' => $tags,
                ];
            })
                ->sort(function ($x, $y) {
                    if ($x['pref'] !== $y['pref']) {
                        return $y['pref'] <=> $x['pref'];
                    }
                    if ($x['score'] !== $y['score']) {
                        return $y['score'] <=> $x['score'];
                    }

                    $ta = $x['asset']->created_at?->getTimestamp() ?? 0;
                    $tb = $y['asset']->created_at?->getTimestamp() ?? 0;

                    return $tb <=> $ta;
                })
                ->values()
                ->take(40);

            $candidates = $this->buildWizardBackgroundPayloadsFromAssets(
                $scored->map(static fn (array $row) => $row['asset'])
            );

            $merged = $this->mergePhotographyFirstBackgroundCandidates($tenant, $brand, $applyPhotoOnlyFilter, $candidates);

            $sorted = $this->sortBackgroundCandidatePayloadsByPreference($merged);

            if ($sorted !== []) {
                return $sorted;
            }

            // Before falling back to any brand raster (including AI), try a
            // random real library photo: Photography category + tag fallbacks
            // (same rule as the bottom-tier pass but earlier in the pipeline).
            $randomFromPhotography = $this->tryRandomPhotographyCategoryBackgroundCandidates(
                $tenant,
                $brand,
                $applyPhotoOnlyFilter
            );
            if ($randomFromPhotography !== []) {
                return $randomFromPhotography;
            }

            $applyPhotoOnlyFilterWithAi = function ($q) {
                $q->whereIn('type', [AssetType::ASSET, AssetType::DELIVERABLE, AssetType::AI_GENERATED])
                    ->excludeCompositionTagged();
            };

            $fallbackAssets = $this->queryBrandRasterAssetsForWizardFallback(
                $tenant,
                $brand,
                [AssetType::ASSET, AssetType::DELIVERABLE, AssetType::AI_GENERATED],
                false
            );
            $fallbackPayloads = $this->buildWizardBackgroundPayloadsFromAssets($fallbackAssets);
            $sorted = $this->sortBackgroundCandidatePayloadsByPreference(
                $this->mergePhotographyFirstBackgroundCandidates($tenant, $brand, $applyPhotoOnlyFilterWithAi, $fallbackPayloads)
            );

            if ($sorted !== []) {
                return $sorted;
            }

            $fallbackAssets = $this->queryBrandRasterAssetsForWizardFallback(
                $tenant,
                $brand,
                [AssetType::ASSET, AssetType::DELIVERABLE, AssetType::AI_GENERATED],
                true
            );
            $fallbackPayloads = $this->buildWizardBackgroundPayloadsFromAssets($fallbackAssets);

            $final = $this->sortBackgroundCandidatePayloadsByPreference(
                $this->mergePhotographyFirstBackgroundCandidates($tenant, $brand, $applyPhotoOnlyFilterWithAi, $fallbackPayloads)
            );

            if ($final !== []) {
                return $final;
            }

            // Random photography + tag pass already ran when the first ranked
            // list was empty; no further heuristics to try.
            return [];
        } catch (\Throwable $e) {
            Log::warning('editor.wizard_defaults_background_failed', [
                'tenant_id' => $tenant->id,
                'brand_id' => $brand->id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Library folders that are "Photography" for the DAM — slug and naming vary by tenant.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\Category>
     */
    private function resolvePhotographyDamCategories(Tenant $tenant, Brand $brand)
    {
        return Category::query()
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->where('asset_type', AssetType::ASSET)
            ->where(function ($q) {
                $q->whereIn('slug', ['photography', 'photographs', 'photo', 'photos'])
                    ->orWhere('slug', 'like', 'photograph%')
                    ->orWhere('slug', 'like', 'photo%');
            })
            ->active()
            ->get();
    }

    /**
     * Bottom-tier auto-fill: pick one **random** image from Photography DAM
     * categories (driver-safe category_id match), then tag fallbacks
     * (`photography`, `photo`). Excludes AI rows via $applyPhotoOnlyFilter.
     *
     * @param  \Closure(\Illuminate\Database\Eloquent\Builder): void  $applyPhotoOnlyFilter
     * @return list<array{id:string,name:string,file_url:?string,thumbnail_url:?string,width:?int,height:?int,tags:list<string>}>
     */
    private function tryRandomPhotographyCategoryBackgroundCandidates(
        Tenant $tenant,
        Brand $brand,
        \Closure $applyPhotoOnlyFilter
    ): array {
        $catIds = $this->resolvePhotographyDamCategories($tenant, $brand)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        $baseAssetQuery = function (array $restrictToIds) use ($tenant, $brand, $applyPhotoOnlyFilter) {
            $q = Asset::query()
                ->where('tenant_id', $tenant->id)
                ->where('brand_id', $brand->id)
                ->whereNotNull('mime_type')
                ->where('mime_type', 'like', 'image/%')
                ->normalIntakeOnly()
                ->excludeBuilderStaged()
                ->tap($applyPhotoOnlyFilter)
                ->with(['category']);

            if ($restrictToIds !== []) {
                $q->whereIn('id', $restrictToIds);
            }

            return $q;
        };

        $collectPayloads = function ($query) {
            $assets = $query
                ->orderByDesc('created_at')
                ->limit(200)
                ->get()
                ->filter(function (Asset $a) {
                    $slug = $a->category?->slug;

                    return $slug !== 'logos';
                });

            return $this->buildWizardBackgroundPayloadsFromAssets($assets);
        };

        if ($catIds !== []) {
            $payloads = $collectPayloads(
                $baseAssetQuery([])->whereNotNull('metadata')
                    ->whereIn(DB::raw(Asset::categoryIdMetadataCastExpression()), $catIds)
            );
            if ($payloads !== []) {
                shuffle($payloads);

                return [array_values($payloads)[0]];
            }
        }

        $tagIds = $this->assetIdsWithTagForBrand($tenant, $brand, 'photography');
        if ($tagIds === []) {
            $tagIds = $this->assetIdsWithTagForBrand($tenant, $brand, 'photo');
        }
        if ($tagIds !== []) {
            $payloads = $collectPayloads($baseAssetQuery($tagIds));
            if ($payloads !== []) {
                shuffle($payloads);

                return [array_values($payloads)[0]];
            }
        }

        return [];
    }

    /**
     * Brand raster pool for wizard backgrounds: latest images, excluding logo category rows.
     *
     * @param  list<AssetType>  $types
     * @return Collection<int, Asset>
     */
    private function queryBrandRasterAssetsForWizardFallback(Tenant $tenant, Brand $brand, array $types, bool $relaxMime): Collection
    {
        $q = Asset::query()
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->whereIn('type', $types)
            ->normalIntakeOnly()
            ->excludeBuilderStaged()
            ->excludeCompositionTagged()
            ->with(['latestBrandIntelligenceScore', 'category']);

        if ($relaxMime) {
            $q->where(function ($outer) {
                $outer->where('mime_type', 'like', 'image/%')
                    ->orWhere(function ($inner) {
                        $inner->whereNull('mime_type')
                            ->where(function ($extQ) {
                                foreach (['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif', 'heic', 'heif'] as $ext) {
                                    $extQ->orWhereRaw('LOWER(assets.original_filename) LIKE ?', ['%.'.$ext]);
                                }
                            });
                    });
            });
        } else {
            $q->whereNotNull('mime_type')
                ->where('mime_type', 'like', 'image/%');
        }

        return $q->orderByDesc('created_at')
            ->limit(32)
            ->get()
            ->filter(fn (Asset $a) => $a->category?->slug !== 'logos');
    }

    /**
     * @param  Collection<int, Asset>|iterable<Asset>  $assets
     * @return list<array{id:string,name:string,file_url:?string,thumbnail_url:?string,width:?int,height:?int,tags:list<string>}>
     */
    private function buildWizardBackgroundPayloadsFromAssets(iterable $assets): array
    {
        $list = $assets instanceof Collection ? $assets : collect($assets);
        if ($list->isEmpty()) {
            return [];
        }

        $tagsByAssetId = $this->loadTagsForAssets($list->pluck('id')->all());
        $payloads = [];
        foreach ($list as $asset) {
            $fileUrl = $asset->deliveryUrl(AssetVariant::ORIGINAL, DeliveryContext::AUTHENTICATED);
            $thumbMedium = $asset->deliveryUrl(AssetVariant::THUMB_MEDIUM, DeliveryContext::AUTHENTICATED);
            $thumbSmall = $asset->deliveryUrl(AssetVariant::THUMB_SMALL, DeliveryContext::AUTHENTICATED);
            // The grid can show a thumbnail when the full original is not yet
            // deliverable (e.g. cold storage) — same asset must still be pickable
            // for the wizard; the editor applies via /file bridge by asset id.
            if (! $fileUrl) {
                $fileUrl = $thumbMedium ?: $thumbSmall;
            }
            if (! $fileUrl) {
                continue;
            }
            $thumbnailUrl = $thumbMedium
                ?: $thumbSmall
                ?: $fileUrl;
            $payloads[] = [
                'id' => (string) $asset->id,
                'name' => $asset->title ?? $asset->original_filename ?? 'Photo',
                'file_url' => $fileUrl,
                'thumbnail_url' => $thumbnailUrl,
                'width' => $asset->width ? (int) $asset->width : null,
                'height' => $asset->height ? (int) $asset->height : null,
                'tags' => array_values(array_map('strval', $tagsByAssetId[(string) $asset->id] ?? [])),
            ];
        }

        return $payloads;
    }

    /**
     * Asset IDs for this brand whose tags match $tagLower (case-insensitive).
     *
     * @return list<string>
     */
    private function assetIdsWithTagForBrand(Tenant $tenant, Brand $brand, string $tagLower): array
    {
        $needle = mb_strtolower($tagLower);

        return DB::table('asset_tags')
            ->join('assets', 'assets.id', '=', 'asset_tags.asset_id')
            ->where('assets.tenant_id', $tenant->id)
            ->where('assets.brand_id', $brand->id)
            ->whereNull('assets.deleted_at')
            ->whereRaw('LOWER(asset_tags.tag) = ?', [$needle])
            ->pluck('assets.id')
            ->map(fn ($v) => (string) $v)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Prepend assets tagged "photography" so the wizard preview has a real
     * reference image when available; remaining slots keep the scored pool order.
     *
     * @param  \Closure(\Illuminate\Database\Eloquent\Builder): void  $applyPhotoOnlyFilter
     * @param  list<array{id:string,name:string,file_url:?string,thumbnail_url:?string,width:?int,height:?int,tags:list<string>}>  $candidates
     * @return list<array{id:string,name:string,file_url:?string,thumbnail_url:?string,width:?int,height:?int,tags:list<string>}>
     *                                                                                                                            merged with photography-tagged assets first (deduped); caller sorts + trims to 8
     */
    private function mergePhotographyFirstBackgroundCandidates(
        Tenant $tenant,
        Brand $brand,
        \Closure $applyPhotoOnlyFilter,
        array $candidates
    ): array {
        $photoIds = $this->assetIdsWithTagForBrand($tenant, $brand, 'photography');
        if ($photoIds === []) {
            return $candidates;
        }

        $photoAssets = Asset::query()
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->whereNotNull('mime_type')
            ->where('mime_type', 'like', 'image/%')
            ->tap($applyPhotoOnlyFilter)
            ->whereIn('id', $photoIds)
            ->with(['latestBrandIntelligenceScore', 'category'])
            ->get()
            ->filter(fn (Asset $a) => $a->category?->slug !== 'logos')
            ->sortByDesc(fn (Asset $a) => (float) ($a->latestBrandIntelligenceScore?->overall_score ?? 0))
            ->values();

        $tagsByAssetId = $this->loadTagsForAssets($photoAssets->pluck('id')->all());

        $photoPayloads = [];
        foreach ($photoAssets as $asset) {
            $fileUrl = $asset->deliveryUrl(AssetVariant::ORIGINAL, DeliveryContext::AUTHENTICATED);
            if (! $fileUrl) {
                continue;
            }
            $thumbnailUrl = $asset->deliveryUrl(AssetVariant::THUMB_MEDIUM, DeliveryContext::AUTHENTICATED)
                ?: $asset->deliveryUrl(AssetVariant::THUMB_SMALL, DeliveryContext::AUTHENTICATED)
                ?: $fileUrl;
            $photoPayloads[] = [
                'id' => (string) $asset->id,
                'name' => $asset->title ?? $asset->original_filename ?? 'Photo',
                'file_url' => $fileUrl,
                'thumbnail_url' => $thumbnailUrl,
                'width' => $asset->width ? (int) $asset->width : null,
                'height' => $asset->height ? (int) $asset->height : null,
                'tags' => array_values(array_map('strval', $tagsByAssetId[(string) $asset->id] ?? [])),
            ];
        }

        if ($photoPayloads === []) {
            return $candidates;
        }

        $seen = [];
        $merged = [];
        foreach ($photoPayloads as $p) {
            if (! isset($seen[$p['id']])) {
                $seen[$p['id']] = true;
                $merged[] = $p;
            }
        }
        foreach ($candidates as $c) {
            if (! isset($seen[$c['id']])) {
                $seen[$c['id']] = true;
                $merged[] = $c;
            }
        }

        return $merged;
    }

    public function generate(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'prompt' => 'required|string|max:4000',
            'brand_context' => 'nullable|array',
        ]);

        $tenant = app('tenant');
        if (! $tenant instanceof Tenant) {
            return response()->json(['message' => 'Tenant context required.'], 422);
        }

        $brand = app('brand');

        try {
            $this->aiUsageService->checkUsage($tenant, 'generative_editor_layout');
        } catch (\App\Exceptions\PlanLimitExceededException $e) {
            return response()->json($e->toApiArray(), 429);
        }

        $brandContext = $validated['brand_context'] ?? [];
        $assetGroups = $this->fetchGroupedBrandAssets($tenant, $brand);
        $brandDna = $this->resolveBrandDna($brand, $brandContext);
        $aiPrompt = $this->buildPrompt($validated['prompt'], $brandDna, $assetGroups);

        $executeOptions = [
            'tenant' => $tenant,
            'user' => $user,
            'max_tokens' => 1800,
            'temperature' => 0.7,
            'response_format' => ['type' => 'json_object'],
        ];
        if ($brand instanceof Brand) {
            $executeOptions['brand_id'] = $brand->id;
        }

        try {
            $result = $this->aiService->executeAgent(
                'editor_layout_generator',
                AITaskType::EDITOR_LAYOUT_GENERATION,
                $aiPrompt,
                $executeOptions
            );

            $raw = trim($result['text'] ?? '');
            $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw);
            $raw = preg_replace('/\s*```$/', '', $raw);
            $parsed = json_decode($raw, true);

            if (! is_array($parsed) || empty($parsed['layout_style'])) {
                return response()->json([
                    'message' => 'Could not generate a layout recommendation. Please try again.',
                ], 502);
            }

            $parsed = $this->validateAndSanitizeResponse($parsed, $tenant, $brand, $assetGroups);

            $this->aiUsageService->trackUsageWithCost(
                $tenant,
                'generative_editor_layout',
                1,
                (float) ($result['cost'] ?? 0.0),
                isset($result['tokens_in']) ? (int) $result['tokens_in'] : null,
                isset($result['tokens_out']) ? (int) $result['tokens_out'] : null,
                $result['resolved_model_key'] ?? 'gpt-4o-mini'
            );

            Log::info('editor.generate_layout', [
                'user_id' => $user->id,
                'tenant_id' => $tenant->id,
                'brand_id' => $brand instanceof Brand ? $brand->id : null,
                'layout_style' => $parsed['layout_style'] ?? null,
                'format_id' => $parsed['format_id'] ?? null,
                'layer_assignments_count' => count($parsed['layer_assignments'] ?? []),
                'agent_run_id' => $result['agent_run_id'] ?? null,
                'tokens_in' => $result['tokens_in'] ?? null,
                'tokens_out' => $result['tokens_out'] ?? null,
            ]);

            return response()->json($parsed);

        } catch (AIBudgetExceededException $e) {
            Log::warning('editor.generate_layout_budget', [
                'user_id' => $user->id,
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => $e->getPublicMessage(),
            ], 503);
        } catch (\Throwable $e) {
            Log::warning('editor.generate_layout_failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Could not generate layout. Please try again.',
            ], 503);
        }
    }

    // ── Phase 1a: Smart Asset Selection ────────────────────────────

    /**
     * Query, score, and group brand assets for the AI prompt.
     *
     * @return array{logos: list<array>, product_photos: list<array>, lifestyle_photos: list<array>, general_photos: list<array>}
     */
    private function fetchGroupedBrandAssets(?Tenant $tenant, mixed $brand): array
    {
        $empty = ['logos' => [], 'product_photos' => [], 'lifestyle_photos' => [], 'general_photos' => []];

        if (! $tenant || ! $brand instanceof Brand) {
            return $empty;
        }

        try {
            $assets = Asset::query()
                ->where('tenant_id', $tenant->id)
                ->where('brand_id', $brand->id)
                ->whereNotNull('mime_type')
                ->where('mime_type', 'like', 'image/%')
                ->with(['latestBrandIntelligenceScore', 'category'])
                ->latest()
                ->limit(100)
                ->get();

            $tagsByAssetId = $this->loadTagsForAssets($assets->pluck('id')->toArray());
            $categorySlugCache = [];

            $scored = $assets->map(function (Asset $a) use ($tagsByAssetId, &$categorySlugCache) {
                $meta = is_array($a->metadata) ? $a->metadata : [];
                $fields = is_array($meta['fields'] ?? null) ? $meta['fields'] : [];
                $photoType = $this->extractFieldValue($fields, 'photo_type');
                $subjectType = $this->extractFieldValue($fields, 'subject_type');

                $biScore = $a->latestBrandIntelligenceScore?->overall_score;
                $starred = ! empty($meta['starred']);
                $qualityRating = is_numeric($meta['quality_rating'] ?? null) ? (float) $meta['quality_rating'] : null;

                $compositeScore = 0;
                if ($biScore !== null) {
                    $compositeScore += (int) $biScore * 0.4;
                }
                if ($starred) {
                    $compositeScore += 20;
                }
                if ($qualityRating !== null) {
                    $compositeScore += min($qualityRating * 4, 20);
                }
                $daysSinceCreation = now()->diffInDays($a->created_at ?? now());
                $compositeScore += max(0, 10 - ($daysSinceCreation / 30));

                $categorySlug = null;
                if ($a->category) {
                    $categorySlug = $a->category->slug;
                }

                $mime = strtolower((string) ($a->mime_type ?? ''));
                $format = match (true) {
                    str_contains($mime, 'svg') => 'svg',
                    str_contains($mime, 'png') => 'png',
                    str_contains($mime, 'webp') => 'webp',
                    str_contains($mime, 'jpeg'), str_contains($mime, 'jpg') => 'jpeg',
                    default => $mime ?: 'unknown',
                };

                $tags = $tagsByAssetId[(string) $a->id] ?? [];
                $tagSet = array_map(fn ($t) => strtolower((string) $t), $tags);
                $transparentTag = (bool) array_intersect($tagSet, ['transparent', 'cutout', 'isolated', 'no-background', 'no_background', 'knockout']);
                // PNG/SVG may have transparency; JPEG cannot. Treat tagged cutouts or logos as confirmed transparent.
                $isLikelyTransparent = in_array($format, ['svg'], true) || $transparentTag
                    || ($format === 'png' && ($categorySlug === 'logos' || in_array($photoType, ['flat_lay'], true)));

                return [
                    'id' => (string) $a->id,
                    'name' => $a->title ?? $a->original_filename ?? 'Untitled',
                    'category_slug' => $categorySlug,
                    'photo_type' => $photoType,
                    'subject_type' => $subjectType,
                    'tags' => $tags,
                    'brand_score' => $biScore,
                    'dominant_hue' => $a->dominant_hue_group,
                    'width' => $a->width,
                    'height' => $a->height,
                    'composite_score' => round($compositeScore, 1),
                    'format' => $format,
                    'is_likely_transparent' => $isLikelyTransparent,
                ];
            });

            $scored = $scored->sortByDesc('composite_score')->values();

            $groups = $empty;

            $this->addBrandLogos($groups, $brand);

            foreach ($scored as $item) {
                $isLogo = $item['category_slug'] === 'logos'
                    || str_contains(strtolower($item['name']), 'logo');

                if ($isLogo && count($groups['logos']) < 5) {
                    $groups['logos'][] = $item;
                } elseif (in_array($item['photo_type'], self::PRODUCT_PHOTO_TYPES, true)
                    || $item['subject_type'] === 'product') {
                    if (count($groups['product_photos']) < 10) {
                        $groups['product_photos'][] = $item;
                    }
                } elseif (in_array($item['photo_type'], self::LIFESTYLE_PHOTO_TYPES, true)) {
                    if (count($groups['lifestyle_photos']) < 10) {
                        $groups['lifestyle_photos'][] = $item;
                    }
                } else {
                    if (count($groups['general_photos']) < 15) {
                        $groups['general_photos'][] = $item;
                    }
                }
            }

            return $groups;
        } catch (\Throwable $e) {
            Log::warning('editor.generate_layout_assets_failed', ['error' => $e->getMessage()]);

            return $empty;
        }
    }

    /**
     * Add explicit brand logo assets (from Brand model fields) to the logos group.
     */
    private function addBrandLogos(array &$groups, Brand $brand): void
    {
        $logoFields = [
            'logo_id' => 'primary',
            'logo_dark_id' => 'dark',
            'logo_horizontal_id' => 'horizontal',
        ];

        $existingIds = array_column($groups['logos'], 'id');

        foreach ($logoFields as $field => $variant) {
            $assetId = $brand->$field ?? null;
            if (! $assetId || in_array((string) $assetId, $existingIds, true)) {
                continue;
            }

            $groups['logos'][] = [
                'id' => (string) $assetId,
                'name' => "Brand Logo ({$variant})",
                'category_slug' => 'logos',
                'photo_type' => null,
                'subject_type' => null,
                'tags' => ['logo', $variant],
                'brand_score' => 100,
                'dominant_hue' => null,
                'width' => null,
                'height' => null,
                'composite_score' => 100,
                'format' => 'svg',
                'is_likely_transparent' => true,
            ];
            $existingIds[] = (string) $assetId;
        }
    }

    /**
     * Higher scores surface first for template wizard background auto-fill.
     *
     * @param  list<string>  $tagStrings
     */
    private function rankAssetForBackgroundWizard(Asset $a, array $tagStrings): int
    {
        $meta = is_array($a->metadata) ? $a->metadata : [];
        $fields = is_array($meta['fields'] ?? null) ? $meta['fields'] : [];
        $photoType = strtolower((string) ($this->extractFieldValue($fields, 'photo_type') ?? ''));
        $subjectType = strtolower((string) ($this->extractFieldValue($fields, 'subject_type') ?? ''));
        $slug = strtolower((string) ($a->category?->slug ?? ''));
        $tags = array_map(static fn ($t) => strtolower((string) $t), $tagStrings);

        $rank = 0;

        if ($a->type === AssetType::AI_GENERATED) {
            // Prefer uploads / deliverables for template backgrounds; AI is a last-resort pool only.
            $rank -= 650;
        }

        if ($slug === 'photography') {
            $rank += 700;
        }

        foreach (['lifestyle', 'editorial', 'hero', 'background', 'scene'] as $t) {
            if (in_array($t, $tags, true)) {
                $rank += 120;
            }
        }

        if (in_array($photoType, ['lifestyle', 'editorial', 'event', 'action', 'hero'], true)) {
            $rank += 520;
        }

        if ($photoType === 'studio' && $subjectType !== 'product') {
            $rank += 380;
        }

        if (in_array($photoType, ['portrait', 'landscape'], true) && $subjectType !== 'product') {
            $rank += 160;
        }

        if (in_array($subjectType, ['scene', 'environment', 'location'], true)) {
            $rank += 360;
        }

        if (in_array($subjectType, ['person', 'people'], true)) {
            $rank += 220;
        }

        if (array_intersect($tags, ['transparent', 'cutout', 'isolated', 'no-background', 'no_background', 'knockout'])) {
            $rank -= 520;
        }

        if ($subjectType === 'product') {
            $rank -= 620;
        }

        if (in_array($photoType, self::BACKGROUND_DEPRIORITIZED_PHOTO_TYPES, true)) {
            $rank -= 480;
        }

        if ($photoType === 'studio' && $subjectType === 'product') {
            $rank -= 400;
        }

        return $rank;
    }

    /**
     * @param  list<array{id:string,name:string,file_url:?string,thumbnail_url:?string,width:?int,height:?int,tags:list<string>}>  $payloads
     * @return list<array{id:string,name:string,file_url:?string,thumbnail_url:?string,width:?int,height:?int,tags:list<string>}>
     */
    private function sortBackgroundCandidatePayloadsByPreference(array $payloads): array
    {
        if ($payloads === []) {
            return [];
        }

        $ids = array_values(array_unique(array_map(static fn ($p) => (int) $p['id'], $payloads)));
        $assets = Asset::query()
            ->whereIn('id', $ids)
            ->with(['latestBrandIntelligenceScore', 'category'])
            ->get()
            ->keyBy(static fn (Asset $a) => (string) $a->id);

        usort($payloads, function (array $a, array $b) use ($assets): int {
            $assetA = $assets->get($a['id']);
            $assetB = $assets->get($b['id']);
            $tagsA = is_array($a['tags'] ?? null) ? $a['tags'] : [];
            $tagsB = is_array($b['tags'] ?? null) ? $b['tags'] : [];
            $ra = $assetA ? $this->rankAssetForBackgroundWizard($assetA, $tagsA) : 0;
            $rb = $assetB ? $this->rankAssetForBackgroundWizard($assetB, $tagsB) : 0;
            if ($ra !== $rb) {
                return $rb <=> $ra;
            }
            $sa = (float) ($assetA?->latestBrandIntelligenceScore?->overall_score ?? 0);
            $sb = (float) ($assetB?->latestBrandIntelligenceScore?->overall_score ?? 0);
            if ($sa !== $sb) {
                return $sb <=> $sa;
            }

            return strcmp($a['id'], $b['id']);
        });

        return array_slice($payloads, 0, 8);
    }

    /**
     * Batch-load approved tags for a set of asset IDs.
     *
     * @return array<string, list<string>>
     */
    private function loadTagsForAssets(array $assetIds): array
    {
        if ($assetIds === []) {
            return [];
        }

        $rows = DB::table('asset_tags')
            ->whereIn('asset_id', $assetIds)
            ->whereNotNull('tag')
            ->where('tag', '!=', '')
            ->get(['asset_id', 'tag']);

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->asset_id][] = $row->tag;
        }

        return $out;
    }

    /**
     * Extract a metadata field value, handling both direct and nested structures.
     */
    private function extractFieldValue(array $fields, string $key): ?string
    {
        $val = $fields[$key] ?? null;
        if (is_string($val) && $val !== '') {
            return $val;
        }
        if (is_array($val) && isset($val['value'])) {
            return is_string($val['value']) ? $val['value'] : null;
        }

        return null;
    }

    // ── Phase 1b: Brand DNA Resolution ─────────────────────────────

    /**
     * Merge frontend brand_context with server-side brand data for a complete DNA snapshot.
     */
    private function resolveBrandDna(mixed $brand, array $frontendContext): array
    {
        $dna = [
            'name' => null,
            'tone' => [],
            'visual_style' => null,
            'archetype' => null,
            'primary_color' => null,
            'secondary_color' => null,
            'accent_color' => null,
            'primary_font' => null,
            'secondary_font' => null,
            'typography_presets' => [],
        ];

        if ($brand instanceof Brand) {
            $dna['name'] = $brand->name;
            $dna['primary_color'] = $brand->primary_color;
            $dna['secondary_color'] = $brand->secondary_color;
            $dna['accent_color'] = $brand->accent_color;

            $settings = is_array($brand->settings) ? $brand->settings : [];
            $dna['primary_font'] = $settings['typography']['primary_font']
                ?? $settings['primary_font']
                ?? null;
            $dna['secondary_font'] = $settings['typography']['secondary_font'] ?? null;
        }

        if (! empty($frontendContext['tone']) && is_array($frontendContext['tone'])) {
            $dna['tone'] = array_values(array_filter($frontendContext['tone'], fn ($t) => is_string($t) && $t !== ''));
        }
        if (is_string($frontendContext['visual_style'] ?? null) && $frontendContext['visual_style'] !== '') {
            $dna['visual_style'] = $frontendContext['visual_style'];
        }
        if (is_string($frontendContext['archetype'] ?? null) && $frontendContext['archetype'] !== '') {
            $dna['archetype'] = $frontendContext['archetype'];
        }

        if (! empty($frontendContext['brand_color_slots']) && is_array($frontendContext['brand_color_slots'])) {
            $slots = $frontendContext['brand_color_slots'];
            $dna['primary_color'] = $dna['primary_color'] ?: ($slots['primary'] ?? null);
            $dna['secondary_color'] = $dna['secondary_color'] ?: ($slots['secondary'] ?? null);
            $dna['accent_color'] = $dna['accent_color'] ?: ($slots['accent'] ?? null);
        }

        if (! empty($frontendContext['typography']['primary_font'])) {
            $dna['primary_font'] = $dna['primary_font'] ?: $frontendContext['typography']['primary_font'];
        }
        if (! empty($frontendContext['typography']['secondary_font'])) {
            $dna['secondary_font'] = $dna['secondary_font'] ?: $frontendContext['typography']['secondary_font'];
        }

        // Typography presets: heading/subheading/body/caption styles — font weight is a strong hint
        // ("blocky" = heavy weight ≥700, "elegant" = light weight ≤300). Let AI reason about the vibe.
        $presetKeys = ['heading', 'subheading', 'body', 'caption'];
        $presetsIn = is_array($frontendContext['typography']['presets'] ?? null)
            ? $frontendContext['typography']['presets']
            : [];
        foreach ($presetKeys as $key) {
            if (! isset($presetsIn[$key]) || ! is_array($presetsIn[$key])) {
                continue;
            }
            $p = $presetsIn[$key];
            $dna['typography_presets'][$key] = [
                'fontSize' => $p['fontSize'] ?? null,
                'fontWeight' => $p['fontWeight'] ?? null,
                'lineHeight' => $p['lineHeight'] ?? null,
                'letterSpacing' => $p['letterSpacing'] ?? null,
            ];
        }

        return $dna;
    }

    // ── Phase 2: Rich AI Prompt ────────────────────────────────────

    private function buildPrompt(string $userPrompt, array $brandDna, array $assetGroups): string
    {
        $brandName = $brandDna['name'] ?? 'Unknown brand';
        $toneStr = $brandDna['tone'] !== [] ? implode(', ', $brandDna['tone']) : 'not specified';
        $visualStyle = $brandDna['visual_style'] ?? 'not specified';
        $archetype = $brandDna['archetype'] ?? 'not specified';
        $primaryColor = $brandDna['primary_color'] ?? 'not specified';
        $secondaryColor = $brandDna['secondary_color'] ?? 'not specified';
        $accentColor = $brandDna['accent_color'] ?? 'not specified';
        $primaryFont = $brandDna['primary_font'] ?? 'not specified';
        $secondaryFont = $brandDna['secondary_font'] ?? 'not specified';

        $logosBlock = $this->formatAssetGroup($assetGroups['logos'] ?? [], 'No brand logos available.');
        $productBlock = $this->formatAssetGroup($assetGroups['product_photos'] ?? [], 'No product photos available.');
        $lifestyleBlock = $this->formatAssetGroup($assetGroups['lifestyle_photos'] ?? [], 'No lifestyle photos available.');
        $generalBlock = $this->formatAssetGroup($assetGroups['general_photos'] ?? [], 'No other images available.');

        $formatBlock = $this->buildFormatReferenceBlock();
        $typographyBlock = $this->buildTypographyBlock($brandDna['typography_presets'] ?? []);

        // Flatten all groups to a single set of available IDs for a strict inventory list
        $allAssets = array_merge(
            $assetGroups['logos'] ?? [],
            $assetGroups['product_photos'] ?? [],
            $assetGroups['lifestyle_photos'] ?? [],
            $assetGroups['general_photos'] ?? [],
        );
        $transparentAssets = array_values(array_filter($allAssets, fn ($a) => ! empty($a['is_likely_transparent'])));
        $transparentIds = array_column($transparentAssets, 'id');
        $transparentBlock = $transparentIds !== []
            ? 'TRANSPARENT-CAPABLE ASSETS (PNG/SVG with cutout/isolated backgrounds — safe to layer on top of other imagery): '.implode(', ', $transparentIds)
            : 'TRANSPARENT-CAPABLE ASSETS: NONE. You must NOT stack a product photo over a background photo — pick one image to use as the background instead.';

        return <<<PROMPT
You are an AI creative director for Jackpot, a digital asset management and brand marketing platform. A user wants to create a new composition for their brand. Based on their description and the brand intelligence below, recommend the best template format, layout style, and specific asset placements.

USER REQUEST: {$userPrompt}

BRAND DNA:
- Brand Name: {$brandName}
- Tone: {$toneStr}
- Visual Style: {$visualStyle}
- Archetype: {$archetype}
- Primary Color: {$primaryColor} | Secondary Color: {$secondaryColor} | Accent Color: {$accentColor}
- Primary Font: {$primaryFont} | Secondary Font: {$secondaryFont}

TYPOGRAPHY SIGNAL (use this to pick the right headline feel):
{$typographyBlock}

BRAND LOGOS (prefer these for the logo layer):
{$logosBlock}

PRODUCT PHOTOGRAPHY (sorted by brand alignment score — prefer higher-scored assets):
{$productBlock}

LIFESTYLE PHOTOGRAPHY (sorted by brand alignment score):
{$lifestyleBlock}

GENERAL IMAGES:
{$generalBlock}

{$transparentBlock}

{$formatBlock}

LAYOUT STYLES:
- product_focused: Hero product image + headline + CTA + text_boost overlay. Use when the user mentions products, sales, or specific items. Only use this style if you have a transparent-capable product asset OR you are confident a single image (used as background, no stacked hero) reads as the product.
- brand_focused: Full background image with brand message, headline, and logo. Use for awareness, announcements, brand storytelling.
- lifestyle: Immersive full-bleed image with minimal text overlay. Use for mood, ambiance, seasonal campaigns.
- special: Minimal starting point — just a background. Use only when explicitly requested.

HARD RULES (follow exactly — failures produce broken designs):
1. ASSET STACKING: Do NOT assign the same asset_id to both "background" and "hero_image". They must be two different assets. If you only have ONE usable image, put it as "background" and OMIT the hero_image assignment.
2. TRANSPARENT HEROES ONLY: For the "hero_image" role, ONLY use asset IDs listed in TRANSPARENT-CAPABLE ASSETS. Full-frame JPEG product photos placed on top of another photo look broken. If no transparent asset is available, skip hero_image entirely and either use the product as the background, or use layout_style=brand_focused.
3. TEXT BOOST IS REQUIRED WHEN TEXT OVERLAYS IMAGERY: Every layout style except "special" includes a text_boost gradient layer to keep headline/subheadline readable on top of photos. You do not need to return a layer_assignment for text_boost (it is generated by the template), but DO provide a good "overlay_color" value that complements the chosen background (dark overlay for bright photos, light overlay for dark photos).
4. LOGO PLACEMENT: ALWAYS include a logo layer_assignment when any logo is listed in BRAND LOGOS. Brands expect branded compositions.
5. CTA BUTTON: When the layout has a CTA, the CTA sits on a colored button background (a fill layer named "CTA Button"). In color_palette.cta_bg provide the button background color (use the brand primary or accent for contrast against the image). In color_palette.cta_text provide the button text color with WCAG AA contrast against cta_bg.
6. HEADLINE STYLING: Look at the typography signal. Heavy fontWeight (≥700) = blocky/bold headlines — headline_color should be punchy and high contrast. Light fontWeight (≤300) = elegant — headline_color can be softer. If brand primary color is vivid and readable over the background, use it for headline_color; otherwise use #ffffff or #0b0f1a.
7. COPY: Write headline (3–7 words, high-impact), subheadline (6–14 words, supports the headline), and cta_text (1–3 words, action verb). Match brand tone.
8. ASSET MATCHING: Select assets whose tags/photo_type match the user's intent. For a knife sale, pick the knife product photo, not a logo bracelet photo.
9. Return ONLY a valid JSON object with the exact shape below — no prose outside the JSON, no markdown fences.

Return ONLY valid JSON with this exact shape:
{
  "format_id": "ig_feed_square",
  "format_name": "Instagram Feed Post",
  "width": 1080,
  "height": 1080,
  "layout_style": "product_focused",
  "headline": "Your Headline Here",
  "subheadline": "Supporting copy here",
  "cta_text": "Shop Now",
  "background_prompt": "descriptive scene prompt if generating a new background image",
  "overlay_color": "#000000cc",
  "layer_assignments": [
    {"role": "hero_image", "asset_id": "uuid-of-TRANSPARENT-photo-or-OMIT", "reason": "why this asset was chosen"},
    {"role": "logo", "asset_id": "uuid-of-logo", "reason": "Primary brand logo"},
    {"role": "background", "source": "use_asset", "asset_id": "uuid-of-background-photo", "reason": "why this photo works as background"}
  ],
  "color_palette": {
    "headline_color": "#ffffff",
    "subheadline_color": "#ffffffcc",
    "cta_bg": "#7c3aed",
    "cta_text": "#ffffff"
  },
  "reasoning": "One or two sentences explaining why this combination was chosen",
  "post_generation_suggestions": [
    {"type": "variant", "description": "Try swapping to a lifestyle photo for the background"},
    {"type": "ai_enhance", "description": "Generate an AI background that matches the product colors"}
  ]
}
PROMPT;
    }

    private function buildTypographyBlock(array $presets): string
    {
        if ($presets === []) {
            return '- No typography presets available; use brand primary font for headline if provided.';
        }
        $lines = [];
        foreach ($presets as $key => $p) {
            $size = $p['fontSize'] ?? null;
            $weight = $p['fontWeight'] ?? null;
            $spacing = $p['letterSpacing'] ?? null;
            $vibe = null;
            if (is_numeric($weight)) {
                $w = (int) $weight;
                $vibe = $w >= 800 ? 'ultra-heavy/blocky'
                    : ($w >= 700 ? 'bold/blocky'
                        : ($w >= 500 ? 'medium/modern'
                            : ($w <= 300 ? 'light/elegant' : 'regular')));
            }
            $parts = ["- {$key}:"];
            if ($size) {
                $parts[] = "size ~{$size}px";
            }
            if ($weight) {
                $parts[] = "weight {$weight}";
            }
            if ($spacing !== null && $spacing !== 0) {
                $parts[] = "tracking {$spacing}";
            }
            if ($vibe) {
                $parts[] = "vibe: {$vibe}";
            }
            $lines[] = implode(', ', $parts);
        }

        return implode("\n", $lines);
    }

    private function formatAssetGroup(array $assets, string $emptyMessage): string
    {
        if ($assets === []) {
            return $emptyMessage;
        }

        $lines = [];
        foreach ($assets as $a) {
            $parts = ["- {$a['name']} (id: {$a['id']}"];
            if (! empty($a['format'])) {
                $parts[] = "format: {$a['format']}";
            }
            if (! empty($a['is_likely_transparent'])) {
                $parts[] = 'transparent: yes';
            }
            if ($a['brand_score'] !== null) {
                $parts[] = "score: {$a['brand_score']}/100";
            }
            if ($a['photo_type']) {
                $parts[] = "type: {$a['photo_type']}";
            }
            if (! empty($a['tags'])) {
                $parts[] = 'tags: ['.implode(', ', array_slice($a['tags'], 0, 8)).']';
            }
            if ($a['width'] && $a['height']) {
                $parts[] = "{$a['width']}x{$a['height']}";
            }
            if ($a['dominant_hue']) {
                $parts[] = "color: {$a['dominant_hue']}";
            }
            $lines[] = implode(', ', $parts).')';
        }

        return implode("\n", $lines);
    }

    private function buildFormatReferenceBlock(): string
    {
        $lines = ['AVAILABLE FORMATS:'];
        $byCategory = [];
        foreach (self::FORMAT_REGISTRY as $id => $f) {
            $byCategory[$f['cat']][] = "  - {$id}: {$f['name']} ({$f['w']}x{$f['h']}) [{$f['platform']}]";
        }
        foreach ($byCategory as $cat => $entries) {
            $label = str_replace('_', ' ', ucfirst($cat));
            $lines[] = "\n{$label}:";
            foreach ($entries as $entry) {
                $lines[] = $entry;
            }
        }

        return implode("\n", $lines);
    }

    // ── Phase 2c: Response Validation ──────────────────────────────

    /**
     * Validate AI response: verify asset IDs, format, layout style. Sanitize gracefully.
     *
     * @param  array<string, mixed>  $parsed
     * @param  array<string, list<array>>  $assetGroups
     */
    private function validateAndSanitizeResponse(array $parsed, Tenant $tenant, mixed $brand, array $assetGroups = []): array
    {
        if (! in_array($parsed['layout_style'] ?? '', self::VALID_LAYOUT_STYLES, true)) {
            $parsed['layout_style'] = 'brand_focused';
        }

        $formatId = $parsed['format_id'] ?? null;
        if ($formatId && isset(self::FORMAT_REGISTRY[$formatId])) {
            $reg = self::FORMAT_REGISTRY[$formatId];
            $parsed['width'] = $parsed['width'] ?? $reg['w'];
            $parsed['height'] = $parsed['height'] ?? $reg['h'];
            $parsed['format_name'] = $parsed['format_name'] ?? $reg['name'];
        } elseif ($formatId) {
            $parsed['format_id'] = 'ig_feed_square';
            $parsed['width'] = 1080;
            $parsed['height'] = 1080;
            $parsed['format_name'] = 'Instagram Feed Post';
        }

        $parsed['width'] = max(50, min(5000, (int) ($parsed['width'] ?? 1080)));
        $parsed['height'] = max(50, min(5000, (int) ($parsed['height'] ?? 1080)));

        // Build a transparency lookup from the asset inventory so we can enforce the hero rule
        // even when the AI ignores the prompt instructions.
        $transparencyById = [];
        foreach (['logos', 'product_photos', 'lifestyle_photos', 'general_photos'] as $group) {
            foreach ($assetGroups[$group] ?? [] as $a) {
                $transparencyById[(string) ($a['id'] ?? '')] = (bool) ($a['is_likely_transparent'] ?? false);
            }
        }

        /** @var array<string, true> */
        $logoAssetIds = [];
        foreach ($assetGroups['logos'] ?? [] as $logoRow) {
            if (! empty($logoRow['id'])) {
                $logoAssetIds[(string) $logoRow['id']] = true;
            }
        }

        if (! empty($parsed['layer_assignments']) && is_array($parsed['layer_assignments'])) {
            $validAssetIds = $this->collectValidAssetIds($parsed['layer_assignments'], $tenant, $brand);
            $parsed['layer_assignments'] = array_values(array_filter(
                $parsed['layer_assignments'],
                function (array $assignment) use ($validAssetIds) {
                    if (! isset($assignment['role'])) {
                        return false;
                    }
                    if (isset($assignment['asset_id'])) {
                        return in_array($assignment['asset_id'], $validAssetIds, true);
                    }

                    return true;
                }
            ));

            // Guard 1: If AI assigns the same asset_id to both background and hero_image, drop the hero.
            $bgId = null;
            foreach ($parsed['layer_assignments'] as $a) {
                if (($a['role'] ?? null) === 'background' && ! empty($a['asset_id'])) {
                    $bgId = (string) $a['asset_id'];
                    break;
                }
            }
            $parsed['layer_assignments'] = array_values(array_filter(
                $parsed['layer_assignments'],
                function (array $a) use ($bgId, $transparencyById, $logoAssetIds) {
                    if (($a['role'] ?? null) !== 'hero_image' || empty($a['asset_id'])) {
                        return true;
                    }
                    $id = (string) $a['asset_id'];
                    // Guard 1: same as background
                    if ($bgId !== null && $id === $bgId) {
                        return false;
                    }
                    // Guard 2: hero must be transparent-capable (if we know).
                    // If the asset isn't in our inventory map, trust the AI (validated tenant ownership already).
                    if (array_key_exists($id, $transparencyById) && $transparencyById[$id] === false) {
                        return false;
                    }

                    // Guard 3: never treat brand logos as the hero/product layer — they are listed as
                    // TRANSPARENT-CAPABLE and the model sometimes picks them to satisfy the "stacked hero"
                    // rule, which renders as a cropped wordmark on the canvas.
                    if (isset($logoAssetIds[$id])) {
                        return false;
                    }

                    return true;
                }
            ));
        } else {
            $parsed['layer_assignments'] = [];
        }

        if (! empty($parsed['asset_suggestions']) && is_array($parsed['asset_suggestions'])) {
            foreach ($parsed['asset_suggestions'] as $suggestion) {
                if (! empty($suggestion['asset_id']) && ! empty($suggestion['role'])) {
                    $parsed['layer_assignments'][] = [
                        'role' => $suggestion['role'],
                        'asset_id' => $suggestion['asset_id'],
                        'reason' => 'Migrated from legacy asset_suggestions',
                    ];
                }
            }
        }

        if (! isset($parsed['color_palette']) || ! is_array($parsed['color_palette'])) {
            $parsed['color_palette'] = [];
        }

        if (! isset($parsed['post_generation_suggestions']) || ! is_array($parsed['post_generation_suggestions'])) {
            $parsed['post_generation_suggestions'] = [];
        }

        return $parsed;
    }

    /**
     * Verify that asset IDs referenced in layer_assignments belong to this tenant/brand.
     *
     * @return list<string>
     */
    private function collectValidAssetIds(array $assignments, Tenant $tenant, mixed $brand): array
    {
        $referencedIds = [];
        foreach ($assignments as $a) {
            if (! empty($a['asset_id']) && is_string($a['asset_id'])) {
                $referencedIds[] = $a['asset_id'];
            }
        }

        if ($referencedIds === []) {
            return [];
        }

        $query = Asset::query()
            ->whereIn('id', array_unique($referencedIds))
            ->where('tenant_id', $tenant->id);

        if ($brand instanceof Brand) {
            $query->where(function ($q) use ($brand) {
                $q->where('brand_id', $brand->id)
                    ->orWhereNull('brand_id');
            });
        }

        return $query->pluck('id')->map(fn ($id) => (string) $id)->toArray();
    }
}
