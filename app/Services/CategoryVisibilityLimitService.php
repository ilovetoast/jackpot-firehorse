<?php

namespace App\Services;

use App\Enums\AssetType;
use App\Models\Brand;
use App\Models\Category;

/**
 * Enforces max visible (non-hidden) categories per brand for asset / deliverable types.
 */
class CategoryVisibilityLimitService
{
    public function appliesTo(AssetType $assetType): bool
    {
        return in_array($assetType, [AssetType::ASSET, AssetType::DELIVERABLE], true);
    }

    public function maxFor(AssetType $assetType): int
    {
        $key = $assetType->value;

        return (int) config("categories.max_visible_per_brand_by_asset_type.{$key}", 20);
    }

    public function countVisible(Brand $brand, AssetType $assetType): int
    {
        if (! $this->appliesTo($assetType)) {
            return 0;
        }

        return Category::query()
            ->where('brand_id', $brand->id)
            ->where('asset_type', $assetType)
            ->where('is_hidden', false)
            ->count();
    }

    /**
     * @throws \RuntimeException
     */
    public function assertCanMakeVisible(Brand $brand, AssetType $assetType): void
    {
        if (! $this->appliesTo($assetType)) {
            return;
        }

        if ($this->countVisible($brand, $assetType) >= $this->maxFor($assetType)) {
            throw new \RuntimeException(sprintf(
                'You can have at most %d visible categories for %s. Hide another category first.',
                $this->maxFor($assetType),
                $assetType->value
            ));
        }
    }

    /**
     * @return array{asset: array{visible: int, max: int, at_cap: bool}, deliverable: array{visible: int, max: int, at_cap: bool}}
     */
    public function limitsPayloadForBrand(Brand $brand): array
    {
        $out = [];
        foreach ([AssetType::ASSET, AssetType::DELIVERABLE] as $type) {
            $max = $this->maxFor($type);
            $visible = $this->countVisible($brand, $type);
            $out[$type->value] = [
                'visible' => $visible,
                'max' => $max,
                'at_cap' => $visible >= $max,
            ];
        }

        return $out;
    }
}
