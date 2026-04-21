<?php

namespace App\Studio\Animation\Services;

use App\Enums\AITaskType;
use App\Enums\ApprovalStatus;
use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\ThumbnailStatus;
use App\Exceptions\PlanLimitExceededException;
use App\Models\AIAgentRun;
use App\Models\Asset;
use App\Models\AssetVersion;
use App\Models\Brand;
use App\Models\StudioAnimationJob;
use App\Models\StudioAnimationOutput;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AiUsageService;
use App\Services\AssetPathGenerator;
use App\Studio\Animation\Enums\StudioAnimationStatus;
use App\Studio\Animation\Support\StudioAnimationFinalizeFingerprint;
use App\Studio\Animation\Support\StudioAnimationObservability;
use Illuminate\Database\QueryException;
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
            $this->reuseOutputAndComplete($job, $byFingerprint, 'finalize_reused_existing_output', 'fingerprint', 'exact_fingerprint_match');

            return;
        }

        $anyOutput = StudioAnimationOutput::query()
            ->where('studio_animation_job_id', $job->id)
            ->orderBy('id')
            ->first();
        if ($anyOutput !== null) {
            $matchMode = $anyOutput->finalize_fingerprint === null || $anyOutput->finalize_fingerprint === ''
                ? 'job_only'
                : 'fallback';
            $reason = $matchMode === 'job_only'
                ? 'legacy_or_missing_output_fingerprint'
                : 'fingerprint_mismatch_reuse_existing_output';
            $this->reuseOutputAndComplete($job, $anyOutput, 'finalize_reused_existing_output', $matchMode, $reason);

            return;
        }

        $binary = $this->downloadVideoBinary($videoUrl);

        $disk = (string) config('studio_animation.output_disk', 's3');
        $size = strlen($binary);

        DB::transaction(function () use ($job, $fingerprint, $binary, $size, $disk, $videoUrl): void {
            $locked = StudioAnimationJob::query()->whereKey($job->id)->lockForUpdate()->firstOrFail();

            $dupFp = StudioAnimationOutput::query()
                ->where('studio_animation_job_id', $locked->id)
                ->where('finalize_fingerprint', $fingerprint)
                ->first();
            if ($dupFp !== null) {
                $this->reuseOutputAndComplete($locked, $dupFp, 'finalize_duplicate_prevented', 'fingerprint', 'race_duplicate_fingerprint');

                return;
            }

            $dupAny = StudioAnimationOutput::query()
                ->where('studio_animation_job_id', $locked->id)
                ->orderBy('id')
                ->first();
            if ($dupAny !== null) {
                $matchMode = $dupAny->finalize_fingerprint === null || $dupAny->finalize_fingerprint === ''
                    ? 'job_only'
                    : 'fallback';
                $reason = $matchMode === 'job_only'
                    ? 'legacy_or_missing_output_fingerprint'
                    : 'fingerprint_mismatch_after_lock';
                $this->reuseOutputAndComplete($locked, $dupAny, 'finalize_duplicate_prevented', $matchMode, $reason);

                return;
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

            $asset = Asset::forceCreate([
                'id' => $assetId,
                'tenant_id' => $tenant->id,
                'brand_id' => $brand->id,
                'user_id' => $user->id,
                'status' => AssetStatus::VISIBLE,
                'type' => AssetType::AI_GENERATED,
                'title' => 'Studio animation',
                'original_filename' => 'studio-animation-'.$locked->id.'.mp4',
                'mime_type' => 'video/mp4',
                'size_bytes' => $size,
                'width' => null,
                'height' => null,
                'storage_root_path' => $path,
                'thumbnail_status' => ThumbnailStatus::PENDING,
                'analysis_status' => 'pending',
                'approval_status' => ApprovalStatus::NOT_REQUIRED,
                'published_at' => null,
                'source' => 'studio_animation',
                'builder_staged' => false,
                'intake_state' => 'normal',
                'metadata' => array_filter([
                    'studio_animation_job_id' => (string) $locked->id,
                    'provider' => $locked->provider,
                    'provider_model' => $locked->provider_model,
                    'composition_id' => $locked->composition_id !== null ? (string) $locked->composition_id : null,
                    'motion_preset' => $locked->motion_preset,
                    'aspect_ratio' => $locked->aspect_ratio,
                    'remote_video_url' => $videoUrl,
                    'finalize_fingerprint' => $fingerprint,
                ], static fn ($v) => $v !== null && $v !== ''),
            ]);

            AssetVersion::query()->create([
                'id' => (string) Str::uuid(),
                'asset_id' => $asset->id,
                'version_number' => 1,
                'file_path' => $path,
                'file_size' => $size,
                'mime_type' => 'video/mp4',
                'width' => null,
                'height' => null,
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
                    'duration_seconds' => $locked->duration_seconds,
                    'width' => null,
                    'height' => null,
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

            if (! AIAgentRun::query()
                ->where('entity_type', 'studio_animation_job')
                ->where('entity_id', (string) $locked->id)
                ->where('status', 'success')
                ->exists()) {
                AIAgentRun::query()->create([
                    'agent_id' => 'studio_animate_composition',
                    'agent_name' => (string) (config('ai.agents.studio_animate_composition.name') ?? 'Studio Animate Composition'),
                    'triggering_context' => 'user',
                    'environment' => app()->environment(),
                    'tenant_id' => $tenant->id,
                    'user_id' => $user->id,
                    'task_type' => AITaskType::STUDIO_COMPOSITION_ANIMATION,
                    'entity_type' => 'studio_animation_job',
                    'entity_id' => (string) $locked->id,
                    'model_used' => (string) $locked->provider_model,
                    'tokens_in' => 0,
                    'tokens_out' => 0,
                    'estimated_cost' => 0,
                    'status' => 'success',
                    'started_at' => $locked->started_at ?? now(),
                    'completed_at' => now(),
                    'metadata' => [
                        'provider' => $locked->provider,
                        'provider_model' => $locked->provider_model,
                        'duration_seconds' => $locked->duration_seconds,
                        'composition_id' => $locked->composition_id,
                        'asset_id' => $asset->id,
                        'credits' => (int) (($locked->settings_json ?? [])['credits_charged'] ?? 0),
                    ],
                ]);
            }

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
        });
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

    private function reuseOutputAndComplete(
        StudioAnimationJob $job,
        StudioAnimationOutput $output,
        string $outcome,
        string $finalizeReuseMode,
        ?string $reuseReason = null,
    ): void {
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
