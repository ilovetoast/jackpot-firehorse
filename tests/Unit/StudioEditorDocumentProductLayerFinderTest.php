<?php

namespace Tests\Unit;

use App\Support\StudioEditorDocumentProductLayerFinder;
use PHPUnit\Framework\TestCase;

class StudioEditorDocumentProductLayerFinderTest extends TestCase
{
    public function test_prefers_product_named_layer(): void
    {
        $doc = [
            'layers' => [
                ['id' => 'bg', 'type' => 'image', 'z' => 0, 'name' => 'Background', 'assetId' => 'a1', 'src' => '/x'],
                ['id' => 'p', 'type' => 'image', 'z' => 2, 'name' => 'Product', 'assetId' => 'a2', 'src' => '/y'],
            ],
        ];
        $found = StudioEditorDocumentProductLayerFinder::find($doc);
        $this->assertSame('p', $found['layer_id']);
        $this->assertSame('a2', $found['asset_id']);
    }
}
