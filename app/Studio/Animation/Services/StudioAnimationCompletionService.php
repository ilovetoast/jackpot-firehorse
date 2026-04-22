<?php

namespace App\Studio\Animation\Services;

use App\Enums\AITaskType;
use App\Enums\ApprovalStatus;
use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\ThumbnailStatus;
use App\Exceptions\PlanLimitExceededException;
use App\Jobs\GenerateThumbnailsJob;
use App\Jobs\ProcessAssetJob;
use App\Models\AIAgentRun;
use App\Models\Asset;
use App\Models\AssetVersion;
use App\Models\Brand;
use App\Models\Composition;
use App\Models\StudioAnimationJob;
use App\Models\StudioAnimationOutput;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AiUsageService;
use App\Services\AssetPathGenerator;
use App\Studio\Animation\Enums\StudioAnimationStatus;
use App\Studio\Animation\Support\StudioAnimationFinalizeFingerprint;
use App\Studio\Animation\Support\StudioAnimationFinalizeVideoProbe;
use App\Studio\Animation\Support\StudioAnimationObservability;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class StudioAnimationCompletionService
{
    public function __construct(
        protected AssetPathGenerator $pathGenerator,
        protected AiUsageService $aiUsageService,
        protected StudioAnimationFinalizeVideoProbe $finalizeVideoProbe,
    ) {}

    public function finalizeFromRemoteUrl(StudioAnimationJob $job, string $videoUrl): void
    {
        try {
            $this->finalizeFromRemoteUrlInternal($job, $videoUrl);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (str_starts_with($msg, 'DOWNLOAD_FAILED:')) {
                throw $e;
            }
            throw new \RuntimeException('FINALIZE_FAILED: '.$msg, 0, $e);
        }
    }

    private function finalizeFromRemoteUrlInternal(StudioAnimationJob $job, string $videoUrl): void
    {
        $job->refresh();
        $fingerprint = StudioAnimationFinalizeFingerprint::compute($job, $videoUrl);

        $byFingerprint = StudioAnimationOutput::query()
            ->where('studio_animation_job_id', $job->id)
            ->where('finalize_fingerprint', $fingerprint)
            ->first();
        if ($byFingerprint !== null) {
            if (! $this->isStudioOutputVideoDurable($byFingerprint)) {
                Log::warning('[StudioAnimationCompletionService] dropping undurable output (fingerprint match)', [
                    'job_id' => $job->id,
                    'output_id' => $byFingerprint->id,
                ]);
                $byFingerprint->delete();
            } else {
                $this->reuseOutputAndComplete($job, $byFingerprint, 'finalize_reused_existing_output', 'fingerprint', 'exact_fingerprint_match');

                return;
            }
        }

        $anyOutput = StudioAnimationOutput::query()
            ->where('studio_animation_job_id', $job->id)
            ->orderBy('id')
            ->first();
        if ($anyOutput !== null) {
            if (! $this->isStudioOutputVideoDurable($anyOutput)) {
                Log::warning('[StudioAnimationCompletionService] dropping undurable output (reuse fallback)', [
                    'job_id' => $job->id,
                    'output_id' => $anyOutput->id,
                ]);
                $anyOutput->delete();
            } else {
                $matchMode = $anyOutput->finalize_fingerprint === null || $anyOutput->finalize_fingerprint === ''
                    ? 'job_only'
                    : 'fallback';
                $reason = $matchMode === 'job_only'
                    ? 'legacy_or_missing_output_fingerprint'
                    : 'fingerprint_mismatch_reuse_existing_output';
                $this->reuseOutputAndComplete($job, $anyOutput, 'finalize_reused_existing_output', $matchMode, $reason);

                return;
            }
        }

        $binary = $this->downloadVideoBinary($videoUrl);
        $probed = $this->finalizeVideoProbe->probeBinary($binary);

        $disk = (string) config('studio_animation.output_disk', 's3');
        $size = strlen($binary);
        $outputDurationSeconds = max(1, (int) round($probed['duration']));
        $outW = $probed['display_width'];
        $outH = $probed['display_height'];

        $dispatchProcessAssetAfterCommit = null;
        $eagerThumbnailsVersionId = null;

        DB::transaction(function () use ($job, $fingerprint, $binary, $size, $disk, $videoUrl, $outputDurationSeconds, $outW, $outH, &$dispatchProcessAssetAfterCommit, &$eagerThumbnailsVersionId): void {
            $locked = StudioAnimationJob::query()->whereKey($job->id)->lockForUpdate()->firstOrFail();

            $dupFp = StudioAnimationOutput::query()
                ->where('studio_animation_job_id', $locked->id)
                ->where('finalize_fingerprint', $fingerprint)
                ->first();
            if ($dupFp !== null) {
                if (! $this->isStudioOutputVideoDurable($dupFp)) {
                    Log::warning('[StudioAnimationCompletionService] dropping undurable output (locked duplicate fingerprint)', [
                        'job_id' => $locked->id,
                        'output_id' => $dupFp->id,
                    ]);
                    $dupFp->delete();
                } else {
                    $this->reuseOutputAndComplete($locked, $dupFp, 'finalize_duplicate_prevented', 'fingerprint', 'race_duplicate_fingerprint');

                    return;
                }
            }

            $dupAny = StudioAnimationOutput::query()
                ->where('studio_animation_job_id', $locked->id)
                ->orderBy('id')
                ->first();
            if ($dupAny !== null) {
                if (! $this->isStudioOutputVideoDurable($dupAny)) {
                    Log::warning('[StudioAnimationCompletionService] dropping undurable output (locked fallback)', [
                        'job_id' => $locked->id,
                        'output_id' => $dupAny->id,
                    ]);
                    $dupAny->delete();
                } else {
                    $matchMode = $dupAny->finalize_fingerprint === null || $dupAny->finalize_fingerprint === ''
                        ? 'job_only'
                        : 'fallback';
                    $reason = $matchMode === 'job_only'
                        ? 'legacy_or_missing_output_fingerprint'
                        : 'fingerprint_mismatch_after_lock';
                    $this->reuseOutputAndComplete($locked, $dupAny, 'finalize_duplicate_prevented', $matchMode, $reason);

                    return;
                }
            }

            $tenant = Tenant::query()->findOrFail($locked->tenant_id);
            $brand = Brand::query()->findOrFail($locked->brand_id);
            $user = User::query()->findOrFail($locked->user_id);

            $settings = $locked->settings_json ?? [];
            if (empty($settings['credits_tracked'])) {
                $credits = (int) ($settings['credit_cost_reserved'] ?? $this->aiUsageService->getStudioAnimationCreditCost((int) $locked->duration_seconds));
                try {
                    $this->aiUsageService->trackUsage($tenant, 'studio_animation', $credits);
                } catch (PlanLimitExceededException $e) {
                    Log::warning('[StudioAnimationCompletionService] credit track failed', [
                        'job_id' => $locked->id,
                        'message' => $e->getMessage(),
                    ]);
                    throw $e;
                }
                $settings['credits_tracked'] = true;
                $settings['credits_charged'] = $credits;
                $locked->update(['settings_json' => $settings]);
            }

            $assetId = (string) Str::uuid();
            $path = $this->pathGenerator->generateOriginalPathForAssetId($tenant, $assetId, 1, 'mp4');

            Storage::disk($disk)->put($path, $binary, 'private');

            $assetTitle = 'Studio animation';
            if ($locked->composition_id) {
                $comp = Composition::query()
                    ->whereKey($locked->composition_id)
                    ->where('brand_id', $locked->brand_id)
                    ->first();
                if ($comp !== null) {
                    $name = trim((string) ($comp->name ?? ''));
                    if ($name !== '') {
                        $assetTitle = Str::limit($name, 100, '…').' — video';
                    }
                }
            }

            $asset = Asset::forceCreate([
                'id' => $assetId,
                'tenant_id' => $tenant->id,
                'brand_id' => $brand->id,
                'user_id' => $user->id,
                'status' => AssetStatus::VISIBLE,
                'type' => AssetType::AI_GENERATED,
                'title' => $assetTitle,
                'original_filename' => 'studio-animation-'.$locked->id.'.mp4',
                'mime_type' => 'video/mp4',
                'size_bytes' => $size,
                'width' => $outW,
                'height' => $outH,
                'storage_root_path' => $path,
                'thumbnail_status' => ThumbnailStatus::PENDING,
                // ProcessAssetJob only runs when analysis_status is the post-upload state (same as normal uploads).
                'analysis_status' => 'uploading',
                'approval_status' => ApprovalStatus::NOT_REQUIRED,
                'published_at' => null,
                'source' => 'studio_animation',
                'builder_staged' => false,
                'intake_state' => 'staged',
                'metadata' => array_filter([
                    'studio_animation_job_id' => (string) $locked->id,
                    'provider' => $locked->provider,
                    'provider_model' => $locked->provider_model,
                    'composition_id' => $locked->composition_id !== null ? (string) $locked->composition_id : null,
                    'motion_preset' => $locked->motion_preset,
                    'aspect_ratio' => $locked->aspect_ratio,
                    'remote_video_url' => $videoUrl,
                    'finalize_fingerprint' => $fingerprint,
                    // Staged studio output: run thumbnails + video preview now; defer vision/insights until filed in library.
                    '_studio_staged_defer_ai' => true,
                    '_skip_ai_tagging' => true,
                    '_skip_ai_metadata' => true,
                    '_skip_ai_video_insights' => true,
                ], static fn ($v) => $v !== null && $v !== ''),
            ]);

            $versionId = (string) Str::uuid();
            AssetVersion::query()->create([
                'id' => $versionId,
                'asset_id' => $asset->id,
                'version_number' => 1,
                'file_path' => $path,
                'file_size' => $size,
                'mime_type' => 'video/mp4',
                'width' => $outW,
                'height' => $outH,
                'checksum' => hash('sha256', $binary),
                'is_current' => true,
                'pipeline_status' => 'complete',
                'uploaded_by' => $user->id,
            ]);

            try {
                StudioAnimationOutput::query()->create([
                    'studio_animation_job_id' => $locked->id,
                    'finalize_fingerprint' => $fingerprint,
                    'asset_id' => $asset->id,
                    'disk' => $disk,
                    'video_path' => $path,
                    'poster_path' => null,
                    'mime_type' => 'video/mp4',
                    'duration_seconds' => $outputDurationSeconds,
                    'width' => $outW,
                    'height' => $outH,
                    'metadata_json' => array_filter([
                        'provider' => $locked->provider,
                        'provider_model' => $locked->provider_model,
                        'finalize_fingerprint' => $fingerprint,
                        'intent_version' => is_array($locked->animation_intent_json)
                            ? ($locked->animation_intent_json['intent_version'] ?? null)
                            : null,
                        'schema_version' => is_array($locked->animation_intent_json)
                            ? ($locked->animation_intent_json['schema_version'] ?? null)
                            : null,
                        'start_frame_renderer_version' => is_array($locked->settings_json['canonical_frame'] ?? null)
                            ? ($locked->settings_json['canonical_frame']['renderer_version'] ?? null)
                            : null,
                        'start_frame_render_engine' => is_array($locked->settings_json['canonical_frame'] ?? null)
                            ? ($locked->settings_json['canonical_frame']['render_engine'] ?? null)
                            : null,
                    ], static fn ($v) => $v !== null && $v !== ''),
                ]);
            } catch (QueryException $e) {
                if ($this->isDuplicateKeyException($e)) {
                    Storage::disk($disk)->delete($path);
                    AssetVersion::query()->where('asset_id', $asset->id)->delete();
                    $asset->delete();
                    $existing = StudioAnimationOutput::query()
                        ->where('studio_animation_job_id', $locked->id)
                        ->where('finalize_fingerprint', $fingerprint)
                        ->first()
                        ?? StudioAnimationOutput::query()
                            ->where('studio_animation_job_id', $locked->id)
                            ->orderBy('id')
                            ->first();
                    if ($existing !== null) {
                        $this->reuseOutputAndComplete($locked, $existing, 'finalize_duplicate_prevented', 'fingerprint', 'unique_constraint_race');

                        return;
                    }
                }
                throw $e;
            }

            $eagerThumbnailsVersionId = $versionId;

            $creditsCharged = (int) (($locked->settings_json ?? [])['credits_charged'] ?? 0);
            $this->ensureStudioAnimationAgentRunRecorded(
                $locked,
                (string) $asset->id,
                $outputDurationSeconds,
                $creditsCharged
            );

            $locked->update([
                'status' => StudioAnimationStatus::Complete->value,
                'completed_at' => now(),
                'error_code' => null,
                'error_message' => null,
            ]);

            $locked->refresh();
            $st = $locked->settings_json ?? [];
            unset($st['composition_snapshot_png_base64'], $st['pending_finalize_remote_video_url']);
            $st['finalize_last_outcome'] = 'finalize_completed';
            $st['finalize_reuse_mode'] = 'none';
            $st['was_reused_existing_output'] = false;
            $st['reused_output_match_mode'] = null;
            $st['credits_charged_for_output'] = (bool) ($st['credits_tracked'] ?? false);
            $this->pushFinalizeAudit($st, 'finalize_completed', ['fingerprint' => $fingerprint]);
            $locked->update(['settings_json' => $st]);
            StudioAnimationObservability::log('finalize_new_asset', $locked, [
                'finalize_reuse_mode' => 'none',
            ]);

            $dispatchProcessAssetAfterCommit = (string) $asset->id;
        });

        if ($eagerThumbnailsVersionId !== null && (bool) config('studio_animation.eager_video_thumbnails', true)) {
            try {
                Bus::dispatchSync(new GenerateThumbnailsJob($eagerThumbnailsVersionId));
            } catch (\Throwable $e) {
                Log::warning('[StudioAnimationCompletionService] Eager video thumbnails failed; async pipeline will retry', [
                    'version_id' => $eagerThumbnailsVersionId,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        if ($dispatchProcessAssetAfterCommit !== null) {
            ProcessAssetJob::dispatch($dispatchProcessAssetAfterCommit)
                ->onQueue(config('queue.images_queue', 'images'));
        }
    }

    private function isDuplicateKeyException(QueryException $e): bool
    {
        $sqlState = (string) $e->errorInfo[0];
        if ($sqlState === '23000') {
            return true;
        }
        $msg = strtolower($e->getMessage());

        return str_contains($msg, 'duplicate') || str_contains($msg, 'unique');
    }

    /**
     * @param  array<string, mixed>  $st
     * @param  array<string, mixed>  $ctx
     */
    private function pushFinalizeAudit(array &$st, string $outcome, array $ctx = []): void
    {
        $log = $st['finalize_audit_log'] ?? [];
        if (! is_array($log)) {
            $log = [];
        }
        $log[] = array_merge([
            'at' => now()->toIso8601String(),
            'outcome' => $outcome,
        ], $ctx);
        $st['finalize_audit_log'] = array_slice($log, -25);
    }

    /**
     * Reuse and admin audit assume the file is present. Legacy rows may omit {@see Asset} but must have an object on disk.
     */
    private function isStudioOutputVideoDurable(StudioAnimationOutput $output): bool
    {
        $path = (string) ($output->video_path ?? '');
        if ($path === '') {
            return false;
        }
        $diskName = (string) ($output->disk !== null && $output->disk !== ''
            ? $output->disk
            : config('studio_animation.output_disk', 's3'));
        try {
            if (! Storage::disk($diskName)->exists($path)) {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }
        if ($output->asset_id !== null && $output->asset_id !== '') {
            return Asset::query()->whereKey($output->asset_id)->exists();
        }

        return true;
    }

    private function reuseOutputAndComplete(
        StudioAnimationJob $job,
        StudioAnimationOutput $output,
        string $outcome,
        string $finalizeReuseMode,
        ?string $reuseReason = null,
    ): void {
        if (! $this->isStudioOutputVideoDurable($output)) {
            throw new \RuntimeException('FINALIZE_FAILED: existing studio output is not durable (missing file or asset).');
        }
        $job->update([
            'status' => StudioAnimationStatus::Complete->value,
            'completed_at' => now(),
            'error_code' => null,
            'error_message' => null,
        ]);
        $job->refresh();
        $st = $job->settings_json ?? [];
        unset($st['composition_snapshot_png_base64'], $st['pending_finalize_remote_video_url']);
        $st['finalize_last_outcome'] = $outcome;
        $st['finalize_reuse_mode'] = $finalizeReuseMode;
        $st['was_reused_existing_output'] = true;
        $st['reused_output_match_mode'] = $finalizeReuseMode;
        if ($reuseReason !== null) {
            $st['finalize_reuse_reason'] = $reuseReason;
        }
        $st['credits_charged_for_output'] = (bool) ($st['credits_tracked'] ?? false);
        $this->pushFinalizeAudit($st, $outcome, [
            'output_id' => $output->id,
            'finalize_reuse_mode' => $finalizeReuseMode,
            'reuse_reason' => $reuseReason,
        ]);
        $job->update(['settings_json' => $st]);
        StudioAnimationObservability::log('finalize_reused_output', $job->fresh(), [
            'finalize_last_outcome' => $outcome,
            'finalize_reuse_mode' => $finalizeReuseMode,
        ]);

        $fresh = $job->fresh();
        if ($fresh !== null && $output->asset_id !== null && $output->asset_id !== '') {
            $this->ensureStudioAnimationAgentRunRecorded(
                $fresh,
                (string) $output->asset_id,
                (int) ($output->duration_seconds ?? 0),
                (int) (($fresh->settings_json ?? [])['credits_charged'] ?? 0)
            );
        }
    }

    /**
     * One success row per job for admin AI audit (same table as editor generative/edit).
     * Idempotent: skips if a success run already exists for this studio_animation_job entity.
     */
    private function ensureStudioAnimationAgentRunRecorded(
        StudioAnimationJob $job,
        string $outputAssetId,
        int $outputDurationSeconds,
        int $creditsCharged,
    ): void {
        if (AIAgentRun::query()
            ->where('entity_type', 'studio_animation_job')
            ->where('entity_id', (string) $job->id)
            ->where('status', 'success')
            ->exists()) {
            return;
        }

        $tenant = Tenant::query()->find($job->tenant_id);
        $user = User::query()->find($job->user_id);
        if ($tenant === null || $user === null) {
            return;
        }

        $costBreakdown = $this->buildStudioAnimationCostBreakdown($outputDurationSeconds, $creditsCharged);
        $estimatedProviderUsd = $costBreakdown['cogs_total_usd'];
        $generativeAudit = $this->buildStudioGenerativeAuditPayload(
            $job,
            $outputAssetId,
            $outputDurationSeconds,
            $creditsCharged,
            $costBreakdown
        );

        AIAgentRun::query()->create([
            'agent_id' => 'studio_animate_composition',
            'agent_name' => (string) (config('ai.agents.studio_animate_composition.name') ?? 'Studio Animate Composition'),
            'triggering_context' => 'user',
            'environment' => app()->environment(),
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'task_type' => AITaskType::STUDIO_COMPOSITION_ANIMATION,
            'entity_type' => 'studio_animation_job',
            'entity_id' => (string) $job->id,
            'model_used' => (string) $job->provider_model,
            'tokens_in' => 0,
            'tokens_out' => 0,
            /** Internal COGS estimate from config (Kling has no token-based bill on this row). */
            'estimated_cost' => $estimatedProviderUsd,
            'status' => 'success',
            'started_at' => $job->started_at ?? now(),
            'completed_at' => now(),
            'metadata' => [
                'provider' => $job->provider,
                'provider_model' => $job->provider_model,
                'duration_seconds' => $outputDurationSeconds,
                'composition_id' => $job->composition_id,
                'asset_id' => $outputAssetId,
                'credits' => $creditsCharged,
                'estimated_provider_usd' => $estimatedProviderUsd,
                'estimated_cost_basis' => 'config:studio_animation.cost_tracking',
                'cost_estimate' => $costBreakdown,
                'generative_audit' => $generativeAudit,
            ],
        ]);
    }

    /**
     * COGS (vendor) + retail credit value for admin; length beyond base adds both credits and $ estimate.
     *
     * @return array{
     *   cogs_total_usd: float,
     *   credits_retail_list_usd: float,
     *   list_price_usd_per_credit: float,
     *   components: array<string, mixed>,
     *   disclaimer: string,
     *   pricing_note: string
     * }
     */
    private function buildStudioAnimationCostBreakdown(int $outputDurationSeconds, int $creditsCharged): array
    {
        $perJob = (float) config('studio_animation.cost_tracking.estimated_usd_per_job', 1.0);
        $perExtraSec = (float) config('studio_animation.cost_tracking.estimated_usd_per_extra_second', 0.0);
        $disclaimer = (string) config(
            'studio_animation.cost_tracking.disclaimer',
            'Internal COGS estimate from config, not a vendor invoice.'
        );
        $covers = max(1, (int) config('studio_animation.credits.base_covers_seconds', 5));
        $d = max(1, $outputDurationSeconds);
        $extra = max(0, $d - $covers);
        $total = round($perJob + $extra * $perExtraSec, 6);
        $listPerCredit = (float) config('studio_animation.credits.list_price_usd_per_credit', 0.058);
        $retail = round($creditsCharged * $listPerCredit, 4);

        return [
            'cogs_total_usd' => $total,
            'credits_retail_list_usd' => $retail,
            'list_price_usd_per_credit' => $listPerCredit,
            'components' => [
                'base_cogs_usd' => $perJob,
                'per_extra_second_cogs_usd' => $perExtraSec,
                'output_duration_seconds' => $d,
                'base_covers_seconds' => $covers,
                'extra_seconds' => $extra,
                'credits_charged' => $creditsCharged,
            ],
            'disclaimer' => $disclaimer,
            'pricing_note' => 'cogs_usd = vendor cost estimate; credits_retail_list_usd = credits × list pack $/credit (display only, STUDIO_ANIMATION_LIST_USD_PER_CREDIT).',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildStudioGenerativeAuditPayload(
        StudioAnimationJob $job,
        string $outputAssetId,
        int $outputDurationSeconds,
        int $creditsCharged,
        array $costBreakdown,
    ): array {
        $settings = is_array($job->settings_json) ? $job->settings_json : [];
        $prompt = (string) ($job->prompt ?? '');
        $preview = $prompt !== ''
            ? mb_substr($prompt, 0, 500)
            : ($job->motion_preset !== null && $job->motion_preset !== ''
                ? 'Motion: '.(string) $job->motion_preset
                : '(provider image-to-video from composition snapshot)');
        $preview = mb_substr($preview, 0, 160);

        return [
            'audit_kind' => 'studio_composition_animation',
            'prompt' => $prompt,
            'prompt_preview' => $preview,
            'provider' => (string) $job->provider,
            'registry_model_key' => (string) $job->provider_model,
            'composition_id' => $job->composition_id !== null ? (string) $job->composition_id : null,
            'studio_animation_job_id' => (string) $job->id,
            'output_asset_id' => $outputAssetId,
            'output_duration_seconds' => $outputDurationSeconds,
            'credits_charged' => $creditsCharged,
            'estimated_provider_usd' => $costBreakdown['cogs_total_usd'],
            'cost_estimate' => $costBreakdown,
            'credits_reserved' => (int) ($settings['credit_cost_reserved'] ?? 0),
            'motion_preset' => $job->motion_preset,
            'aspect_ratio' => $job->aspect_ratio,
            'provider_job_id' => $job->provider_job_id,
        ];
    }

    private function downloadVideoBinary(string $videoUrl): string
    {
        $response = Http::timeout(600)->withOptions(['stream' => true])->get($videoUrl);
        if (! $response->successful()) {
            throw new \RuntimeException('DOWNLOAD_FAILED: HTTP '.$response->status());
        }

        $binary = $response->body();
        if ($binary === '' || strlen($binary) < 32) {
            throw new \RuntimeException('DOWNLOAD_FAILED: Empty video payload.');
        }

        return $binary;
    }
}
