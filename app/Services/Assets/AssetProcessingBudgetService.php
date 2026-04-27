<?php

namespace App\Services\Assets;

use App\Models\Asset;
use App\Models\AssetVersion;
use App\Services\FileTypeService;
use App\Support\PipelineQueueResolver;
use Illuminate\Support\Facades\Log;

/**
 * Central worker profile / processing budget for asset pipelines.
 *
 * Uses cheap inputs only (recorded sizes, MIME, extension, optional width/height).
 * Call sites must not download or decode large binaries before consulting this service.
 */
class AssetProcessingBudgetService
{
    public const GUARDRAIL_LOG_KEY = 'asset_processing_guardrail';

    public const DEFAULT_DEFER_MESSAGE = 'This file is too large for the current processing worker. It requires a heavy media worker.';

    public function currentProfileName(): string
    {
        $name = trim((string) config('asset_processing.worker_profile', 'normal'));

        return $name !== '' ? $name : 'normal';
    }

    /**
     * @return array<string, mixed>
     */
    public function currentProfileLimits(): array
    {
        $profiles = (array) config('asset_processing.profiles', []);
        $name = $this->currentProfileName();

        return (array) ($profiles[$name] ?? $profiles['normal'] ?? []);
    }

    /**
     * @return array<string, mixed>
     */
    public function heavyProfileLimits(): array
    {
        $profiles = (array) config('asset_processing.profiles', []);

        return (array) ($profiles['heavy'] ?? []);
    }

    /**
     * @param  array{file_size_bytes?: int, mime_type?: string|null}  $hints
     */
    public function classify(Asset $asset, ?AssetVersion $version = null, array $hints = []): ProcessingBudgetDecision
    {
        $profile = $this->currentProfileLimits();
        $profileName = $this->currentProfileName();
        $heavy = $this->heavyProfileLimits();

        $mime = $hints['mime_type'] ?? ($version?->mime_type ?? $asset->mime_type);
        $mime = $mime !== null ? strtolower(trim((string) $mime)) : null;
        $ext = strtolower(pathinfo((string) ($asset->original_filename ?? ''), PATHINFO_EXTENSION));

        $fileSizeBytes = (int) ($hints['file_size_bytes'] ?? $this->resolveFileSizeBytes($asset, $version));
        $isPsdLike = PipelineQueueResolver::isPsdLike($mime, $asset->original_filename);

        $fileTypeService = app(FileTypeService::class);
        $fileType = $fileTypeService->detectFileType($mime, $ext);

        $width = (int) ($version?->width ?? $asset->width ?? 0);
        $height = (int) ($version?->height ?? $asset->height ?? 0);
        $pixels = ($width > 0 && $height > 0) ? $width * $height : null;

        $maxPixels = (int) ($profile['max_pixels'] ?? 120_000_000);
        $allowHugePsd = (bool) ($profile['allow_huge_psd'] ?? false);

        if ($pixels !== null && $pixels > $maxPixels) {
            if ($isPsdLike && $allowHugePsd) {
                // PSD "huge composite" allowed on this profile when configured
            } else {
                return new ProcessingBudgetDecision(
                    ProcessingBudgetDecision::FAIL_PIXEL_LIMIT_EXCEEDED,
                    'pixel_limit_exceeded',
                    'This image exceeds the maximum pixel dimensions allowed on the current processing worker.',
                    $profileName,
                    $fileSizeBytes,
                    null,
                    $mime,
                );
            }
        }

        $limitBytes = $this->sizeLimitBytesForKind($profile, $fileType, $isPsdLike);
        $heavyLimitBytes = $this->sizeLimitBytesForKind($heavy, $fileType, $isPsdLike);

        if ($fileSizeBytes > 0 && $limitBytes !== null && $fileSizeBytes > $limitBytes) {
            if ($profileName === 'heavy' || $heavyLimitBytes === null || $fileSizeBytes > $heavyLimitBytes) {
                return new ProcessingBudgetDecision(
                    ProcessingBudgetDecision::FAIL_FILE_TOO_LARGE,
                    'file_exceeds_worker_limits',
                    self::DEFAULT_DEFER_MESSAGE,
                    $profileName,
                    $fileSizeBytes,
                    $limitBytes,
                    $mime,
                );
            }

            return new ProcessingBudgetDecision(
                ProcessingBudgetDecision::DEFER_TO_HEAVY_WORKER,
                'deferred_to_heavy_worker',
                self::DEFAULT_DEFER_MESSAGE,
                $profileName,
                $fileSizeBytes,
                $limitBytes,
                $mime,
            );
        }

        return ProcessingBudgetDecision::allowed($profileName, $fileSizeBytes, $mime);
    }

    /**
     * @param  array{file_size_bytes?: int, mime_type?: string|null}  $hints
     */
    public function canGenerateThumbnails(Asset $asset, ?AssetVersion $version = null, array $hints = []): bool
    {
        return $this->classify($asset, $version, $hints)->isAllowed();
    }

    /**
     * @param  array{file_size_bytes?: int, mime_type?: string|null}  $hints
     */
    public function canGeneratePreview(Asset $asset, ?AssetVersion $version = null, array $hints = []): bool
    {
        return $this->classify($asset, $version, $hints)->isAllowed();
    }

    /**
     * @param  array{file_size_bytes?: int, mime_type?: string|null}  $hints
     */
    public function canRunPsdPipeline(Asset $asset, ?AssetVersion $version = null, array $hints = []): bool
    {
        $mime = $hints['mime_type'] ?? ($version?->mime_type ?? $asset->mime_type);
        if (! PipelineQueueResolver::isPsdLike($mime, $asset->original_filename)) {
            return true;
        }

        return $this->classify($asset, $version, $hints)->isAllowed();
    }

    /**
     * @param  array{file_size_bytes?: int, mime_type?: string|null}  $hints
     */
    public function shouldDeferToHeavyWorker(Asset $asset, ?AssetVersion $version = null, array $hints = []): bool
    {
        return $this->classify($asset, $version, $hints)->shouldDeferToHeavyWorker();
    }

    public function failureCode(ProcessingBudgetDecision $decision): ?string
    {
        return $decision->failureCode();
    }

    public function humanMessage(ProcessingBudgetDecision $decision): string
    {
        $m = $decision->humanMessage();

        return $m !== '' ? $m : self::DEFAULT_DEFER_MESSAGE;
    }

    /**
     * @return array{should_dispatch: bool, target_queue: string|null}
     */
    public function heavyQueueRedispatchPlan(Asset $asset, ?AssetVersion $version, ProcessingBudgetDecision $decision, int $fileSizeBytes, ?string $mimeType): array
    {
        if (! $decision->shouldDeferToHeavyWorker()) {
            return ['should_dispatch' => false, 'target_queue' => null];
        }
        if (! (bool) config('asset_processing.defer_heavy_to_queue', false)) {
            return ['should_dispatch' => false, 'target_queue' => null];
        }

        $mime = $mimeType ?? ($version?->mime_type ?? $asset->mime_type);
        $target = PipelineQueueResolver::forPipeline(
            $fileSizeBytes,
            $mime,
            (string) ($asset->original_filename ?? '')
        );

        return ['should_dispatch' => true, 'target_queue' => $target];
    }

    public function logGuardrail(
        Asset $asset,
        ?AssetVersion $version,
        ProcessingBudgetDecision $decision,
        string $source,
    ): void {
        $limitBytes = $decision->configuredLimitBytes();
        $fileMb = $decision->fileSizeBytes > 0 ? round($decision->fileSizeBytes / 1024 / 1024, 2) : null;
        $limitMb = $limitBytes !== null && $limitBytes > 0 ? round($limitBytes / 1024 / 1024, 2) : null;

        Log::info('['.self::GUARDRAIL_LOG_KEY.']', [
            'asset_id' => $asset->id,
            'asset_version_id' => $version?->id,
            'decision' => $decision->kind,
            'code' => $decision->failureCode(),
            'worker_profile' => $decision->workerProfile,
            'file_size_mb' => $fileMb,
            'limit_mb' => $limitMb,
            'mime_type' => $decision->mimeType,
            'source' => $source,
        ]);
    }

    /**
     * @return array<string, int|string|bool|null>
     */
    public function guardrailMetadataPayload(ProcessingBudgetDecision $decision): array
    {
        $code = $decision->failureCode() ?? 'file_exceeds_worker_limits';

        return [
            'worker_processing_code' => $code,
            'worker_processing_message' => $this->humanMessage($decision),
            'worker_profile' => $decision->workerProfile,
            'worker_processing_file_size_bytes' => $decision->fileSizeBytes,
            'worker_processing_limit_bytes' => $decision->configuredLimitBytes(),
        ];
    }

    protected function resolveFileSizeBytes(Asset $asset, ?AssetVersion $version): int
    {
        $fromVersion = (int) ($version?->file_size ?? 0);
        $fromAsset = (int) ($asset->size_bytes ?? 0);

        return max($fromVersion, $fromAsset);
    }

    /**
     * @param  array<string, mixed>  $profileRow
     */
    protected function sizeLimitBytesForKind(array $profileRow, ?string $fileType, bool $isPsdLike): ?int
    {
        if ($isPsdLike) {
            return $this->mbToBytes($profileRow['max_psd_mb'] ?? null);
        }
        if ($fileType === 'video') {
            return $this->mbToBytes($profileRow['max_video_mb'] ?? null);
        }
        if ($fileType === 'pdf') {
            return $this->mbToBytes($profileRow['max_pdf_mb'] ?? null);
        }

        return $this->mbToBytes($profileRow['max_image_mb'] ?? null);
    }

    protected function mbToBytes(mixed $mb): ?int
    {
        if ($mb === null) {
            return null;
        }
        $n = (float) $mb;
        if ($n <= 0) {
            return null;
        }

        return (int) round($n * 1024 * 1024);
    }
}
