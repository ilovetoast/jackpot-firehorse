<?php

namespace App\Jobs;

use App\Models\ProstaffUploadBatch;
use App\Services\ApprovalNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessProstaffUploadBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 10;

    public function __construct(
        public string $batchKey
    ) {}

    public function handle(ApprovalNotificationService $approvalNotificationService): void
    {
        $window = max(1, (int) config('prostaff.batch_window_minutes', 5));
        $maxDuration = max(1, (int) config('prostaff.max_batch_duration_minutes', 30));

        $result = DB::transaction(function () use ($window, $maxDuration) {
            $batch = ProstaffUploadBatch::query()
                ->where('batch_key', $this->batchKey)
                ->lockForUpdate()
                ->first();

            if ($batch === null) {
                return ['type' => 'noop'];
            }

            if ($batch->notifications_sent_at !== null) {
                return ['type' => 'noop'];
            }

            if ($batch->processed_at !== null) {
                $staleAfterMinutes = $maxDuration + $window;
                $claimStaleAt = $batch->processed_at->copy()->addMinutes($staleAfterMinutes);
                if (now()->lt($claimStaleAt)) {
                    return ['type' => 'noop'];
                }

                ProstaffUploadBatch::query()->whereKey($batch->getKey())->update(['processed_at' => null]);
                $batch->refresh();
            }

            $quietUntil = $batch->last_activity_at->copy()->addMinutes($window);
            $capUntil = $batch->started_at->copy()->addMinutes($maxDuration);
            $canProcess = now()->gte($quietUntil) || now()->gte($capUntil);

            if (! $canProcess) {
                $wakeAt = $quietUntil->lt($capUntil) ? $quietUntil : $capUntil;

                return ['type' => 'release', 'until' => $wakeAt];
            }

            $claimed = ProstaffUploadBatch::query()
                ->whereKey($batch->getKey())
                ->whereNull('notifications_sent_at')
                ->whereNull('processed_at')
                ->update(['processed_at' => now()]);

            if ($claimed === 0) {
                return ['type' => 'noop'];
            }

            return ['type' => 'notify', 'batch_id' => $batch->id];
        });

        if (($result['type'] ?? '') === 'release') {
            /** @var \Carbon\Carbon $until */
            $until = $result['until'];
            $seconds = max(1, (int) ($until->timestamp - now()->timestamp));
            $this->release($seconds);

            return;
        }

        if (($result['type'] ?? '') !== 'notify') {
            return;
        }

        $batch = ProstaffUploadBatch::query()->find($result['batch_id']);
        if ($batch === null) {
            return;
        }

        try {
            $approvalNotificationService->notifyProstaffUploadBatch($batch);
        } catch (\Throwable $e) {
            ProstaffUploadBatch::query()
                ->where('batch_key', $this->batchKey)
                ->update(['processed_at' => null]);

            Log::error('[ProcessProstaffUploadBatchJob] Notification failed; claim released for retry', [
                'batch_key' => $this->batchKey,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $finalized = ProstaffUploadBatch::query()
            ->where('batch_key', $this->batchKey)
            ->whereNull('notifications_sent_at')
            ->update([
                'notifications_sent_at' => now(),
                'processed_at' => now(),
            ]);

        if ($finalized === 0) {
            Log::warning('[ProcessProstaffUploadBatchJob] Notify succeeded but row already had notifications_sent_at (idempotent skip)', [
                'batch_key' => $this->batchKey,
            ]);
        }

        Log::info('[ProcessProstaffUploadBatchJob] Batch processed', [
            'batch_key' => $this->batchKey,
            'upload_count' => $batch->upload_count,
        ]);
    }
}
