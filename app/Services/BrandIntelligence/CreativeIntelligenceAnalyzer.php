<?php

namespace App\Services\BrandIntelligence;

use App\Enums\AssetContextType;
use App\Enums\MediaType;
use App\Enums\PdfBrandIntelligenceScanMode;
use App\Models\Asset;
use App\Models\Brand;
use App\Services\AI\Contracts\AIProviderInterface;
use App\Services\AiMetadataGenerationService;
use Illuminate\Support\Facades\Log;

/**
 * Parallel AI pass: structured visual + copy extraction vs Brand DNA, separate from embedding/reference scoring.
 */
final class CreativeIntelligenceAnalyzer
{
    public const AI_USAGE_TYPE = 'brand_intelligence_creative';

    public function __construct(
        protected AIProviderInterface $aiProvider,
        protected AiMetadataGenerationService $aiMetadataGenerationService,
        protected VisualEvaluationSourceResolver $visualEvaluationSourceResolver,
    ) {}

    /**
     * @return array{
     *   creative_analysis: array|null,
     *   creative_signals: array|null,
     *   copy_alignment: array,
     *   context_analysis: array,
     *   visual_alignment_ai: array|null,
     *   overall_summary: ?string,
     *   brand_copy_conflict: bool,
     *   ebi_ai_trace: array
     * }
     */
    public function analyze(
        Asset $asset,
        Brand $brand,
        AssetContextType $heuristicContext,
        bool $dryRun,
        PdfBrandIntelligenceScanMode $pdfScanMode = PdfBrandIntelligenceScanMode::Standard,
    ): array {
        $resolvedVisual = $this->visualEvaluationSourceResolver->resolve($asset);
        $visualTrace = VisualEvaluationSourceResolver::traceSubset($resolvedVisual);

        $mime = strtolower((string) ($asset->mime_type ?? ''));
        $mediaType = MediaType::fromMime($mime);

        $trace = [
            'creative_ai_ran' => false,
            'copy_extracted' => false,
            'copy_alignment_scored' => false,
            'skipped' => true,
            'skip_reason' => null,
            'visual_evaluation_source' => $visualTrace,
        ];

        if ($mediaType === MediaType::PDF) {
            $trace['pdf_scan_mode'] = $pdfScanMode->value;
            $trace['max_pdf_pages_allowed'] = $pdfScanMode->maxPdfPagesForSelection();
        }

        if ($dryRun) {
            $trace['skip_reason'] = 'dry_run';

            return $this->emptyPayload($trace);
        }
        $canUseVisionRaster = match ($mediaType) {
            MediaType::IMAGE => true,
            MediaType::PDF, MediaType::VIDEO => $this->visualEvaluationSourceResolver->assetHasRenderableRaster($asset),
            default => (($resolvedVisual['resolved'] ?? false) === true),
        };

        if (! $canUseVisionRaster) {
            $trace['skip_reason'] = match ($mediaType) {
                MediaType::PDF => 'pdf_visual_source_missing',
                MediaType::VIDEO => 'video_preview_missing',
                default => 'not_image',
            };

            return $this->augmentWithVideoInsights($asset, $this->emptyPayload($trace));
        }

        if ($heuristicContext === AssetContextType::LOGO_ONLY) {
            $trace['skip_reason'] = 'logo_only_context';

            return $this->emptyPayload($trace);
        }

        $catalog = [];
        $selection = null;
        if ($mediaType === MediaType::PDF) {
            $catalog = PdfBrandIntelligencePageRasterCatalog::discoverRastersByPage($asset, $this->visualEvaluationSourceResolver);
            $selection = PdfBrandIntelligencePageSelector::select(
                $asset,
                $catalog,
                $pdfScanMode->maxPdfPagesForSelection(),
            );
        }

        $dna = $this->extractBrandDnaForCopyAlignment($brand);
        $modelKey = 'gpt-4o-mini';
        $modelName = config("ai.models.{$modelKey}.model_name", 'gpt-4o-mini');
        $prompt = $this->buildVisionPrompt($heuristicContext, $dna);
        $visionOpts = [
            'model' => $modelName,
            'max_tokens' => 1800,
            'response_format' => ['type' => 'json_object'],
        ];

        if ($mediaType === MediaType::PDF && $selection !== null && count($selection['entries']) > 1) {
            return $this->augmentWithVideoInsights($asset, $this->analyzePdfMultiPageCreative($asset, $heuristicContext, $prompt, $visionOpts, $trace, $selection));
        }

        $imageDataUrl = null;
        if ($mediaType === MediaType::PDF && $selection !== null && $selection['entries'] !== []) {
            $firstPath = (string) ($selection['entries'][0]['storage_path'] ?? '');
            if ($firstPath !== '') {
                $imageDataUrl = $this->aiMetadataGenerationService->fetchStoragePathForVisionAnalysis($asset, $firstPath);
            }
        }
        if ($imageDataUrl === null || $imageDataUrl === '') {
            $imageDataUrl = $this->aiMetadataGenerationService->fetchThumbnailForVisionAnalysis($asset);
        }
        if ($imageDataUrl === null || $imageDataUrl === '') {
            $trace['skip_reason'] = 'no_thumbnail_for_vision';

            return $this->augmentWithVideoInsights($asset, $this->emptyPayload($trace));
        }

        try {
            $response = $this->aiProvider->analyzeImage($imageDataUrl, $prompt, $visionOpts);
        } catch (\Throwable $e) {
            Log::warning('[EBI Creative] Vision analysis failed', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);
            $trace['skip_reason'] = 'ai_error: '.$e->getMessage();

            return $this->augmentWithVideoInsights($asset, $this->emptyPayload($trace));
        }

        $parsed = $this->parseCreativeJson($response['text'] ?? '');
        if ($parsed === null) {
            $trace['skip_reason'] = 'parse_failed';

            return $this->augmentWithVideoInsights($asset, $this->emptyPayload($trace));
        }

        if ($mediaType === MediaType::PDF && $selection !== null) {
            $trace['pdf_multi_page'] = $this->buildPdfMultiPageTraceSingleRasterPlan($selection, $trace);
        }

        return $this->augmentWithVideoInsights($asset, $this->finalizeCreativePayload($parsed, $heuristicContext, $trace));
    }

    /**
     * Merge ai_video_insights (summary + scene/activity/setting) into creative_signals so
     * Context Fit, Visual Style, and Copy/Voice can benefit from multi-frame video analysis
     * on top of the single-keyframe VLM view. Safe to call for non-videos — no-op if
     * ai_video_insights is absent. Adds a SignalFamily.TEXT_DERIVED hint so diversity
     * tracking can tell video-summary-derived signals from pixel VLM signals.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function augmentWithVideoInsights(Asset $asset, array $payload): array
    {
        $meta = is_array($asset->metadata ?? null) ? $asset->metadata : [];
        $insights = $meta['ai_video_insights'] ?? null;
        if (! is_array($insights)) {
            return $payload;
        }

        $scene = is_string($insights['metadata']['scene'] ?? null) ? trim($insights['metadata']['scene']) : '';
        $activity = is_string($insights['metadata']['activity'] ?? null) ? trim($insights['metadata']['activity']) : '';
        $setting = is_string($insights['metadata']['setting'] ?? null) ? trim($insights['metadata']['setting']) : '';
        $summary = is_string($insights['summary'] ?? null) ? trim($insights['summary']) : '';
        $tags = is_array($insights['tags'] ?? null) ? array_values(array_filter($insights['tags'], 'is_string')) : [];
        $suggestedCategory = is_string($insights['suggested_category'] ?? null) ? trim($insights['suggested_category']) : '';

        if ($scene === '' && $activity === '' && $setting === '' && $summary === '' && $tags === [] && $suggestedCategory === '') {
            return $payload;
        }

        $signals = is_array($payload['creative_signals'] ?? null) ? $payload['creative_signals'] : [];

        $existingStyle = is_array($signals['visual_style'] ?? null) ? $signals['visual_style'] : [];
        $styleHints = [];
        foreach ([$activity, $setting, $scene] as $raw) {
            if ($raw === '') {
                continue;
            }
            $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $raw) ?? '');
            $slug = trim($slug, '-');
            if ($slug !== '' && mb_strlen($slug) >= 2) {
                $styleHints[] = $slug;
            }
        }
        foreach ($tags as $tag) {
            $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $tag) ?? '');
            $slug = trim($slug, '-');
            if ($slug !== '' && mb_strlen($slug) >= 2) {
                $styleHints[] = $slug;
            }
        }
        $mergedStyle = array_values(array_unique(array_merge($existingStyle, $styleHints)));
        if (count($mergedStyle) > 15) {
            $mergedStyle = array_slice($mergedStyle, 0, 15);
        }
        if ($mergedStyle !== []) {
            $signals['visual_style'] = $mergedStyle;
        }

        if (empty($signals['context_type'])) {
            $ctx = $setting !== '' ? $setting : ($suggestedCategory !== '' ? $suggestedCategory : $scene);
            if ($ctx !== '') {
                $signals['context_type'] = $ctx;
            }
        }

        $videoContext = array_filter([
            'scene' => $scene !== '' ? $scene : null,
            'activity' => $activity !== '' ? $activity : null,
            'setting' => $setting !== '' ? $setting : null,
            'summary' => $summary !== '' ? mb_substr($summary, 0, 2000) : null,
            'suggested_category' => $suggestedCategory !== '' ? $suggestedCategory : null,
            'tags' => $tags !== [] ? array_values(array_slice($tags, 0, 25)) : null,
        ], static fn ($v): bool => $v !== null);
        if ($videoContext !== []) {
            $signals['video_context'] = $videoContext;
        }

        if ($signals !== []) {
            $payload['creative_signals'] = $signals;
        }

        $trace = is_array($payload['ebi_ai_trace'] ?? null) ? $payload['ebi_ai_trace'] : [];
        $trace['video_insights_merged'] = true;
        $trace['video_insights_fields'] = array_keys($videoContext);
        $payload['ebi_ai_trace'] = $trace;

        return $payload;
    }

    /**
     * @param  array{
     *     strategy: string,
     *     total_pdf_pages_known: int|null,
     *     selected_pages: list<int>,
     *     entries: list<array{page: int, storage_path: string, origin: string, size_bytes?: int}>
     * }  $selection
     * @param  array<string, mixed>  $trace
     * @param  array{model: string, max_tokens: int, response_format: array<string, mixed>}  $visionOpts
     * @return array{
     *   creative_analysis: array|null,
     *   copy_alignment: array,
     *   context_analysis: array,
     *   visual_alignment_ai: array|null,
     *   overall_summary: ?string,
     *   brand_copy_conflict: bool,
     *   ebi_ai_trace: array
     * }
     */
    protected function analyzePdfMultiPageCreative(
        Asset $asset,
        AssetContextType $heuristicContext,
        string $prompt,
        array $visionOpts,
        array $trace,
        array $selection,
    ): array {
        $perPageSources = [];
        $parsedPages = [];
        $evaluatedPages = [];

        foreach ($selection['entries'] as $entry) {
            $page = (int) ($entry['page'] ?? 0);
            $path = (string) ($entry['storage_path'] ?? '');
            $origin = is_string($entry['origin'] ?? null) ? (string) $entry['origin'] : 'unknown';
            if ($page < 1) {
                $perPageSources[] = [
                    'page' => $page,
                    'origin' => $origin,
                    'source_type' => 'pdf_rendered_image',
                    'storage_path' => $path !== '' ? $path : null,
                    'resolved' => false,
                    'reason' => 'invalid_page',
                ];

                continue;
            }
            if ($path === '') {
                $perPageSources[] = [
                    'page' => $page,
                    'origin' => $origin,
                    'source_type' => 'pdf_rendered_image',
                    'storage_path' => null,
                    'resolved' => false,
                    'reason' => 'missing_storage_path',
                ];

                continue;
            }

            $dataUrl = $this->aiMetadataGenerationService->fetchStoragePathForVisionAnalysis($asset, $path);
            if ($dataUrl === null || $dataUrl === '') {
                $perPageSources[] = [
                    'page' => $page,
                    'origin' => $origin,
                    'source_type' => 'pdf_rendered_image',
                    'storage_path' => $path,
                    'resolved' => false,
                    'reason' => 'fetch_failed_or_empty',
                ];

                continue;
            }

            try {
                $response = $this->aiProvider->analyzeImage($dataUrl, $prompt, $visionOpts);
            } catch (\Throwable $e) {
                Log::warning('[EBI Creative] Vision analysis failed (PDF page)', [
                    'asset_id' => $asset->id,
                    'page' => $page,
                    'error' => $e->getMessage(),
                ]);
                $perPageSources[] = [
                    'page' => $page,
                    'origin' => $origin,
                    'source_type' => 'pdf_rendered_image',
                    'storage_path' => $path,
                    'resolved' => false,
                    'reason' => 'ai_error: '.$e->getMessage(),
                ];

                continue;
            }

            $parsed = $this->parseCreativeJson($response['text'] ?? '');
            if ($parsed === null) {
                $perPageSources[] = [
                    'page' => $page,
                    'origin' => $origin,
                    'source_type' => 'pdf_rendered_image',
                    'storage_path' => $path,
                    'resolved' => false,
                    'reason' => 'parse_failed',
                ];

                continue;
            }

            $perPageSources[] = [
                'page' => $page,
                'origin' => $origin,
                'source_type' => 'pdf_rendered_image',
                'storage_path' => $path,
                'resolved' => true,
                'reason' => 'ok',
            ];
            $parsedPages[] = $parsed;
            $evaluatedPages[] = $page;
        }

        $combination = count($parsedPages) >= 2
            ? 'merged_multi_page_vision_best_signals'
            : (count($parsedPages) === 1 ? 'single_evaluated_page_from_multi_page_plan' : 'none');

        $trace['pdf_multi_page'] = [
            'pdf_scan_mode' => $trace['pdf_scan_mode'] ?? PdfBrandIntelligenceScanMode::Standard->value,
            'max_pdf_pages_allowed' => (int) ($trace['max_pdf_pages_allowed'] ?? 1),
            'total_pdf_pages_known' => $selection['total_pdf_pages_known'],
            'selected_pdf_pages' => $selection['selected_pages'],
            'evaluated_pdf_pages' => $evaluatedPages,
            'pdf_page_selection_strategy' => $selection['strategy'],
            'per_page_visual_sources' => $perPageSources,
            'page_combination_strategy' => $combination,
        ];

        if ($parsedPages === []) {
            $trace['skip_reason'] = 'no_thumbnail_for_vision';

            return $this->emptyPayload($trace);
        }

        if (count($parsedPages) === 1) {
            $trace['pdf_multi_page']['page_combination_strategy'] = 'single_evaluated_page_from_multi_page_plan';

            return $this->finalizeCreativePayload($parsedPages[0], $heuristicContext, $trace);
        }

        $mergedParsed = $this->mergeParsedCreativeFromPdfPages($parsedPages, $evaluatedPages, $heuristicContext);

        return $this->finalizeCreativePayload($mergedParsed, $heuristicContext, $trace);
    }

    /**
     * @param  array<string, mixed>  $trace
     * @return array{
     *   creative_analysis: array|null,
     *   creative_signals: array|null,
     *   copy_alignment: array,
     *   context_analysis: array,
     *   visual_alignment_ai: array|null,
     *   overall_summary: ?string,
     *   brand_copy_conflict: bool,
     *   ebi_ai_trace: array
     * }
     */
    protected function finalizeCreativePayload(array $parsed, AssetContextType $heuristicContext, array $trace): array
    {
        $trace['creative_ai_ran'] = true;
        $trace['skipped'] = false;
        $trace['skip_reason'] = null;

        $creative = $parsed['creative_analysis'] ?? $parsed;
        if (! is_array($creative)) {
            $creative = [];
        }

        $hasText = $this->detectCopyExtracted($creative);
        $trace['copy_extracted'] = $hasText;

        $copyAlignment = $parsed['copy_alignment'] ?? null;
        if (! is_array($copyAlignment)) {
            $copyAlignment = [
                'score' => null,
                'alignment_state' => 'not_applicable',
                'confidence' => 0.0,
                'reasons' => ['Copy alignment block missing from model output.'],
            ];
        } else {
            $trace['copy_alignment_scored'] = ($copyAlignment['alignment_state'] ?? '') !== 'not_applicable'
                && isset($copyAlignment['score']) && is_numeric($copyAlignment['score']);
        }

        $ctxAnalysis = is_array($parsed['context_analysis'] ?? null)
            ? $parsed['context_analysis']
            : [
                'context_type_heuristic' => $heuristicContext->value,
                'context_type_ai' => is_string($creative['context_type'] ?? null) ? $creative['context_type'] : null,
                'scene_type' => $creative['scene_type'] ?? null,
                'lighting_type' => $creative['lighting_type'] ?? null,
                'mood' => $creative['mood'] ?? null,
            ];
        if (($ctxAnalysis['context_type_heuristic'] ?? null) === null || $ctxAnalysis['context_type_heuristic'] === '') {
            $ctxAnalysis['context_type_heuristic'] = $heuristicContext->value;
        }

        $visualAi = $parsed['visual_alignment'] ?? null;
        if (! is_array($visualAi)) {
            $visualAi = null;
        }

        $summary = is_string($parsed['overall_summary'] ?? null) ? trim((string) $parsed['overall_summary']) : null;
        $summary = $summary === '' ? null : $summary;
        $conflict = filter_var($parsed['brand_copy_conflict'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return [
            'creative_analysis' => $this->normalizeCreativeAnalysis($creative),
            'creative_signals' => $this->normalizeCreativeSignals($parsed, $creative),
            'copy_alignment' => $this->normalizeCopyAlignment($copyAlignment, $hasText),
            'context_analysis' => $ctxAnalysis,
            'visual_alignment_ai' => $visualAi,
            'overall_summary' => $summary,
            'brand_copy_conflict' => $conflict,
            'ebi_ai_trace' => $trace,
        ];
    }

    /**
     * @param  array{
     *     strategy: string,
     *     total_pdf_pages_known: int|null,
     *     selected_pages: list<int>,
     *     entries: list<array{page: int, storage_path: string, origin: string, size_bytes?: int}>
     * }  $selection
     * @return array<string, mixed>
     */
    /**
     * @param  array<string, mixed>  $trace
     */
    protected function buildPdfMultiPageTraceSingleRasterPlan(array $selection, array $trace): array
    {
        $first = $selection['entries'][0] ?? null;
        $page = is_array($first) ? (int) ($first['page'] ?? 1) : 1;

        return [
            'pdf_scan_mode' => $trace['pdf_scan_mode'] ?? PdfBrandIntelligenceScanMode::Standard->value,
            'max_pdf_pages_allowed' => (int) ($trace['max_pdf_pages_allowed'] ?? 1),
            'total_pdf_pages_known' => $selection['total_pdf_pages_known'],
            'selected_pdf_pages' => $selection['selected_pages'],
            'evaluated_pdf_pages' => [$page],
            'pdf_page_selection_strategy' => $selection['strategy'],
            'per_page_visual_sources' => [],
            'page_combination_strategy' => 'single_page_catalog_or_thumbnail',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $parsedPages
     * @param  list<int>  $evaluatedPages
     * @return array<string, mixed>
     */
    protected function mergeParsedCreativeFromPdfPages(array $parsedPages, array $evaluatedPages, AssetContextType $heuristicContext): array
    {
        $creatives = [];
        foreach ($parsedPages as $parsed) {
            $c = $parsed['creative_analysis'] ?? $parsed;
            $creatives[] = is_array($c) ? $c : [];
        }

        $mergedCreative = $this->mergeCreativeAnalysisFields($creatives);

        $voteCounts = [];
        foreach ($creatives as $c) {
            $ct = $c['context_type'] ?? null;
            if (is_string($ct) && trim($ct) !== '') {
                $t = trim($ct);
                $voteCounts[$t] = ($voteCounts[$t] ?? 0) + 1;
            }
        }
        arsort($voteCounts);
        $winnerContext = $voteCounts === [] ? null : array_key_first($voteCounts);
        $n = count($parsedPages);
        $topVotes = $voteCounts === [] ? 0 : max($voteCounts);
        $agreement = $n > 0 ? round($topVotes / $n, 3) : null;

        $copyAlignment = $this->mergeCopyAlignmentFromPages($parsedPages, $this->detectCopyExtracted($mergedCreative));

        $visualAi = $this->mergeVisualAlignmentFromPages($parsedPages);

        $summaries = [];
        foreach ($parsedPages as $i => $parsed) {
            $s = $parsed['overall_summary'] ?? null;
            if (is_string($s) && trim($s) !== '') {
                $p = $evaluatedPages[$i] ?? ($i + 1);
                $summaries[] = 'Page '.$p.': '.trim($s);
            }
        }
        $overallSummary = $summaries === [] ? null : implode(' ', $summaries);

        $conflict = false;
        foreach ($parsedPages as $parsed) {
            if (filter_var($parsed['brand_copy_conflict'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $conflict = true;
                break;
            }
        }

        return [
            'creative_analysis' => $mergedCreative,
            'type_classification' => $this->mergeTypeClassificationFromPages($parsedPages),
            'visual_style' => $this->mergeVisualStyleFromPages($parsedPages),
            'logo_presence' => $this->mergeLogoPresenceFromPages($parsedPages),
            'dominant_colors_visible' => $this->mergeDominantColorsFromPages($parsedPages),
            'context_type' => $winnerContext,
            'copy_alignment' => $copyAlignment,
            'context_analysis' => [
                'context_type_heuristic' => $heuristicContext->value,
                'context_type_ai' => $winnerContext,
                'scene_type' => $mergedCreative['scene_type'] ?? null,
                'lighting_type' => $mergedCreative['lighting_type'] ?? null,
                'mood' => $mergedCreative['mood'] ?? null,
                'multi_page_context_type_votes' => $voteCounts,
                'multi_page_context_agreement' => $agreement,
            ],
            'visual_alignment' => $visualAi,
            'overall_summary' => $overallSummary,
            'brand_copy_conflict' => $conflict,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $creatives
     * @return array<string, mixed>
     */
    protected function mergeCreativeAnalysisFields(array $creatives): array
    {
        $merged = [
            'context_type' => null,
            'scene_type' => null,
            'lighting_type' => null,
            'mood' => null,
            'detected_text' => null,
            'headline_text' => null,
            'supporting_text' => null,
            'cta_text' => null,
            'voice_traits_detected' => [],
            'visual_traits_detected' => [],
        ];

        $voteCounts = [];
        foreach ($creatives as $c) {
            $ct = $c['context_type'] ?? null;
            if (is_string($ct) && trim($ct) !== '') {
                $t = trim($ct);
                $voteCounts[$t] = ($voteCounts[$t] ?? 0) + 1;
            }
        }
        arsort($voteCounts);
        if ($voteCounts !== []) {
            $merged['context_type'] = array_key_first($voteCounts);
        }

        $joinUnique = static function (array $parts, string $sep): ?string {
            $u = [];
            foreach ($parts as $p) {
                if (! is_string($p)) {
                    continue;
                }
                $t = trim($p);
                if ($t === '') {
                    continue;
                }
                $u[$t] = true;
            }

            return $u === [] ? null : implode($sep, array_keys($u));
        };

        foreach (['scene_type', 'lighting_type', 'mood'] as $k) {
            $parts = [];
            foreach ($creatives as $c) {
                $v = $c[$k] ?? null;
                if (is_string($v) && trim($v) !== '') {
                    $parts[] = trim($v);
                }
            }
            $merged[$k] = $joinUnique($parts, '; ');
        }

        foreach (['detected_text', 'headline_text', 'supporting_text', 'cta_text'] as $k) {
            $parts = [];
            foreach ($creatives as $c) {
                $v = $c[$k] ?? null;
                if (is_string($v) && trim($v) !== '') {
                    $parts[] = trim($v);
                }
            }
            $merged[$k] = $joinUnique($parts, "\n");
        }

        $voices = [];
        $visuals = [];
        foreach ($creatives as $c) {
            foreach ($this->stringList($c['voice_traits_detected'] ?? []) as $t) {
                $voices[$t] = true;
            }
            foreach ($this->stringList($c['visual_traits_detected'] ?? []) as $t) {
                $visuals[$t] = true;
            }
        }
        $merged['voice_traits_detected'] = array_slice(array_keys($voices), 0, 24);
        $merged['visual_traits_detected'] = array_slice(array_keys($visuals), 0, 24);

        return $merged;
    }

    /**
     * @param  list<array<string, mixed>>  $parsedPages
     * @return array<string, mixed>
     */
    protected function mergeCopyAlignmentFromPages(array $parsedPages, bool $mergedHasText): array
    {
        $best = null;
        $bestWeight = -1.0;
        foreach ($parsedPages as $parsed) {
            $ca = $parsed['copy_alignment'] ?? null;
            if (! is_array($ca)) {
                continue;
            }
            $state = is_string($ca['alignment_state'] ?? null) ? $ca['alignment_state'] : 'not_applicable';
            $score = $ca['score'] ?? null;
            $conf = is_numeric($ca['confidence'] ?? null) ? (float) $ca['confidence'] : 0.0;
            if (! in_array($state, ['aligned', 'partial', 'off_brand'], true)) {
                continue;
            }
            if ($score === null || ! is_numeric($score)) {
                continue;
            }
            $weight = (float) $score * max(0.01, $conf);
            if ($weight > $bestWeight) {
                $bestWeight = $weight;
                $best = $ca;
            }
        }

        $reasons = [];
        foreach ($parsedPages as $parsed) {
            $ca = $parsed['copy_alignment'] ?? null;
            if (! is_array($ca) || ! isset($ca['reasons']) || ! is_array($ca['reasons'])) {
                continue;
            }
            foreach ($ca['reasons'] as $r) {
                if (is_string($r) && trim($r) !== '') {
                    $reasons[] = trim($r);
                }
            }
        }
        $reasons = array_slice(array_values(array_unique($reasons)), 0, 8);

        if ($best !== null) {
            $merged = $best;
            if ($reasons !== []) {
                $existing = isset($merged['reasons']) && is_array($merged['reasons']) ? $merged['reasons'] : [];
                $merged['reasons'] = array_slice(array_values(array_unique(array_merge(
                    array_filter($existing, static fn ($x) => is_string($x) && trim($x) !== ''),
                    $reasons
                ))), 0, 8);
            }

            return $merged;
        }

        return [
            'score' => null,
            'alignment_state' => 'not_applicable',
            'confidence' => 0.0,
            'reasons' => $reasons !== [] ? $reasons : ['No scored copy alignment across evaluated PDF pages.'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $parsedPages
     * @return array<string, mixed>|null
     */
    protected function mergeVisualAlignmentFromPages(array $parsedPages): ?array
    {
        $best = null;
        $bestKey = -1.0;
        foreach ($parsedPages as $parsed) {
            $va = $parsed['visual_alignment'] ?? null;
            if (! is_array($va)) {
                continue;
            }
            $fit = $va['fit_score'] ?? null;
            $conf = $va['confidence'] ?? null;
            if (! is_numeric($fit)) {
                continue;
            }
            $c = is_numeric($conf) ? (float) $conf : 0.5;
            $key = (float) $fit * max(0.05, $c);
            if ($key > $bestKey) {
                $bestKey = $key;
                $best = $va;
            }
        }

        if ($best === null) {
            return null;
        }

        $summaries = [];
        foreach ($parsedPages as $parsed) {
            $va = $parsed['visual_alignment'] ?? null;
            if (! is_array($va)) {
                continue;
            }
            $s = $va['summary'] ?? null;
            if (is_string($s) && trim($s) !== '') {
                $summaries[] = trim($s);
            }
        }
        if ($summaries !== []) {
            $best['summary'] = implode(' ', array_slice(array_values(array_unique($summaries)), 0, 3));
        }

        return $best;
    }

    /**
     * @return array{voice: ?string, tone: ?string, personality: ?string, positioning: ?string, promise: ?string, messaging: ?string}
     */
    public function extractBrandDnaForCopyAlignment(Brand $brand): array
    {
        $brand->loadMissing('brandModel.activeVersion');
        $payload = $brand->brandModel?->activeVersion?->model_payload ?? [];
        $personality = is_array($payload['personality'] ?? null) ? $payload['personality'] : [];
        $positioning = is_array($payload['positioning'] ?? null) ? $payload['positioning'] : [];
        $messaging = is_array($payload['messaging'] ?? null) ? $payload['messaging'] : [];
        $rules = is_array($payload['scoring_rules'] ?? null) ? $payload['scoring_rules'] : [];

        $toneKeywords = $rules['tone_keywords'] ?? null;
        $toneStr = null;
        if (is_array($toneKeywords)) {
            $flat = [];
            foreach ($toneKeywords as $item) {
                if (is_string($item)) {
                    $flat[] = $item;
                } elseif (is_array($item)) {
                    $flat[] = (string) ($item['label'] ?? $item['value'] ?? $item['text'] ?? '');
                }
            }
            $flat = array_filter(array_map('trim', $flat));
            $toneStr = $flat !== [] ? implode(', ', $flat) : null;
        }

        return [
            'voice' => $this->str($personality['voice'] ?? $personality['brand_voice'] ?? null),
            'tone' => $this->str($personality['tone'] ?? null),
            'personality' => $this->str($personality['personality'] ?? $personality['brand_personality'] ?? null),
            'positioning' => $this->str($positioning['statement'] ?? $positioning['value_prop'] ?? $positioning['positioning'] ?? null),
            'promise' => $this->str($positioning['promise'] ?? null),
            'messaging' => $this->str(is_string($messaging['guidance'] ?? null) ? $messaging['guidance'] : (is_array($messaging['pillars'] ?? null) ? implode('; ', array_filter($messaging['pillars'])) : null)),
            'tone_keywords' => $toneStr,
        ];
    }

    protected function str(mixed $v): ?string
    {
        if (! is_string($v)) {
            return null;
        }
        $t = trim($v);

        return $t === '' ? null : $t;
    }

    /**
     * @param  array<string, mixed>  $trace
     * @return array{creative_analysis: null, creative_signals: null, copy_alignment: array, context_analysis: array, visual_alignment_ai: null, ebi_ai_trace: array}
     */
    protected function emptyPayload(array $trace): array
    {
        return [
            'creative_analysis' => null,
            'creative_signals' => null,
            'copy_alignment' => [
                'score' => null,
                'alignment_state' => 'not_applicable',
                'confidence' => 0.0,
                'reasons' => [],
            ],
            'context_analysis' => [
                'context_type_heuristic' => null,
                'context_type_ai' => null,
                'scene_type' => null,
                'lighting_type' => null,
                'mood' => null,
            ],
            'visual_alignment_ai' => null,
            'overall_summary' => null,
            'brand_copy_conflict' => false,
            'ebi_ai_trace' => $trace,
        ];
    }

    protected function buildVisionPrompt(AssetContextType $heuristicContext, array $dna): string
    {
        $dnaJson = json_encode($dna, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return <<<PROMPT
You are a brand creative director. Analyze this image for Brand Intelligence: separate VISUAL traits from COPY (text in the image).

Brand DNA (evaluate copy against these when text exists):
{$dnaJson}

Heuristic context hint from filename/metadata: {$heuristicContext->value}

Return JSON only with this shape:
{
  "creative_analysis": {
    "context_type": "<one of: product_hero, lifestyle, digital_ad, social_post, logo_only, other>",
    "scene_type": "<short string>",
    "lighting_type": "<short string>",
    "mood": "<short string>",
    "detected_text": "<all readable text, space-separated or empty>",
    "headline_text": "<primary headline or empty>",
    "supporting_text": "<subcopy or empty>",
    "cta_text": "<CTA/button text or empty>",
    "voice_traits_detected": ["<trait>", "..."],
    "visual_traits_detected": ["<trait>", "..."]
  },
  "type_classification": {
    "primary_category": "<one of: sans_serif, serif, display, monospace, script, mixed, none>",
    "weight_hint": "<one of: bold, medium, regular, light, variable, unknown>",
    "all_caps_detected": <true|false>,
    "confidence": <0-1>,
    "notes": "<optional short note on distinctive type characteristics>"
  },
  "visual_style": ["<short kebab-case tag>", "..."],
  "logo_presence": {
    "present": <true|false>,
    "brand_name_visible": <true|false>,
    "placement": "<one of: top-left, top-center, top-right, center-left, center, center-right, bottom-left, bottom-center, bottom-right, tiled, none>",
    "region": {"x": <0-1>, "y": <0-1>, "w": <0-1>, "h": <0-1>},
    "confidence": <0-1>
  },
  "dominant_colors_visible": [
    {"hex": "#RRGGBB", "role": "<one of: primary, secondary, accent, background, text>", "coverage": <0-1>}
  ],
  "context_type": "<same taxonomy as creative_analysis.context_type, repeated here for direct access>",
  "visual_alignment": {
    "summary": "<one sentence how visuals fit a premium brand look>",
    "fit_score": <0-100 integer estimate of visual brand fit from the image alone>,
    "confidence": <0-1>
  },
  "copy_alignment": {
    "score": <0-100 or null if no meaningful copy in image>,
    "alignment_state": "<aligned|partial|off_brand|not_applicable|insufficient>",
    "confidence": <0-1>,
    "reasons": ["<short bullet>", "..."]
  },
  "overall_summary": "<2-3 sentences: combine visual + copy; if copy is missing or illegible, do NOT penalize visual assessment>",
  "brand_copy_conflict": <true only if on-image copy clearly contradicts Brand DNA; otherwise false>
}

Rules:
- If there is no text or only trivial text (logos, watermarks), set copy_alignment.alignment_state to not_applicable or insufficient and copy_alignment.score null.
- voice_traits_detected / visual_traits_detected: short phrases.
- Do not invent long passages of text; detected_text should reflect what you actually see.
- visual_style tags must be short kebab-case (e.g. "outdoor-lifestyle", "earth-tones", "rugged-craft", "premium-editorial"); return at most 8 tags that are clearly visible in the image.
- logo_presence.region uses normalized coordinates (0..1) where x,y is the top-left and w,h is width/height of the logo bounding box. If no logo is visible, set present=false and region to zeros.
- dominant_colors_visible: up to 6 entries, ordered by perceptual coverage. Assign role based on visual prominence (primary = most brand-forward large areas; background = neutral fill; text = legible typography color).
- type_classification should reflect the most prominent typographic treatment in the image. If text is absent or illegible, use primary_category="none" and weight_hint="unknown".
- Do not invent long passages of text; detected_text should reflect what you actually see.
PROMPT;
    }

    protected function parseCreativeJson(string $text): ?array
    {
        $raw = trim($text);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/```(?:json)?\s*(\{[\s\S]*\})\s*```/m', $raw, $m)) {
            $raw = $m[1];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $creative
     */
    protected function detectCopyExtracted(array $creative): bool
    {
        foreach (['detected_text', 'headline_text', 'supporting_text', 'cta_text'] as $k) {
            $v = $creative[$k] ?? '';
            if (is_string($v) && trim($v) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $c
     * @return array<string, mixed>
     */
    protected function normalizeCreativeAnalysis(array $c): array
    {
        return [
            'context_type' => is_string($c['context_type'] ?? null) ? $c['context_type'] : null,
            'scene_type' => is_string($c['scene_type'] ?? null) ? $c['scene_type'] : null,
            'lighting_type' => is_string($c['lighting_type'] ?? null) ? $c['lighting_type'] : null,
            'mood' => is_string($c['mood'] ?? null) ? $c['mood'] : null,
            'detected_text' => is_string($c['detected_text'] ?? null) ? $c['detected_text'] : null,
            'headline_text' => is_string($c['headline_text'] ?? null) ? $c['headline_text'] : null,
            'supporting_text' => is_string($c['supporting_text'] ?? null) ? $c['supporting_text'] : null,
            'cta_text' => is_string($c['cta_text'] ?? null) ? $c['cta_text'] : null,
            'voice_traits_detected' => $this->stringList($c['voice_traits_detected'] ?? []),
            'visual_traits_detected' => $this->stringList($c['visual_traits_detected'] ?? []),
        ];
    }

    /**
     * Produce the structured, evaluator-facing "creative_signals" subset. Designed to be
     * directly consumed by TypographyEvaluator / VisualStyleEvaluator / ColorEvaluator /
     * ContextFitEvaluator / IdentityEvaluator in Stage 3 without re-parsing raw VLM output.
     *
     * Every subfield is optional. Callers should treat missing or null values as
     * "no signal" and fall back to existing evaluation paths.
     *
     * @param  array<string, mixed>  $parsed    The full VLM JSON (top-level).
     * @param  array<string, mixed>  $creative  The creative_analysis sub-object (already unpacked).
     * @return array<string, mixed>|null
     */
    protected function normalizeCreativeSignals(array $parsed, array $creative): ?array
    {
        $type = $this->normalizeTypeClassification($parsed['type_classification'] ?? null);
        $style = $this->normalizeVisualStyleList($parsed['visual_style'] ?? null);
        $logo = $this->normalizeLogoPresence($parsed['logo_presence'] ?? null);
        $colors = $this->normalizeDominantColorsVisible($parsed['dominant_colors_visible'] ?? null);
        $contextType = is_string($parsed['context_type'] ?? null) && trim($parsed['context_type']) !== ''
            ? trim($parsed['context_type'])
            : (is_string($creative['context_type'] ?? null) ? $creative['context_type'] : null);

        $anyPresent = $type !== null
            || $style !== []
            || $logo !== null
            || $colors !== []
            || $contextType !== null;

        if (! $anyPresent) {
            return null;
        }

        return [
            'type_classification' => $type,
            'visual_style' => $style,
            'logo_presence' => $logo,
            'dominant_colors_visible' => $colors,
            'context_type' => $contextType,
        ];
    }

    /**
     * @return array{primary_category: ?string, weight_hint: ?string, all_caps_detected: bool, confidence: float, notes: ?string}|null
     */
    protected function normalizeTypeClassification(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }
        static $categories = ['sans_serif', 'serif', 'display', 'monospace', 'script', 'mixed', 'none'];
        static $weights = ['bold', 'medium', 'regular', 'light', 'variable', 'unknown'];

        $cat = is_string($raw['primary_category'] ?? null)
            ? strtolower(str_replace('-', '_', trim($raw['primary_category'])))
            : null;
        if ($cat !== null && ! in_array($cat, $categories, true)) {
            $cat = null;
        }

        $weight = is_string($raw['weight_hint'] ?? null)
            ? strtolower(trim($raw['weight_hint']))
            : null;
        if ($weight !== null && ! in_array($weight, $weights, true)) {
            $weight = null;
        }

        $allCaps = filter_var($raw['all_caps_detected'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $conf = is_numeric($raw['confidence'] ?? null)
            ? round(max(0.0, min(1.0, (float) $raw['confidence'])), 2)
            : 0.0;
        $notes = is_string($raw['notes'] ?? null) && trim($raw['notes']) !== ''
            ? trim($raw['notes'])
            : null;

        if ($cat === null && $weight === null && ! $allCaps && $notes === null) {
            return null;
        }

        return [
            'primary_category' => $cat,
            'weight_hint' => $weight,
            'all_caps_detected' => $allCaps,
            'confidence' => $conf,
            'notes' => $notes,
        ];
    }

    /**
     * @return list<string>
     */
    protected function normalizeVisualStyleList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $item) {
            if (! is_string($item)) {
                continue;
            }
            $slug = strtolower(trim($item));
            $slug = preg_replace('/[^a-z0-9]+/', '-', $slug ?? '');
            $slug = trim((string) $slug, '-');
            if ($slug === '' || mb_strlen($slug, 'UTF-8') < 2) {
                continue;
            }
            $out[$slug] = true;
            if (count($out) >= 10) {
                break;
            }
        }

        return array_keys($out);
    }

    /**
     * @return array{present: bool, brand_name_visible: bool, placement: ?string, region: ?array{x: float, y: float, w: float, h: float}, confidence: float}|null
     */
    protected function normalizeLogoPresence(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }
        static $placements = [
            'top-left', 'top-center', 'top-right',
            'center-left', 'center', 'center-right',
            'bottom-left', 'bottom-center', 'bottom-right',
            'tiled', 'none',
        ];

        $present = filter_var($raw['present'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $nameVisible = filter_var($raw['brand_name_visible'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $placement = is_string($raw['placement'] ?? null) ? strtolower(trim($raw['placement'])) : null;
        if ($placement !== null && ! in_array($placement, $placements, true)) {
            $placement = null;
        }
        $conf = is_numeric($raw['confidence'] ?? null)
            ? round(max(0.0, min(1.0, (float) $raw['confidence'])), 2)
            : 0.0;

        $region = null;
        if (is_array($raw['region'] ?? null)) {
            $r = $raw['region'];
            $x = is_numeric($r['x'] ?? null) ? (float) $r['x'] : null;
            $y = is_numeric($r['y'] ?? null) ? (float) $r['y'] : null;
            $w = is_numeric($r['w'] ?? null) ? (float) $r['w'] : null;
            $h = is_numeric($r['h'] ?? null) ? (float) $r['h'] : null;
            if ($x !== null && $y !== null && $w !== null && $h !== null
                && $w > 0.0 && $h > 0.0
                && $x >= 0.0 && $y >= 0.0
                && $x + $w <= 1.001 && $y + $h <= 1.001
            ) {
                $region = [
                    'x' => round(max(0.0, min(1.0, $x)), 4),
                    'y' => round(max(0.0, min(1.0, $y)), 4),
                    'w' => round(max(0.0, min(1.0, $w)), 4),
                    'h' => round(max(0.0, min(1.0, $h)), 4),
                ];
            }
        }

        if (! $present && ! $nameVisible && $placement === null && $region === null && $conf === 0.0) {
            return null;
        }

        return [
            'present' => $present,
            'brand_name_visible' => $nameVisible,
            'placement' => $placement,
            'region' => $present ? $region : null,
            'confidence' => $conf,
        ];
    }

    /**
     * @return list<array{hex: string, role: ?string, coverage: float}>
     */
    protected function normalizeDominantColorsVisible(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        static $roles = ['primary', 'secondary', 'accent', 'background', 'text'];
        $out = [];
        foreach ($raw as $item) {
            if (! is_array($item)) {
                continue;
            }
            $hex = is_string($item['hex'] ?? null) ? strtoupper(trim($item['hex'])) : null;
            if ($hex === null) {
                continue;
            }
            if (preg_match('/^#?[0-9A-F]{6}$/', $hex)) {
                $hex = '#' . substr($hex, -6);
            } else {
                continue;
            }
            $role = is_string($item['role'] ?? null) ? strtolower(trim($item['role'])) : null;
            if ($role !== null && ! in_array($role, $roles, true)) {
                $role = null;
            }
            $cov = is_numeric($item['coverage'] ?? null)
                ? round(max(0.0, min(1.0, (float) $item['coverage'])), 3)
                : 0.0;

            $out[] = [
                'hex' => $hex,
                'role' => $role,
                'coverage' => $cov,
            ];
            if (count($out) >= 6) {
                break;
            }
        }

        return $out;
    }

    /**
     * Pick the highest-confidence non-empty type classification across PDF pages.
     *
     * @param  list<array<string, mixed>>  $parsedPages
     * @return array<string, mixed>|null
     */
    protected function mergeTypeClassificationFromPages(array $parsedPages): ?array
    {
        $best = null;
        $bestConf = -1.0;
        foreach ($parsedPages as $parsed) {
            $t = $this->normalizeTypeClassification($parsed['type_classification'] ?? null);
            if ($t === null) {
                continue;
            }
            $c = (float) ($t['confidence'] ?? 0.0);
            if ($c > $bestConf) {
                $best = $t;
                $bestConf = $c;
            }
        }

        return $best;
    }

    /**
     * Union of style tags across pages, capped.
     *
     * @param  list<array<string, mixed>>  $parsedPages
     * @return list<string>
     */
    protected function mergeVisualStyleFromPages(array $parsedPages): array
    {
        $seen = [];
        foreach ($parsedPages as $parsed) {
            foreach ($this->normalizeVisualStyleList($parsed['visual_style'] ?? null) as $tag) {
                $seen[$tag] = true;
            }
        }

        return array_slice(array_keys($seen), 0, 10);
    }

    /**
     * Prefer the page where a logo is actually present (highest confidence).
     *
     * @param  list<array<string, mixed>>  $parsedPages
     * @return array<string, mixed>|null
     */
    protected function mergeLogoPresenceFromPages(array $parsedPages): ?array
    {
        $best = null;
        $bestConf = -1.0;
        foreach ($parsedPages as $parsed) {
            $l = $this->normalizeLogoPresence($parsed['logo_presence'] ?? null);
            if ($l === null) {
                continue;
            }
            $score = ($l['present'] ? 1.0 : 0.0) + (float) ($l['confidence'] ?? 0.0);
            if ($score > $bestConf) {
                $best = $l;
                $bestConf = $score;
            }
        }

        return $best;
    }

    /**
     * Concatenate all dominant colors across pages, de-duped on hex, capped.
     *
     * @param  list<array<string, mixed>>  $parsedPages
     * @return list<array<string, mixed>>
     */
    protected function mergeDominantColorsFromPages(array $parsedPages): array
    {
        $byHex = [];
        foreach ($parsedPages as $parsed) {
            foreach ($this->normalizeDominantColorsVisible($parsed['dominant_colors_visible'] ?? null) as $c) {
                $hex = (string) ($c['hex'] ?? '');
                if ($hex === '') {
                    continue;
                }
                if (! isset($byHex[$hex]) || ($byHex[$hex]['coverage'] ?? 0.0) < ($c['coverage'] ?? 0.0)) {
                    $byHex[$hex] = $c;
                }
            }
        }
        $list = array_values($byHex);
        usort($list, static fn ($a, $b) => ($b['coverage'] ?? 0.0) <=> ($a['coverage'] ?? 0.0));

        return array_slice($list, 0, 6);
    }

    /**
     * @param  array<string, mixed>  $ca
     */
    protected function normalizeCopyAlignment(array $ca, bool $hasText): array
    {
        $score = $ca['score'] ?? null;
        if ($score !== null && is_numeric($score)) {
            $score = (int) round(max(0, min(100, (float) $score)));
        } else {
            $score = null;
        }

        $state = is_string($ca['alignment_state'] ?? null) ? $ca['alignment_state'] : 'not_applicable';
        $conf = $ca['confidence'] ?? 0.0;
        $conf = is_numeric($conf) ? round(max(0.0, min(1.0, (float) $conf)), 2) : 0.0;

        $reasons = [];
        if (isset($ca['reasons']) && is_array($ca['reasons'])) {
            foreach ($ca['reasons'] as $r) {
                if (is_string($r) && trim($r) !== '') {
                    $reasons[] = trim($r);
                }
            }
        }

        if (! $hasText && ($state === 'aligned' || $state === 'partial' || $state === 'off_brand')) {
            $state = 'not_applicable';
            $score = null;
            $reasons[] = 'No extractable marketing copy in image.';
        }

        return [
            'score' => $score,
            'alignment_state' => $state,
            'confidence' => $conf,
            'reasons' => array_slice($reasons, 0, 8),
        ];
    }

    protected function stringList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            }
        }

        return array_slice($out, 0, 24);
    }
}
