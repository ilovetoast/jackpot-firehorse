<?php

namespace Tests\Unit\Services\BrandDNA;

use App\Enums\AssetType;
use App\Models\Brand;
use App\Models\BrandModel;
use App\Models\Category;
use App\Services\BrandDNA\GoogleFontLibraryEntriesService;
use Tests\TestCase;

/**
 * No database: covers early exits. Full virtual rows (typography + dedupe) need a migrated DB — see docs/FONTS_LIBRARY.md.
 */
class GoogleFontLibraryEntriesServiceTest extends TestCase
{
    public function test_returns_empty_when_category_slug_is_not_fonts(): void
    {
        $brand = new Brand;
        $brand->forceFill(['id' => 1, 'tenant_id' => 1]);

        $category = new Category;
        $category->forceFill(['slug' => 'photography', 'asset_type' => AssetType::ASSET]);

        $svc = new GoogleFontLibraryEntriesService;

        $this->assertSame([], $svc->virtualAssetsForFontsCategory($brand, $category));
    }

    public function test_returns_empty_when_active_brand_dna_version_missing(): void
    {
        $brand = new Brand;
        $brand->forceFill(['id' => 1, 'tenant_id' => 1]);

        $brandModel = new BrandModel;
        $brandModel->setRelation('activeVersion', null);
        $brand->setRelation('brandModel', $brandModel);

        $category = new Category;
        $category->forceFill(['slug' => 'fonts', 'asset_type' => AssetType::ASSET]);

        $svc = new GoogleFontLibraryEntriesService;

        $this->assertSame([], $svc->virtualAssetsForFontsCategory($brand, $category));
    }
}
