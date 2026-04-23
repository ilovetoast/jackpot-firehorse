<?php

namespace Tests\Unit;

use App\Models\StudioCompositionVideoExportJob;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Studio\StudioCompositionCanvasRuntimeVideoExportService;
use App\Services\Studio\StudioCompositionVideoExportOrchestrator;
use App\Services\Studio\StudioCompositionVideoExportRenderMode;
use App\Services\Studio\StudioCompositionVideoExportService;
use Mockery;
use Tests\TestCase;

class StudioCompositionVideoExportOrchestratorTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_legacy_bitmap_routes_to_legacy_service_only(): void
    {
        $legacy = Mockery::mock(StudioCompositionVideoExportService::class);
        $legacy->shouldReceive('run')->once();
        $canvas = Mockery::mock(StudioCompositionCanvasRuntimeVideoExportService::class);
        $canvas->shouldReceive('run')->never();

        $orchestrator = new StudioCompositionVideoExportOrchestrator($legacy, $canvas);

        $row = new StudioCompositionVideoExportJob([
            'render_mode' => StudioCompositionVideoExportRenderMode::LEGACY_BITMAP->value,
        ]);
        $tenant = new Tenant(['id' => 1]);
        $user = new User(['id' => 1]);

        $orchestrator->run($row, $tenant, $user);
    }

    public function test_canvas_runtime_routes_to_canvas_service_only(): void
    {
        $legacy = Mockery::mock(StudioCompositionVideoExportService::class);
        $legacy->shouldReceive('run')->never();
        $canvas = Mockery::mock(StudioCompositionCanvasRuntimeVideoExportService::class);
        $canvas->shouldReceive('run')->once();

        $orchestrator = new StudioCompositionVideoExportOrchestrator($legacy, $canvas);

        $row = new StudioCompositionVideoExportJob([
            'render_mode' => StudioCompositionVideoExportRenderMode::CANVAS_RUNTIME->value,
        ]);
        $tenant = new Tenant(['id' => 1]);
        $user = new User(['id' => 1]);

        $orchestrator->run($row, $tenant, $user);
    }
}
