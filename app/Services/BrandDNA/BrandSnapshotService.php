<?php

namespace App\Services\BrandDNA;

use App\Models\Brand;
use App\Models\BrandModelVersion;
use App\Models\BrandPipelineRun;
use App\Models\BrandPipelineSnapshot;
use App\Services\BrandDNA\Extraction\BrandExtractionSchema;
use App\Services\BrandDNA\Extraction\ExtractionQualityValidator;
use App\Services\BrandDNA\Extraction\ExtractionSuggestionService;
use App\Services\BrandDNA\Extraction\SectionAwareBrandGuidelinesProcessor;
use Illuminate\Support\Facades\Log;

/**
 * Generates BrandPipelineSnapshot from merged extraction data.
 * Extracted from RunBrandIngestionJob.
 */
class BrandSnapshotService
{
    public function __construct(
        protected BrandCoherenceScoringService $coherenceService,
        protected BrandAlignmentEngine $alignmentEngine,
        protected ExtractionSuggestionService $suggestionService,
        protected ExtractionEvidenceMapBuilder $evidenceMapBuilder,
        protected FieldCandidateValidationService $validationService,
        protected BrandResearchReportBuilder $reportBuilder
    ) {}

    /**
     * Generate snapshot from pipeline run's merged extraction.
     * Called by BrandPipelineSnapshotJob after merge stores merged_extraction_json.
     */
    public function generate(BrandPipelineRun $run): BrandPipelineSnapshot
    {
        $run->loadMissing(['brand', 'brandModelVersion']);
        $brand = $run->brand;
        $draft = $run->brandModelVersion;

        if (! $brand || ! $draft) {
            throw new \RuntimeException('Pipeline run missing brand or draft');
        }

        $merged = $run->merged_extraction_json;
        if (empty($merged)) {
            throw new \RuntimeException('Pipeline run has no merged extraction');
        }

        $activeSources = $this->deriveActiveSourcesFromRun($run);

        return $this->createFromExtractions(
            $brand,
            $draft,
            [$merged],
            $activeSources,
            $run,
            'ingestion'
        );
    }

    protected function deriveActiveSourcesFromRun(BrandPipelineRun $run): array
    {
        $sources = [];
        if ($run->asset_id) {
            $sources[] = 'pdf';
        }
        $draft = $run->brandModelVersion;
        if ($draft) {
            $websiteUrl = $draft->model_payload['sources']['website_url'] ?? null;
            if (! empty(trim((string) $websiteUrl))) {
                $sources[] = 'website';
            }
            if ($draft->assetsForContext('brand_material')->count() > 0) {
                $sources[] = 'materials';
            }
        }

        return $sources;
    }

    /**
     * Create a snapshot from a single extraction (e.g. PDF-only or website-only).
     */
    public function createFromExtraction(
        Brand $brand,
        BrandModelVersion $draft,
        array $extraction,
        array $activeSources,
        ?BrandPipelineRun $pipelineRun = null,
        ?string $sourceUrl = null
    ): BrandPipelineSnapshot {
        $extractions = [$extraction];

        return $this->createFromExtractions(
            $brand,
            $draft,
            $extractions,
            $activeSources,
            $pipelineRun,
            $sourceUrl ?? 'ingestion'
        );
    }

    /**
     * Create a snapshot from multiple extractions (PDF + website + materials merged).
     *
     * @param array<int, array> $extractions
     */
    public function createFromExtractions(
        Brand $brand,
        BrandModelVersion $draft,
        array $extractions,
        array $activeSources,
        ?BrandPipelineRun $pipelineRun = null,
        string $sourceUrl = 'ingestion'
    ): BrandPipelineSnapshot {
        $extraction = empty($extractions)
            ? BrandExtractionSchema::empty()
            : BrandExtractionSchema::merge(...$extractions);

        $extraction = $this->validationService->sanitizeMergedExtraction($extraction);
        $this->ensureExplicitSignalsFromExtraction($extraction);
        $extraction['evidence_map'] = $this->evidenceMapBuilder->build($extractions, $extraction);
        $extraction['narrative_field_debug'] = $this->buildNarrativeFieldDebug($extraction);

        foreach ($extractions as $ext) {
            if (! empty($ext['rejected_field_candidates'])) {
                $extraction['rejected_field_candidates'] = $ext['rejected_field_candidates'];
            }
        }

        $conflicts = $extraction['conflicts'] ?? [];
        $suggestions = $this->suggestionService->generateSuggestions($extraction, $conflicts, $activeSources);
        [$draft, $autoApplyBlocked] = AutoApplyHighConfidenceSuggestions::apply($draft, $suggestions);
        if (! empty($autoApplyBlocked)) {
            $extraction['_extraction_debug'] = array_merge($extraction['_extraction_debug'] ?? [], [
                'auto_apply_blocked' => $autoApplyBlocked,
            ]);
        }

        $snapshotPayload = $this->extractionToSnapshotPayload($extraction);
        $snapshotPayload['conflicts'] = $conflicts;
        $snapshotPayload['snapshot_generated_at'] = now()->toIso8601String();
        $snapshotPayload['pipeline_version'] = '2.0';
        $snapshotPayload['extraction_mode'] = $pipelineRun?->extraction_mode;
        $sectionsJson = $this->buildSectionsJson($extraction);

        $brandMaterialCount = $draft->assetsForContext('brand_material')->count();
        $coherence = $this->coherenceService->score(
            $draft->model_payload ?? [],
            $suggestions,
            $snapshotPayload,
            $brand,
            $brandMaterialCount,
            $conflicts
        );
        $alignment = $this->alignmentEngine->analyze($draft->model_payload ?? []);
        $report = $this->reportBuilder->build(
            $snapshotPayload,
            $suggestions,
            $coherence,
            $alignment,
            $activeSources
        );

        return BrandPipelineSnapshot::create([
            'brand_pipeline_run_id' => $pipelineRun?->id,
            'brand_id' => $brand->id,
            'brand_model_version_id' => $draft->id,
            'source_url' => $sourceUrl,
            'status' => BrandPipelineSnapshot::STATUS_COMPLETED,
            'snapshot' => $snapshotPayload,
            'suggestions' => $suggestions,
            'coherence' => $coherence,
            'alignment' => $alignment,
            'report' => $report,
            'sections_json' => $sectionsJson,
        ]);
    }

    protected function ensureExplicitSignalsFromExtraction(array &$extraction): void
    {
        $extraction['explicit_signals'] = $extraction['explicit_signals'] ?? [];

        $archetype = $extraction['personality']['primary_archetype'] ?? null;
        if (is_array($archetype) && ($archetype['source_type'] ?? '') === 'explicit') {
            $extraction['explicit_signals']['archetype_declared'] = true;
        }

        $colors = $extraction['visual']['primary_colors'] ?? [];
        if (! empty($colors)) {
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

    protected function buildNarrativeFieldDebug(array $extraction): array
    {
        $fields = ['identity.mission', 'identity.positioning', 'personality.tone_keywords'];
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

        $evidenceMap = $extraction['evidence_map'] ?? [];
        $debug = [];

        foreach ($fields as $fieldPath) {
            $candidatePages = [];
            $attemptedCount = 0;
            $accepted = [];
            $rejectedForField = $rejected[$fieldPath] ?? [];

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
        $minQuality = SectionAwareBrandGuidelinesProcessor::MIN_QUALITY_SCORE;

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

        $identity = $extraction['identity'] ?? [];
        $personality = $extraction['personality'] ?? [];

        $payload = [
            'logo_description' => $this->unwrapScalar($visual['logo_description'] ?? null),
            'primary_colors' => $this->normalizeColorsToHexStrings($colors),
            'secondary_colors' => $this->normalizeColorsToHexStrings($visual['secondary_colors'] ?? []),
            'detected_fonts' => $this->normalizeFontsToStrings($fonts),
            'hero_headlines' => $extraction['sources']['website']['hero_headlines'] ?? [],
            'brand_bio' => $brandBio,
            'industry' => $this->unwrapScalar($identity['industry'] ?? null),
            'target_audience' => $this->unwrapScalar($identity['target_audience'] ?? null),
            'mission' => $this->unwrapScalar($identity['mission'] ?? null),
            'vision' => $this->unwrapScalar($identity['vision'] ?? null),
            'tagline' => $this->unwrapScalar($identity['tagline'] ?? null),
            'photography_style' => $this->unwrapScalar($visual['photography_style'] ?? null),
            'visual_style' => $this->unwrapScalar($visual['visual_style'] ?? null),
            'design_cues' => $visual['design_cues'] ?? [],
            'voice_description' => $this->unwrapScalar($personality['voice_description'] ?? null),
            'brand_look' => $this->unwrapScalar($personality['brand_look'] ?? null),
        ];

        $typo = $extraction['typography'] ?? [];
        if (array_filter($typo)) {
            $payload['typography'] = $typo;
        }

        if (! empty($extraction['section_confidence'] ?? [])) {
            $payload['section_confidence'] = $extraction['section_confidence'];
        }
        if (! empty($extraction['_extraction_notes'] ?? [])) {
            $payload['extraction_notes'] = $extraction['_extraction_notes'];
        }
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
        return $payload;
    }

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
}
