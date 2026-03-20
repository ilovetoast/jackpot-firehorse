<?php

namespace Tests\Unit\Services\BrandDNA;

use App\Models\Asset;
use App\Models\Brand;
use App\Services\BrandDNA\BrandComplianceService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BrandComplianceServiceTest extends TestCase
{
    #[Test]
    public function score_asset_returns_null(): void
    {
        $svc = new BrandComplianceService;

        $this->assertNull($svc->scoreAsset(
            $this->createMock(Asset::class),
            $this->createMock(Brand::class)
        ));
    }
}
