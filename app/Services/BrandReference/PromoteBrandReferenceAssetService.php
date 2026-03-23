<?php

namespace App\Services\BrandReference;

use App\Enums\AssetContextType;
use App\Models\Asset;
use App\Models\BrandReferenceAsset;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PromoteBrandReferenceAssetService
{
    /**
     * @param  'reference'|'guideline'  $type
     * @param  ?string  $contextType  Optional {@see AssetContextType} value for EBI context matching
     *
     * @throws ValidationException
     */
    public function promote(Asset $asset, User $user, string $type, ?string $category = null, ?string $contextType = null): BrandReferenceAsset
    {
        if (! in_array($type, ['reference', 'guideline'], true)) {
            throw ValidationException::withMessages(['type' => ['Invalid promotion type.']]);
        }

        [$tier, $weight] = match ($type) {
            'reference' => [BrandReferenceAsset::TIER_REFERENCE, 0.6],
            'guideline' => [BrandReferenceAsset::TIER_GUIDELINE, 1.0],
        };

        $brandId = $asset->brand_id;
        if (! $brandId) {
            throw ValidationException::withMessages(['asset' => ['Asset has no brand.']]);
        }

        if (BrandReferenceAsset::query()->where('brand_id', $brandId)->where('asset_id', $asset->id)->exists()) {
            throw ValidationException::withMessages([
                'asset' => ['This asset is already promoted as a brand reference.'],
            ]);
        }

        $ctx = null;
        if ($contextType !== null && $contextType !== '') {
            $enum = AssetContextType::tryFromString($contextType);
            if ($enum === null) {
                throw ValidationException::withMessages([
                    'context_type' => ['Invalid context type.'],
                ]);
            }
            $ctx = $enum->value;
        }

        try {
            return DB::transaction(function () use ($asset, $user, $brandId, $tier, $weight, $category, $ctx) {
                return BrandReferenceAsset::create([
                    'brand_id' => $brandId,
                    'asset_id' => $asset->id,
                    'reference_type' => BrandReferenceAsset::REFERENCE_TYPE_STYLE,
                    'tier' => $tier,
                    'weight' => $weight,
                    'category' => $category !== null && $category !== '' ? $category : null,
                    'context_type' => $ctx,
                    'created_by' => $user->id,
                ]);
            });
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'brand_reference_assets_brand_id_asset_id_unique')) {
                throw ValidationException::withMessages([
                    'asset' => ['This asset is already promoted as a brand reference.'],
                ]);
            }
            throw $e;
        }
    }
}
