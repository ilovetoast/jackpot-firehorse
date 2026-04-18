<?php

namespace App\Services\BrandIntelligence\Dimensions;

use App\Enums\AlignmentDimension;
use App\Enums\MediaType;
use App\Models\Asset;
use App\Models\Brand;
use App\Services\BrandIntelligence\AssetContextClassifier;
use App\Services\BrandIntelligence\BrandColorPaletteAlignmentEvaluator;

final class EvaluationOrchestrator
{
    /**
     * Category-aware dimension weights.
     * Typography is intentionally low for most types -- font extraction from pixels is rarely reliable.
     *
     * @var array<string, array<string, float>>
     */
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

    private IdentityEvaluator $identityEvaluator;
    private ColorEvaluator $colorEvaluator;
    private TypographyEvaluator $typographyEvaluator;
    private VisualStyleEvaluator $visualStyleEvaluator;
    private CopyVoiceEvaluator $copyVoiceEvaluator;
    private ContextFitEvaluator $contextFitEvaluator;
    private AssetContextClassifier $contextClassifier;

    public function __construct(
        BrandColorPaletteAlignmentEvaluator $paletteEvaluator,
        AssetContextClassifier $contextClassifier,
    ) {
        $this->identityEvaluator = new IdentityEvaluator();
        $this->colorEvaluator = new ColorEvaluator($paletteEvaluator);
        $this->typographyEvaluator = new TypographyEvaluator();
        $this->visualStyleEvaluator = new VisualStyleEvaluator();
        $this->copyVoiceEvaluator = new CopyVoiceEvaluator();
        $this->contextFitEvaluator = new ContextFitEvaluator();
        $this->contextClassifier = $contextClassifier;
    }

    /**
     * Run all 6 dimension evaluators and return results + context + weights.
     *
     * @return array{
     *   dimensions: array<string, DimensionResult>,
     *   context: EvaluationContext,
     *   weights: array<string, float>,
     *   weights_note: string|null,
     * }
     */
    /**
     * @param  list<float>|null  $logoCropVector  Embedding of the detected logo region (Stage 4).
     */
    public function evaluate(Asset $asset, Brand $brand, ?string $supplementalCreativeOcrText = null, ?array $logoCropVector = null): array
    {
        $contextType = $this->contextClassifier->classify($asset);
        $context = EvaluationContext::fromAsset($asset, $contextType);
        if ($supplementalCreativeOcrText !== null && trim($supplementalCreativeOcrText) !== '') {
            $context = EvaluationContext::withSupplementalCreativeOcr($context, $supplementalCreativeOcrText);
        }
        if (is_array($logoCropVector) && $logoCropVector !== []) {
            $context->logoCropVector = array_values(array_map('floatval', $logoCropVector));
        }

        $results = [];
        $results[AlignmentDimension::IDENTITY->value] = $this->identityEvaluator->evaluate($asset, $brand, $context);
        $results[AlignmentDimension::COLOR->value] = $this->colorEvaluator->evaluate($asset, $brand, $context);
        $results[AlignmentDimension::TYPOGRAPHY->value] = $this->typographyEvaluator->evaluate($asset, $brand, $context);
        $results[AlignmentDimension::VISUAL_STYLE->value] = $this->visualStyleEvaluator->evaluate($asset, $brand, $context);
        $results[AlignmentDimension::COPY_VOICE->value] = $this->copyVoiceEvaluator->evaluate($asset, $brand, $context);
        $results[AlignmentDimension::CONTEXT_FIT->value] = $this->contextFitEvaluator->evaluate($asset, $brand, $context);

        $baseWeights = self::WEIGHT_PROFILES[$context->mediaType->value] ?? self::WEIGHT_PROFILES['other'];
        $redistributed = $this->redistributeWeights($baseWeights, $results);

        return [
            'dimensions' => $results,
            'context' => $context,
            'weights' => $redistributed['weights'],
            'weights_note' => $redistributed['note'],
        ];
    }

    /**
     * Enrich dimension results with creative intelligence AI pass data.
     *
     * @param  array<string, DimensionResult>  $dimensions
     * @return array<string, DimensionResult>
     */
    public function enrichWithCreativeIntelligence(array $dimensions, ?array $creativePayload): array
    {
        if ($creativePayload === null) {
            return $dimensions;
        }

        $copyKey = AlignmentDimension::COPY_VOICE->value;
        if (isset($dimensions[$copyKey])) {
            $dimensions[$copyKey] = $this->copyVoiceEvaluator->enrichWithCreativeIntelligence(
                $dimensions[$copyKey],
                $creativePayload['copy_alignment'] ?? null,
                $creativePayload['ebi_ai_trace'] ?? null,
            );
        }

        $contextKey = AlignmentDimension::CONTEXT_FIT->value;
        if (isset($dimensions[$contextKey])) {
            $dimensions[$contextKey] = $this->contextFitEvaluator->enrichWithCreativeIntelligence(
                $dimensions[$contextKey],
                $creativePayload['context_analysis'] ?? null,
            );
        }

        return $dimensions;
    }

    /**
     * Second enrichment pass: route structured VLM `creative_signals` into the four
     * dimensions that can use them (Typography, Visual Style, Color, Context Fit).
     *
     * Safe to call with null; becomes a no-op. All evaluator hooks bail out when
     * the current result is already evaluated with a non-VLM source, so this is
     * strictly additive.
     *
     * @param  array<string, DimensionResult>  $dimensions
     * @param  array<string, mixed>|null  $creativeSignals  breakdown.creative_signals
     * @return array<string, DimensionResult>
     */
    public function enrichWithCreativeSignals(
        Asset $asset,
        Brand $brand,
        array $dimensions,
        ?array $creativeSignals,
    ): array {
        if ($creativeSignals === null) {
            return $dimensions;
        }

        $typoKey = AlignmentDimension::TYPOGRAPHY->value;
        if (isset($dimensions[$typoKey])) {
            $dimensions[$typoKey] = $this->typographyEvaluator->applyCreativeSignals(
                $dimensions[$typoKey],
                $creativeSignals,
                $brand,
            );
        }

        $styleKey = AlignmentDimension::VISUAL_STYLE->value;
        if (isset($dimensions[$styleKey])) {
            $dimensions[$styleKey] = $this->visualStyleEvaluator->applyCreativeSignals(
                $dimensions[$styleKey],
                $creativeSignals,
                $brand,
            );
        }

        $colorKey = AlignmentDimension::COLOR->value;
        if (isset($dimensions[$colorKey])) {
            $dimensions[$colorKey] = $this->colorEvaluator->applyCreativeSignals(
                $dimensions[$colorKey],
                $creativeSignals,
                $asset,
                $brand,
            );
        }

        $contextKey = AlignmentDimension::CONTEXT_FIT->value;
        if (isset($dimensions[$contextKey])) {
            $dimensions[$contextKey] = $this->contextFitEvaluator->applyCreativeSignals(
                $dimensions[$contextKey],
                $creativeSignals,
            );
        }

        return $dimensions;
    }

    /**
     * Third enrichment pass (Stage 8a): peer-cohort Context Fit fallback.
     *
     * Runs after {@see self::enrichWithCreativeSignals()} so a real VLM classification wins
     * over peer similarity. Only acts when Context Fit is still unclassified/approximate.
     *
     * @param  array<string, DimensionResult>  $dimensions
     * @return array<string, DimensionResult>
     */
    public function enrichWithPeerCohortContextFit(
        Asset $asset,
        array $dimensions,
        \App\Services\BrandIntelligence\PeerCohortContextFitService $peerCohortService,
    ): array {
        $contextKey = AlignmentDimension::CONTEXT_FIT->value;
        if (! isset($dimensions[$contextKey])) {
            return $dimensions;
        }

        $dimensions[$contextKey] = $this->contextFitEvaluator->applyPeerCohortFallback(
            $dimensions[$contextKey],
            $asset,
            $peerCohortService,
        );

        return $dimensions;
    }

    /**
     * Redistribute weight from not_evaluable / missing_reference / configuration_only dimensions
     * to evaluable dimensions proportionally.
     *
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
}
