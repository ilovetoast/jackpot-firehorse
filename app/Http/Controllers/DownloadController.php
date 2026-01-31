<?php

namespace App\Http\Controllers;

use App\Enums\DownloadAccessMode;
use App\Enums\DownloadSource;
use App\Enums\DownloadStatus;
use App\Enums\DownloadType;
use App\Enums\ZipStatus;
use App\Jobs\BuildDownloadZipJob;
use App\Models\Asset;
use App\Models\Download;
use App\Services\DownloadBucketService;
use App\Services\DownloadEventEmitter;
use App\Services\DownloadExpirationPolicy;
use App\Services\PlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 🔒 Phase 3.1 — Downloader System (LOCKED)
 * Phase D1 — Secure Asset Downloader (Foundation): index, store, public download route.
 */
class DownloadController extends Controller
{
    public function __construct(
        protected DownloadBucketService $bucket,
        protected PlanService $planService,
        protected DownloadExpirationPolicy $expirationPolicy
    ) {}

    /**
     * Show the downloads page (My Downloads). Phase D1.
     */
    public function index(): Response
    {
        $user = Auth::user();
        $tenant = app('tenant');

        if (! $tenant) {
            return Inertia::render('Downloads/Index', ['downloads' => [], 'bucket_count' => 0]);
        }

        $downloads = Download::query()
            ->where('tenant_id', $tenant->id)
            ->where('created_by_user_id', $user->id)
            ->with(['assets' => fn ($q) => $q->select('assets.id', 'assets.original_filename', 'assets.metadata')])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Download $d) => $this->mapDownloadForHistory($d));

        $bucketCount = $this->bucket->count();

        return Inertia::render('Downloads/Index', [
            'downloads' => $downloads,
            'bucket_count' => $bucketCount,
        ]);
    }

    /**
     * Create a download from the current bucket. Phase D1.
     * Validates plan limits, creates Download record, attaches assets, dispatches job, clears bucket.
     */
    public function store(Request $request): JsonResponse
    {
        $user = Auth::user();
        $tenant = app('tenant');
        $brand = app('brand');

        if (! $tenant) {
            return response()->json(['message' => 'No company selected.'], 422);
        }

        $source = $request->input('source', DownloadSource::GRID->value);
        if (! in_array($source, ['grid', 'drawer', 'collection'], true)) {
            $source = DownloadSource::GRID->value;
        }

        $visibleIds = $this->bucket->visibleItems();
        if (empty($visibleIds)) {
            return response()->json(['message' => 'Add at least one asset to the download bucket.'], 422);
        }

        $maxAssets = $this->planService->getMaxDownloadAssets($tenant);
        if (count($visibleIds) > $maxAssets) {
            return response()->json([
                'message' => "This plan allows up to {$maxAssets} assets per download.",
            ], 422);
        }

        $assets = Asset::query()->whereIn('id', $visibleIds)->get();
        $estimatedBytes = $assets->sum(fn (Asset $a) => (int) ($a->metadata['file_size'] ?? $a->metadata['size'] ?? 0));
        $maxZipBytes = $this->planService->getMaxDownloadZipBytes($tenant);
        if ($estimatedBytes > $maxZipBytes) {
            return response()->json([
                'message' => 'Estimated ZIP size exceeds your plan limit.',
            ], 422);
        }

        $context = $brand ? 'brand' : 'collection';
        $expiresAt = $this->expirationPolicy->calculateExpiresAt($tenant, DownloadType::SNAPSHOT);
        $hardDeleteAt = $expiresAt ? $this->expirationPolicy->calculateHardDeleteAt(
            (new Download)->setRelation('tenant', $tenant),
            $expiresAt
        ) : null;

        $slug = $this->uniqueSlug($tenant->id);

        DB::beginTransaction();
        try {
            $download = Download::create([
                'tenant_id' => $tenant->id,
                'brand_id' => $brand?->id,
                'created_by_user_id' => $user->id,
                'download_type' => DownloadType::SNAPSHOT,
                'source' => $source,
                'title' => null,
                'slug' => $slug,
                'version' => 1,
                'status' => DownloadStatus::READY,
                'zip_status' => ZipStatus::NONE,
                'expires_at' => $expiresAt,
                'hard_delete_at' => $hardDeleteAt,
                'download_options' => ['context' => $context],
                'access_mode' => DownloadAccessMode::PUBLIC,
                'allow_reshare' => true,
            ]);

            foreach ($visibleIds as $i => $assetId) {
                $download->assets()->attach($assetId, ['is_primary' => $i === 0]);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[DownloadController] Failed to create download', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['message' => 'Failed to create download.'], 500);
        }

        $this->bucket->clear();

        BuildDownloadZipJob::dispatch($download->id);

        // Inertia requests (e.g. from DownloadBucketBar) must receive a redirect, not plain JSON
        if ($request->header('X-Inertia')) {
            return redirect()->route('downloads.index');
        }

        $publicUrl = route('downloads.public', ['download' => $download->id]);

        return response()->json([
            'download_id' => $download->id,
            'public_url' => $publicUrl,
            'expires_at' => $expiresAt?->toIso8601String(),
            'asset_count' => count($visibleIds),
        ]);
    }

    private function uniqueSlug(int $tenantId): string
    {
        do {
            $slug = Str::lower(Str::random(12));
        } while (Download::where('tenant_id', $tenantId)->where('slug', $slug)->exists());

        return $slug;
    }

    private function mapDownloadForHistory(Download $d): array
    {
        $thumbnails = $d->assets->take(12)->map(function (Asset $a) {
            $metadata = $a->metadata ?? [];
            $thumbnailStatus = $a->thumbnail_status instanceof \App\Enums\ThumbnailStatus
                ? $a->thumbnail_status->value
                : ($a->thumbnail_status ?? 'pending');

            // Match AssetController / DownloadBucketController: preview when preview exists, final when completed
            $previewThumbnailUrl = null;
            $previewThumbnails = $metadata['preview_thumbnails'] ?? [];
            if (! empty($previewThumbnails) && isset($previewThumbnails['preview'])) {
                $previewThumbnailUrl = route('assets.thumbnail.preview', [
                    'asset' => $a->id,
                    'style' => 'preview',
                ]);
            }

            $finalThumbnailUrl = null;
            if ($thumbnailStatus === 'completed') {
                $thumbnailVersion = $metadata['thumbnails_generated_at'] ?? null;
                $finalThumbnailUrl = route('assets.thumbnail.final', [
                    'asset' => $a->id,
                    'style' => 'thumb',
                ]);
                if ($thumbnailVersion) {
                    $finalThumbnailUrl .= '?v=' . urlencode($thumbnailVersion);
                }
            }

            $thumbnailUrl = $finalThumbnailUrl ?? $previewThumbnailUrl;

            return [
                'id' => $a->id,
                'thumbnail_url' => $thumbnailUrl,
                'original_filename' => $a->original_filename,
            ];
        })->all();

        return [
            'id' => $d->id,
            'slug' => $d->slug,
            'status' => $d->status->value,
            'zip_status' => $d->zip_status->value,
            'expires_at' => $d->expires_at?->toIso8601String(),
            'asset_count' => $d->assets->count(),
            'zip_size_bytes' => $d->zip_size_bytes,
            'public_url' => route('downloads.public', ['download' => $d->id]),
            'created_at' => $d->created_at->toIso8601String(),
            'thumbnails' => $thumbnails,
        ];
    }

    /**
     * Download a ZIP file from a download group.
     * 
     * GET /downloads/{download}/download
     * 
     * Responsibilities:
     * - Validate download exists and is READY
     * - Validate access (public link OR authenticated user with permission)
     * - Generate short-lived S3 signed URL (5-15 minutes)
     * - Redirect user to S3 URL (do NOT proxy file)
     * - Update last_accessed_at (if desired)
     * - Do NOT increment analytics yet (log intent only)
     * 
     * @param Download $download
     * @return RedirectResponse|JsonResponse
     */
    public function download(Download $download): RedirectResponse|JsonResponse
    {
        // Verify download exists and is accessible
        if ($download->trashed()) {
            return response()->json([
                'message' => 'Download not found',
            ], 404);
        }

        // For non-public downloads, verify tenant scope (Phase D1: only resolve tenant when needed)
        if ($download->access_mode !== DownloadAccessMode::PUBLIC) {
            $tenant = app()->bound('tenant') ? app('tenant') : null;
            if (! $tenant || $download->tenant_id !== $tenant->id) {
                return response()->json([
                    'message' => 'Download not found',
                ], 404);
            }
        }

        // Validate download status
        if ($download->status !== DownloadStatus::READY) {
            return response()->json([
                'message' => $this->getStatusErrorMessage($download->status),
            ], 422);
        }

        // Validate ZIP status
        if ($download->zip_status !== ZipStatus::READY) {
            return response()->json([
                'message' => $this->getZipStatusErrorMessage($download->zip_status),
            ], 422);
        }

        // Validate ZIP path exists
        if (!$download->zip_path) {
            Log::error('[DownloadController] Download ZIP path is missing', [
                'download_id' => $download->id,
            ]);
            return response()->json([
                'message' => 'ZIP file not available',
            ], 404);
        }

        // Validate access
        $accessAllowed = $this->validateAccess($download);
        if (!$accessAllowed) {
            return response()->json([
                'message' => 'Access denied',
            ], 403);
        }

        // Check if download is expired (Phase 2.8: also check if assets are archived)
        if ($download->isExpired()) {
            return response()->json([
                'message' => 'This download has expired',
            ], 410);
        }

        try {
            // Generate signed S3 URL (short-lived: 10 minutes)
            $disk = Storage::disk('s3');
            $signedUrl = $disk->temporaryUrl(
                $download->zip_path,
                now()->addMinutes(10)
            );

            // Phase 3.1 Step 5: Emit download ZIP requested event
            DownloadEventEmitter::emitDownloadZipRequested($download);

            // Best-effort: Emit completed event (we can't track actual completion from S3)
            DownloadEventEmitter::emitDownloadZipCompleted($download);

            // TODO: Update last_accessed_at field if it exists in schema
            // $download->update(['last_accessed_at' => now()]);

            // Redirect to signed URL
            return redirect($signedUrl);
        } catch (\Exception $e) {
            Log::error('[DownloadController] Failed to generate download URL', [
                'download_id' => $download->id,
                'zip_path' => $download->zip_path,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to generate download URL',
            ], 500);
        }
    }

    /**
     * Validate access to download based on access_mode.
     * 
     * @param Download $download
     * @return bool True if access is allowed
     */
    protected function validateAccess(Download $download): bool
    {
        $user = auth()->user();

        switch ($download->access_mode) {
            case DownloadAccessMode::PUBLIC:
                // Public access - anyone with the link can access
                return true;

            case DownloadAccessMode::TEAM:
                // Team access - only authenticated users who are members of the tenant
                if (!$user) {
                    return false;
                }

                // Verify user belongs to the download's tenant
                $tenant = app('tenant');
                return $tenant && $download->tenant_id === $tenant->id;

            case DownloadAccessMode::RESTRICTED:
                // Restricted access - only specific users (future implementation)
                // For now, treat as team access
                if (!$user) {
                    return false;
                }

                $tenant = app('tenant');
                return $tenant && $download->tenant_id === $tenant->id;

            default:
                Log::warning('[DownloadController] Unknown access mode', [
                    'download_id' => $download->id,
                    'access_mode' => $download->access_mode?->value ?? 'null',
                ]);
                return false;
        }
    }

    /**
     * Get error message for download status.
     * 
     * @param DownloadStatus $status
     * @return string
     */
    protected function getStatusErrorMessage(DownloadStatus $status): string
    {
        return match ($status) {
            DownloadStatus::PENDING => 'Download is not ready yet',
            DownloadStatus::INVALIDATED => 'Download has been invalidated',
            DownloadStatus::FAILED => 'Download failed',
            DownloadStatus::READY => 'Download is ready', // Should not reach here
        };
    }

    /**
     * Get error message for ZIP status.
     * 
     * @param ZipStatus $zipStatus
     * @return string
     */
    protected function getZipStatusErrorMessage(ZipStatus $zipStatus): string
    {
        return match ($zipStatus) {
            ZipStatus::NONE => 'ZIP file has not been generated yet',
            ZipStatus::BUILDING => 'ZIP file is being built. Please try again in a few moments',
            ZipStatus::INVALIDATED => 'ZIP file needs to be regenerated',
            ZipStatus::FAILED => 'ZIP file generation failed',
            ZipStatus::READY => 'ZIP file is ready', // Should not reach here
        };
    }
}
