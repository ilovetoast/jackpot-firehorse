<?php

namespace App\Services\BrandIntelligence\Dimensions;

use App\Enums\AssetContextType;
use App\Enums\MediaType;
use App\Models\Asset;
use App\Models\CollectionCampaignIdentity;
use App\Services\BrandIntelligence\Campaign\CampaignIdentityPayloadNormalizer;

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
    ) {
        $this->mediaType = $mediaType;
        $this->contextType = $contextType;
        $this->availableExtractions = $availableExtractions;
        $this->unavailableExtractions = $unavailableExtractions;
        $this->hasCampaignOverride = $hasCampaignOverride;
        $this->campaignDna = $campaignDna;
    }

    public function hasExtraction(string $key): bool
    {
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
        ];
    }

    public static function fromAsset(Asset $asset, AssetContextType $contextType): self
    {
        $mime = strtolower(trim((string) ($asset->mime_type ?? '')));
        $mediaType = MediaType::fromMime($mime);
        $meta = is_array($asset->metadata ?? null) ? $asset->metadata : [];

        $available = [];
        $unavailable = [];

        if ($mediaType === MediaType::IMAGE || $mediaType === MediaType::PDF) {
            $available[] = 'screenshot';
        } else {
            $unavailable[] = 'screenshot';
        }

        $hasOcr = ! empty($meta['extracted_text'] ?? null)
            || ! empty($meta['ocr_text'] ?? null)
            || ! empty($meta['vision_ocr'] ?? null)
            || ! empty($meta['detected_text'] ?? null);
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

        if ($mediaType === MediaType::VIDEO || $mediaType === MediaType::AUDIO) {
            $unavailable[] = 'transcript';
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
        );
    }
}
