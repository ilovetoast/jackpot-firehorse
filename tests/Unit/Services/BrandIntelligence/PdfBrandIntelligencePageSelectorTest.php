<?php

namespace Tests\Unit\Services\BrandIntelligence;

use App\Models\Asset;
use App\Services\BrandIntelligence\PdfBrandIntelligencePageSelector;
use Tests\TestCase;

class PdfBrandIntelligencePageSelectorTest extends TestCase
{
    public function test_prefers_first_middle_last_when_no_size_hints(): void
    {
        $asset = new Asset([
            'mime_type' => 'application/pdf',
            'pdf_page_count' => 5,
            'metadata' => ['pdf_page_count' => 5],
        ]);
        $catalog = [
            ['page' => 1, 'storage_path' => 'assets/x/p1.webp', 'origin' => 't'],
            ['page' => 3, 'storage_path' => 'assets/x/p3.webp', 'origin' => 't'],
            ['page' => 5, 'storage_path' => 'assets/x/p5.webp', 'origin' => 't'],
        ];
        $plan = PdfBrandIntelligencePageSelector::select($asset, $catalog, 3);
        $this->assertSame('first_middle_last_then_fill', $plan['strategy']);
        $this->assertSame([1, 3, 5], $plan['selected_pages']);
    }

    public function test_inserts_largest_raster_page_after_first_when_size_bytes_present(): void
    {
        $asset = new Asset([
            'mime_type' => 'application/pdf',
            'pdf_page_count' => 6,
            'metadata' => [],
        ]);
        $catalog = [
            ['page' => 1, 'storage_path' => 'assets/x/p1.webp', 'origin' => 'db', 'size_bytes' => 100],
            ['page' => 2, 'storage_path' => 'assets/x/p2.webp', 'origin' => 'db', 'size_bytes' => 900],
            ['page' => 3, 'storage_path' => 'assets/x/p3.webp', 'origin' => 'db', 'size_bytes' => 200],
            ['page' => 6, 'storage_path' => 'assets/x/p6.webp', 'origin' => 'db', 'size_bytes' => 150],
        ];
        $plan = PdfBrandIntelligencePageSelector::select($asset, $catalog, 3);
        $this->assertSame('first_largest_raster_then_spaced_then_fill', $plan['strategy']);
        $this->assertSame([1, 2, 3], $plan['selected_pages']);
    }

    public function test_single_page_only_strategy(): void
    {
        $asset = new Asset(['mime_type' => 'application/pdf', 'pdf_page_count' => 1]);
        $catalog = [
            ['page' => 1, 'storage_path' => 'assets/x/p1.webp', 'origin' => 't'],
        ];
        $plan = PdfBrandIntelligencePageSelector::select($asset, $catalog, 3);
        $this->assertSame('single_page_only', $plan['strategy']);
        $this->assertSame([1], $plan['selected_pages']);
    }
}
