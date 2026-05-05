<?php

namespace Tests\Unit;

use App\Support\GatewayIntendedUrl;
use PHPUnit\Framework\TestCase;

class GatewayIntendedUrlTest extends TestCase
{
    public function test_discards_download_bucket_paths(): void
    {
        $this->assertTrue(GatewayIntendedUrl::shouldDiscardPath('/app/download-bucket/items'));
        $this->assertTrue(GatewayIntendedUrl::shouldDiscardPath('/app/download-bucket/add'));
    }

    public function test_discards_app_api_paths(): void
    {
        $this->assertTrue(GatewayIntendedUrl::shouldDiscardPath('/app/api/foo'));
    }

    public function test_keeps_normal_app_pages(): void
    {
        $this->assertFalse(GatewayIntendedUrl::shouldDiscardPath('/app/overview'));
        $this->assertFalse(GatewayIntendedUrl::shouldDiscardPath('/app/assets'));
    }
}
