<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\AssetType;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

trait BuildsBulkAssignCategoryOptions
{
    /**
     * Hidden Fonts category is shown in the library (and bulk assign) when the user manages
     * categories, filters to Fonts, or at least one asset is filed under Fonts.
     */
    protected function shouldIncludeHiddenFontsCategory(
        \App\Models\Tenant $tenant,
        Brand $brand,
        ?User $user,
        ?string $categoryQuerySlug
    ): bool {
        if ($user && $user->can('manage categories')) {
            return true;
        }
        if ($categoryQuerySlug !== null && strtolower($categoryQuerySlug) === 'fonts') {
            return true;
        }

        $fontsCategory = Category::query()
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->where('asset_type', AssetType::ASSET)
            ->where('slug', 'fonts')
            ->active()
            ->first();

        if (! $fontsCategory) {
            return false;
        }

        return Asset::query()
            ->normalIntakeOnly()
            ->excludeBuilderStaged()
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->forAssetLibraryTypes()
            ->whereNotNull('metadata')
            ->whereRaw(
                'CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata, "$.category_id")) AS UNSIGNED) = ?',
                [$fontsCategory->id]
            )
            ->exists();
    }

    /**
     * Category dropdown options per asset type for bulk "Assign Category" (library / execution / generative).
     *
     * @return array{asset: list<array{id: int, name: string, slug: string, asset_type: string}>, deliverable: list<array<string, mixed>>, ai_generated: list<array<string, mixed>>}
     */
    protected function buildBulkAssignCategoryOptionsByAssetType($tenant, Brand $brand, ?User $user): array
    {
        $out = [
            AssetType::ASSET->value => [],
            AssetType::DELIVERABLE->value => [],
            AssetType::AI_GENERATED->value => [],
        ];

        $includeHiddenFontsNav = $this->shouldIncludeHiddenFontsCategory($tenant, $brand, $user, null);

        foreach ([AssetType::ASSET, AssetType::DELIVERABLE, AssetType::AI_GENERATED] as $type) {
            $query = Category::where('tenant_id', $tenant->id)
                ->where('brand_id', $brand->id)
                ->where('asset_type', $type)
                ->active()
                ->ordered();

            if (! $user || ! $user->can('manage categories')) {
                if ($type === AssetType::ASSET && $includeHiddenFontsNav) {
                    $query->where(function ($q) {
                        $q->where('is_hidden', false)
                            ->orWhere(function ($q2) {
                                $q2->where('slug', 'fonts')->where('is_hidden', true);
                            });
                    });
                } else {
                    $query->visible();
                }
            }

            $categories = $query->get()->filter(function ($category) use ($user) {
                return $user ? Gate::forUser($user)->allows('view', $category) : false;
            });

            $out[$type->value] = $categories->map(fn (Category $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'slug' => $c->slug,
                'asset_type' => $c->asset_type->value,
            ])->values()->all();
        }

        return $out;
    }
}
