<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Collection;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Metadata-only upload preflight: plan, storage, MIME, dangerous types, collection gates.
 * Does not create assets, S3 sessions, or consume AI credits.
 */
class UploadPreflightService
{
    public const CACHE_PREFIX = 'upload_preflight:';

    public const CACHE_TTL_SECONDS = 7200;

    /** Blocked as potentially executable / installer payloads (DAM policy). */
    private const DANGEROUS_EXTENSIONS = [
        'exe', 'bat', 'cmd', 'com', 'pif', 'scr', 'vbs', 'msi', 'dll', 'app', 'reg',
        'ps1', 'deb', 'rpm', 'sh', 'bash', 'cpl', 'lnk', 'gadget', 'msp', 'hta',
    ];

    public function __construct(
        protected PlanService $planService,
        protected FileTypeService $fileTypeService,
        protected FeatureGate $featureGate,
        protected AiUsageService $aiUsageService,
    ) {}

    /**
     * Max files per preflight / validate / initiate-batch (see config/assets.php).
     */
    public static function maxFilesPerBatch(): int
    {
        return max(1, min(10000, (int) config('assets.upload_max_files_per_batch', 500)));
    }

    /**
     * @param  array<int, array<string, mixed>>  $files
     * @param  array<int>|null  $collectionIds
     * @return array<string, mixed>
     */
    public function evaluate(
        Tenant $tenant,
        User $user,
        Brand $brand,
        ?Category $category,
        ?array $collectionIds,
        array $files,
        bool $assumeAutoAiMetadata,
    ): array {
        $preflightId = (string) Str::uuid();
        $maxUploadBytes = $this->planService->getMaxUploadSize($tenant);
        $storageInfo = $this->planService->getStorageInfo($tenant);
        $currentUsage = (int) ($storageInfo['current_usage_bytes'] ?? 0);
        $maxStorage = (int) ($storageInfo['max_storage_bytes'] ?? 0);

        $batchLevelReject = null;
        if (! $this->featureGate->canUploadAssets($tenant)) {
            $batchLevelReject = [
                'code' => 'email_verification_required',
                'message' => 'The workspace owner must verify their email before anyone can upload files on this plan.',
            ];
        } elseif ($this->planService->isBrandDisabledByPlanLimit($brand, $tenant)) {
            $batchLevelReject = [
                'code' => 'brand_plan_limit',
                'message' => 'This brand is over your plan’s brand limit and cannot accept uploads until you upgrade or free a slot.',
            ];
        } else {
            $collectionIds = array_values(array_filter(array_map('intval', $collectionIds ?? [])));
            foreach ($collectionIds as $collectionId) {
                $collection = Collection::query()
                    ->where('id', $collectionId)
                    ->where('brand_id', $brand->id)
                    ->where('tenant_id', $tenant->id)
                    ->first();
                if (! $collection) {
                    $batchLevelReject = [
                        'code' => 'collection_not_found',
                        'message' => "Collection {$collectionId} was not found for this brand.",
                    ];
                    break;
                }
                if (! Gate::forUser($user)->allows('addAsset', $collection)) {
                    $batchLevelReject = [
                        'code' => 'collection_upload_denied',
                        'message' => 'You do not have permission to add assets to one of the selected collections.',
                    ];
                    break;
                }
            }
        }

        $aiStatus = $this->aiUsageService->getUsageStatus($tenant);
        $aiCap = (int) ($aiStatus['credits_cap'] ?? 0);
        $aiRemaining = $aiStatus['credits_remaining'];
        $lowAiCredits = $assumeAutoAiMetadata
            && $aiCap > 0
            && is_int($aiRemaining)
            && $aiRemaining < max(10, (int) ceil($aiCap * 0.05));

        $accepted = [];
        $rejected = [];
        $warnings = [];
        $runningStorageTotal = 0;

        if ($batchLevelReject !== null) {
            foreach ($files as $row) {
                $rejected[] = $this->rejectedRow($row, [$batchLevelReject]);
            }
        } else {
            $seenNames = [];
            foreach ($files as $row) {
                $clientId = (string) ($row['client_file_id'] ?? '');
                $name = (string) ($row['name'] ?? '');
                $size = (int) ($row['size'] ?? 0);
                $mime = isset($row['mime_type']) ? strtolower((string) $row['mime_type']) : '';
                $ext = isset($row['extension']) ? strtolower(ltrim((string) $row['extension'], '.')) : '';
                if ($ext === '' && $name !== '') {
                    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                }

                $reasons = [];
                $fileWarnings = [];

                if ($clientId === '' || ! Str::isUuid($clientId)) {
                    $reasons[] = ['code' => 'invalid_client_file_id', 'message' => 'Each file must include a valid client_file_id (UUID).'];
                }
                if ($name === '' || strlen($name) > 255) {
                    $reasons[] = ['code' => 'invalid_name', 'message' => 'File name is required and must be at most 255 characters.'];
                }
                if ($size < 1) {
                    $reasons[] = ['code' => 'invalid_size', 'message' => 'File size must be at least 1 byte.'];
                }
                if ($size > $maxUploadBytes) {
                    $reasons[] = [
                        'code' => 'file_size_limit',
                        'message' => 'This file exceeds the maximum upload size for your plan.',
                    ];
                }

                $danger = $ext !== '' && in_array($ext, self::DANGEROUS_EXTENSIONS, true);
                if ($danger) {
                    $reasons[] = [
                        'code' => 'dangerous_file_type',
                        'message' => 'This file type cannot be uploaded for security reasons.',
                    ];
                }

                $unsupported = $this->fileTypeService->getUnsupportedReason($mime ?: null, $ext ?: null);
                if ($unsupported && ! empty($unsupported['disable_upload_reason'])) {
                    $reasons[] = [
                        'code' => 'unsupported_type',
                        'message' => (string) $unsupported['disable_upload_reason'],
                    ];
                } elseif (! $this->fileTypeService->isSupported($mime ?: null, $ext ?: null)) {
                    $reasons[] = [
                        'code' => 'unsupported_type',
                        'message' => 'This file type is not supported for upload.',
                    ];
                }

                $detected = $this->fileTypeService->detectFileType($mime ?: null, $ext ?: null);
                if ($detected) {
                    $req = $this->fileTypeService->checkRequirements($detected);
                    if (! $req['met']) {
                        $fileWarnings[] = [
                            'code' => 'processing_requirements',
                            'message' => 'Server may process this type with reduced quality until optional components are installed: '.implode(', ', $req['missing']),
                        ];
                    }
                }

                $normKey = strtolower($name);
                if ($normKey !== '' && isset($seenNames[$normKey])) {
                    $fileWarnings[] = [
                        'code' => 'duplicate_filename',
                        'message' => 'Another file in this batch uses the same name; check you intended both.',
                    ];
                }
                $seenNames[$normKey] = true;

                if ($reasons === [] && ($currentUsage + $runningStorageTotal + $size) > $maxStorage) {
                    $reasons[] = [
                        'code' => 'storage_limit',
                        'message' => 'Not enough storage remaining for this file (including earlier files in the batch).',
                    ];
                }

                if ($reasons !== []) {
                    $rejected[] = $this->rejectedRow($row, $reasons);

                    continue;
                }

                $runningStorageTotal += $size;
                $acceptedRow = [
                    'client_file_id' => $clientId,
                    'name' => $name,
                    'size' => $size,
                    'mime_type' => $mime,
                    'extension' => $ext,
                    'last_modified' => $row['last_modified'] ?? null,
                    'relative_path' => $row['relative_path'] ?? null,
                ];
                $accepted[] = $acceptedRow;

                if ($fileWarnings !== []) {
                    $warnings[] = array_merge($acceptedRow, ['warnings' => $fileWarnings]);
                }
            }
        }

        if ($lowAiCredits && count($accepted) > 0) {
            $warnings[] = [
                'client_file_id' => null,
                'name' => null,
                'size' => null,
                'mime_type' => null,
                'extension' => null,
                'warnings' => [[
                    'code' => 'ai_credits_low',
                    'message' => 'Monthly AI credits are running low. Uploads will still proceed; some automatic AI metadata steps may be skipped or fail if credits run out.',
                ]],
            ];
        }

        $acceptedBytes = array_sum(array_column($accepted, 'size'));
        $remainingAfter = max(0, $maxStorage - $currentUsage - $acceptedBytes);

        $rejectedCountsByCode = [];
        foreach ($rejected as $rej) {
            $first = is_array($rej['reasons'] ?? null) ? ($rej['reasons'][0] ?? null) : null;
            $primary = is_array($first) ? (string) ($first['code'] ?? 'unknown') : 'unknown';
            $rejectedCountsByCode[$primary] = ($rejectedCountsByCode[$primary] ?? 0) + 1;
        }

        $cacheAccepted = [];
        foreach ($accepted as $a) {
            $cacheAccepted[$a['client_file_id']] = [
                'file_name' => $a['name'],
                'file_size' => $a['size'],
                'mime_type' => $a['mime_type'] ?: null,
            ];
        }

        Cache::put(
            self::CACHE_PREFIX.$preflightId,
            [
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'brand_id' => $brand->id,
                'accepted' => $cacheAccepted,
                'created_at' => now()->toIso8601String(),
            ],
            self::CACHE_TTL_SECONDS
        );

        $batchSummary = [
            'total_submitted' => count($files),
            'accepted_count' => count($accepted),
            'rejected_count' => count($rejected),
            'warning_rows_count' => count($warnings),
            'total_accepted_bytes' => $acceptedBytes,
            'storage_remaining_bytes_after_accepted' => $remainingAfter,
            'max_files_per_batch' => self::maxFilesPerBatch(),
            'max_upload_bytes' => $maxUploadBytes,
            'rejected_counts_by_code' => $rejectedCountsByCode,
        ];

        return [
            'preflight_id' => $preflightId,
            /** @deprecated Use preflight_id; kept for clients expecting a single batch id */
            'upload_session_id' => $preflightId,
            'accepted' => $accepted,
            'rejected' => $rejected,
            'warnings' => $warnings,
            'batch_summary' => $batchSummary,
            'storage' => $storageInfo,
        ];
    }

    /**
     * @return array{tenant_id: int, user_id: int, brand_id: int, accepted: array<string, array<string, mixed>>, created_at: string}|null
     */
    public function getCachedPayload(string $preflightId): ?array
    {
        $data = Cache::get(self::CACHE_PREFIX.$preflightId);

        return is_array($data) ? $data : null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, array{code: string, message: string}>  $reasons
     * @return array<string, mixed>
     */
    private function rejectedRow(array $row, array $reasons): array
    {
        return [
            'client_file_id' => $row['client_file_id'] ?? null,
            'name' => $row['name'] ?? null,
            'size' => $row['size'] ?? null,
            'mime_type' => $row['mime_type'] ?? null,
            'extension' => $row['extension'] ?? null,
            'reasons' => $reasons,
        ];
    }
}
