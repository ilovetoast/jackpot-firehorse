<?php

namespace App\Services\BrandIntelligence\Dimensions;

use App\Enums\AlignmentDimension;
use App\Enums\DimensionStatus;
use App\Enums\EvidenceSource;
use App\Models\Asset;
use App\Models\AssetEmbedding;
use App\Models\Brand;
use App\Models\BrandVisualReference;
use App\Services\BrandIntelligence\BrandIntelligenceTextEvidence;
use Illuminate\Support\Facades\Log;

final class IdentityEvaluator implements DimensionEvaluatorInterface
{
    private const LOGO_EMBEDDING_SIMILARITY_THRESHOLD = 0.72;

    public function evaluate(Asset $asset, Brand $brand, EvaluationContext $context): DimensionResult
    {
        $evidence = [];
        $blockers = [];

        $ocrResult = $this->brandNameInOcrText($asset, $brand, $context);
        $metadataResult = $this->brandNameInMetadata($asset, $brand);
        $embResult = $this->logoEmbeddingSimilarity($asset, $brand);

        $hasLogoReferences = BrandVisualReference::query()
            ->where('brand_id', $brand->id)
            ->where('type', BrandVisualReference::TYPE_LOGO)
            ->whereNotNull('embedding_vector')
            ->exists();

        if ($embResult['similarity'] !== null && $embResult['similarity'] >= self::LOGO_EMBEDDING_SIMILARITY_THRESHOLD) {
            $evidence[] = EvidenceItem::hard(
                EvidenceSource::VISUAL_SIMILARITY,
                sprintf('Logo embedding similarity %.2f to reference #%s', $embResult['similarity'], $embResult['reference_id'] ?? '?'),
            );
        } elseif ($embResult['similarity'] !== null) {
            $evidence[] = EvidenceItem::soft(
                EvidenceSource::VISUAL_SIMILARITY,
                sprintf('Logo embedding similarity %.2f (below threshold %.2f)', $embResult['similarity'], self::LOGO_EMBEDDING_SIMILARITY_THRESHOLD),
            );
        }

        if ($ocrResult['matched']) {
            $evidence[] = EvidenceItem::hard(
                EvidenceSource::EXTRACTED_TEXT,
                sprintf('Brand name "%s" found via OCR / extracted text', $ocrResult['token'] ?? ''),
            );
        }

        if ($metadataResult['matched']) {
            $evidence[] = EvidenceItem::readiness(
                EvidenceSource::METADATA_HINT,
                sprintf('Filename or title contains brand name "%s"', $metadataResult['token'] ?? ''),
            );
        }

        if ($hasLogoReferences && $embResult['similarity'] === null) {
            $evidence[] = EvidenceItem::readiness(
                EvidenceSource::CONFIGURATION_ONLY,
                'Logo references exist but asset has no embedding for comparison',
            );
            $blockers[] = 'Asset embedding not available for visual logo comparison';
        }

        if (! $hasLogoReferences) {
            $blockers[] = 'Upload approved logo references to enable visual identity comparison';
        }

        if (! $ocrResult['matched'] && ! $context->hasExtraction('ocr')) {
            $blockers[] = 'No OCR / extracted text available for brand name search';
        }

        $hasHard = false;
        $hasSoft = false;
        $primarySource = EvidenceSource::NOT_EVALUABLE;

        foreach ($evidence as $e) {
            if ($e->type === EvidenceSource::VISUAL_SIMILARITY && $e->weight === \App\Enums\EvidenceWeight::HARD) {
                $hasHard = true;
                $primarySource = EvidenceSource::VISUAL_SIMILARITY;
            }
            if ($e->type === EvidenceSource::EXTRACTED_TEXT && $e->weight === \App\Enums\EvidenceWeight::HARD) {
                $hasHard = true;
                if ($primarySource !== EvidenceSource::VISUAL_SIMILARITY) {
                    $primarySource = EvidenceSource::EXTRACTED_TEXT;
                }
            }
            if ($e->type === EvidenceSource::VISUAL_SIMILARITY && $e->weight === \App\Enums\EvidenceWeight::SOFT) {
                $hasSoft = true;
            }
        }

        if (! $hasHard && ! $hasSoft && $metadataResult['matched']) {
            $primarySource = EvidenceSource::METADATA_HINT;
        }

        return $this->deriveResult($evidence, $blockers, $hasHard, $hasSoft, $metadataResult['matched'], $primarySource, $embResult);
    }

    /**
     * @return array{matched: bool, token: string|null}
     */
    private function brandNameInOcrText(Asset $asset, Brand $brand, EvaluationContext $context): array
    {
        $haystack = $this->collectOcrTextHaystack($asset, $context);
        if ($haystack === '') {
            return ['matched' => false, 'token' => null];
        }

        foreach ($this->brandNameSearchTokens($brand) as $token) {
            if ($token === '' || mb_strlen($token, 'UTF-8') < 2) {
                continue;
            }
            if (mb_stripos($haystack, $token, 0, 'UTF-8') !== false) {
                return ['matched' => true, 'token' => $token];
            }
        }

        return ['matched' => false, 'token' => null];
    }

    /**
     * @return array{matched: bool, token: string|null}
     */
    private function brandNameInMetadata(Asset $asset, Brand $brand): array
    {
        $haystack = $this->collectMetadataHaystack($asset);
        if ($haystack === '') {
            return ['matched' => false, 'token' => null];
        }

        foreach ($this->brandNameSearchTokens($brand) as $token) {
            if ($token === '' || mb_strlen($token, 'UTF-8') < 2) {
                continue;
            }
            if (mb_stripos($haystack, $token, 0, 'UTF-8') !== false) {
                return ['matched' => true, 'token' => $token];
            }
        }

        return ['matched' => false, 'token' => null];
    }

    /**
     * OCR / extracted text / PDF text only -- NO title or filename.
     */
    private function collectOcrTextHaystack(Asset $asset, EvaluationContext $context): string
    {
        return BrandIntelligenceTextEvidence::mergedOcrBodyLowercase(
            $asset,
            $context->supplementalCreativeOcrText
        );
    }

    /**
     * Title + original_filename only -- produces metadata_hint (readiness) evidence.
     */
    private function collectMetadataHaystack(Asset $asset): string
    {
        $parts = [
            (string) ($asset->title ?? ''),
            (string) ($asset->original_filename ?? ''),
        ];

        return mb_strtolower(trim(implode("\n", array_filter($parts))), 'UTF-8');
    }

    /**
     * @return list<string>
     */
    private function brandNameSearchTokens(Brand $brand): array
    {
        $brand->loadMissing('brandModel.activeVersion');
        $payload = $brand->brandModel?->activeVersion?->model_payload ?? [];
        $short = is_array($payload['brand'] ?? null) ? ($payload['brand']['short_name'] ?? null) : null;

        $raw = array_filter([
            trim((string) $brand->name),
            trim((string) $brand->slug),
            is_string($short) ? trim($short) : null,
        ]);

        $tokens = [];
        foreach ($raw as $r) {
            if ($r === '') {
                continue;
            }
            $tokens[] = mb_strtolower($r, 'UTF-8');
            if (str_contains($r, '-') || str_contains($r, '_')) {
                $tokens[] = mb_strtolower(str_replace(['-', '_'], ' ', $r), 'UTF-8');
            }
        }

        return array_values(array_unique(array_filter($tokens, fn ($t) => mb_strlen($t, 'UTF-8') >= 2)));
    }

    /**
     * @return array{similarity: float|null, reference_id: int|string|null}
     */
    private function logoEmbeddingSimilarity(Asset $asset, Brand $brand): array
    {
        $row = AssetEmbedding::query()->where('asset_id', $asset->id)->first();
        if (! $row || empty($row->embedding_vector)) {
            return ['similarity' => null, 'reference_id' => null];
        }
        $vec = array_values($row->embedding_vector);

        $q = BrandVisualReference::query()
            ->where('brand_id', $brand->id)
            ->where('type', BrandVisualReference::TYPE_LOGO)
            ->whereNotNull('embedding_vector');

        $best = null;
        $bestId = null;
        foreach ($q->cursor() as $ref) {
            $refVec = array_values($ref->embedding_vector ?? []);
            if ($refVec === [] || count($refVec) !== count($vec)) {
                continue;
            }
            $c = $this->cosineSimilarity($vec, $refVec);
            if ($best === null || $c > $best) {
                $best = $c;
                $bestId = $ref->id;
            }
        }

        return [
            'similarity' => $best !== null ? round((float) $best, 4) : null,
            'reference_id' => $bestId,
        ];
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

    /**
     * @param  list<EvidenceItem>  $evidence
     * @param  list<string>  $blockers
     */
    private function deriveResult(
        array $evidence,
        array $blockers,
        bool $hasHard,
        bool $hasSoft,
        bool $hasMetadataHint,
        EvidenceSource $primarySource,
        array $embResult,
    ): DimensionResult {
        if (count($evidence) === 0 || ($primarySource === EvidenceSource::NOT_EVALUABLE && ! $hasMetadataHint)) {
            return DimensionResult::notEvaluable(
                AlignmentDimension::IDENTITY,
                'No identity evidence found (no OCR text, no logo references, no embedding)',
                $blockers,
            );
        }

        if ($hasHard) {
            $score = 0.0;
            $confidence = 0.0;

            if ($primarySource === EvidenceSource::VISUAL_SIMILARITY) {
                $sim = $embResult['similarity'] ?? 0.0;
                $score = min(1.0, max(0.0, ($sim - 0.5) / 0.5));
                $confidence = min(1.0, $sim);
            }
            if ($primarySource === EvidenceSource::EXTRACTED_TEXT || $primarySource === EvidenceSource::VISUAL_SIMILARITY) {
                foreach ($evidence as $e) {
                    if ($e->type === EvidenceSource::EXTRACTED_TEXT && $e->weight === \App\Enums\EvidenceWeight::HARD) {
                        $score = max($score, 0.7);
                        $confidence = max($confidence, 0.65);
                    }
                }
            }

            $bothVisualAndText = false;
            foreach ($evidence as $e) {
                if ($e->type === EvidenceSource::VISUAL_SIMILARITY && $e->weight === \App\Enums\EvidenceWeight::HARD) {
                    $bothVisualAndText = true;
                }
            }
            $bothVisualAndText = $bothVisualAndText && collect($evidence)->contains(fn ($e) => $e->type === EvidenceSource::EXTRACTED_TEXT && $e->weight === \App\Enums\EvidenceWeight::HARD);

            if ($bothVisualAndText) {
                $score = min(1.0, $score + 0.15);
                $confidence = min(1.0, $confidence + 0.1);
            }

            $status = $score >= 0.6 ? DimensionStatus::ALIGNED : DimensionStatus::PARTIAL;
            $reason = $this->buildStatusReason($evidence, $status);

            return new DimensionResult(
                dimension: AlignmentDimension::IDENTITY,
                status: $status,
                score: $score,
                confidence: $confidence,
                primaryEvidenceSource: $primarySource,
                evidence: $evidence,
                blockers: $blockers,
                evaluable: true,
                statusReason: $reason,
            );
        }

        if ($hasSoft) {
            return new DimensionResult(
                dimension: AlignmentDimension::IDENTITY,
                status: DimensionStatus::WEAK,
                score: 0.3,
                confidence: 0.3,
                primaryEvidenceSource: $primarySource,
                evidence: $evidence,
                blockers: $blockers,
                evaluable: true,
                statusReason: 'Logo similarity below threshold; weak visual match only',
            );
        }

        // metadata_hint only -- capped at weak, never aligned/partial
        return new DimensionResult(
            dimension: AlignmentDimension::IDENTITY,
            status: DimensionStatus::WEAK,
            score: 0.15,
            confidence: 0.15,
            primaryEvidenceSource: EvidenceSource::METADATA_HINT,
            evidence: $evidence,
            blockers: $blockers,
            evaluable: true,
            statusReason: 'Only filename/title metadata hints found; no visual or text evidence from creative',
        );
    }

    /**
     * @param  list<EvidenceItem>  $evidence
     */
    private function buildStatusReason(array $evidence, DimensionStatus $status): string
    {
        $parts = [];
        foreach ($evidence as $e) {
            if ($e->weight === \App\Enums\EvidenceWeight::HARD) {
                if ($e->type === EvidenceSource::VISUAL_SIMILARITY) {
                    $parts[] = 'logo visually similar to reference';
                } elseif ($e->type === EvidenceSource::EXTRACTED_TEXT) {
                    $parts[] = 'brand text found via OCR';
                }
            }
        }

        if ($parts === []) {
            return 'Identity evaluation completed with limited evidence';
        }

        $joined = implode(' and ', $parts);

        return ucfirst($joined);
    }
}
