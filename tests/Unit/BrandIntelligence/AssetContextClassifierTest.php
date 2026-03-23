<?php

namespace Tests\Unit\BrandIntelligence;

use App\Enums\AssetContextType;
use App\Models\Asset;
use App\Services\BrandIntelligence\AssetContextClassifier;
use Tests\TestCase;

class AssetContextClassifierTest extends TestCase
{
    public function test_outdoor_dark_ad_classifies_as_lifestyle(): void
    {
        $c = new AssetContextClassifier();
        $asset = new Asset([
            'title' => 'Q4 outdoor campaign — dark evening',
            'original_filename' => 'brand-outdoor-evening-dark.jpg',
            'mime_type' => 'image/jpeg',
            'metadata' => ['width' => 2400, 'height' => 1600],
        ]);

        $this->assertSame(AssetContextType::LIFESTYLE, $c->classify($asset));
    }

    public function test_product_hero_keyword(): void
    {
        $c = new AssetContextClassifier();
        $asset = new Asset([
            'title' => 'SKU packshot',
            'original_filename' => 'product-hero.png',
            'mime_type' => 'image/png',
        ]);

        $this->assertSame(AssetContextType::PRODUCT_HERO, $c->classify($asset));
    }

    public function test_social_keyword(): void
    {
        $c = new AssetContextClassifier();
        $asset = new Asset([
            'title' => 'Instagram story',
            'original_filename' => 'slide.jpg',
            'mime_type' => 'image/jpeg',
        ]);

        $this->assertSame(AssetContextType::SOCIAL_POST, $c->classify($asset));
    }
}
