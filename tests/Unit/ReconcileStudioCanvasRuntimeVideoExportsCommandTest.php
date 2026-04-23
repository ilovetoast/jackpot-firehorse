<?php

namespace Tests\Unit;

use App\Models\Brand;
use App\Models\Composition;
use App\Models\StudioCompositionVideoExportJob;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Studio\StudioCompositionCanvasRuntimeVideoExportService;
use App\Services\Studio\StudioCompositionVideoExportRenderMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class ReconcileStudioCanvasRuntimeVideoExportsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_dry_run_lists_ambiguous_missing_artifacts(): void
    {
        $seed = $this->seedMinimalExportJob(false, true);
        $job = $seed['job'];

        $exit = Artisan::call('studio:reconcile-canvas-runtime-video-exports', [
            '--id' => (string) $job->id,
        ]);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString(
            'ambiguous_complete_merge_pending_missing_artifacts',
            Artisan::output(),
        );
    }

    public function test_execute_calls_repair_for_repairable_row(): void
    {
        $seed = $this->seedMinimalExportJob(true, true);
        $job = $seed['job'];

        $mock = Mockery::mock(StudioCompositionCanvasRuntimeVideoExportService::class);
        $mock->shouldReceive('repairMergePublish')
            ->once()
            ->with(
                Mockery::on(fn (StudioCompositionVideoExportJob $j): bool => (int) $j->id === (int) $job->id),
                Mockery::type(Tenant::class),
                Mockery::type(User::class),
            )
            ->andReturn(['ok' => true, 'classification' => 'repairable_stuck_complete_merge_pending', 'message' => 'ok']);
        $this->app->instance(StudioCompositionCanvasRuntimeVideoExportService::class, $mock);

        Artisan::call('studio:reconcile-canvas-runtime-video-exports', [
            '--execute' => true,
            '--id' => (string) $job->id,
        ]);

        $workDir = (string) data_get($job->fresh()->meta_json, 'canvas_runtime_capture.working_directory');
        if ($workDir !== '' && is_dir($workDir)) {
            File::deleteDirectory($workDir);
        }
    }

    /**
     * @return array{tenant: Tenant, brand: Brand, user: User, composition: Composition, job: StudioCompositionVideoExportJob}
     */
    private function seedMinimalExportJob(bool $usableArtifacts, bool $repairableComplete): array
    {
        $tenant = Tenant::create([
            'name' => 'T',
            'slug' => 't-'.Str::random(6),
            'uuid' => (string) Str::uuid(),
        ]);
        $brand = Brand::create([
            'tenant_id' => $tenant->id,
            'name' => 'B',
            'slug' => 'b-'.Str::random(6),
        ]);
        $user = User::factory()->create();
        $composition = Composition::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'user_id' => $user->id,
            'visibility' => Composition::VISIBILITY_SHARED,
            'name' => 'C',
            'document_json' => ['width' => 64, 'height' => 64, 'layers' => []],
        ]);

        $workDir = storage_path('app/tmp/jp-reconcile-'.Str::random(8));
        $manifestPath = $workDir.DIRECTORY_SEPARATOR.'capture-manifest.json';
        if ($usableArtifacts) {
            File::ensureDirectoryExists($workDir);
            file_put_contents($manifestPath, json_encode([
                'total_captured_frames' => 1,
                'frame_filename_pattern' => 'frame_%06d.png',
            ]));
        } else {
            $workDir = '/no/such/dir';
            $manifestPath = '/no/such/dir/capture-manifest.json';
        }

        $status = $repairableComplete
            ? StudioCompositionVideoExportJob::STATUS_COMPLETE
            : StudioCompositionVideoExportJob::STATUS_FAILED;

        $job = StudioCompositionVideoExportJob::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'user_id' => $user->id,
            'composition_id' => $composition->id,
            'render_mode' => StudioCompositionVideoExportRenderMode::CANVAS_RUNTIME->value,
            'status' => $status,
            'output_asset_id' => null,
            'meta_json' => [
                'canvas_runtime_capture' => [
                    'ffmpeg_merge_pending' => true,
                    'working_directory' => $workDir,
                    'manifest_path' => $manifestPath,
                    'phase' => 'frames_captured',
                ],
            ],
        ]);

        return compact('tenant', 'brand', 'user', 'composition', 'job');
    }
}
