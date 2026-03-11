<?php

namespace App\Jobs;

use App\Models\Asset;
use App\Models\Brand;
use App\Models\BrandIngestionRecord;
use App\Models\BrandPdfVisionExtraction;
use App\Models\BrandModelVersion;
use App\Models\BrandResearchSnapshot;
use App\Services\BrandDNA\BrandAlignmentEngine;
use App\Services\BrandDNA\BrandCoherenceScoringService;
use App\Services\BrandDNA\BrandWebsiteCrawlerService;
use App\Services\BrandDNA\Extraction\BrandExtractionSchema;
use App\Services\BrandDNA\Extraction\BrandGuidelinesProcessor;
use App\Services\BrandDNA\Extraction\BrandMaterialProcessor;
use App\Services\BrandDNA\Extraction\SectionAwareBrandGuidelinesProcessor;
use App\Services\BrandDNA\AutoApplyHighConfidenceSuggestions;
use App\Services\BrandDNA\BrandResearchReportBuilder;
use App\Services\BrandDNA\ExtractionEvidenceMapBuilder;
use App\Services\BrandDNA\FieldCandidateValidationService;
use App\Services\BrandDNA\Extraction\ExtractionSuggestionService;
use App\Services\BrandDNA\Extraction\ExtractionQualityValidator;
use App\Services\BrandDNA\BrandResearchNotificationService;
use App\Services\BrandDNA\Extraction\WebsiteExtractionProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Unified Brand Ingestion Job.
 * Extracts from PDF, website, materials; merges; creates snapshot with suggestions.
 * Does NOT auto-mutate draft.
 */
class RunBrandIngestionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $brandId,
        public int $brandModelVersionId,
        public mixed $pdfAssetId = null,
        public ?string $websiteUrl = null,
        public array $materialAssetIds = []
    ) {}

    public function handle(
        BrandWebsiteCrawlerService $crawlerService,
        BrandCoherenceScoringService $coherenceService,
        BrandAlignmentEngine $alignmentEngine,
        BrandGuidelinesProcessor $pdfProcessor,
        WebsiteExtractionProcessor $websiteProcessor,
        BrandMaterialProcessor $materialProcessor,
        ExtractionSuggestionService $suggestionService
    ): void {
        $brand = Brand::find($this->brandId);
        if (! $brand) {
            return;
        }

        $draft = BrandModelVersion::find($this->brandModelVersionId);
        if (! $draft || ($draft->brandModel?->brand_id ?? null) !== $brand->id) {
            return;
        }

            $record = BrandIngestionRecord::create([
                'brand_id' => $brand->id,
                'brand_model_version_id' => $draft->id,
                'status' => BrandIngestionRecord::STATUS_PROCESSING,
            ]);

        $visualEnabled = config('brand_dna.visual_page_extraction_enabled', false);
        Log::info('[RunBrandIngestionJob] Pipeline config', [
            'draft_id' => $draft->id,
            'visual_page_extraction_enabled' => $visualEnabled,
            'env' => config('app.env'),
            'pdf_asset_id' => $this->pdfAssetId,
        ]);

        try {
            $extractions = [];

            if ($this->pdfAssetId) {
                $pdfExt = $this->processPdf($pdfProcessor);
                if ($pdfExt) {
                    $extractions[] = $pdfExt;
                }
            }

            if ($this->websiteUrl) {
                $crawlData = $crawlerService->crawl($this->websiteUrl);
                $extractions[] = $websiteProcessor->process($crawlData);
            }

            if (! empty($this->materialAssetIds)) {
                $assets = Asset::whereIn('id', $this->materialAssetIds)
                    ->where('brand_id', $brand->id)
                    ->get();
                if ($assets->isNotEmpty()) {
                    $extractions[] = $materialProcessor->process($assets);
                }
            }

            $extraction = empty($extractions)
                ? BrandExtractionSchema::empty()
                : BrandExtractionSchema::merge(...$extractions);

            $this->ensureExplicitSignalsFromExtraction($extraction);

            $validationService = app(FieldCandidateValidationService::class);
            $extraction = $validationService->sanitizeMergedExtraction($extraction);

            $evidenceMap = app(ExtractionEvidenceMapBuilder::class)->build($extractions, $extraction);
            $extraction['evidence_map'] = $evidenceMap;

            $this->enrichPageAnalysisWithMergeContributions($extraction);

            $extraction['narrative_field_debug'] = $this->buildNarrativeFieldDebug($extraction);

            foreach ($extractions as $ext) {
                if (! empty($ext['page_classifications_json'])) {
                    $extraction['page_classifications_json'] = $ext['page_classifications_json'];
                }
                if (! empty($ext['page_extractions_json'])) {
                    $extraction['page_extractions_json'] = $ext['page_extractions_json'];
                }
                if (! empty($ext['page_analysis'])) {
                    $extraction['page_analysis'] = $ext['page_analysis'];
                }
                if (! empty($ext['rejected_field_candidates'])) {
                    $extraction['rejected_field_candidates'] = $ext['rejected_field_candidates'];
                }
            }

            $conflicts = $extraction['conflicts'] ?? [];

            $record->update(['extraction_json' => $extraction]);

            $activeSources = $this->deriveActiveSources();
            $suggestions = $suggestionService->generateSuggestions($extraction, $conflicts, $activeSources);

            [$draft, $autoApplyBlocked] = AutoApplyHighConfidenceSuggestions::apply($draft, $suggestions);
            if (! empty($autoApplyBlocked)) {
                $extraction['_extraction_debug'] = array_merge($extraction['_extraction_debug'] ?? [], [
                    'auto_apply_blocked' => $autoApplyBlocked,
                ]);
            }

            $snapshotPayload = $this->extractionToSnapshotPayload($extraction);
            $snapshotPayload['conflicts'] = $conflicts;
            $snapshotPayload['pipeline_version'] = '1.0';
            $snapshotPayload['visual_pipeline_enabled'] = config('brand_dna.visual_page_extraction_enabled', false);
            $snapshotPayload['snapshot_generated_at'] = now()->toIso8601String();

            Log::info('[RunBrandIngestionJob] Snapshot payload before save', [
                'draft_id' => $draft->id,
                'has_page_classifications_json' => ! empty($extraction['page_classifications_json']),
                'has_page_extractions_json' => ! empty($extraction['page_extractions_json']),
                'has_page_analysis' => ! empty($extraction['page_analysis']),
                'page_classifications_count' => isset($extraction['page_classifications_json']) ? count($extraction['page_classifications_json']) : 0,
                'page_extractions_count' => isset($extraction['page_extractions_json']) ? count($extraction['page_extractions_json']) : 0,
            ]);

            $sectionsJson = $this->buildSectionsJson($extraction);

            $brandMaterialCount = $draft->assetsForContext('brand_material')->count();
            $coherence = $coherenceService->score(
                $draft->model_payload ?? [],
                $suggestions,
                $snapshotPayload,
                $brand,
                $brandMaterialCount,
                $conflicts
            );
            $alignment = $alignmentEngine->analyze($draft->model_payload ?? []);

            $report = BrandResearchReportBuilder::build(
                $snapshotPayload,
                $suggestions,
                $coherence,
                $alignment,
                $activeSources
            );

            $snapshot = BrandResearchSnapshot::create([
                'brand_id' => $brand->id,
                'brand_model_version_id' => $draft->id,
                'source_url' => $this->websiteUrl ?? 'ingestion',
                'status' => 'completed',
                'snapshot' => $snapshotPayload,
                'suggestions' => $suggestions,
                'coherence' => $coherence,
                'alignment' => $alignment,
                'report' => $report,
                'sections_json' => $sectionsJson,
                'page_classifications_json' => $extraction['page_classifications_json'] ?? null,
                'page_extractions_json' => $extraction['page_extractions_json'] ?? null,
            ]);

            $draft->getOrCreateInsightState($snapshot->id);

            $record->update(['status' => BrandIngestionRecord::STATUS_COMPLETED]);

            app(BrandResearchNotificationService::class)->maybeNotifyResearchReady($brand, $draft);

            $extractedSignalCount = $this->countExtractionSignals($extraction);
            Log::info('Brand ingestion summary', [
                'draft_id' => $draft->id,
                'pdf_asset_id' => $this->pdfAssetId,
                'extracted_signal_count' => $extractedSignalCount,
                'suggestion_count' => count($suggestions),
                'snapshot_created' => true,
            ]);
        } catch (\Throwable $e) {
            Log::error('[RunBrandIngestionJob] Ingestion failed', [
                'draft_id' => $this->brandModelVersionId,
                'pdf_asset_id' => $this->pdfAssetId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $record->update([
                'status' => BrandIngestionRecord::STATUS_FAILED,
                'extraction_json' => array_merge($record->extraction_json ?? [], ['error' => $e->getMessage()]),
                'failure_reason' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    protected function processPdf(BrandGuidelinesProcessor $processor): ?array
    {
        $asset = Asset::find($this->pdfAssetId);
        if (! $asset) {
            return null;
        }

        $visionExtraction = BrandPdfVisionExtraction::where('asset_id', $asset->id)
            ->where('brand_id', $this->brandId)
            ->where('brand_model_version_id', $this->brandModelVersionId)
            ->where('status', BrandPdfVisionExtraction::STATUS_COMPLETED)
            ->latest()
            ->first();

        if ($visionExtraction && ! empty($visionExtraction->extraction_json)) {
            return $visionExtraction->extraction_json;
        }

        $extraction = $asset->getLatestPdfTextExtractionForVersion($asset->currentVersion?->id);
        if (! $extraction || ! $extraction->isComplete()) {
            return null;
        }

        $text = trim($extraction->extracted_text ?? '');
        if ($text === '') {
            return null;
        }

        return app(SectionAwareBrandGuidelinesProcessor::class)->process($text);
    }

    protected function countExtractionSignals(array $extraction): int
    {
        $count = 0;
        foreach (['identity', 'personality', 'visual'] as $section) {
            $data = $extraction[$section] ?? [];
            if (! is_array($data)) {
                continue;
            }
            foreach ($data as $v) {
                if ($v !== null && $v !== '' && $v !== []) {
                    $count++;
                }
            }
        }
        return $count;
    }

    protected function deriveActiveSources(): array
    {
        $sources = [];
        if ($this->pdfAssetId) {
            $sources[] = 'pdf';
        }
        if ($this->websiteUrl) {
            $sources[] = 'website';
        }
        if (! empty($this->materialAssetIds)) {
            $sources[] = 'materials';
        }

        return $sources;
    }

    protected function buildSectionsJson(array $extraction): ?array
    {
        $sections = $extraction['sections'] ?? [];
        $tocMap = $extraction['toc_map'] ?? [];
        $sectionMetadata = $extraction['_extraction_debug']['section_metadata'] ?? [];
        $extractionDebug = $extraction['_extraction_debug'] ?? [];
        if (empty($sections) && empty($tocMap)) {
            return null;
        }

        $metaByTitle = [];
        foreach ($sectionMetadata as $m) {
            $metaByTitle[strtoupper($m['title'] ?? '')] = $m;
        }

        $result = [
            'sections' => array_map(static function ($s) use ($metaByTitle) {
                $title = $s['title'] ?? '';
                $meta = $metaByTitle[strtoupper($title)] ?? $s;
                return [
                    'title' => $title,
                    'page' => $s['page'] ?? null,
                    'source' => $meta['source'] ?? $s['source'] ?? 'heuristic',
                    'content_length' => $meta['content_length'] ?? $s['content_length'] ?? 0,
                    'quality_score' => $meta['quality_score'] ?? $s['quality_score'] ?? 0.5,
                    'used_for_extraction' => $meta['used_for_extraction'] ?? false,
                ];
            }, $sections),
            'toc_map' => $tocMap,
        ];

        if (isset($extractionDebug['section_count_raw'])) {
            $result['section_count_raw'] = $extractionDebug['section_count_raw'];
        }
        if (isset($extractionDebug['section_count_usable'])) {
            $result['section_count_usable'] = $extractionDebug['section_count_usable'];
        }
        if (isset($extractionDebug['section_count_suppressed'])) {
            $result['section_count_suppressed'] = $extractionDebug['section_count_suppressed'];
        }
        if (! empty($extractionDebug['suppressed_sections'])) {
            $result['suppressed_sections'] = $extractionDebug['suppressed_sections'];
        }
        if (! empty($extractionDebug['collapsed_sections'])) {
            $result['collapsed_sections'] = $extractionDebug['collapsed_sections'];
        }

        return $result;
    }

    protected function extractionToSnapshotPayload(array $extraction): array
    {
        $visual = $extraction['visual'] ?? [];
        $colors = $visual['primary_colors'] ?? [];
        $fonts = $visual['fonts'] ?? [];
        $sectionQualityByPath = $extraction['_extraction_debug']['section_quality_by_path'] ?? [];
        $minQuality = \App\Services\BrandDNA\Extraction\SectionAwareBrandGuidelinesProcessor::MIN_QUALITY_SCORE;

        $brandBio = $this->unwrapScalar($extraction['identity']['positioning'] ?? $extraction['sources']['website']['brand_bio'] ?? null);
        if ($brandBio !== null && ExtractionQualityValidator::isLowQualityExtractedValue($brandBio)) {
            $brandBio = null;
        }
        if ($brandBio !== null && isset($extraction['identity']['positioning']) && isset($sectionQualityByPath['identity.positioning'])) {
            $q = (float) $sectionQualityByPath['identity.positioning'];
            if ($q < $minQuality) {
                $brandBio = null;
            }
        }

        $payload = [
            'logo_url' => $visual['logo_detected'] ?? null,
            'primary_colors' => $this->normalizeColorsToHexStrings($colors),
            'detected_fonts' => $this->normalizeFontsToStrings($fonts),
            'hero_headlines' => $extraction['sources']['website']['hero_headlines'] ?? [],
            'brand_bio' => $brandBio,
        ];
        if (! empty($extraction['_extraction_debug'] ?? [])) {
            $payload['extraction_debug'] = $extraction['_extraction_debug'];
        }
        if (! empty($extraction['evidence_map'] ?? [])) {
            $payload['evidence_map'] = $extraction['evidence_map'];
        }
        if (! empty($extraction['rejected_field_candidates'] ?? [])) {
            $payload['rejected_field_candidates'] = $extraction['rejected_field_candidates'];
        }
        if (! empty($extraction['explicit_signals'] ?? [])) {
            $payload['explicit_signals'] = $extraction['explicit_signals'];
        }
        if (! empty($extraction['narrative_field_debug'] ?? [])) {
            $payload['narrative_field_debug'] = $extraction['narrative_field_debug'];
        }
        if (! empty($extraction['page_analysis'] ?? [])) {
            $payload['page_analysis'] = $extraction['page_analysis'];
        }

        return $payload;
    }

    /**
     * Normalize colors to array of hex strings. Handles string, {hex}, {value}, and nested arrays.
     */
    protected function normalizeColorsToHexStrings(array $colors): array
    {
        $out = [];
        foreach ($colors as $c) {
            $hex = null;
            if (is_string($c)) {
                $hex = $c;
            } elseif (is_array($c) && isset($c['hex']) && is_string($c['hex'])) {
                $hex = $c['hex'];
            } elseif (is_array($c) && isset($c['value'])) {
                $v = $c['value'];
                $hex = is_string($v) ? $v : null;
            }
            if ($hex !== null && $hex !== '') {
                $out[] = $hex;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Normalize fonts to array of strings. Handles string, {value}, {name}, and nested arrays.
     */
    protected function normalizeFontsToStrings(array $fonts): array
    {
        $out = [];
        foreach ($fonts as $f) {
            $name = null;
            if (is_string($f)) {
                $name = $f;
            } elseif (is_array($f)) {
                $name = $f['value'] ?? $f['name'] ?? null;
                $name = is_string($name) ? $name : null;
            }
            if ($name !== null && $name !== '') {
                $out[] = $name;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Unwrap scalar from wrapped value (e.g. {value: '...'} from extraction merge).
     */
    protected function unwrapScalar(mixed $val): ?string
    {
        if ($val === null || is_string($val)) {
            return $val;
        }
        if (is_array($val) && isset($val['value']) && is_string($val['value'])) {
            return $val['value'];
        }

        return null;
    }

    /**
     * Enrich page_analysis with used_in_final_merge from evidence_map.
     */
    protected function enrichPageAnalysisWithMergeContributions(array &$extraction): void
    {
        $pageAnalysis = $extraction['page_analysis'] ?? [];
        if (empty($pageAnalysis)) {
            return;
        }

        $evidenceMap = $extraction['evidence_map'] ?? [];
        foreach ($evidenceMap as $fieldPath => $ev) {
            $winningPage = $ev['winning_page'] ?? null;
            if ($winningPage === null) {
                continue;
            }
            foreach ($pageAnalysis as $i => $record) {
                if (($record['page'] ?? null) === $winningPage) {
                    $pageAnalysis[$i]['used_in_final_merge'] = $pageAnalysis[$i]['used_in_final_merge'] ?? [];
                    if (! in_array($fieldPath, $pageAnalysis[$i]['used_in_final_merge'], true)) {
                        $pageAnalysis[$i]['used_in_final_merge'][] = $fieldPath;
                    }
                    break;
                }
            }
        }

        $extraction['page_analysis'] = $pageAnalysis;
    }

    /**
     * Build narrative_field_debug for identity.mission, identity.positioning, tone_keywords.
     * Shows candidate pages, attempted count, accepted, rejected with reasons.
     */
    protected function buildNarrativeFieldDebug(array $extraction): array
    {
        $fields = [
            'identity.mission',
            'identity.positioning',
            'personality.tone_keywords',
        ];

        $rejected = [];
        foreach ($extraction['rejected_field_candidates'] ?? [] as $r) {
            $path = $r['path'] ?? null;
            if ($path && in_array($path, $fields, true)) {
                $rejected[$path][] = [
                    'page' => $r['page'] ?? null,
                    'value' => $r['value'] ?? null,
                    'reason' => $r['reason'] ?? 'rejected',
                ];
            }
        }

        $pageExtractions = $extraction['page_extractions_json'] ?? [];
        $pageAnalysis = $extraction['page_analysis'] ?? [];
        $evidenceMap = $extraction['evidence_map'] ?? [];

        $debug = [];
        foreach ($fields as $fieldPath) {
            $candidatePages = [];
            $attemptedCount = 0;
            $accepted = [];
            $rejectedForField = $rejected[$fieldPath] ?? [];

            foreach ($pageExtractions as $pageData) {
                $pageNum = $pageData['page'] ?? null;
                $eligible = $pageData['eligible_fields'] ?? [];
                $attempted = $pageData['attempted_fields'] ?? [];
                $acceptedForPage = $pageData['accepted_fields'] ?? [];
                if (in_array($fieldPath, $eligible, true)) {
                    $candidatePages[] = $pageNum;
                    if (in_array($fieldPath, $attempted, true) || in_array($fieldPath, $acceptedForPage, true)) {
                        $attemptedCount++;
                    }
                }
            }
            foreach ($pageAnalysis as $pa) {
                if (in_array($fieldPath, $pa['eligible_fields'] ?? [], true) && ! in_array($pa['page'] ?? null, $candidatePages, true)) {
                    $candidatePages[] = $pa['page'] ?? null;
                }
            }
            $candidatePages = array_values(array_unique(array_filter($candidatePages)));

            $ev = $evidenceMap[$fieldPath] ?? null;
            if ($ev && ! empty($ev['final_value'] ?? null)) {
                $accepted[] = [
                    'page' => $ev['winning_page'] ?? null,
                    'value' => $ev['final_value'],
                ];
            }

            $debug[$fieldPath] = [
                'candidate_pages' => $candidatePages,
                'attempted' => $attemptedCount,
                'accepted' => $accepted,
                'rejected' => $rejectedForField,
            ];
        }

        return $debug;
    }

    /**
     * Set explicit_signals only when backed by actual accepted candidates or evidence.
     * Avoids false positives (e.g. positioning_declared when no positioning exists).
     */
    protected function ensureExplicitSignalsFromExtraction(array &$extraction): void
    {
        $extraction['explicit_signals'] = $extraction['explicit_signals'] ?? [];

        $archetype = $extraction['personality']['primary_archetype'] ?? null;
        if (is_array($archetype) && ($archetype['source_type'] ?? '') === 'explicit') {
            $extraction['explicit_signals']['archetype_declared'] = true;
        }

        $colors = $extraction['visual']['primary_colors'] ?? [];
        if (! empty($colors) && ! empty($extraction['page_extractions_json'] ?? [])) {
            $extraction['explicit_signals']['colors_declared'] = true;
        }

        $identity = $extraction['identity'] ?? [];
        $missionVal = $this->unwrapScalar($identity['mission'] ?? null);
        $positioningVal = $this->unwrapScalar($identity['positioning'] ?? null);

        if (($extraction['explicit_signals']['mission_declared'] ?? false) && ($missionVal === null || $missionVal === '')) {
            $extraction['explicit_signals']['mission_declared'] = false;
        }
        if (($extraction['explicit_signals']['positioning_declared'] ?? false) && ($positioningVal === null || $positioningVal === '')) {
            $extraction['explicit_signals']['positioning_declared'] = false;
        }
    }
}
