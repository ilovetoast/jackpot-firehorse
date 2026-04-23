<?php

namespace App\Console\Commands;

use App\Models\StudioCompositionVideoExportJob;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Studio\StudioCanvasRuntimeExportJobClassifier;
use App\Services\Studio\StudioCompositionCanvasRuntimeVideoExportService;
use App\Services\Studio\StudioCompositionVideoExportRenderMode;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class ReconcileStudioCanvasRuntimeVideoExportsCommand extends Command
{
    protected $signature = 'studio:reconcile-canvas-runtime-video-exports
                            {--execute : Run merge-only repairs (default: dry-run summary only)}
                            {--id= : Single export job row id}
                            {--tenant-id= : Filter by tenant_id}
                            {--brand-id= : Filter by brand_id}
                            {--since= : created_at on or after this date (Y-m-d)}
                            {--until= : created_at on or before this date (Y-m-d)}
                            {--full-scan : Include all canvas_runtime rows (default: only rows with ffmpeg_merge_pending=true)}';

    protected $description = 'Find canvas_runtime export jobs in inconsistent states (e.g. complete with merge pending) and optionally repair merge-only (dry-run by default)';

    public function handle(StudioCompositionCanvasRuntimeVideoExportService $canvasRuntimeExport): int
    {
        $execute = (bool) $this->option('execute');
        if (! $execute) {
            $this->warn('Dry run (no DB changes). Pass --execute to apply merge-only repairs.');
        }

        $q = $this->baseQuery();
        $rows = $q->orderBy('id')->get();

        $counts = [];
        $repaired = 0;
        $failedRepair = 0;

        foreach ($rows as $row) {
            $c = StudioCanvasRuntimeExportJobClassifier::classify($row);
            $counts[$c] = ($counts[$c] ?? 0) + 1;

            $line = sprintf(
                'job=%d tenant=%s status=%s classification=%s merge_pending=%s output_asset=%s',
                $row->id,
                (string) $row->tenant_id,
                $row->status,
                $c,
                json_encode((bool) data_get($row->meta_json, 'canvas_runtime_capture.ffmpeg_merge_pending')),
                $row->output_asset_id !== null ? (string) $row->output_asset_id : 'null'
            );
            $this->line($line);

            $repairable = [
                StudioCanvasRuntimeExportJobClassifier::REPAIRABLE_STUCK_COMPLETE_MERGE_PENDING,
                StudioCanvasRuntimeExportJobClassifier::REPAIRABLE_PROCESSING_MERGE_PENDING,
            ];

            if ($execute && in_array($c, $repairable, true)) {
                $tenant = Tenant::query()->find($row->tenant_id);
                $user = $row->user_id !== null ? User::query()->find($row->user_id) : null;
                if (! $tenant instanceof Tenant || ! $user instanceof User) {
                    $this->error("  skip repair job={$row->id}: missing tenant or user");
                    $failedRepair++;

                    continue;
                }
                $result = $canvasRuntimeExport->repairMergePublish($row, $tenant, $user);
                if ($result['ok'] ?? false) {
                    $this->info('  repaired: ok');
                    $repaired++;
                } else {
                    $this->error('  repair failed: '.($result['message'] ?? 'unknown'));
                    $failedRepair++;
                }
            }
        }

        $this->newLine();
        $this->info('Classification counts:');
        foreach ($counts as $k => $n) {
            $this->line("  {$k}: {$n}");
        }
        if ($execute) {
            $this->info("Repairs attempted: {$repaired}, failed: {$failedRepair}");
        }

        return self::SUCCESS;
    }

    /**
     * @return Builder<StudioCompositionVideoExportJob>
     */
    private function baseQuery(): Builder
    {
        $q = StudioCompositionVideoExportJob::query()
            ->where('render_mode', StudioCompositionVideoExportRenderMode::CANVAS_RUNTIME->value);

        if ($this->option('id') !== null && $this->option('id') !== '') {
            $q->where('id', (int) $this->option('id'));
        } elseif (! (bool) $this->option('full-scan')) {
            $q->where('meta_json->canvas_runtime_capture->ffmpeg_merge_pending', true);
        }
        if ($this->option('tenant-id') !== null && $this->option('tenant-id') !== '') {
            $q->where('tenant_id', (int) $this->option('tenant-id'));
        }
        if ($this->option('brand-id') !== null && $this->option('brand-id') !== '') {
            $q->where('brand_id', (int) $this->option('brand-id'));
        }
        $since = $this->option('since');
        if (is_string($since) && $since !== '') {
            $q->whereDate('created_at', '>=', $since);
        }
        $until = $this->option('until');
        if (is_string($until) && $until !== '') {
            $q->whereDate('created_at', '<=', $until);
        }

        return $q;
    }
}
