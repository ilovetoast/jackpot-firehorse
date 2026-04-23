<?php

namespace App\Jobs;

use App\Models\StudioCompositionVideoExportJob;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Studio\StudioCompositionVideoExportOrchestrator;
use App\Services\Studio\StudioCompositionVideoExportRenderMode;
use App\Support\StudioCanvasExportQueue;
use App\Support\StudioVideoQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessStudioCompositionVideoExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Must stay ≤ Horizon {@see config('horizon.defaults.supervisor-video-heavy.tries')} so workers do not
     * throw {@see \Illuminate\Queue\MaxAttemptsExceededException} before the last attempt runs {@see handle()}.
     */
    public int $tries = 3;

    public int $timeout = 3600;

    public function __construct(
        public readonly int $exportJobRowId,
    ) {
        $row = StudioCompositionVideoExportJob::query()->find($exportJobRowId);
        $queue = StudioVideoQueue::heavy();
        if ($row && $row->render_mode === StudioCompositionVideoExportRenderMode::CANVAS_RUNTIME->value) {
            $queue = StudioCanvasExportQueue::heavy();
        }
        $this->onQueue($queue);
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(StudioCompositionVideoExportOrchestrator $orchestrator): void
    {
        $row = StudioCompositionVideoExportJob::query()->find($this->exportJobRowId);
        if (! $row) {
            return;
        }
        if ($row->status === StudioCompositionVideoExportJob::STATUS_COMPLETE) {
            return;
        }
        $user = $row->user_id !== null ? User::query()->find($row->user_id) : null;
        if (! $user instanceof User) {
            $row->update([
                'status' => StudioCompositionVideoExportJob::STATUS_FAILED,
                'error_json' => ['message' => 'User context missing.'],
            ]);

            return;
        }
        $tenant = Tenant::query()->find($row->tenant_id);
        if (! $tenant) {
            $row->update([
                'status' => StudioCompositionVideoExportJob::STATUS_FAILED,
                'error_json' => ['message' => 'Tenant missing.'],
            ]);

            return;
        }
        $orchestrator->run($row, $tenant, $user);
    }

    /**
     * When the queue gives up (timeouts, MaxAttemptsExceeded, worker crash), ensure the studio export row
     * is not left stuck in {@see StudioCompositionVideoExportJob::STATUS_PROCESSING}.
     */
    public function failed(?Throwable $exception): void
    {
        $row = StudioCompositionVideoExportJob::query()->find($this->exportJobRowId);
        if ($row === null) {
            return;
        }
        if ($row->status === StudioCompositionVideoExportJob::STATUS_COMPLETE) {
            return;
        }
        $msg = $exception !== null ? $exception->getMessage() : 'Video export job failed on the queue.';
        $row->update([
            'status' => StudioCompositionVideoExportJob::STATUS_FAILED,
            'error_json' => [
                'message' => $msg,
                'source' => 'queue_failed_handler',
            ],
        ]);
    }
}
