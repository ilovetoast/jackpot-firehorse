<?php

namespace Tests\Unit\Support;

use App\Models\Asset;
use App\Support\AssetVariant;
use App\Support\DeliveryContext;
use App\Support\Preview3dDeliveryUrls;
use Mockery;
use Tests\TestCase;

class Preview3dDeliveryUrlsTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * @return \Mockery\MockInterface&Asset
     */
    private function assetMockWithMetadata(array $metadata): \Mockery\MockInterface
    {
        $asset = Mockery::mock(Asset::class);
        $asset->shouldReceive('offsetExists')->with('metadata')->andReturn(true);
        $asset->shouldReceive('getAttribute')->with('metadata')->andReturn($metadata);
        $asset->shouldReceive('getAttribute')->with('id')->andReturn('00000000-0000-4000-8000-000000000001');
        $asset->shouldReceive('getAttribute')->with('tenant_id')->andReturn('00000000-0000-4000-8000-000000000002');

        return $asset;
    }

    public function test_poster_url_non_empty_when_delivery_returns_cdn_url_viewer_null_when_delivery_empty(): void
    {
        $posterUrl = 'https://cdn.example.com/tenants/u/assets/a/v1/previews/model_3d_poster.webp';

        $asset = $this->assetMockWithMetadata([]);
        $asset->shouldReceive('deliveryUrl')
            ->once()
            ->with(AssetVariant::PREVIEW_3D_POSTER, DeliveryContext::AUTHENTICATED)
            ->andReturn($posterUrl);
        $asset->shouldReceive('deliveryUrl')
            ->once()
            ->with(AssetVariant::PREVIEW_3D_GLB, DeliveryContext::AUTHENTICATED)
            ->andReturn('');

        $urls = Preview3dDeliveryUrls::forAuthenticatedAsset($asset);

        $this->assertSame($posterUrl, $urls['preview_3d_poster_url']);
        $this->assertNull($urls['preview_3d_viewer_url']);
        $this->assertStringStartsWith('https://cdn.example.com/', $urls['preview_3d_poster_url']);
        $this->assertArrayHasKey('preview_3d_revision', $urls);
        $this->assertSame('', $urls['preview_3d_revision']);
    }

    public function test_preview_3d_viewer_url_non_empty_when_delivery_returns_url(): void
    {
        $posterUrl = 'https://cdn.example.com/poster.webp';
        $viewerUrl = 'https://cdn.example.com/model.glb';

        $asset = $this->assetMockWithMetadata([
            'preview_3d' => ['status' => 'ready', 'poster_path' => 'p', 'viewer_path' => 'v'],
        ]);
        $asset->shouldReceive('deliveryUrl')
            ->once()
            ->with(AssetVariant::PREVIEW_3D_POSTER, DeliveryContext::AUTHENTICATED)
            ->andReturn($posterUrl);
        $asset->shouldReceive('deliveryUrl')
            ->once()
            ->with(AssetVariant::PREVIEW_3D_GLB, DeliveryContext::AUTHENTICATED)
            ->andReturn($viewerUrl);

        $urls = Preview3dDeliveryUrls::forAuthenticatedAsset($asset);

        $this->assertSame($posterUrl, $urls['preview_3d_poster_url']);
        $this->assertSame($viewerUrl, $urls['preview_3d_viewer_url']);
        $this->assertArrayHasKey('preview_3d_revision', $urls);
        $this->assertSame(12, strlen((string) $urls['preview_3d_revision']));
    }
}
