<?php

namespace Tests\Unit\Services\Admin;

use App\Services\Admin\StudioCompositionVideoExportAdminMetrics;
use Tests\TestCase;

class StudioCompositionVideoExportAdminMetricsTest extends TestCase
{
    public function test_operations_center_payload_has_expected_shape(): void
    {
        $p = StudioCompositionVideoExportAdminMetrics::operationsCenterPayload();

        $this->assertIsArray($p);
        $this->assertArrayHasKey('last_24h', $p);
        $this->assertArrayHasKey('last_7d', $p);
        $this->assertArrayHasKey('by_code', $p);
        $this->assertArrayHasKey('rows', $p);
        $this->assertIsInt($p['last_24h']);
        $this->assertIsInt($p['last_7d']);
        $this->assertIsArray($p['by_code']);
        $this->assertIsArray($p['rows']);
    }

    public function test_failure_count_last_24_hours_returns_int(): void
    {
        $n = StudioCompositionVideoExportAdminMetrics::failureCountLast24Hours();
        $this->assertIsInt($n);
        $this->assertGreaterThanOrEqual(0, $n);
    }
}
