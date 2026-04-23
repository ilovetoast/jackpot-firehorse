<?php

namespace Tests\Unit;

use App\Models\StudioCompositionVideoExportJob;
use App\Support\StudioCanvasRuntimeExportJobDiagnostics;
use PHPUnit\Framework\Assert;
use Tests\TestCase;

class StudioCanvasRuntimeExportJobDiagnosticsTest extends TestCase
{
    public function test_human_phase_inconsistent_complete_merge_pending(): void
    {
        $row = new StudioCompositionVideoExportJob([
            'render_mode' => 'canvas_runtime',
            'status' => StudioCompositionVideoExportJob::STATUS_COMPLETE,
            'output_asset_id' => null,
            'meta_json' => [
                'canvas_runtime_capture' => [
                    'ffmpeg_merge_pending' => true,
                    'phase' => 'frames_captured',
                ],
            ],
        ]);

        Assert::assertSame(
            'inconsistent_complete_merge_pending',
            StudioCanvasRuntimeExportJobDiagnostics::humanExportPhase($row),
        );
    }

    public function test_debug_block_keys(): void
    {
        $row = new StudioCompositionVideoExportJob([
            'render_mode' => 'canvas_runtime',
            'status' => StudioCompositionVideoExportJob::STATUS_PROCESSING,
            'meta_json' => [
                'canvas_runtime_capture' => ['ffmpeg_merge_pending' => true, 'phase' => 'frames_captured'],
                'canvas_runtime_diagnostics' => ['x' => 1],
                'canvas_runtime_merge_diagnostics' => ['y' => 1],
            ],
        ]);
        $b = StudioCanvasRuntimeExportJobDiagnostics::canvasRuntimeDebugBlock($row);
        $this->assertTrue($b['ffmpeg_merge_pending']);
        $this->assertTrue($b['has_canvas_runtime_diagnostics']);
        $this->assertTrue($b['has_canvas_runtime_merge_diagnostics']);
    }
}
