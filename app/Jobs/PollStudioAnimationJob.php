<?php

namespace App\Jobs;

use App\Support\StudioAnimationQueue;
use App\Models\StudioAnimationJob;
use App\Studio\Animation\Enums\StudioAnimationStatus;
use App\Studio\Animation\Providers\Kling\KlingAnimationProvider;
use App\Studio\Animation\Services\StudioAnimationProviderStatusService;
use App\Studio\Animation\Support\StudioAnimationFailureClassifier;
use App\Studio\Animation\Support\StudioAnimationObservability;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PollStudioAnimationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 120;

    public function __construct(
        public readonly int $studioAnimationJobId,
    ) {
        $this->onQueue(StudioAnimationQueue::name());
    }

    public function handle(KlingAnimationProvider $kling, StudioAnimationProviderStatusService $statusService): void
    {
        $lock = Cache::lock('studio_animation_poll:'.$this->studioAnimationJobId, 60);
        if (! $lock->get()) {
            $wait = app()->environment('testing') ? 1 : 10;
            self::dispatch($this->studioAnimationJobId)->delay(now()->addSeconds($wait));

            return;
        }

        try {
            $job = StudioAnimationJob::query()->find($this->studioAnimationJobId);
            if (! $job || $job->provider_job_id === null || $job->provider_job_id === '') {
                return;
            }

            if (in_array($job->status, [
                StudioAnimationStatus::Complete->value,
                StudioAnimationStatus::Failed->value,
                StudioAnimationStatus::Canceled->value,
                StudioAnimationStatus::Finalizing->value,
                StudioAnimationStatus::Downloading->value,
            ], true)) {
                return;
            }

            $provider = match ($job->provider) {
                'kling' => $kling,
                default => throw new \RuntimeException('Unknown provider'),
            };

            $result = $provider->poll((string) $job->provider_job_id);

            $traceEntry = [
                'kind' => 'poll',
                'normalized_provider_status' => $result->normalizedProviderStatus,
                'provider_phase_debug' => $result->providerPhaseDebug,
                'raw_response_excerpt' => self::excerptDebug($result->rawResponseDebug),
            ];
            $merged = $statusService->mergeProviderTelemetry($job, $traceEntry, $result->rawResponseDebug);

            $job->update([
                'provider_response_json' => $merged,
            ]);

            if ($result->isInFlight()) {
                if ($this->attempts() >= $this->tries) {
                    $job->update([
                        'status' => StudioAnimationStatus::Failed->value,
                        'error_code' => 'provider_timeout',
                        'error_message' => 'Timed out waiting for the provider.',
                        'completed_at' => now(),
                    ]);
                    StudioAnimationObservability::log('poll_provider_timeout', $job->fresh());

                    return;
                }
                $d = app()->environment('testing') ? 0 : 15;
                self::dispatch($this->studioAnimationJobId)->delay(now()->addSeconds($d));

                return;
            }

            if ($result->isTerminalFailure()) {
                $job->update([
                    'status' => StudioAnimationStatus::Failed->value,
                    'error_code' => StudioAnimationFailureClassifier::mapProviderPollFailureCode($result->errorCode),
                    'error_message' => $result->errorMessage,
                    'completed_at' => now(),
                ]);
                StudioAnimationObservability::log('poll_terminal_failure', $job->fresh());

                return;
            }

            if ($result->isTerminalSuccess() && $result->remoteVideoUrl) {
                $settings = $job->settings_json ?? [];
                $settings['pending_finalize_remote_video_url'] = $result->remoteVideoUrl;
                $job->update([
                    'settings_json' => $settings,
                    'status' => StudioAnimationStatus::Downloading->value,
                ]);
                FinalizeStudioAnimationJob::dispatch($this->studioAnimationJobId, $result->remoteVideoUrl)->onQueue(StudioAnimationQueue::name());
                StudioAnimationObservability::log('poll_finalize_scheduled', $job->fresh());

                return;
            }

            Log::warning('[PollStudioAnimationJob] unexpected poll outcome', ['job_id' => $job->id]);
            $d = app()->environment('testing') ? 0 : 20;
            self::dispatch($this->studioAnimationJobId)->delay(now()->addSeconds($d));
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>|null
     */
    private static function excerptDebug(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (! is_string($json)) {
            return ['note' => 'unserializable'];
        }
        $max = (int) config('studio_animation.provider_debug_max_json_bytes', 12000);
        if (strlen($json) > $max) {
            return ['truncated' => true, 'head' => substr($json, 0, $max)];
        }

        return $payload;
    }
}
