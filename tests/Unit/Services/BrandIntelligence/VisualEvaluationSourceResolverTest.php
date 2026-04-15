<?php

namespace Tests\Unit\Services\BrandIntelligence;

use App\Enums\ThumbnailStatus;
use App\Models\Asset;
use App\Services\BrandIntelligence\VisualEvaluationSourceResolver;
use Tests\TestCase;

class VisualEvaluationSourceResolverTest extends TestCase
{
    public function test_image_asset_resolves_medium_thumbnail(): void
    {
        $resolver = new VisualEvaluationSourceResolver;
        $asset = new Asset([
            'mime_type' => 'image/png',
            'metadata' => [
                'thumbnails' => [
                    'original' => [
                        'medium' => ['path' => 'assets/tenant/x/medium.webp'],
                    ],
                ],
            ],
        ]);

        $r = $resolver->resolve($asset);
        $this->assertTrue($r['resolved']);
        $this->assertSame('original_image', $r['source_type']);
        $this->assertSame('preferred_thumbnail', $r['origin']);
        $this->assertSame('assets/tenant/x/medium.webp', $r['storage_path']);
    }

    public function test_pdf_with_medium_render_resolves_pdf_rendered_image(): void
    {
        $resolver = new VisualEvaluationSourceResolver;
        $asset = new Asset([
            'mime_type' => 'application/pdf',
            'thumbnail_status' => ThumbnailStatus::COMPLETED,
            'metadata' => [
                'thumbnails' => [
                    'original' => [
                        'medium' => ['path' => 'assets/tenant/x/page_1.png'],
                    ],
                ],
            ],
        ]);

        $r = $resolver->resolve($asset);
        $this->assertTrue($r['resolved']);
        $this->assertSame('pdf_rendered_image', $r['source_type']);
        $this->assertSame('preferred_thumbnail', $r['origin']);
        $this->assertSame(1, $r['page']);
        $this->assertSame('image/png', $r['mime_type']);
    }

    public function test_pdf_without_thumbnail_metadata_not_resolved(): void
    {
        $resolver = new VisualEvaluationSourceResolver;
        $asset = new Asset([
            'mime_type' => 'application/pdf',
            'metadata' => [],
        ]);

        $r = $resolver->resolve($asset);
        $this->assertFalse($r['resolved']);
        $this->assertSame('none', $r['source_type']);
        $this->assertSame('no_raster_thumbnail_in_metadata', $r['reason']);
    }

    public function test_trace_subset_maps_used_flag(): void
    {
        $subset = VisualEvaluationSourceResolver::traceSubset([
            'resolved' => true,
            'source_type' => 'pdf_rendered_image',
            'origin' => 'thumbnail',
            'reason' => 'ok',
            'page' => 2,
            'root_mime_type' => 'application/pdf',
        ]);
        $this->assertTrue($subset['used']);
        $this->assertTrue($subset['resolved']);
        $this->assertSame('pdf_rendered_image', $subset['source_type']);
    }
}
