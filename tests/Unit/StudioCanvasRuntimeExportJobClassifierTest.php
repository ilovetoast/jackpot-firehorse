<?php

namespace Tests\Unit;

use App\Models\StudioCompositionVideoExportJob;
use App\Services\Studio\StudioCanvasRuntimeExportJobClassifier;
use App\Services\Studio\StudioCompositionVideoExportRenderMode;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Assert;
use Tests\TestCase;

class StudioCanvasRuntimeExportJobClassifierTest extends TestCase
{
    public function test_skipped_not_canvas_runtime(): void
    {
        $row = new StudioCompositionVideoExportJob([
            'render_mode' => StudioCompositionVideoExportRenderMode::LEGACY_BITMAP->value,
            'status' => StudioCompositionVideoExportJob::STATUS_COMPLETE,
            'meta_json' => ['canvas_runtime_capture' => ['ffmpeg_merge_pending' => true]],
        ]);

        Assert::assertSame(
            StudioCanvasRuntimeExportJobClassifier::SKIPPED_NOT_CANVAS_RUNTIME,
            StudioCanvasRuntimeExportJobClassifier::classify($row),
        );
    }

    public function test_skipped_no_merge_pending(): void
    {
        $row = new StudioCompositionVideoExportJob([
            'render_mode' => StudioCompositionVideoExportRenderMode::CANVAS_RUNTIME->value,
            'status' => StudioCompositionVideoExportJob::STATUS_COMPLETE,
            'output_asset_id' => null,
            'meta_json' => [
                'canvas_runtime_capture' => [
                    'ffmpeg_merge_pending' => false,
                    'working_directory' => '/tmp/x',
                    'manifest_path' => '/tmp/x/m.json',
                ],
            ],
        ]);

        Assert::assertSame(
            StudioCanvasRuntimeExportJobClassifier::SKIPPED_NO_MERGE_PENDING,
            StudioCanvasRuntimeExportJobClassifier::classify($row),
        );
    }

    public function test_ambiguous_complete_with_output(): void
    {
        $row = new StudioCompositionVideoExportJob([
            'render_mode' => StudioCompositionVideoExportRenderMode::CANVAS_RUNTIME->value,
            'status' => StudioCompositionVideoExportJob::STATUS_COMPLETE,
            'output_asset_id' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
            'meta_json' => [
                'canvas_runtime_capture' => [
                    'ffmpeg_merge_pending' => true,
                    'working_directory' => '/tmp/x',
                    'manifest_path' => '/tmp/x/m.json',
                ],
            ],
        ]);

        Assert::assertSame(
            StudioCanvasRuntimeExportJobClassifier::AMBIGUOUS_COMPLETE_MERGE_PENDING_WITH_OUTPUT,
            StudioCanvasRuntimeExportJobClassifier::classify($row),
        );
    }

    public function test_repairable_stuck_complete_when_artifacts_present(): void
    {
        $dir = sys_get_temp_dir().'/jp-classifier-'.bin2hex(random_bytes(4));
        mkdir($dir, 0755, true);
        $manifestPath = $dir.DIRECTORY_SEPARATOR.'capture-manifest.json';
        file_put_contents($manifestPath, '{"total_captured_frames":1,"frame_filename_pattern":"frame_%06d.png"}');

        $row = new StudioCompositionVideoExportJob([
            'render_mode' => StudioCompositionVideoExportRenderMode::CANVAS_RUNTIME->value,
            'status' => StudioCompositionVideoExportJob::STATUS_COMPLETE,
            'output_asset_id' => null,
            'meta_json' => [
                'canvas_runtime_capture' => [
                    'ffmpeg_merge_pending' => true,
                    'working_directory' => $dir,
                    'manifest_path' => $manifestPath,
                ],
            ],
        ]);

        try {
            Assert::assertSame(
                StudioCanvasRuntimeExportJobClassifier::REPAIRABLE_STUCK_COMPLETE_MERGE_PENDING,
                StudioCanvasRuntimeExportJobClassifier::classify($row),
            );
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_validate_manifest_quick(): void
    {
        $dir = sys_get_temp_dir().'/jp-mq-'.bin2hex(random_bytes(4));
        mkdir($dir, 0755, true);
        $p = $dir.'/capture-manifest.json';
        file_put_contents($p, json_encode(['total_captured_frames' => 2, 'frame_filename_pattern' => 'frame_%06d.png']));
        $r = StudioCanvasRuntimeExportJobClassifier::validateManifestQuick($p);
        $this->assertTrue($r['ok']);
        @unlink($p);
        @rmdir($dir);
    }
}
