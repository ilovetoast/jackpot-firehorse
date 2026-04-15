<?php

namespace App\Services\BrandIntelligence\Campaign;

use App\Enums\AlignmentDimension;
use App\Enums\DimensionStatus;
use App\Enums\EvidenceSource;
use App\Enums\EvidenceWeight;
use App\Enums\MediaType;
use App\Models\Asset;
use App\Models\AssetEmbedding;
use App\Models\Brand;
use App\Models\CampaignVisualReference;
use App\Models\CollectionCampaignIdentity;
use App\Services\BrandIntelligence\AssetContextClassifier;
use App\Services\BrandIntelligence\BrandColorPaletteAlignmentEvaluator;
use App\Services\BrandIntelligence\Dimensions\AlignmentScoreDeriver;
use App\Services\BrandIntelligence\Dimensions\ColorEvaluator;
use App\Services\BrandIntelligence\Dimensions\ContextFitEvaluator;
use App\Services\BrandIntelligence\Dimensions\CopyVoiceEvaluator;
use App\Services\BrandIntelligence\Dimensions\DimensionResult;
use App\Services\BrandIntelligence\Dimensions\EvaluationContext;
use App\Services\BrandIntelligence\Dimensions\EvidenceItem;
use App\Services\BrandIntelligence\Dimensions\IdentityEvaluator;
use App\Services\BrandIntelligence\Dimensions\TypographyEvaluator;
use App\Services\BrandIntelligence\Dimensions\VisualStyleEvaluator;
use App\Services\BrandIntelligence\ReferenceSimilarityCalculator;

/**
 * Orchestrates the 6-dimension evaluation against campaign DNA instead of master brand DNA.
 * Reuses the same DimensionResult / EvidenceItem DTOs and AlignmentScoreDeriver.
 *
 * Key differences from master EvaluationOrchestrator:
 * - Identity checks campaign logo_variant / identity references, not master logo
 * - Color compares against campaign palette, not master palette
 * - Typography uses campaign font config
 * - Visual Style uses campaign_visual_references (with type-aware weighting)
 * - Copy/Voice checks campaign messaging (phrases, CTA, tone)
 * - Context Fit uses campaign_goal and campaign rules
 */
final class CampaignEvaluationOrchestrator
{
    private const WEIGHT_PROFILES = [
        'image' => [
            'identity' => 0.20,
            'color' => 0.20,
            'typography' => 0.05,
            'visual_style' => 0.30,
            'copy_voice' => 0.15,
            'context_fit' => 0.10,
        ],
        'pdf' => [
            'identity' => 0.15,
            'color' => 0.15,
            'typography' => 0.15,
            'visual_style' => 0.15,
            'copy_voice' => 0.25,
            'context_fit' => 0.15,
        ],
        'video' => [
            'identity' => 0.15,
            'color' => 0.10,
            'typography' => 0.05,
            'visual_style' => 0.25,
            'copy_voice' => 0.25,
            'context_fit' => 0.20,
        ],
        'audio' => [
            'identity' => 0.0,
            'color' => 0.0,
            'typography' => 0.0,
            'visual_style' => 0.0,
            'copy_voice' => 0.70,
            'context_fit' => 0.30,
        ],
        'other' => [
            'identity' => 0.167,
            'color' => 0.167,
            'typography' => 0.167,
            'visual_style' => 0.167,
            'copy_voice' => 0.167,
            'context_fit' => 0.167,
        ],
    ];

    private const LOGO_EMBEDDING_SIMILARITY_THRESHOLD = 0.72;

    private AssetContextClassifier $contextClassifier;

    public function __construct(
        AssetContextClassifier $contextClassifier,
    ) {
        $this->contextClassifier = $contextClassifier;
    }

    /**
     * @return array{
     *   dimensions: array<string, DimensionResult>,
     *   context: EvaluationContext,
     *   weights: array<string, float>,
     *   weights_note: string|null,
     *   campaign_references_used: array<string, int>,
     *   campaign_rules_checked: list<string>,
     * }
     */
    public function evaluate(Asset $asset, Brand $brand, CollectionCampaignIdentity $campaignIdentity): array
    {
        $contextType = $this->contextClassifier->classify($asset);
        $context = EvaluationContext::fromAssetWithCampaign($asset, $contextType, $campaignIdentity);

        $payload = is_array($campaignIdentity->identity_payload) ? $campaignIdentity->identity_payload : [];
        $references = $campaignIdentity->campaignVisualReferences()->get();

        $results = [];
        $results[AlignmentDimension::IDENTITY->value] = $this->evaluateIdentity($asset, $references, $context);
        $results[AlignmentDimension::COLOR->value] = $this->evaluateColor($asset, $payload, $context);
        $results[AlignmentDimension::TYPOGRAPHY->value] = $this->evaluateTypography($asset, $payload, $context);
        $results[AlignmentDimension::VISUAL_STYLE->value] = $this->evaluateVisualStyle($asset, $references, $context);
        $results[AlignmentDimension::COPY_VOICE->value] = $this->evaluateCopyVoice($asset, $payload, $context);
        $results[AlignmentDimension::CONTEXT_FIT->value] = $this->evaluateContextFit($asset, $campaignIdentity, $payload, $references, $context);

        $baseWeights = self::WEIGHT_PROFILES[$context->mediaType->value] ?? self::WEIGHT_PROFILES['other'];
        $redistributed = $this->redistributeWeights($baseWeights, $results);

        $refCounts = [];
        foreach (CampaignVisualReference::ALL_TYPES as $type) {
            $count = $references->where('reference_type', $type)->count();
            if ($count > 0) {
                $refCounts[$type] = $count;
            }
        }

        $rulesChecked = [];
        if (! empty($payload['messaging']['approved_phrases'])) {
            $rulesChecked[] = 'approved_phrases';
        }
        if (! empty($payload['messaging']['discouraged_phrases'])) {
            $rulesChecked[] = 'discouraged_phrases';
        }
        if (! empty($payload['rules']['required_phrases'])) {
            $rulesChecked[] = 'required_phrases';
        }
        if (! empty($payload['rules']['required_motifs'])) {
            $rulesChecked[] = 'required_motifs';
        }

        return [
            'dimensions' => $results,
            'context' => $context,
            'weights' => $redistributed['weights'],
            'weights_note' => $redistributed['note'],
            'campaign_references_used' => $refCounts,
            'campaign_rules_checked' => $rulesChecked,
        ];
    }

    /**
     * Campaign Identity: checks campaign identity/logo_variant references for visual similarity.
     */
    private function evaluateIdentity(Asset $asset, \Illuminate\Support\Collection $references, EvaluationContext $context): DimensionResult
    {
        $identityRefs = $references->filter(fn (CampaignVisualReference $r) => $r->isIdentityReference());
        $evidence = [];
        $blockers = [];

        $refsWithEmbeddings = $identityRefs->filter(fn (CampaignVisualReference $r) => ! empty($r->embedding_vector));

        if ($refsWithEmbeddings->isEmpty()) {
            if ($identityRefs->isNotEmpty()) {
                $evidence[] = EvidenceItem::readiness(
                    EvidenceSource::CONFIGURATION_ONLY,
                    sprintf('%d campaign identity reference(s) exist but have no embeddings', $identityRefs->count()),
                );
                $blockers[] = 'Generate embeddings for campaign identity references';
            }

            return DimensionResult::missingReference(
                AlignmentDimension::IDENTITY,
                'No campaign identity or logo variant references with embeddings',
                array_merge($blockers, ['Add campaign logo variant or identity references to enable comparison']),
                $evidence,
            );
        }

        if (! $context->hasExtraction('embeddings')) {
            $evidence[] = EvidenceItem::readiness(
                EvidenceSource::CONFIGURATION_ONLY,
                $context->mediaType === MediaType::PDF && $context->visualEvaluationRasterResolved
                    ? 'Campaign identity references exist; PDF page render is ready but asset embedding is not stored yet'
                    : 'Campaign identity references exist but asset has no embedding for comparison',
            );

            return DimensionResult::notEvaluable(
                AlignmentDimension::IDENTITY,
                $context->mediaType === MediaType::PDF && $context->visualEvaluationRasterResolved
                    ? 'PDF page render is available but no stored embedding vector yet for identity comparison'
                    : 'Asset has no embedding for campaign identity comparison',
                ['Generate asset embedding to enable campaign identity evaluation'],
                EvidenceSource::CONFIGURATION_ONLY,
                $evidence,
            );
        }

        $assetRow = AssetEmbedding::query()->where('asset_id', $asset->id)->first();
        $assetVec = ($assetRow && ! empty($assetRow->embedding_vector))
            ? array_values($assetRow->embedding_vector) : [];

        if ($assetVec === []) {
            return DimensionResult::notEvaluable(
                AlignmentDimension::IDENTITY,
                'Asset embedding vector is empty',
                ['Regenerate asset embedding'],
            );
        }

        $best = null;
        $bestId = null;
        foreach ($refsWithEmbeddings as $ref) {
            $refVec = array_values($ref->embedding_vector ?? []);
            if ($refVec === [] || count($refVec) !== count($assetVec)) {
                continue;
            }
            $c = $this->cosineSimilarity($assetVec, $refVec);
            if ($best === null || $c > $best) {
                $best = $c;
                $bestId = $ref->id;
            }
        }

        if ($best === null) {
            return DimensionResult::notEvaluable(
                AlignmentDimension::IDENTITY,
                'No compatible campaign reference vectors for comparison',
                ['Ensure campaign identity references have valid embeddings'],
            );
        }

        $best = round($best, 4);

        if ($best >= self::LOGO_EMBEDDING_SIMILARITY_THRESHOLD) {
            $evidence[] = EvidenceItem::hard(
                EvidenceSource::VISUAL_SIMILARITY,
                sprintf('Campaign identity similarity %.2f to reference #%s', $best, $bestId ?? '?'),
            );

            $score = min(1.0, max(0.0, ($best - 0.5) / 0.5));
            $confidence = min(1.0, $best);

            return new DimensionResult(
                dimension: AlignmentDimension::IDENTITY,
                status: $score >= 0.6 ? DimensionStatus::ALIGNED : DimensionStatus::PARTIAL,
                score: $score,
                confidence: $confidence,
                primaryEvidenceSource: EvidenceSource::VISUAL_SIMILARITY,
                evidence: $evidence,
                blockers: $blockers,
                evaluable: true,
                statusReason: sprintf('Asset visually similar to campaign identity reference (%.0f%%)', $best * 100),
            );
        }

        $evidence[] = EvidenceItem::soft(
            EvidenceSource::VISUAL_SIMILARITY,
            sprintf('Campaign identity similarity %.2f (below threshold %.2f)', $best, self::LOGO_EMBEDDING_SIMILARITY_THRESHOLD),
        );

        return new DimensionResult(
            dimension: AlignmentDimension::IDENTITY,
            status: DimensionStatus::WEAK,
            score: 0.3,
            confidence: 0.3,
            primaryEvidenceSource: EvidenceSource::VISUAL_SIMILARITY,
            evidence: $evidence,
            blockers: $blockers,
            evaluable: true,
            statusReason: 'Weak visual similarity to campaign identity references',
        );
    }

    /**
     * Campaign Color: compare extracted asset colors against campaign palette.
     */
    private function evaluateColor(Asset $asset, array $payload, EvaluationContext $context): DimensionResult
    {
        $campaignPalette = $payload['visual']['palette'] ?? [];
        $accentColors = $payload['visual']['accent_colors'] ?? [];
        $allColors = array_merge(
            is_array($campaignPalette) ? $campaignPalette : [],
            is_array($accentColors) ? $accentColors : [],
        );

        if (empty($allColors)) {
            return DimensionResult::missingReference(
                AlignmentDimension::COLOR,
                'No campaign color palette configured',
                ['Define campaign colors in campaign identity to enable palette comparison'],
            );
        }

        $meta = is_array($asset->metadata ?? null) ? $asset->metadata : [];
        $assetColors = $meta['dominant_colors'] ?? data_get($meta, 'fields.dominant_colors') ?? null;

        if (empty($assetColors) || ! is_array($assetColors)) {
            $evidence = [
                EvidenceItem::readiness(
                    EvidenceSource::CONFIGURATION_ONLY,
                    'Campaign palette exists but no dominant colors extracted from asset',
                ),
            ];

            return DimensionResult::notEvaluable(
                AlignmentDimension::COLOR,
                'No dominant colors extracted from asset for campaign palette comparison',
                ['Dominant color extraction required for campaign palette comparison'],
                EvidenceSource::CONFIGURATION_ONLY,
                $evidence,
            );
        }

        $deltaEs = $this->computeCampaignDeltaEs($assetColors, $allColors);

        if ($deltaEs === []) {
            return DimensionResult::notEvaluable(
                AlignmentDimension::COLOR,
                'Could not compute color distance between asset and campaign palette',
                [],
            );
        }

        $meanDeltaE = array_sum($deltaEs) / count($deltaEs);
        $deltaLabel = sprintf(' (mean delta-E: %.0f)', $meanDeltaE);

        if ($meanDeltaE <= 25.0) {
            $score = $meanDeltaE <= 18.0 ? 0.95 : 0.85;
            $confidence = $meanDeltaE <= 18.0 ? 0.90 : 0.80;

            return new DimensionResult(
                dimension: AlignmentDimension::COLOR,
                status: DimensionStatus::ALIGNED,
                score: $score,
                confidence: $confidence,
                primaryEvidenceSource: EvidenceSource::PALETTE_EXTRACTION,
                evidence: [EvidenceItem::hard(EvidenceSource::PALETTE_EXTRACTION, 'Extracted colors align with campaign palette' . $deltaLabel)],
                blockers: [],
                evaluable: true,
                statusReason: 'Extracted colors align with campaign palette' . $deltaLabel,
            );
        }

        if ($meanDeltaE <= 42.0) {
            return new DimensionResult(
                dimension: AlignmentDimension::COLOR,
                status: DimensionStatus::PARTIAL,
                score: 0.5,
                confidence: 0.70,
                primaryEvidenceSource: EvidenceSource::PALETTE_EXTRACTION,
                evidence: [EvidenceItem::hard(EvidenceSource::PALETTE_EXTRACTION, 'Moderate color distance from campaign palette' . $deltaLabel)],
                blockers: ['Verify creative uses campaign-approved colors'],
                evaluable: true,
                statusReason: 'Moderate color distance from campaign palette' . $deltaLabel,
            );
        }

        return new DimensionResult(
            dimension: AlignmentDimension::COLOR,
            status: DimensionStatus::WEAK,
            score: 0.25,
            confidence: 0.70,
            primaryEvidenceSource: EvidenceSource::PALETTE_EXTRACTION,
            evidence: [EvidenceItem::hard(EvidenceSource::PALETTE_EXTRACTION, 'Extracted colors diverge from campaign palette' . $deltaLabel)],
            blockers: ['Extracted colors diverge from campaign palette'],
            evaluable: true,
            statusReason: 'Extracted colors diverge from campaign palette' . $deltaLabel,
        );
    }

    /**
     * Campaign Typography: uses campaign font config.
     * Same humility rules: configuration_only is never a pass.
     */
    private function evaluateTypography(Asset $asset, array $payload, EvaluationContext $context): DimensionResult
    {
        $typoConfig = $payload['typography'] ?? null;
        $hasCampaignTypo = is_array($typoConfig) && (
            ! empty($typoConfig['primary_font'])
            || ! empty($typoConfig['signature_font'])
            || ! empty($typoConfig['direction'])
        );

        $meta = is_array($asset->metadata ?? null) ? $asset->metadata : [];
        $fields = is_array($meta['fields'] ?? null) ? $meta['fields'] : [];
        $assetHasTypoMeta = false;
        foreach (['font_family', 'fonts', 'typography', 'detected_fonts'] as $k) {
            if (! empty($meta[$k]) || ! empty($fields[$k])) {
                $assetHasTypoMeta = true;
                break;
            }
        }

        if (! $hasCampaignTypo && ! $assetHasTypoMeta) {
            return DimensionResult::notEvaluable(
                AlignmentDimension::TYPOGRAPHY,
                'No campaign typography config and no font metadata on asset',
                ['Add typography settings to campaign identity'],
            );
        }

        if ($hasCampaignTypo && ! $assetHasTypoMeta) {
            $evidence = [
                EvidenceItem::readiness(
                    EvidenceSource::CONFIGURATION_ONLY,
                    'Campaign has typography config but no font data extractable from asset',
                ),
            ];

            $canExtract = $context->mediaType === MediaType::PDF;

            return DimensionResult::notEvaluable(
                AlignmentDimension::TYPOGRAPHY,
                $canExtract
                    ? 'Campaign typography config exists but no font metadata found on asset'
                    : 'Font extraction not available for this asset type',
                ['Typography could not be reliably evaluated from this asset'],
                EvidenceSource::CONFIGURATION_ONLY,
                $evidence,
            );
        }

        if ($assetHasTypoMeta && ! $hasCampaignTypo) {
            return DimensionResult::missingReference(
                AlignmentDimension::TYPOGRAPHY,
                'Asset has font metadata but no campaign typography config to compare against',
                ['Add typography settings to campaign identity to enable comparison'],
                [EvidenceItem::readiness(EvidenceSource::METADATA_HINT, 'Asset has font metadata but no campaign typography config')],
            );
        }

        return new DimensionResult(
            dimension: AlignmentDimension::TYPOGRAPHY,
            status: DimensionStatus::PARTIAL,
            score: 0.5,
            confidence: 0.35,
            primaryEvidenceSource: EvidenceSource::AI_ANALYSIS,
            evidence: [EvidenceItem::soft(EvidenceSource::AI_ANALYSIS, 'Campaign typography config and asset metadata both present; comparison is approximate')],
            blockers: [],
            evaluable: true,
            statusReason: 'Campaign typography comparison based on available metadata; confidence is limited',
        );
    }

    /**
     * Campaign Visual Style: uses campaign style/mood/exemplar references.
     * Mood references carry lower weight than style references.
     */
    private function evaluateVisualStyle(Asset $asset, \Illuminate\Support\Collection $references, EvaluationContext $context): DimensionResult
    {
        if (! $context->hasExtraction('embeddings')) {
            if ($context->mediaType === MediaType::PDF && $context->visualEvaluationRasterResolved) {
                return DimensionResult::notEvaluable(
                    AlignmentDimension::VISUAL_STYLE,
                    'PDF page render is available but no stored embedding vector yet',
                    ['Generate asset embedding for the rendered page to enable campaign visual style evaluation'],
                );
            }

            return DimensionResult::notEvaluable(
                AlignmentDimension::VISUAL_STYLE,
                'Asset has no visual embedding for campaign style comparison',
                ['Generate asset embedding to enable campaign visual style evaluation'],
            );
        }

        $styleRefs = $references->filter(fn (CampaignVisualReference $r) => $r->isStyleReference());
        $refsWithEmbeddings = $styleRefs->filter(fn (CampaignVisualReference $r) => ! empty($r->embedding_vector));

        if ($refsWithEmbeddings->isEmpty()) {
            $evidence = [];
            if ($styleRefs->isNotEmpty()) {
                $evidence[] = EvidenceItem::readiness(
                    EvidenceSource::CONFIGURATION_ONLY,
                    sprintf('%d campaign style reference(s) exist but have no embeddings', $styleRefs->count()),
                );
            }

            return DimensionResult::missingReference(
                AlignmentDimension::VISUAL_STYLE,
                'No campaign style references with embeddings available',
                ['Add campaign style reference images to enable visual style evaluation'],
                $evidence,
            );
        }

        $assetRow = AssetEmbedding::query()->where('asset_id', $asset->id)->first();
        $assetVec = ($assetRow && ! empty($assetRow->embedding_vector))
            ? array_values($assetRow->embedding_vector) : [];

        if ($assetVec === []) {
            return DimensionResult::notEvaluable(
                AlignmentDimension::VISUAL_STYLE,
                'Asset embedding vector is empty',
                ['Regenerate asset embedding'],
            );
        }

        $similarities = [];
        foreach ($refsWithEmbeddings as $ref) {
            $refVec = array_values($ref->embedding_vector ?? []);
            if ($refVec === [] || count($refVec) !== count($assetVec)) {
                continue;
            }
            $c = $this->cosineSimilarity($assetVec, $refVec);
            $weight = $ref->effectiveWeight();
            $similarities[] = ['cosine' => $c, 'weight' => $weight, 'type' => $ref->reference_type, 'id' => $ref->id];
        }

        if ($similarities === []) {
            return DimensionResult::notEvaluable(
                AlignmentDimension::VISUAL_STYLE,
                'No compatible campaign reference vectors for style comparison',
                ['Ensure campaign style references have valid embeddings'],
            );
        }

        usort($similarities, fn ($a, $b) => $b['cosine'] <=> $a['cosine']);
        $topN = array_slice($similarities, 0, 5);

        $weightedSum = 0.0;
        $weightSum = 0.0;
        foreach ($topN as $s) {
            $weightedSum += $s['cosine'] * $s['weight'];
            $weightSum += $s['weight'];
        }
        $meanSim = $weightSum > 0.001 ? $weightedSum / $weightSum : 0.0;

        $totalRefs = $refsWithEmbeddings->count();
        $thinCoverage = $totalRefs < 3;

        // If all top-N refs are mood/exemplar/motif (no direct style refs), treat as soft evidence only.
        // Exemplar refs show "what good looks like" but are not strict compliance targets.
        $directStyleRefCount = $refsWithEmbeddings
            ->filter(fn (CampaignVisualReference $r) => $r->reference_type === CampaignVisualReference::TYPE_STYLE)
            ->count();
        $onlySoftRefs = $directStyleRefCount === 0;

        $confidence = ($thinCoverage || $onlySoftRefs) ? min(0.45, $meanSim) : min(0.85, $meanSim + 0.1);
        $confidence = max(0.0, min(1.0, $confidence));

        $evidence = [];

        if ($onlySoftRefs) {
            $evidence[] = EvidenceItem::soft(
                EvidenceSource::VISUAL_SIMILARITY,
                sprintf('Campaign style similarity %.0f%% (mood/exemplar/motif references only; not strict compliance)', $meanSim * 100, $totalRefs),
            );
        } elseif ($thinCoverage) {
            $evidence[] = EvidenceItem::soft(
                EvidenceSource::VISUAL_SIMILARITY,
                sprintf('Campaign style similarity %.0f%% (weighted mean, %d refs, limited coverage)', $meanSim * 100, $totalRefs),
            );
        } else {
            $evidence[] = EvidenceItem::hard(
                EvidenceSource::VISUAL_SIMILARITY,
                sprintf('Campaign style similarity %.0f%% (weighted mean of top %d, %d total refs)', $meanSim * 100, count($topN), $totalRefs),
            );
        }

        $blockers = [];
        if ($thinCoverage) {
            $blockers[] = sprintf('Only %d campaign style reference(s); add more to improve confidence', $totalRefs);
        }
        if ($onlySoftRefs) {
            $blockers[] = 'Add direct style references for stronger compliance scoring; current references are mood/exemplar only';
        }

        $score = max(0.0, min(1.0, ($meanSim - 0.2) / 0.6));

        if ($score >= 0.6 && ! $thinCoverage) {
            $status = DimensionStatus::ALIGNED;
        } elseif ($score >= 0.35) {
            $status = DimensionStatus::PARTIAL;
        } elseif ($score >= 0.15) {
            $status = DimensionStatus::WEAK;
        } else {
            $status = DimensionStatus::FAIL;
        }

        return new DimensionResult(
            dimension: AlignmentDimension::VISUAL_STYLE,
            status: $status,
            score: $score,
            confidence: $confidence,
            primaryEvidenceSource: EvidenceSource::VISUAL_SIMILARITY,
            evidence: $evidence,
            blockers: $blockers,
            evaluable: true,
            statusReason: sprintf('Campaign style similarity %.0f%% across %d reference(s)', $meanSim * 100, $totalRefs),
        );
    }

    /**
     * Campaign Copy/Voice: checks campaign messaging config (phrases, CTA, tone).
     */
    private function evaluateCopyVoice(Asset $asset, array $payload, EvaluationContext $context): DimensionResult
    {
        $ocrText = $this->extractTextContent($asset);

        if (mb_strlen(trim($ocrText), 'UTF-8') < 10) {
            return DimensionResult::notEvaluable(
                AlignmentDimension::COPY_VOICE,
                'No extractable text or insufficient text for campaign voice comparison',
                ['No OCR/PDF text available for campaign messaging evaluation'],
            );
        }

        $messaging = $payload['messaging'] ?? null;
        $rules = $payload['rules'] ?? null;

        $hasCampaignMessaging = is_array($messaging) && (
            ! empty($messaging['tone'])
            || ! empty($messaging['pillars'])
            || ! empty($messaging['approved_phrases'])
            || ! empty($messaging['discouraged_phrases'])
            || ! empty($messaging['cta_direction'])
        );

        $hasRequiredPhrases = is_array($rules) && ! empty($rules['required_phrases']);
        $hasDiscouragedPhrases = is_array($rules) && ! empty($rules['discouraged_phrases']);

        if (! $hasCampaignMessaging && ! $hasRequiredPhrases && ! $hasDiscouragedPhrases) {
            return DimensionResult::missingReference(
                AlignmentDimension::COPY_VOICE,
                'Text extracted but no campaign messaging or rules configured',
                ['Add campaign tone, phrases, or CTA direction to enable copy evaluation'],
                [EvidenceItem::readiness(EvidenceSource::EXTRACTED_TEXT, sprintf('Text extracted (%d chars) but no campaign messaging config', mb_strlen($ocrText, 'UTF-8')))],
            );
        }

        $evidence = [];
        $score = 0.5;
        $confidence = 0.4;
        $lowerText = mb_strtolower($ocrText, 'UTF-8');

        $approvedFound = 0;
        $approvedPhrases = array_merge(
            is_array($messaging['approved_phrases'] ?? null) ? $messaging['approved_phrases'] : [],
            is_array($rules['required_phrases'] ?? null) ? $rules['required_phrases'] : [],
        );
        foreach ($approvedPhrases as $phrase) {
            if (is_string($phrase) && $phrase !== '' && mb_stripos($lowerText, mb_strtolower($phrase, 'UTF-8')) !== false) {
                $approvedFound++;
            }
        }

        if ($approvedFound > 0) {
            $evidence[] = EvidenceItem::hard(
                EvidenceSource::EXTRACTED_TEXT,
                sprintf('%d campaign-approved phrase(s) found in extracted text', $approvedFound),
            );
            $score = min(1.0, $score + 0.15 * $approvedFound);
            $confidence = min(1.0, $confidence + 0.1);
        }

        $discouragedFound = 0;
        $allDiscouraged = array_merge(
            is_array($messaging['discouraged_phrases'] ?? null) ? $messaging['discouraged_phrases'] : [],
            is_array($rules['discouraged_phrases'] ?? null) ? $rules['discouraged_phrases'] : [],
        );
        foreach ($allDiscouraged as $phrase) {
            if (is_string($phrase) && $phrase !== '' && mb_stripos($lowerText, mb_strtolower($phrase, 'UTF-8')) !== false) {
                $discouragedFound++;
            }
        }

        if ($discouragedFound > 0) {
            $evidence[] = EvidenceItem::hard(
                EvidenceSource::EXTRACTED_TEXT,
                sprintf('%d discouraged phrase(s) found in extracted text', $discouragedFound),
            );
            $score = max(0.0, $score - 0.2 * $discouragedFound);
        }

        if ($hasCampaignMessaging) {
            $evidence[] = EvidenceItem::soft(
                EvidenceSource::EXTRACTED_TEXT,
                sprintf('Text extracted (%d chars); campaign tone/voice comparison available', mb_strlen($ocrText, 'UTF-8')),
            );
        }

        if ($score >= 0.65 && $confidence >= 0.4) {
            $status = DimensionStatus::ALIGNED;
        } elseif ($score >= 0.4) {
            $status = DimensionStatus::PARTIAL;
        } elseif ($score >= 0.2) {
            $status = DimensionStatus::WEAK;
        } else {
            $status = DimensionStatus::FAIL;
        }

        $blockers = [];
        if ($discouragedFound > 0) {
            $blockers[] = 'Discouraged phrases detected in creative copy';
        }

        $statusReason = match ($status) {
            DimensionStatus::ALIGNED => 'Extracted copy appears to align with campaign messaging',
            DimensionStatus::PARTIAL => 'Extracted copy suggests partial campaign messaging alignment',
            DimensionStatus::WEAK => 'Extracted copy shows limited campaign messaging alignment',
            DimensionStatus::FAIL => 'Extracted copy appears to diverge from campaign messaging direction',
            default => 'Campaign copy evaluation completed',
        };

        return new DimensionResult(
            dimension: AlignmentDimension::COPY_VOICE,
            status: $status,
            score: $score,
            confidence: $confidence,
            primaryEvidenceSource: EvidenceSource::EXTRACTED_TEXT,
            evidence: $evidence,
            blockers: $blockers,
            evaluable: true,
            statusReason: $statusReason,
        );
    }

    /**
     * Campaign Context Fit: checks campaign goal, description, and rules.
     */
    private function evaluateContextFit(
        Asset $asset,
        CollectionCampaignIdentity $campaignIdentity,
        array $payload,
        \Illuminate\Support\Collection $references,
        EvaluationContext $context,
    ): DimensionResult {
        $evidence = [];
        $blockers = [];

        $hasGoal = ! empty($campaignIdentity->campaign_goal);
        $hasDesc = ! empty($campaignIdentity->campaign_description);
        $hasRules = is_array($payload['rules'] ?? null) && (
            ! empty($payload['rules']['required_motifs'])
            || ! empty($payload['rules']['category_notes'])
        );
        $hasExemplars = $references->where('reference_type', CampaignVisualReference::TYPE_EXEMPLAR)->isNotEmpty();
        $hasMotifRefs = $references->where('reference_type', CampaignVisualReference::TYPE_MOTIF)->isNotEmpty();

        if (! $hasGoal && ! $hasDesc && ! $hasRules && ! $hasExemplars) {
            return DimensionResult::notEvaluable(
                AlignmentDimension::CONTEXT_FIT,
                'No campaign goal, rules, or exemplar references configured',
                ['Add campaign goal or exemplar references to enable context fit evaluation'],
            );
        }

        if ($hasGoal) {
            $evidence[] = EvidenceItem::soft(
                EvidenceSource::CONFIGURATION_ONLY,
                sprintf('Campaign goal: %s', \Illuminate\Support\Str::limit($campaignIdentity->campaign_goal, 80)),
            );
        }

        if ($hasExemplars) {
            $evidence[] = EvidenceItem::soft(
                EvidenceSource::VISUAL_SIMILARITY,
                sprintf('%d exemplar execution reference(s) available for context fit', $references->where('reference_type', CampaignVisualReference::TYPE_EXEMPLAR)->count()),
            );
        }

        if ($hasMotifRefs) {
            $evidence[] = EvidenceItem::soft(
                EvidenceSource::VISUAL_SIMILARITY,
                sprintf('%d motif reference(s) available', $references->where('reference_type', CampaignVisualReference::TYPE_MOTIF)->count()),
            );
        }

        $score = 0.5;
        $confidence = 0.35;

        if ($hasGoal && $hasExemplars) {
            $confidence = 0.45;
        }

        return new DimensionResult(
            dimension: AlignmentDimension::CONTEXT_FIT,
            status: DimensionStatus::PARTIAL,
            score: $score,
            confidence: $confidence,
            primaryEvidenceSource: EvidenceSource::CONFIGURATION_ONLY,
            evidence: $evidence,
            blockers: $blockers,
            evaluable: true,
            statusReason: 'Campaign context fit based on available campaign goal and references; confidence is limited',
        );
    }

    /**
     * @param  array<string, float>  $baseWeights
     * @param  array<string, DimensionResult>  $results
     * @return array{weights: array<string, float>, note: string|null}
     */
    private function redistributeWeights(array $baseWeights, array $results): array
    {
        $evaluableWeight = 0.0;
        $nonEvaluableWeight = 0.0;
        $nonEvaluableDims = [];

        foreach ($baseWeights as $dim => $w) {
            $result = $results[$dim] ?? null;
            if ($result === null || ! $result->evaluable) {
                $nonEvaluableWeight += $w;
                $nonEvaluableDims[] = $dim;
            } else {
                $evaluableWeight += $w;
            }
        }

        if ($nonEvaluableWeight < 0.001 || $evaluableWeight < 0.001) {
            return ['weights' => $baseWeights, 'note' => null];
        }

        $adjusted = [];
        foreach ($baseWeights as $dim => $w) {
            if (in_array($dim, $nonEvaluableDims, true)) {
                $adjusted[$dim] = 0.0;
            } else {
                $adjusted[$dim] = $w / $evaluableWeight;
            }
        }

        $note = sprintf(
            '%s %s not_evaluable; weight redistributed to evaluable dimensions',
            implode(' and ', $nonEvaluableDims),
            count($nonEvaluableDims) === 1 ? 'was' : 'were',
        );

        return ['weights' => $adjusted, 'note' => $note];
    }

    private function extractTextContent(Asset $asset): string
    {
        $parts = [];
        $meta = is_array($asset->metadata ?? null) ? $asset->metadata : [];

        foreach (['extracted_text', 'ocr_text', 'vision_ocr', 'detected_text'] as $k) {
            if (! empty($meta[$k]) && is_string($meta[$k])) {
                $parts[] = $meta[$k];
            }
        }

        if (\Illuminate\Support\Facades\Schema::hasTable('pdf_text_extractions')) {
            $ext = \App\Models\PdfTextExtraction::query()
                ->where('asset_id', $asset->id)
                ->orderByDesc('id')
                ->first();
            if ($ext && is_string($ext->extracted_text ?? null) && trim($ext->extracted_text) !== '') {
                $parts[] = $ext->extracted_text;
            }
        }

        return trim(implode("\n", array_filter($parts)));
    }

    /**
     * Simple CIEDE2000-approximation via CIE76 for campaign palette comparison.
     *
     * @return list<float>
     */
    private function computeCampaignDeltaEs(array $assetColors, array $campaignColors): array
    {
        $campaignLabs = [];
        foreach ($campaignColors as $hex) {
            if (is_string($hex)) {
                $lab = $this->hexToLab($hex);
                if ($lab !== null) {
                    $campaignLabs[] = $lab;
                }
            }
        }

        if ($campaignLabs === []) {
            return [];
        }

        $deltaEs = [];
        foreach ($assetColors as $hex) {
            if (is_string($hex)) {
                $lab = $this->hexToLab($hex);
            } elseif (is_array($hex) && isset($hex['hex'])) {
                $lab = $this->hexToLab($hex['hex']);
            } else {
                continue;
            }

            if ($lab === null) {
                continue;
            }

            $minDelta = PHP_FLOAT_MAX;
            foreach ($campaignLabs as $cLab) {
                $d = sqrt(
                    pow($lab[0] - $cLab[0], 2)
                    + pow($lab[1] - $cLab[1], 2)
                    + pow($lab[2] - $cLab[2], 2)
                );
                $minDelta = min($minDelta, $d);
            }
            $deltaEs[] = $minDelta;
        }

        return $deltaEs;
    }

    /**
     * @return array{float, float, float}|null
     */
    private function hexToLab(string $hex): ?array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) !== 6 && strlen($hex) !== 3) {
            return null;
        }
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        $r = hexdec(substr($hex, 0, 2)) / 255.0;
        $g = hexdec(substr($hex, 2, 2)) / 255.0;
        $b = hexdec(substr($hex, 4, 2)) / 255.0;

        $r = $r > 0.04045 ? pow(($r + 0.055) / 1.055, 2.4) : $r / 12.92;
        $g = $g > 0.04045 ? pow(($g + 0.055) / 1.055, 2.4) : $g / 12.92;
        $b = $b > 0.04045 ? pow(($b + 0.055) / 1.055, 2.4) : $b / 12.92;

        $x = ($r * 0.4124564 + $g * 0.3575761 + $b * 0.1804375) / 0.95047;
        $y = ($r * 0.2126729 + $g * 0.7151522 + $b * 0.0721750);
        $z = ($r * 0.0193339 + $g * 0.1191920 + $b * 0.9503041) / 1.08883;

        $fx = $x > 0.008856 ? pow($x, 1 / 3) : (903.3 * $x + 16) / 116;
        $fy = $y > 0.008856 ? pow($y, 1 / 3) : (903.3 * $y + 16) / 116;
        $fz = $z > 0.008856 ? pow($z, 1 / 3) : (903.3 * $z + 16) / 116;

        $L = 116 * $fy - 16;
        $a = 500 * ($fx - $fy);
        $bVal = 200 * ($fy - $fz);

        return [$L, $a, $bVal];
    }

    private function cosineSimilarity(array $a, array $b): float
    {
        if (empty($a) || empty($b) || count($a) !== count($b)) {
            return 0.0;
        }
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        foreach ($a as $i => $v) {
            $w = $b[$i] ?? 0;
            $dot += $v * $w;
            $normA += $v * $v;
            $normB += $w * $w;
        }
        $denom = sqrt($normA) * sqrt($normB);

        return $denom < 1e-10 ? 0.0 : (float) ($dot / $denom);
    }
}
