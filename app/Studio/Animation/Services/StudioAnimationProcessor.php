<?php

namespace App\Studio\Animation\Services;

use App\Jobs\PollStudioAnimationJob;
use App\Models\StudioAnimationJob;
use App\Models\StudioAnimationRender;
use App\Studio\Animation\Contracts\AnimationProviderInterface;
use App\Studio\Animation\Data\ProviderAnimationRequestData;
use App\Studio\Animation\Enums\StudioAnimationRenderRole;
use App\Studio\Animation\Enums\StudioAnimationStatus;
use App\Studio\Animation\Providers\Kling\KlingAnimationProvider;
use App\Studio\Animation\Rendering\CompositionSnapshotRenderer;
use App\Studio\Animation\Support\StudioAnimationDriftBlockedException;
use App\Studio\Animation\Support\StudioAnimationFailureClassifier;
use App\Studio\Animation\Support\StudioAnimationObservability;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

final class StudioAnimationProcessor
{
    public function __construct(
        protected CompositionSnapshotRenderer $snapshotRenderer,
        protected StudioAnimationProviderStatusService $statusService,
        protected StudioAnimationDriftGateService $driftGate,
    ) {}

    public function process(int $jobId): void
    {
        $lock = Cache::lock('studio_animation_job:'.$jobId, 120);
        if (! $lock->get()) {
            return;
        }

        try {
            $job = StudioAnimationJob::query()->find($jobId);
            if (! $job) {
                return;
            }

            if (in_array($job->status, [
                StudioAnimationStatus::Complete->value,
                StudioAnimationStatus::Failed->value,
                StudioAnimationStatus::Canceled->value,
                StudioAnimationStatus::Downloading->value,
                StudioAnimationStatus::Finalizing->value,
            ], true)) {
                return;
            }

            $job->update(['status' => StudioAnimationStatus::Rendering->value]);

            $renderData = $this->snapshotRenderer->renderStartFrame($job);

            StudioAnimationRender::query()->create([
                'studio_animation_job_id' => $job->id,
                'render_role' => StudioAnimationRenderRole::StartFrame->value,
                'asset_id' => $renderData->assetId,
                'disk' => $renderData->disk,
                'path' => $renderData->path,
                'mime_type' => $renderData->mimeType,
                'width' => $renderData->width,
                'height' => $renderData->height,
                'sha256' => $renderData->sha256,
                'metadata_json' => $renderData->metadata,
            ]);

            $patch = $renderData->jobSettingsPatch ?? [];
            $mergedSettings = array_merge($job->settings_json ?? [], $patch);
            $canonicalFrame = is_array($mergedSettings['canonical_frame'] ?? null) ? $mergedSettings['canonical_frame'] : null;
            $driftDecision = $this->driftGate->evaluate($canonicalFrame, $mergedSettings);
            $mergedSettings['drift_decision'] = $driftDecision;
            if ($this->driftGate->shouldAbortSubmission($driftDecision)) {
                throw new StudioAnimationDriftBlockedException($driftDecision);
            }
            $job->update(['settings_json' => $mergedSettings]);
            $job->refresh();

            StudioAnimationObservability::log('processor_submit_ready', $job);

            $requestId = null;
            $job->update(['status' => StudioAnimationStatus::Submitting->value]);

            $provider = $this->resolveProvider((string) $job->provider);
            $request = new ProviderAnimationRequestData(
                providerKey: (string) $job->provider,
                providerModelKey: (string) $job->provider_model,
                startImageDisk: $renderData->disk,
                startImageStoragePath: $renderData->path,
                startImageMimeType: $renderData->mimeType,
                prompt: $job->prompt,
                negativePrompt: $job->negative_prompt,
                durationSeconds: (int) $job->duration_seconds,
                aspectRatio: (string) $job->aspect_ratio,
                generateAudio: (bool) $job->generate_audio,
                motionPresetKey: $job->motion_preset,
                settings: is_array($job->settings_json) ? $job->settings_json : [],
            );

            $result = $provider->submitImageToVideo($request);

            $submitTrace = [
                'kind' => 'provider_submit',
                'normalized_provider_status' => $result->normalizedProviderStatus,
                'provider_phase_debug' => $result->providerPhaseDebug,
            ];
            $mergedResponse = $this->statusService->mergeProviderTelemetry($job, $submitTrace, $result->rawResponseDebug);

            $decoded = json_decode((string) ($result->providerJobId ?? ''), true);
            if (is_array($decoded) && isset($decoded['request_id'])) {
                $requestId = (string) $decoded['request_id'];
            }

            $job->update([
                'provider_request_json' => $result->rawRequestDebug,
                'provider_response_json' => $mergedResponse,
                'provider_job_id' => $result->providerJobId,
                'provider_queue_request_id' => $requestId,
            ]);

            if ($result->isTerminalFailure()) {
                $job->update([
                    'status' => StudioAnimationStatus::Failed->value,
                    'error_code' => StudioAnimationFailureClassifier::mapProviderSubmitErrorCode($result->errorCode),
                    'error_message' => $result->errorMessage,
                    'completed_at' => now(),
                ]);

                return;
            }

            $job->update(['status' => StudioAnimationStatus::Processing->value]);

            $pollDelay = app()->environment('testing') ? 0 : 12;
            PollStudioAnimationJob::dispatch($job->id)->delay(now()->addSeconds($pollDelay))->onQueue(config('queue.ai_queue', 'ai'));
            StudioAnimationObservability::log('processor_poll_scheduled', $job->fresh());
        } catch (StudioAnimationDriftBlockedException $e) {
            Log::warning('[StudioAnimationProcessor] drift_blocked', [
                'job_id' => $jobId,
                'decision' => $e->decision,
            ]);
            StudioAnimationObservability::log('processor_drift_blocked', StudioAnimationJob::query()->find($jobId), [
                'drift_decision' => StudioAnimationObservability::compactDriftDecision($e->decision),
            ]);
            $fresh = StudioAnimationJob::query()->find($jobId);
            if ($fresh) {
                $st = array_merge($fresh->settings_json ?? [], ['drift_decision' => $e->decision]);
                $fresh->update([
                    'status' => StudioAnimationStatus::Failed->value,
                    'error_code' => 'drift_blocked',
                    'error_message' => $e->getMessage(),
                    'completed_at' => now(),
                    'settings_json' => $st,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('[StudioAnimationProcessor] failed', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);
            StudioAnimationObservability::log('processor_failed', StudioAnimationJob::query()->find($jobId), [
                'exc' => $e::class,
                'error_brief' => mb_substr($e->getMessage(), 0, 160),
            ]);
            $fresh = StudioAnimationJob::query()->find($jobId);
            if ($fresh) {
                $fresh->update([
                    'status' => StudioAnimationStatus::Failed->value,
                    'error_code' => StudioAnimationFailureClassifier::codeForProcessorThrowable($e),
                    'error_message' => $e->getMessage(),
                    'completed_at' => now(),
                ]);
            }
        } finally {
            $lock->release();
        }
    }

    private function resolveProvider(string $key): AnimationProviderInterface
    {
        return match ($key) {
            'kling' => app(KlingAnimationProvider::class),
            default => throw new \InvalidArgumentException("Unsupported animation provider: {$key}"),
        };
    }
}
