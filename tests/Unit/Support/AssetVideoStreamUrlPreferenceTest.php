<?php

namespace Tests\Unit\Support;

use App\Models\Asset;
use App\Support\AssetVariant;
use App\Support\AssetVideoStreamUrlPreference;
use App\Support\DeliveryContext;
use Mockery;
use Tests\TestCase;

class AssetVideoStreamUrlPreferenceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_prefers_video_web_when_ready_metadata(): void
    {
        $asset = Mockery::mock(Asset::class)->makePartial();
        $asset->shouldReceive('getAttribute')->with('metadata')->andReturn([
            'video' => [
                'web_playback_status' => 'ready',
                'web_playback_path' => 'tenants/x/assets/y/v1/previews/video_web.mp4',
            ],
        ]);
        $asset->shouldReceive('deliveryUrl')
            ->once()
            ->with(AssetVariant::VIDEO_WEB, DeliveryContext::AUTHENTICATED->value)
            ->andReturn('https://cdn.example.com/web.mp4');
        $asset->shouldNotReceive('deliveryUrl')->with(AssetVariant::ORIGINAL, Mockery::any());

        $url = AssetVideoStreamUrlPreference::resolvePlaybackUrl($asset, DeliveryContext::AUTHENTICATED->value);
        $this->assertSame('https://cdn.example.com/web.mp4', $url);
    }

    public function test_falls_back_to_original_when_web_not_ready(): void
    {
        $asset = Mockery::mock(Asset::class)->makePartial();
        $asset->shouldReceive('getAttribute')->with('metadata')->andReturn([]);
        $asset->shouldReceive('deliveryUrl')
            ->with(AssetVariant::ORIGINAL, DeliveryContext::AUTHENTICATED->value)
            ->andReturn('https://cdn.example.com/orig.avi');
        $asset->shouldNotReceive('deliveryUrl')->with(AssetVariant::VIDEO_WEB, Mockery::any());
        $asset->shouldNotReceive('deliveryUrl')->with(AssetVariant::VIDEO_PREVIEW, Mockery::any());

        $url = AssetVideoStreamUrlPreference::resolvePlaybackUrl($asset, DeliveryContext::AUTHENTICATED->value);
        $this->assertSame('https://cdn.example.com/orig.avi', $url);
    }

    public function test_falls_back_to_preview_when_original_empty(): void
    {
        $asset = Mockery::mock(Asset::class)->makePartial();
        $asset->shouldReceive('getAttribute')->with('metadata')->andReturn([]);
        $asset->shouldReceive('deliveryUrl')->with(AssetVariant::ORIGINAL, Mockery::any())->andReturn('');
        $asset->shouldReceive('deliveryUrl')
            ->with(AssetVariant::VIDEO_PREVIEW, DeliveryContext::AUTHENTICATED->value)
            ->andReturn('https://cdn.example.com/prev.mp4');

        $url = AssetVideoStreamUrlPreference::resolvePlaybackUrl($asset, DeliveryContext::AUTHENTICATED->value);
        $this->assertSame('https://cdn.example.com/prev.mp4', $url);
    }
}
