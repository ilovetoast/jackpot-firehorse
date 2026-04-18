<?php

namespace App\Services\BrandIntelligence\Dimensions;

use App\Enums\AssetContextType;
use App\Enums\MediaType;
use App\Models\Asset;
use App\Models\CollectionCampaignIdentity;
use App\Services\BrandIntelligence\Campaign\CampaignIdentityPayloadNormalizer;
use App\Services\BrandIntelligence\VisualEvaluationSourceResolver;

final class EvaluationContext
{
    public MediaType $mediaType;
    public AssetContextType $contextType;
    /** @var list<string> */
    public array $availableExtractions;
    /** @var list<string> */
    public array $unavailableExtractions;
    public bool $hasCampaignOverride;
    public ?array $campaignDna;

    /** Creative-vision text from the same scoring pass (e.g. PDF page raster OCR). */
    public ?string $supplementalCreativeOcrText = null;

    /**
     * True when {@see VisualEvaluationSourceResolver} selected a usable raster (image thumbnail, PDF page render, etc.).
     * Videos and other non-visual roots stay false.
     */
    public bool $visualEvaluationRasterResolved = false;

    /**
     * Embedding of the detected logo region crop (Stage 4). When present,
     * {@see \App\Services\BrandIntelligence\Dimensions\IdentityEvaluator}
     * prefers this over the full-asset embedding for logo-reference similarity.
     *
     * @var list<float>|null
     */
    public ?array $logoCropVector = null;

    /**
     * @param  list<string>  $availableExtractions   e.g. ['screenshot', 'ocr', 'palette', 'embeddings']
     * @param  list<string>  $unavailableExtractions e.g. ['transcript']
     */
    public function __construct(
        MediaType $mediaType,
        AssetContextType $contextType,
        array $availableExtractions,
        array $unavailableExtractions,
        bool $hasCampaignOverride,
        ?array $campaignDna,
        ?string $supplementalCreativeOcrText = null,
        bool $visualEvaluationRasterResolved = false,
    ) {
        $this->mediaType = $mediaType;
        $this->contextType = $contextType;
        $this->availableExtractions = $availableExtractions;
        $this->unavailableExtractions = $unavailableExtractions;
        $this->hasCampaignOverride = $hasCampaignOverride;
        $this->campaignDna = $campaignDna;
        $this->supplementalCreativeOcrText = $supplementalCreativeOcrText;
        $this->visualEvaluationRasterResolved = $visualEvaluationRasterResolved;
    }

    public function hasExtraction(string $key): bool
    {
        if ($key === 'ocr' && is_string($this->supplementalCreativeOcrText) && trim($this->supplementalCreativeOcrText) !== '') {
            return true;
        }

        return in_array($key, $this->availableExtractions, true);
    }

    public function toArray(): array
    {
        return [
            'media_type' => $this->mediaType->value,
            'context_type' => $this->contextType->value,
            'available_extractions' => $this->availableExtractions,
            'unavailable_extractions' => $this->unavailableExtractions,
            'campaign_override' => $this->hasCampaignOverride,
            'supplemental_creative_ocr' => $this->supplementalCreativeOcrText !== null
                && trim($this->supplementalCreativeOcrText) !== '',
            'pdf_page_raster_hint' => $this->mediaType === MediaType::PDF && $this->visualEvaluationRasterResolved,
            'visual_evaluation_raster_resolved' => $this->visualEvaluationRasterResolved,
        ];
    }

    public static function fromAsset(Asset $asset, AssetContextType $contextType): self
    {
        $mime = strtolower(trim((string) ($asset->mime_type ?? '')));
        $mediaType = MediaType::fromMime($mime);
        $meta = is_array($asset->metadata ?? null) ? $asset->metadata : [];

        $visualEvaluationRasterResolved = app(VisualEvaluationSourceResolver::class)->assetHasRenderableRaster($asset);

        $available = [];
        $unavailable = [];

        if ($mediaType === MediaType::AUDIO) {
            $unavailable[] = 'screenshot';
        } elseif ($mediaType === MediaType::VIDEO) {
            if ($visualEvaluationRasterResolved) {
                $available[] = 'screenshot';
            } else {
                $unavailable[] = 'screenshot';
            }
        } elseif ($mediaType === MediaType::PDF) {
            if ($visualEvaluationRasterResolved) {
                $available[] = 'screenshot';
            } else {
                $unavailable[] = 'screenshot';
            }
        } elseif ($mediaType === MediaType::IMAGE || $visualEvaluationRasterResolved) {
            $available[] = 'screenshot';
        } else {
            $unavailable[] = 'screenshot';
        }

        $videoTranscript = (string) data_get($meta, 'ai_video_insights.transcript', '');
        $hasVideoTranscript = trim($videoTranscript) !== '';
        $hasOcr = ! empty($meta['extracted_text'] ?? null)
            || ! empty($meta['ocr_text'] ?? null)
            || ! empty($meta['vision_ocr'] ?? null)
            || ! empty($meta['detected_text'] ?? null)
            || $hasVideoTranscript;
        if ($hasOcr) {
            $available[] = 'ocr';
        } else {
            $unavailable[] = 'ocr';
        }

        $hasPalette = ! empty($meta['dominant_colors'] ?? null)
            || ! empty(data_get($meta, 'fields.dominant_colors'));
        if ($hasPalette) {
            $available[] = 'palette';
        } else {
            $unavailable[] = 'palette';
        }

        if (($mediaType === MediaType::VIDEO || $mediaType === MediaType::AUDIO) && $hasVideoTranscript) {
            $available[] = 'transcript';
        } else {
            $unavailable[] = 'transcript';
        }

        $hasEmbedding = \App\Models\AssetEmbedding::query()
            ->where('asset_id', $asset->id)
            ->whereNotNull('embedding_vector')
            ->exists();
        if ($hasEmbedding) {
            $available[] = 'embeddings';
        } else {
            $unavailable[] = 'embeddings';
        }

        return new self(
            mediaType: $mediaType,
            contextType: $contextType,
            availableExtractions: $available,
            unavailableExtractions: $unavailable,
            hasCampaignOverride: false,
            campaignDna: null,
            supplementalCreativeOcrText: null,
            visualEvaluationRasterResolved: $visualEvaluationRasterResolved,
        );
    }

    public static function fromAssetWithCampaign(
        Asset $asset,
        AssetContextType $contextType,
        CollectionCampaignIdentity $campaignIdentity,
    ): self {
        $base = self::fromAsset($asset, $contextType);

        $normalized = CampaignIdentityPayloadNormalizer::normalize(
            is_array($campaignIdentity->identity_payload) ? $campaignIdentity->identity_payload : []
        );

        return new self(
            mediaType: $base->mediaType,
            contextType: $base->contextType,
            availableExtractions: $base->availableExtractions,
            unavailableExtractions: $base->unavailableExtractions,
            hasCampaignOverride: true,
            campaignDna: $normalized,
            supplementalCreativeOcrText: $base->supplementalCreativeOcrText,
            visualEvaluationRasterResolved: $base->visualEvaluationRasterResolved,
        );
    }

    /**
     * Merge creative-vision OCR into extraction flags for the same request (PDF raster path).
     */
    public static function withSupplementalCreativeOcr(self $base, ?string $text): self
    {
        $trimmed = $text !== null ? trim($text) : '';
        if ($trimmed === '') {
            return $base;
        }

        $available = $base->availableExtractions;
        if (! in_array('ocr', $available, true)) {
            $available[] = 'ocr';
        }
        $unavailable = array_values(array_filter(
            $base->unavailableExtractions,
            static fn (string $x): bool => $x !== 'ocr'
        ));

        return new self(
            mediaType: $base->mediaType,
            contextType: $base->contextType,
            availableExtractions: $available,
            unavailableExtractions: $unavailable,
            hasCampaignOverride: $base->hasCampaignOverride,
            campaignDna: $base->campaignDna,
            supplementalCreativeOcrText: $trimmed,
            visualEvaluationRasterResolved: $base->visualEvaluationRasterResolved,
        );
    }
}
