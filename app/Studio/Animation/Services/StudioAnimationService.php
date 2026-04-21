<?php

namespace App\Studio\Animation\Services;

use App\Exceptions\PlanLimitExceededException;
use App\Jobs\FinalizeStudioAnimationJob;
use App\Jobs\PollStudioAnimationJob;
use App\Jobs\ProcessStudioAnimationJob;
use App\Models\Asset;
use App\Models\Composition;
use App\Models\StudioAnimationJob;
use App\Models\StudioAnimationOutput;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AiUsageService;
use App\Studio\Animation\Analysis\CompositionAnimationPreflightAnalyzer;
use App\Studio\Animation\Data\CreateStudioAnimationData;
use App\Studio\Animation\Enums\StudioAnimationSourceStrategy;
use App\Studio\Animation\Enums\StudioAnimationStatus;
use App\Studio\Animation\Support\AnimationAspectRatioMapper;
use App\Studio\Animation\Support\AnimationIntentBuilder;
use App\Studio\Animation\Support\AnimationSourceLock;
use App\Studio\Animation\Support\MotionPresetCatalog;
use App\Studio\Animation\Support\StudioAnimationObservability;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StudioAnimationService
{
    public function __construct(
        protected AiUsageService $aiUsageService,
    ) {}

    public function create(CreateStudioAnimationData $data, Tenant $tenant, User $user): StudioAnimationJob
    {
        if (! (bool) config('studio_animation.enabled', true)) {
            throw ValidationException::withMessages(['provider' => 'Studio animation is disabled.']);
        }

        $providers = config('studio_animation.providers', []);
        if (! is_array($providers[$data->provider] ?? null)) {
            throw ValidationException::withMessages(['provider' => 'Unknown provider.']);
        }

        $models = $providers[$data->provider]['models'] ?? [];
        if (! is_array($models[$data->providerModel] ?? null)) {
            throw ValidationException::withMessages(['provider_model' => 'Unknown provider model.']);
        }

        $allowedDur = $providers[$data->provider]['duration_allowed'] ?? [];
        if (! is_array($allowedDur) || ! in_array($data->durationSeconds, $allowedDur, true)) {
            throw ValidationException::withMessages(['duration_seconds' => 'Duration is not supported for this provider.']);
        }

        if (! AnimationAspectRatioMapper::isSupported($data->aspectRatio)) {
            throw ValidationException::withMessages(['aspect_ratio' => 'Unsupported aspect ratio.']);
        }

        if ($data->sourceStrategy !== StudioAnimationSourceStrategy::CompositionSnapshot) {
            throw ValidationException::withMessages(['source_strategy' => 'Only composition_snapshot is available.']);
        }

        $motion = $data->motionPreset ?? MotionPresetCatalog::defaultKey();
        if (! MotionPresetCatalog::isValid($motion)) {
            throw ValidationException::withMessages(['motion_preset' => 'Invalid motion preset.']);
        }

        $maxPrompt = (int) config('studio_animation.prompt_max_length', 4000);
        if ($data->prompt !== null && mb_strlen($data->prompt) > $maxPrompt) {
            throw ValidationException::withMessages(['prompt' => "Prompt must be at most {$maxPrompt} characters."]);
        }
        $maxNeg = (int) config('studio_animation.negative_prompt_max_length', 2000);
        if ($data->negativePrompt !== null && mb_strlen($data->negativePrompt) > $maxNeg) {
            throw ValidationException::withMessages(['negative_prompt' => "Negative prompt must be at most {$maxNeg} characters."]);
        }

        if ($data->compositionId !== null) {
            $exists = Composition::query()
                ->where('id', $data->compositionId)
                ->where('tenant_id', $tenant->id)
                ->where('brand_id', $data->brandId)
                ->visibleToUser($user)
                ->exists();
            if (! $exists) {
                throw ValidationException::withMessages(['composition_id' => 'Composition not found in this workspace.']);
            }
        }

        $credits = $this->aiUsageService->getStudioAnimationCreditCost($data->durationSeconds);
        try {
            $this->aiUsageService->checkUsage($tenant, 'studio_animation', $credits);
        } catch (PlanLimitExceededException $e) {
            throw ValidationException::withMessages([
                'credits' => $e->getMessage(),
            ]);
        }

        $lockPayload = [
            'source_composition_version_id' => null,
            'source_document_revision_hash' => null,
            'settings_fragment' => [],
        ];
        if ($data->compositionId !== null) {
            try {
                $lockPayload = AnimationSourceLock::resolveForSubmission(
                    (int) $data->compositionId,
                    $data->sourceCompositionVersionId,
                    $data->documentJson,
                );
            } catch (\InvalidArgumentException $e) {
                throw ValidationException::withMessages(['document_json' => $e->getMessage()]);
            }
        }

        $preflight = (new CompositionAnimationPreflightAnalyzer)->analyze(
            $data->documentJson,
            $data->snapshotWidth,
            $data->snapshotHeight,
        );

        $intent = AnimationIntentBuilder::build(
            $data->sourceStrategy,
            $motion,
            $data->durationSeconds,
            $data->aspectRatio,
            $data->generateAudio,
            $data->provider,
            $data->providerModel,
        );

        $settings = array_merge($data->settings, $lockPayload['settings_fragment'], [
            'composition_snapshot_png_base64' => $data->compositionSnapshotPngBase64,
            'snapshot_width' => $data->snapshotWidth,
            'snapshot_height' => $data->snapshotHeight,
            'credit_cost_reserved' => $credits,
            'preflight_risk' => $preflight,
            'high_fidelity_submit' => $data->highFidelitySubmit,
        ]);

        $job = DB::transaction(function () use ($data, $tenant, $user, $settings, $lockPayload, $intent, $motion): StudioAnimationJob {
            return StudioAnimationJob::query()->create([
                'tenant_id' => $tenant->id,
                'brand_id' => $data->brandId,
                'user_id' => $user->id,
                'studio_document_id' => null,
                'composition_id' => $data->compositionId,
                'source_composition_version_id' => $lockPayload['source_composition_version_id'],
                'source_document_revision_hash' => $lockPayload['source_document_revision_hash'],
                'provider' => $data->provider,
                'provider_model' => $data->providerModel,
                'status' => StudioAnimationStatus::Queued->value,
                'source_strategy' => $data->sourceStrategy->value,
                'prompt' => $data->prompt,
                'negative_prompt' => $data->negativePrompt,
                'motion_preset' => $motion,
                'duration_seconds' => $data->durationSeconds,
                'aspect_ratio' => $data->aspectRatio,
                'generate_audio' => $data->generateAudio,
                'animation_intent_json' => $intent,
                'settings_json' => $settings,
                'provider_request_json' => null,
                'provider_response_json' => null,
                'provider_job_id' => null,
                'error_code' => null,
                'error_message' => null,
                'started_at' => now(),
                'completed_at' => null,
            ]);
        });

        ProcessStudioAnimationJob::dispatch($job->id)->onQueue(config('queue.ai_queue', 'ai'));

        return $job;
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiPayload(StudioAnimationJob $job): array
    {
        $job->loadMissing(['output.asset', 'renders']);

        $out = $job->output;
        $assetViewUrl = null;
        if ($out && $out->asset_id) {
            $assetViewUrl = route('assets.view', ['asset' => $out->asset_id], true);
        }

        $settings = is_array($job->settings_json) ? $job->settings_json : [];
        $canonicalFrame = is_array($settings['canonical_frame'] ?? null) ? $settings['canonical_frame'] : null;

        $payload = [
            'id' => (string) $job->id,
            'status' => $job->status,
            'provider' => $job->provider,
            'provider_model' => $job->provider_model,
            'source_strategy' => $job->source_strategy,
            'composition_id' => $job->composition_id !== null ? (string) $job->composition_id : null,
            'source_composition_version_id' => $job->source_composition_version_id !== null ? (string) $job->source_composition_version_id : null,
            'source_document_revision_hash' => $job->source_document_revision_hash,
            'prompt' => $job->prompt,
            'motion_preset' => $job->motion_preset,
            'duration_seconds' => $job->duration_seconds,
            'aspect_ratio' => $job->aspect_ratio,
            'generate_audio' => (bool) $job->generate_audio,
            'error_code' => $job->error_code,
            'error_message' => $job->error_message,
            'created_at' => $job->created_at?->toIso8601String(),
            'started_at' => $job->started_at?->toIso8601String(),
            'completed_at' => $job->completed_at?->toIso8601String(),
            'animation_intent' => $job->animation_intent_json,
            'preflight_risk' => $settings['preflight_risk'] ?? null,
            'source_lock' => $settings[AnimationSourceLock::SETTINGS_KEY] ?? null,
            'retry_kind' => $this->effectiveRetryKind($job),
            'user_facing_error' => $this->friendlyFailureMessage($job),
            'last_pipeline_event' => $this->lastPipelineEvent($job),
            'canonical_frame' => $settings['canonical_frame'] ?? null,
            'frame_drift_status' => is_array($canonicalFrame) ? ($canonicalFrame['frame_drift_status'] ?? null) : null,
            'drift_level' => is_array($canonicalFrame) ? ($canonicalFrame['drift_level'] ?? null) : null,
            'drift_summary' => is_array($canonicalFrame) ? ($canonicalFrame['drift_summary'] ?? null) : null,
            'provider_submission_used_frame' => is_array($canonicalFrame) ? ($canonicalFrame['provider_submit_start_image_origin'] ?? null) : null,
            'intent_version' => is_array($job->animation_intent_json) ? ($job->animation_intent_json['intent_version'] ?? null) : null,
            'high_fidelity_submit' => (bool) ($settings['high_fidelity_submit'] ?? false),
            'finalize_last_outcome' => $settings['finalize_last_outcome'] ?? null,
            'credits_charged' => (bool) ($settings['credits_tracked'] ?? false),
            'credits_charged_units' => (int) ($settings['credits_charged'] ?? 0),
            /** Reserved at job creation; charged only after a successful finalize. */
            'credits_reserved' => (int) ($settings['credit_cost_reserved'] ?? 0),
            'finalize_reuse_mode' => $settings['finalize_reuse_mode'] ?? null,
            'was_reused_existing_output' => (bool) ($settings['was_reused_existing_output'] ?? false),
            'render_engine' => is_array($canonicalFrame) ? ($canonicalFrame['render_engine'] ?? null) : null,
            'renderer_version' => is_array($canonicalFrame) ? ($canonicalFrame['renderer_version'] ?? null) : null,
            'verified_webhook' => (bool) ($settings['last_webhook_verified'] ?? false),
            'drift_decision' => is_array($settings['drift_decision'] ?? null) ? $settings['drift_decision'] : null,
            'output' => $out ? [
                'asset_id' => $out->asset_id,
                'asset_view_url' => $assetViewUrl,
                'video_path' => $out->video_path,
                'mime_type' => $out->mime_type,
                'duration_seconds' => $out->duration_seconds,
                'width' => $out->width,
                'height' => $out->height,
            ] : null,
        ];

        if ((bool) config('studio_animation.diagnostics_api.enabled', false)) {
            $payload['rollout_diagnostics'] = $this->buildRolloutDiagnostics($job, $out);
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRolloutDiagnostics(StudioAnimationJob $job, ?StudioAnimationOutput $out): array
    {
        $outputCount = StudioAnimationOutput::query()
            ->where('studio_animation_job_id', $job->id)
            ->count();

        $settings = is_array($job->settings_json) ? $job->settings_json : [];

        return [
            'output_policy' => 'single_output_per_job',
            'studio_animation_outputs_count' => $outputCount,
            'has_output_row' => $out !== null,
            'has_pending_finalize_url' => is_string($settings['pending_finalize_remote_video_url'] ?? null)
                && $settings['pending_finalize_remote_video_url'] !== '',
            'verified_webhook_last' => (bool) ($settings['last_webhook_verified'] ?? false),
            'queue_ai' => (string) config('queue.ai_queue', 'ai'),
            'official_playwright' => [
                'enabled' => (bool) config('studio_animation.official_playwright_renderer.enabled', false),
                'require_high_fidelity_submit' => (bool) config('studio_animation.official_playwright_renderer.require_high_fidelity_submit', false),
                'disable_legacy_browser_command' => (bool) config('studio_animation.official_playwright_renderer.disable_legacy_browser_command', false),
            ],
            'webhook_ingest_enabled' => (bool) config('studio_animation.webhooks.ingest_enabled', false),
            'drift_gate' => [
                'enabled' => (bool) config('studio_animation.drift_gate.enabled', false),
                'mode' => (string) config('studio_animation.drift_gate.mode', 'warn_only'),
                'strict_drift_block' => (bool) config('studio_animation.drift_gate.strict_drift_block', false),
            ],
            'validation_cli' => 'php artisan studio-animation:rollout-notes',
            'manual_validation_paths' => [
                'official_renderer' => 'Enable official_playwright_renderer.enabled + script_path; submit with high_fidelity_submit if require_high_fidelity_submit.',
                'fallback_renderer' => 'Disable official + browser; expect render_engine server_basic or client_snapshot in job JSON.',
                'webhook_completion' => 'STUDIO_ANIMATION_WEBHOOK_INGEST_ENABLED + secret; POST completed payload with video_url; verified_webhook_last true.',
                'polling_completion' => 'Webhook off; mock transport completes on poll; poll_finalize_scheduled in logs.',
                'finalize_retry' => 'Fail after download with pending URL and no output row; retry_kind finalize_only; second finalize idempotent.',
                'drift_gate' => 'STUDIO_ANIMATION_DRIFT_GATE_ENABLED + mode; expect drift_blocked and processor_drift_blocked logs.',
            ],
            'status_debug' => [
                'status' => (string) $job->status,
                'error_code' => $job->error_code,
                'render_engine' => is_array($settings['canonical_frame'] ?? null)
                    ? ($settings['canonical_frame']['render_engine'] ?? null)
                    : null,
                'drift_level' => is_array($settings['canonical_frame'] ?? null)
                    ? ($settings['canonical_frame']['drift_level'] ?? null)
                    : null,
                'drift_decision_compact' => StudioAnimationObservability::compactDriftDecision(
                    is_array($settings['drift_decision'] ?? null) ? $settings['drift_decision'] : null
                ),
                'retry_kind' => $this->effectiveRetryKind($job),
                'finalize_reuse_mode' => $settings['finalize_reuse_mode'] ?? null,
                'finalize_last_outcome' => $settings['finalize_last_outcome'] ?? null,
                'verified_webhook' => (bool) ($settings['last_webhook_verified'] ?? false),
            ],
        ];
    }

    /**
     * @return Collection<int, StudioAnimationJob>
     */
    public function listForComposition(int $compositionId, Tenant $tenant, int $brandId, User $user, int $limit = 40): Collection
    {
        $ok = Composition::query()
            ->where('id', $compositionId)
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brandId)
            ->visibleToUser($user)
            ->exists();
        if (! $ok) {
            return new Collection;
        }

        return StudioAnimationJob::query()
            ->where('composition_id', $compositionId)
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brandId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function retry(StudioAnimationJob $job, Tenant $tenant, User $user): void
    {
        if ((int) $job->tenant_id !== (int) $tenant->id) {
            abort(403);
        }

        if ($job->status !== StudioAnimationStatus::Failed->value) {
            throw ValidationException::withMessages(['job' => 'Only failed jobs can be retried.']);
        }

        $credits = $this->aiUsageService->getStudioAnimationCreditCost((int) $job->duration_seconds);
        try {
            $this->aiUsageService->checkUsage($tenant, 'studio_animation', $credits);
        } catch (PlanLimitExceededException $e) {
            throw ValidationException::withMessages(['credits' => $e->getMessage()]);
        }

        $kind = $this->inferRetryKind($job);
        \App\Studio\Animation\Support\StudioAnimationObservability::log('user_retry', $job, [
            'retry_kind' => (string) $kind,
        ]);

        if ($kind === 'finalize_only') {
            $settings = $job->settings_json ?? [];
            $settings['credit_cost_reserved'] = $credits;
            $pending = (string) ($settings['pending_finalize_remote_video_url'] ?? '');
            $job->update([
                'settings_json' => $settings,
                'status' => StudioAnimationStatus::Downloading->value,
                'error_code' => null,
                'error_message' => null,
                'completed_at' => null,
            ]);
            FinalizeStudioAnimationJob::dispatch($job->id, $pending)->onQueue(config('queue.ai_queue', 'ai'));

            return;
        }

        if ($kind === 'poll_only') {
            $settings = $job->settings_json ?? [];
            $settings['credit_cost_reserved'] = $credits;
            $job->update([
                'settings_json' => $settings,
                'status' => StudioAnimationStatus::Processing->value,
                'error_code' => null,
                'error_message' => null,
                'completed_at' => null,
            ]);
            $delay = app()->environment('testing') ? 0 : 12;
            PollStudioAnimationJob::dispatch($job->id)->delay(now()->addSeconds($delay))->onQueue(config('queue.ai_queue', 'ai'));

            return;
        }

        DB::transaction(function () use ($job, $credits): void {
            $existingOutput = $job->output;
            if ($existingOutput && $existingOutput->asset_id) {
                $asset = Asset::query()->find($existingOutput->asset_id);
                if ($asset) {
                    $asset->delete();
                }
            }
            StudioAnimationOutput::query()->where('studio_animation_job_id', $job->id)->delete();

            foreach ($job->renders as $render) {
                try {
                    \Illuminate\Support\Facades\Storage::disk($render->disk)->delete($render->path);
                } catch (\Throwable) {
                    // best-effort
                }
            }
            $job->renders()->delete();

            $settings = $job->settings_json ?? [];
            $settings['credit_cost_reserved'] = $credits;
            unset(
                $settings['pending_finalize_remote_video_url'],
                $settings['canonical_frame'],
                $settings['finalize_audit_log'],
                $settings['finalize_last_outcome'],
                $settings['finalize_reuse_mode'],
                $settings['was_reused_existing_output'],
                $settings['finalize_reuse_reason'],
                $settings['reused_output_match_mode'],
                $settings['drift_decision'],
                $settings['last_webhook_verified'],
                $settings['last_webhook_signature_method'],
            );

            $job->update([
                'status' => StudioAnimationStatus::Queued->value,
                'provider_job_id' => null,
                'provider_queue_request_id' => null,
                'provider_request_json' => null,
                'provider_response_json' => null,
                'error_code' => null,
                'error_message' => null,
                'completed_at' => null,
                'settings_json' => $settings,
            ]);
        });

        ProcessStudioAnimationJob::dispatch($job->id)->onQueue(config('queue.ai_queue', 'ai'));
    }

    public function cancel(StudioAnimationJob $job): void
    {
        if (in_array($job->status, [
            StudioAnimationStatus::Complete->value,
            StudioAnimationStatus::Failed->value,
            StudioAnimationStatus::Canceled->value,
        ], true)) {
            return;
        }

        $job->update([
            'status' => StudioAnimationStatus::Canceled->value,
            'completed_at' => now(),
            'error_code' => 'canceled',
            'error_message' => 'Canceled by user.',
        ]);
    }

    /**
     * Exposed for observability and API payloads; only meaningful when the job failed.
     */
    public function effectiveRetryKind(StudioAnimationJob $job): ?string
    {
        return $this->inferRetryKind($job);
    }

    private function inferRetryKind(StudioAnimationJob $job): ?string
    {
        if ($job->status !== StudioAnimationStatus::Failed->value) {
            return null;
        }

        $code = (string) ($job->error_code ?? '');
        $settings = is_array($job->settings_json) ? $job->settings_json : [];
        $pending = $settings['pending_finalize_remote_video_url'] ?? null;

        if (in_array($code, [
            'download_failed',
            'finalize_failed',
            'finalize_failed_after_download',
            'finalize_failed_before_download',
        ], true) && is_string($pending) && $pending !== '' && $job->output()->doesntExist()) {
            return 'finalize_only';
        }

        if ($code === 'provider_timeout' && $job->provider_job_id !== null && $job->provider_job_id !== '') {
            return 'poll_only';
        }

        return 'full_retry';
    }

    private function friendlyFailureMessage(StudioAnimationJob $job): ?string
    {
        if ($job->status !== StudioAnimationStatus::Failed->value) {
            return null;
        }

        if ((string) $job->error_code === 'render_failed'
            && str_contains((string) ($job->error_message ?? ''), 'Snapshot dimensions do not match')) {
            return 'The snapshot image did not match your composition width and height (often a scaled-down capture vs. the document canvas). Update the app and use Retry, or confirm the live canvas matches the document size.';
        }

        if ((string) $job->error_code === 'provider_submit_failed') {
            $em = strtolower((string) ($job->error_message ?? ''));
            if (str_contains($em, 'authentication')
                || str_contains($em, '401')
                || str_contains($em, 'cannot access application')) {
                return 'The Fal / Kling API rejected this request (not authenticated). On the server, set a valid FAL_KEY (see .env.example; KLING_API_KEY is used as a fallback name), restart PHP/queue workers, then use Retry.';
            }
        }

        return match ((string) $job->error_code) {
            'render_failed' => 'We could not prepare the composition snapshot for animation.',
            'provider_submit_failed' => 'The video provider rejected the job at submission time.',
            'provider_timeout' => 'The provider took too long; you can retry polling without resubmitting.',
            'provider_failed' => 'The provider could not generate this video.',
            'download_failed' => 'We could not download the finished video from the provider.',
            'finalize_failed' => 'The video downloaded, but saving it to your workspace failed.',
            'finalize_failed_after_download' => 'The video file was retrieved, but saving it to storage or your library failed.',
            'finalize_failed_before_download' => 'We could not finish saving the animation before the download completed.',
            'drift_blocked' => 'Snapshot drift exceeded your workspace policy; try adjusting the composition or relaxing drift settings.',
            'canceled' => 'This animation was canceled.',
            default => $job->error_message ?: 'Animation failed.',
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lastPipelineEvent(StudioAnimationJob $job): ?array
    {
        $pr = is_array($job->provider_response_json) ? $job->provider_response_json : [];
        $trace = $pr['internal_pipeline_trace'] ?? null;
        if (! is_array($trace) || $trace === []) {
            return null;
        }

        $last = $trace[array_key_last($trace)];

        return is_array($last) ? $last : null;
    }
}
