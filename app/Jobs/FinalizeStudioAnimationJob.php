<?php

namespace App\Jobs;

use App\Support\StudioAnimationQueue;
use App\Models\StudioAnimationJob;
use App\Studio\Animation\Enums\StudioAnimationStatus;
use App\Studio\Animation\Services\StudioAnimationCompletionService;
use App\Studio\Animation\Support\StudioAnimationObservability;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FinalizeStudioAnimationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly int $studioAnimationJobId,
        public readonly string $remoteVideoUrl,
    ) {
        $this->onQueue(StudioAnimationQueue::name());
    }

    public function handle(StudioAnimationCompletionService $completion): void
    {
        $job = StudioAnimationJob::query()->find($this->studioAnimationJobId);
        if (! $job) {
            return;
        }

        if ($job->status === StudioAnimationStatus::Canceled->value) {
            return;
        }

        $settings = $job->settings_json ?? [];
        $settings['pending_finalize_remote_video_url'] = $this->remoteVideoUrl;
        $job->update([
            'settings_json' => $settings,
            'status' => StudioAnimationStatus::Downloading->value,
        ]);

        try {
            $job->update(['status' => StudioAnimationStatus::Finalizing->value]);
            $completion->finalizeFromRemoteUrl($job->fresh(), $this->remoteVideoUrl);
        } catch (\Throwable $e) {
            Log::warning('[FinalizeStudioAnimationJob] failed', [
                'job_id' => $this->studioAnimationJobId,
                'error' => $e->getMessage(),
            ]);
            StudioAnimationObservability::log('finalize_job_failed', StudioAnimationJob::query()->find($this->studioAnimationJobId), [
                'exc' => $e::class,
                'error_brief' => mb_substr($e->getMessage(), 0, 160),
            ]);
            $fresh = $job->fresh();
            if (! $fresh) {
                throw $e;
            }
            $msg = $e->getMessage();
            $code = match (true) {
                str_starts_with($msg, 'DOWNLOAD_FAILED:') => 'download_failed',
                str_contains($msg, 'FINALIZE_INVALID_VIDEO') => 'invalid_video',
                str_contains($msg, 'FINALIZE_FAILED:') && (str_contains($msg, 'Storage') || str_contains($msg, 'Unable to write') || str_contains($msg, 'disk')) => 'finalize_failed_after_download',
                str_contains($msg, 'FINALIZE_FAILED:') => 'finalize_failed_before_download',
                default => 'finalize_failed',
            };
            $st = $fresh->settings_json ?? [];
            $st['pending_finalize_remote_video_url'] = $this->remoteVideoUrl;
            $fresh->update([
                'settings_json' => $st,
                'status' => StudioAnimationStatus::Failed->value,
                'error_code' => $code,
                'error_message' => $msg,
                'completed_at' => now(),
            ]);
            throw $e;
        }
    }
}
