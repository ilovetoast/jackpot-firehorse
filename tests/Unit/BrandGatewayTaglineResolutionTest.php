<?php

namespace Tests\Unit;

use App\Models\Brand;
use App\Models\Tenant;
use App\Services\BrandGateway\BrandThemeBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrandGatewayTaglineResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_tagline_hidden_source_returns_null_tagline(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't-slug']);
        $brand = Brand::create([
            'tenant_id' => $tenant->id,
            'name' => 'B',
            'slug' => 'b-slug',
            'portal_settings' => [
                'entry' => [
                    'tagline_source' => 'hidden',
                    'tagline_override' => 'Should not show',
                ],
            ],
        ]);

        $theme = app(BrandThemeBuilder::class)->build($tenant, $brand, false);

        $this->assertNull($theme['tagline']);
    }

    public function test_tagline_custom_source_uses_override(): void
    {
        $tenant = Tenant::create(['name' => 'T2', 'slug' => 't2-slug']);
        $brand = Brand::create([
            'tenant_id' => $tenant->id,
            'name' => 'B2',
            'slug' => 'b2-slug',
            'portal_settings' => [
                'entry' => [
                    'tagline_source' => 'custom',
                    'tagline_override' => 'Custom gateway line',
                ],
            ],
        ]);

        $theme = app(BrandThemeBuilder::class)->build($tenant, $brand, false);

        $this->assertSame('Custom gateway line', $theme['tagline']);
    }
}
