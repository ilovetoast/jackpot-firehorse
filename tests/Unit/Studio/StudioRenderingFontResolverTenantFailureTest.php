<?php

namespace Tests\Unit\Studio;

use App\Models\Tenant;
use App\Studio\Rendering\Exceptions\StudioFontResolutionException;
use App\Studio\Rendering\StudioGoogleFontFileCache;
use App\Studio\Rendering\StudioRenderingFontFileCache;
use App\Studio\Rendering\StudioRenderingFontResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudioRenderingFontResolverTenantFailureTest extends TestCase
{
    use RefreshDatabase;

    public function test_explicit_missing_tenant_font_asset_throws(): void
    {
        Config::set('studio_rendering.default_font_path', '');
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't-'.Str::random(6)]);
        $resolver = new StudioRenderingFontResolver(new StudioRenderingFontFileCache, new StudioGoogleFontFileCache);
        $this->expectException(StudioFontResolutionException::class);
        $this->expectExceptionMessage('Font asset not found');
        $resolver->resolveForTextLayer(
            $tenant,
            null,
            [
                'font_asset_id' => '00000000-0000-7000-8000-000000000001',
                'font_family' => 'Custom',
            ],
            'Custom',
        );
    }
}
