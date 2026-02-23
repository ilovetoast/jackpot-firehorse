<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\EventType;
use App\Enums\ThumbnailStatus;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateAssetEmbeddingJob;
use App\Jobs\GenerateThumbnailsJob;
use App\Jobs\PopulateAutomaticMetadataJob;
use App\Jobs\ProcessAssetJob;
use App\Jobs\PromoteAssetJob;
use App\Models\ActivityEvent;
use App\Models\AssetEmbedding;
use App\Models\BrandComplianceScore;
use App\Models\Asset;
use App\Models\Category;
use App\Models\SystemIncident;
use App\Models\Tenant;
use App\Models\Brand;
use App\Services\Assets\AssetStateReconciliationService;
use App\Services\AssetUrlService;
use App\Services\Reliability\ReliabilityEngine;
use App\Services\SystemIncidentRecoveryService;
use App\Services\TenantBucketService;
use App\Services\ThumbnailRetryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Enterprise Asset Operations Console.
 *
 * Cross-tenant, cross-brand admin tooling for support, engineering, and system recovery.
 * NOT tenant-scoped.
 */
class AdminAssetController extends Controller
{
    public function __construct(
        protected AssetStateReconciliationService $reconciliationService,
        protected SystemIncidentRecoveryService $recoveryService,
        protected ReliabilityEngine $reliabilityEngine,
        protected ThumbnailRetryService $thumbnailRetryService,
        protected AssetUrlService $assetUrlService
    ) {}

    protected function authorizeAdmin(): void
    {
        $user = Auth::user();
        if (!$user) {
            abort(403);
        }
        $siteRoles = $user->getSiteRoles();
        $isSiteOwner = $user->id === 1;
        $isSiteAdmin = in_array('site_admin', $siteRoles) || in_array('site_owner', $siteRoles);
        $isSiteEngineering = in_array('site_engineering', $siteRoles);
        $canRegenerate = $user->can('assets.regenerate_thumbnails_admin');

        if (!$isSiteOwner && !$isSiteAdmin && !$isSiteEngineering && !$canRegenerate) {
            abort(403, 'Only site owners, site admins, site engineering, or users with assets.regenerate_thumbnails_admin can access this page.');
        }
    }

    protected function canDestructive(): bool
    {
        $user = Auth::user();
        $siteRoles = $user->getSiteRoles();
        return $user->id === 1
            || in_array('site_owner', $siteRoles)
            || in_array('site_engineering', $siteRoles);
    }

    /**
     * GET /app/admin/assets - Index with filters.
     */
    public function index(Request $request): Response
    {
        $this->authorizeAdmin();

        Log::info('[AdminAssets] Index request', [
            'filters' => $request->only(['tenant_id', 'brand_id', 'per_page', 'sort', 'sort_direction']),
        ]);

        $filters = $this->parseFilters($request);
        $query = $this->buildQuery($filters);

        $sortColumn = $this->resolveSortColumn($filters['sort'] ?? 'created_at');
        $sortDirection = in_array(strtolower($filters['sort_direction'] ?? 'desc'), ['asc', 'desc'], true)
            ? strtolower($filters['sort_direction'])
            : 'desc';

        $perPage = min((int) $request->get('per_page', 25), 100);
        $assets = $query
            ->with(['tenant:id,name,slug,uuid', 'brand:id,name', 'user:id,first_name,last_name,email'])
            ->orderBy($sortColumn, $sortDirection)
            ->paginate($perPage)
            ->withQueryString();

        // Admin multi-tenant CDN: pass tenant UUIDs so middleware can issue scoped cookies for each
        $tenantUuids = $assets->getCollection()
            ->pluck('tenant.uuid')
            ->filter()
            ->unique()
            ->values()
            ->all();
        $request->attributes->set('admin_tenants', $tenantUuids);

        $formatted = $assets->getCollection()->map(function ($a) {
            try {
                return $this->formatAssetForList($a);
            } catch (\Throwable $e) {
                Log::error('[AdminAssets] formatAssetForList failed for asset', [
                    'asset_id' => $a->id,
                    'tenant_id' => $a->tenant_id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                return $this->formatAssetForListFallback($a);
            }
        });

        Log::info('[AdminAssets] Index response', [
            'assets_count' => $formatted->count(),
            'tenant_uuid_count' => count($tenantUuids),
        ]);

        $filterOptions = [
            'tenants' => Tenant::select('id', 'name', 'slug')->orderBy('name')->get(),
            'brands' => Brand::select('id', 'name', 'tenant_id')->orderBy('name')->get(),
        ];

        // Assets without category_id disappear from grid — show warning and recovery option
        $assetsWithoutCategoryCount = Asset::query()
            ->whereNotNull('metadata')
            ->whereNull('deleted_at')
            ->whereRaw('(JSON_UNQUOTE(JSON_EXTRACT(metadata, "$.category_id")) IS NULL OR JSON_UNQUOTE(JSON_EXTRACT(metadata, "$.category_id")) = "" OR JSON_UNQUOTE(JSON_EXTRACT(metadata, "$.category_id")) = "null")')
            ->count();

        $categoriesForRecovery = [];
        if ($assetsWithoutCategoryCount > 0) {
            $brandIdsWithAffected = Asset::query()
                ->whereNotNull('metadata')
                ->whereNull('deleted_at')
                ->whereRaw('(JSON_UNQUOTE(JSON_EXTRACT(metadata, "$.category_id")) IS NULL OR JSON_UNQUOTE(JSON_EXTRACT(metadata, "$.category_id")) = "" OR JSON_UNQUOTE(JSON_EXTRACT(metadata, "$.category_id")) = "null")')
                ->distinct()
                ->pluck('brand_id');
            $categoriesForRecovery = Category::query()
                ->whereIn('brand_id', $brandIdsWithAffected)
                ->where('asset_type', AssetType::ASSET)
                ->with('brand:id,name')
                ->orderBy('brand_id')
                ->orderBy('name')
                ->get(['id', 'name', 'brand_id'])
                ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'brand_id' => $c->brand_id, 'brand_name' => $c->brand?->name ?? '—'])
                ->values()
                ->all();
        }

        return Inertia::render('Admin/Assets/Index', [
            'assets' => $formatted,
            'pagination' => $assets->toArray(),
            'totalMatching' => $assets->total(),
            'filters' => $filters,
            'filterOptions' => $filterOptions,
            'canDestructive' => $this->canDestructive(),
            'assetsWithoutCategoryCount' => $assetsWithoutCategoryCount,
            'categoriesForRecovery' => $categoriesForRecovery,
        ]);
    }

    /**
     * GET /app/admin/assets/{asset} - Single asset detail (for modal).
     */
    public function show(Request $request, string $asset): JsonResponse
    {
        $this->authorizeAdmin();

        $asset = Asset::withTrashed()->findOrFail($asset);
        $asset->load([
            'tenant:id,name,slug,uuid',
            'brand:id,name',
            'user:id,first_name,last_name,email',
        ]);

        // Admin multi-tenant CDN: pass tenant UUID so middleware can issue scoped cookie
        if ($asset->tenant?->uuid) {
            $request->attributes->set('admin_tenants', [$asset->tenant->uuid]);
        }

        $incidents = SystemIncident::where('source_type', 'asset')
            ->where('source_id', $asset->id)
            ->whereNull('resolved_at')
            ->orderBy('detected_at', 'desc')
            ->get()
            ->map(fn ($i) => [
                'id' => $i->id,
                'severity' => $i->severity,
                'title' => $i->title,
                'message' => $i->message,
                'detected_at' => $i->detected_at?->toIso8601String(),
            ]);

        $metadata = $asset->metadata ?? [];
        $visibility = app(\App\Services\AssetVisibilityService::class)->getVisibilityDetail($asset);
        // Infer thumbnails_generated when flag missing (legacy/race): thumbnail_status=completed + thumbnails/thumbnail_dimensions
        $thumbnailsGenerated = (bool) ($metadata['thumbnails_generated'] ?? false);
        if (!$thumbnailsGenerated && $asset->thumbnail_status === \App\Enums\ThumbnailStatus::COMPLETED) {
            $thumbnailsGenerated = !empty($metadata['thumbnails_generated_at'])
                || !empty($metadata['thumbnails'])
                || !empty($metadata['thumbnail_dimensions']['medium'] ?? []);
        }
        $pipelineFlags = [
            'visible_in_grid' => $visibility['visible'],
            'processing_failed' => (bool) ($metadata['processing_failed'] ?? false),
            'pipeline_completed' => (bool) ($metadata['pipeline_completed_at'] ?? false),
            'metadata_extracted' => (bool) ($metadata['metadata_extracted'] ?? false),
            'thumbnails_generated' => $thumbnailsGenerated,
            'thumbnail_timeout' => (bool) ($metadata['thumbnail_timeout'] ?? false),
            'stuck_state_detected' => ($asset->analysis_status ?? '') === 'uploading' && !empty($metadata['metadata_extracted']),
            'auto_recover_attempted' => (bool) ($metadata['auto_recover_attempted'] ?? false),
        ];

        $assetIdStr = (string) $asset->id;
        $failedJobs = \DB::table('failed_jobs')
            ->where('payload', 'like', '%' . $assetIdStr . '%')
            ->orderByDesc('failed_at')
            ->limit(20)
            ->get(['id', 'uuid', 'queue', 'payload', 'exception', 'failed_at'])
            ->filter(fn ($j) => str_contains($j->payload ?? '', $assetIdStr))
            ->values()
            ->map(fn ($j) => [
                'id' => $j->id,
                'uuid' => $j->uuid,
                'queue' => $j->queue,
                'failed_at' => $j->failed_at ? \Carbon\Carbon::parse($j->failed_at)->toIso8601String() : null,
                'exception_preview' => \Str::limit($j->exception, 300),
                'exception_full' => $j->exception,
            ])
            ->all();

        // Always load versions for admin operations (debugging/troubleshooting regardless of plan)
        $versions = $asset->versions()
            ->with('uploadedBy:id,first_name,last_name')
            ->orderByDesc('version_number')
            ->limit(50)
            ->get()
            ->map(fn ($v) => [
                'id' => $v->id,
                'version_number' => $v->version_number,
                'is_current' => $v->is_current,
                'file_size' => $v->file_size,
                'mime_type' => $v->mime_type,
                'uploaded_by' => $v->uploadedBy ? ['id' => $v->uploadedBy->id, 'name' => $v->uploadedBy->name] : null,
                'created_at' => $v->created_at?->toIso8601String(),
                'pipeline_status' => $v->pipeline_status,
                'storage_class' => $v->storage_class,
                'change_note' => $v->change_note,
                'restored_from_version_id' => $v->restored_from_version_id,
            ])
            ->values()
            ->all();

        $planAllowsVersions = $asset->tenant && app(\App\Services\PlanService::class)->planAllowsVersions($asset->tenant);

        return response()->json([
            'asset' => $this->formatAssetForDetail($asset),
            'incidents' => $incidents,
            'pipeline_flags' => $pipelineFlags,
            'failed_jobs' => $failedJobs,
            'versions' => $versions,
            'plan_allows_versions' => $planAllowsVersions,
        ]);
    }

    /**
     * POST /app/admin/assets/{asset}/versions/{version}/restore
     * Admin-only version restore (bypasses tenant policy).
     */
    public function restoreVersion(string $assetId, string $versionId): JsonResponse
    {
        $this->authorizeAdmin();

        $asset = Asset::withTrashed()->findOrFail($assetId);
        $version = \Illuminate\Support\Str::isUuid($versionId)
            ? \App\Models\AssetVersion::where('asset_id', $asset->id)->where('id', $versionId)->firstOrFail()
            : \App\Models\AssetVersion::where('asset_id', $asset->id)->where('version_number', (int) $versionId)->firstOrFail();

        if (!app(\App\Services\PlanService::class)->planAllowsVersions($asset->tenant)) {
            return response()->json(['error' => 'Versioning not enabled for this tenant.'], 403);
        }

        $archived = in_array($version->storage_class ?? '', ['GLACIER', 'DEEP_ARCHIVE', 'GLACIER_IR'], true);
        if ($archived) {
            return response()->json(['error' => 'This version is archived in Glacier and must be restored before use.'], 400);
        }

        $service = app(\App\Services\AssetVersionRestoreService::class);
        $newVersion = $service->restore($asset, $version, true, false, (string) Auth::id());

        return response()->json(['success' => true, 'new_version_id' => $newVersion->id]);
    }

    /**
     * POST /app/admin/assets/bulk-action
     */
    public function bulkAction(Request $request): JsonResponse
    {
        $this->authorizeAdmin();

        $action = $request->input('action');
        $assetIds = $request->input('asset_ids', []);
        $selectAllMatching = (bool) $request->input('select_all_matching', false);
        $requestFilters = $request->input('filters', []);

        $validActions = [
            'restore', 'retry_pipeline', 'regenerate_thumbnails', 'rerun_metadata', 'rerun_ai_tagging',
            'publish', 'unpublish', 'archive', 'clear_thumbnail_timeout', 'clear_promotion_failed', 'reconcile', 'create_ticket', 'export_ids',
        ];
        if ($this->canDestructive()) {
            $validActions[] = 'delete';
        }

        if (!in_array($action, $validActions, true)) {
            return response()->json(['error' => 'Invalid action'], 400);
        }

        if ($selectAllMatching) {
            $filters = is_array($requestFilters) ? $requestFilters : [];
            $query = $this->buildQuery($filters);
            $assetIds = $query->pluck('id')->toArray();
        }

        if (empty($assetIds) && $action !== 'export_ids') {
            return response()->json(['error' => 'No assets selected'], 400);
        }

        if ($action === 'export_ids') {
            return response()->json([
                'asset_ids' => $assetIds,
                'count' => count($assetIds),
            ]);
        }

        $results = [];
        foreach ($assetIds as $id) {
            $asset = Asset::withTrashed()->find($id);
            if (!$asset) {
                $results[] = ['id' => $id, 'ok' => false, 'error' => 'Asset not found'];
                continue;
            }
            try {
                $this->executeBulkAction($action, $asset);
                $results[] = ['id' => $id, 'ok' => true];
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('[AdminAssetController] bulk action failed', [
                    'action' => $action,
                    'asset_id' => $id,
                    'error' => $e->getMessage(),
                ]);
                $results[] = ['id' => $id, 'ok' => false, 'error' => $e->getMessage()];
            }
        }

        $successCount = count(array_filter($results, fn ($r) => $r['ok']));
        return response()->json([
            'results' => $results,
            'success_count' => $successCount,
            'failed_count' => count($results) - $successCount,
        ]);
    }

    /**
     * POST /app/admin/assets/{asset}/repair
     */
    public function repair(string $asset): JsonResponse
    {
        $this->authorizeAdmin();

        $asset = Asset::withTrashed()->findOrFail($asset);
        $result = $this->reconciliationService->reconcile($asset);
        $incident = SystemIncident::where('source_type', 'asset')->where('source_id', $asset->id)->whereNull('resolved_at')->first();
        if ($incident) {
            $repairResult = $this->recoveryService->attemptRepair($incident);
            $result['resolved'] = $repairResult['resolved'] ?? false;
        }

        return response()->json([
            'updated' => $result['updated'] ?? false,
            'changes' => $result['changes'] ?? [],
            'resolved' => $result['resolved'] ?? false,
        ]);
    }

    /**
     * POST /app/admin/assets/recover-category-id
     *
     * Assign category_id to assets that have null (they disappear from grid).
     * Only updates assets in the same brand as the chosen category.
     */
    public function recoverCategoryId(Request $request): JsonResponse
    {
        $this->authorizeAdmin();

        $categoryId = (int) $request->input('category_id');
        if (!$categoryId) {
            return response()->json(['error' => 'category_id is required'], 422);
        }

        $category = Category::with('brand')->find($categoryId);
        if (!$category) {
            return response()->json(['error' => 'Category not found'], 404);
        }

        $query = Asset::query()
            ->whereNotNull('metadata')
            ->whereNull('deleted_at')
            ->where('brand_id', $category->brand_id)
            ->whereRaw('(JSON_UNQUOTE(JSON_EXTRACT(metadata, "$.category_id")) IS NULL OR JSON_UNQUOTE(JSON_EXTRACT(metadata, "$.category_id")) = "" OR JSON_UNQUOTE(JSON_EXTRACT(metadata, "$.category_id")) = "null")');

        $assets = $query->limit(1000)->get();
        $updated = 0;
        foreach ($assets as $asset) {
            $meta = $asset->metadata ?? [];
            $meta['category_id'] = $categoryId;
            $asset->update(['metadata' => $meta]);
            $updated++;
        }

        return response()->json([
            'updated' => $updated,
            'category' => ['id' => $category->id, 'name' => $category->name],
            'message' => "Assigned category \"{$category->name}\" to {$updated} asset(s).",
        ]);
    }

    /**
     * GET /app/admin/assets/{asset}/download-source
     *
     * Admin-only download of the source file. Streams from S3 through the backend
     * to avoid cross-tenant IAM issues with presigned URLs.
     */
    public function downloadSource(string $asset): HttpResponse
    {
        $this->authorizeAdmin();

        $model = Asset::withTrashed()->with(['storageBucket', 'tenant'])->findOrFail($asset);
        if (!$model->storage_root_path) {
            abort(404, 'Source file path not available.');
        }

        $bucketService = app(TenantBucketService::class);
        $bucket = $model->storageBucket
            ?? $bucketService->resolveActiveBucketOrFail($model->tenant);
        $s3Client = $bucketService->getS3Client();

        try {
            $result = $s3Client->getObject([
                'Bucket' => $bucket->name,
                'Key' => $model->storage_root_path,
            ]);
        } catch (\Aws\S3\Exception\S3Exception $e) {
            if ($e->getStatusCode() === 404) {
                abort(404, 'Source file not found in storage. It may have been deleted or never promoted.');
            }
            throw $e;
        }

        $filename = $model->original_filename ?? basename($model->storage_root_path);
        $contentType = $result['ContentType'] ?? 'application/octet-stream';
        $contentLength = $result['ContentLength'] ?? 0;
        $disposition = 'attachment; filename="' . addcslashes($filename, '"\\') . '"';

        return response()->stream(function () use ($result) {
            $body = $result['Body'];
            while (!$body->eof()) {
                echo $body->read(8192);
                flush();
            }
        }, 200, [
            'Content-Type' => $contentType,
            'Content-Length' => $contentLength,
            'Content-Disposition' => $disposition,
            'Cache-Control' => 'private, no-cache',
        ]);
    }

    /**
     * POST /app/admin/assets/{asset}/restore
     */
    public function restore(string $asset): JsonResponse
    {
        $this->authorizeAdmin();

        $model = Asset::withTrashed()->findOrFail($asset);
        $model->restore();

        return response()->json(['restored' => true]);
    }

    /**
     * POST /app/admin/assets/{asset}/retry-pipeline
     *
     * Clears blocking flags so ProcessAssetJob can run again, then dispatches the full pipeline.
     * Handles assets that failed mid-pipeline (e.g. PopulateAutomaticMetadataJob) or were
     * skipped for formats now supported (e.g. SVG, TIFF, AVIF).
     */
    public function retryPipeline(string $asset): JsonResponse
    {
        $this->authorizeAdmin();

        $asset = Asset::withTrashed()->findOrFail($asset);
        $canRetry = $this->thumbnailRetryService->canRetry($asset);
        if (!$canRetry['allowed']) {
            return response()->json(['error' => $canRetry['reason'] ?? 'Retry not allowed'], 400);
        }

        $metadata = $asset->metadata ?? [];

        // Clear blocking flags so ProcessAssetJob will run (it skips if processing_started)
        unset($metadata['processing_started']);
        unset($metadata['processing_started_at']);

        // Clear old skip reasons for formats now supported (SVG, TIFF, AVIF, PSD)
        $skipReason = $metadata['thumbnail_skip_reason'] ?? null;
        $mimeType = strtolower($asset->mime_type ?? '');
        $extension = strtolower(pathinfo($asset->original_filename ?? '', PATHINFO_EXTENSION));
        $isNowSupported = $skipReason === 'unsupported_format:tiff' && ($mimeType === 'image/tiff' || $mimeType === 'image/tif' || $extension === 'tiff' || $extension === 'tif') && extension_loaded('imagick')
            || $skipReason === 'unsupported_format:avif' && ($mimeType === 'image/avif' || $extension === 'avif') && extension_loaded('imagick')
            || ($skipReason === 'unsupported_format:psd' || $skipReason === 'unsupported_file_type') && ($mimeType === 'image/vnd.adobe.photoshop' || $extension === 'psd' || $extension === 'psb') && extension_loaded('imagick')
            || $skipReason === 'unsupported_format:svg' && ($mimeType === 'image/svg+xml' || $extension === 'svg');
        if ($isNowSupported) {
            unset($metadata['thumbnail_skip_reason']);
        }

        // Clear failure metadata so pipeline can proceed
        unset($metadata['processing_failed']);
        unset($metadata['failure_reason']);
        unset($metadata['failed_job']);
        unset($metadata['failure_attempts']);
        unset($metadata['failure_is_retryable']);
        unset($metadata['failed_at']);

        $updateData = [
            'metadata' => $metadata,
            'analysis_status' => 'uploading',
        ];

        // Restore visibility if asset was marked FAILED by pipeline failure
        if ($asset->status === AssetStatus::FAILED) {
            $updateData['status'] = AssetStatus::VISIBLE;
        }

        // Reset SKIPPED thumbnail status to PENDING so GenerateThumbnailsJob will run
        if ($asset->thumbnail_status === ThumbnailStatus::SKIPPED) {
            $updateData['thumbnail_status'] = ThumbnailStatus::PENDING;
            $updateData['thumbnail_error'] = null;
        }

        $asset->update($updateData);

        ProcessAssetJob::dispatch($asset->id);

        return response()->json(['dispatched' => true]);
    }

    /**
     * POST /app/admin/assets/{asset}/reanalyze
     *
     * Re-run analysis (thumbnails, metadata, embedding) to fix incomplete brand data.
     * Dispatches: GenerateThumbnailsJob -> PopulateAutomaticMetadataJob -> GenerateAssetEmbeddingJob.
     */
    public function reanalyze(string $assetId): JsonResponse
    {
        $this->authorizeAdmin();

        $asset = Asset::withTrashed()->findOrFail($assetId);

        BrandComplianceScore::where('asset_id', $asset->id)->where('brand_id', $asset->brand_id)->delete();
        $asset->update([
            'analysis_status' => 'generating_thumbnails',
            'thumbnail_status' => ThumbnailStatus::PENDING,
            'thumbnail_error' => null,
        ]);

        AssetEmbedding::where('asset_id', $asset->id)->delete();

        Bus::chain([
            new GenerateThumbnailsJob($asset->id),
            new PopulateAutomaticMetadataJob($asset->id),
            new GenerateAssetEmbeddingJob($asset->id),
        ])->dispatch();

        ActivityEvent::create([
            'tenant_id' => $asset->tenant_id,
            'brand_id' => $asset->brand_id,
            'event_type' => EventType::ASSET_ANALYSIS_RERUN_REQUESTED,
            'subject_type' => Asset::class,
            'subject_id' => $asset->id,
            'actor_type' => 'user',
            'actor_id' => Auth::id(),
            'metadata' => null,
            'created_at' => now(),
        ]);

        return response()->json(['status' => 'queued']);
    }

    /**
     * POST /app/admin/assets/{asset}/clear-promotion-failed
     *
     * Clears promotion_failed flag so asset no longer appears in "Assets with Processing Issues".
     */
    public function clearPromotionFailed(string $assetId): JsonResponse
    {
        $this->authorizeAdmin();

        $asset = Asset::withTrashed()->findOrFail($assetId);

        $meta = $asset->metadata ?? [];
        unset($meta['promotion_failed'], $meta['promotion_failed_at'], $meta['promotion_error']);
        $asset->update([
            'metadata' => $meta,
            'analysis_status' => 'complete',
        ]);

        return response()->json(['cleared' => true]);
    }

    protected function parseFilters(Request $request): array
    {
        $search = trim($request->get('search', ''));
        $parsed = [
            'search' => $search,
            'sort' => $request->filled('sort') ? trim($request->sort) : 'created_at',
            'sort_direction' => in_array(strtolower($request->get('sort_direction', 'desc')), ['asc', 'desc'], true)
                ? strtolower($request->get('sort_direction', 'desc'))
                : 'desc',
            'asset_id' => $request->filled('asset_id') ? trim($request->asset_id) : null,
            'tenant_id' => $request->filled('tenant_id') ? (int) $request->tenant_id : null,
            'brand_id' => $request->filled('brand_id') ? (int) $request->brand_id : null,
            'category_id' => $request->filled('category_id') ? (int) $request->category_id : null,
            'created_by' => $request->filled('created_by') ? (int) $request->created_by : null,
            'tag' => $request->filled('tag') ? trim($request->tag) : null,
            'status' => $request->filled('status') ? trim($request->status) : null,
            'asset_type' => $request->filled('asset_type') ? trim($request->asset_type) : null,
            'visible_in_grid' => $this->parseBoolParam($request, 'visible_in_grid'),
            'analysis_status' => $request->filled('analysis_status') ? trim($request->analysis_status) : null,
            'thumbnail_status' => $request->filled('thumbnail_status') ? trim($request->thumbnail_status) : null,
            'has_incident' => $request->has('has_incident') ? (bool) $request->has_incident : null,
            'deleted' => $this->parseBoolParam($request, 'deleted'),
            'date_from' => $request->filled('date_from') ? trim($request->date_from) : null,
            'date_to' => $request->filled('date_to') ? trim($request->date_to) : null,
        ];

        if ($search !== '') {
            $this->parseSmartFilter($search, $parsed);
        }

        return $parsed;
    }

    protected function parseBoolParam(Request $request, string $key): ?bool
    {
        if (!$request->has($key)) {
            return null;
        }
        $val = $request->get($key);
        if (is_bool($val)) {
            return $val;
        }
        if (in_array(strtolower((string) $val), ['true', '1', 'yes'], true)) {
            return true;
        }
        if (in_array(strtolower((string) $val), ['false', '0', 'no'], true)) {
            return false;
        }
        return null;
    }

    protected function parseSmartFilter(string $search, array &$parsed): void
    {
        $patterns = [
            '/tenant:(\d+)/i' => 'tenant_id',
            '/brand:(\d+)/i' => 'brand_id',
            '/brand:([a-z0-9_-]+)/i' => 'brand_slug',
            '/status:(\w+)/i' => 'status',
            '/type:(asset|deliverable|ai_generated|execution|generative)/i' => 'asset_type',
            '/analysis:(\w+)/i' => 'analysis_status',
            '/thumb:(\w+)/i' => 'thumbnail_status',
            '/incident:(true|false|1|0)/i' => 'has_incident',
            '/visible:(true|false|1|0)/i' => 'visible_in_grid',
            '/tag:([a-z0-9_-]+)/i' => 'tag',
            '/category:(\w+)/i' => 'category_slug',
            '/user:(\d+)/i' => 'created_by',
            '/dead:(true|1)/i' => 'storage_missing',
            '/deleted:(true|false|1|0)/i' => 'deleted',
        ];

        foreach ($patterns as $regex => $key) {
            if (preg_match($regex, $search, $m)) {
                $val = $m[1];
                if ($key === 'has_incident') {
                    $parsed[$key] = in_array(strtolower($val), ['true', '1']);
                } elseif ($key === 'deleted') {
                    $parsed[$key] = in_array(strtolower($val), ['true', '1']) ? true : (in_array(strtolower($val), ['false', '0']) ? false : null);
                } elseif ($key === 'storage_missing') {
                    $parsed[$key] = true;
                } elseif ($key === 'brand_slug' || $key === 'category_slug') {
                    $parsed[$key] = $val;
                } elseif ($key === 'asset_type') {
                    $parsed[$key] = match (strtolower($val)) {
                        'execution' => 'deliverable',
                        'generative' => 'ai_generated',
                        'asset', 'basic' => 'asset',
                        default => $val,
                    };
                } elseif ($key === 'visible_in_grid') {
                    $parsed[$key] = in_array(strtolower($val), ['true', '1']) ? true : (in_array(strtolower($val), ['false', '0']) ? false : $parsed[$key] ?? null);
                } else {
                    $parsed[$key] = is_numeric($val) ? (int) $val : $val;
                }
            }
        }
    }

    protected function resolveSortColumn(?string $sort): string
    {
        $allowed = [
            'created_at' => 'assets.created_at',
            'filename' => 'assets.original_filename',
            'title' => 'assets.title',
            'analysis_status' => 'assets.analysis_status',
            'thumbnail_status' => 'assets.thumbnail_status',
        ];

        return $allowed[$sort] ?? 'assets.created_at';
    }

    protected function buildQuery(array $filters): \Illuminate\Database\Eloquent\Builder
    {
        $query = Asset::query()->withTrashed();

        if (!empty($filters['asset_id'])) {
            $query->where('assets.id', $filters['asset_id']);
        }

        if (!empty($filters['search'])) {
            $q = $filters['search'];
            $like = DB::getDriverName() === 'pgsql' ? 'ilike' : 'like';
            $pattern = '%' . addcslashes($q, '%_\\') . '%';
            if (preg_match('/^[0-9a-f]{8}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{12}$/i', str_replace('-', '', $q))) {
                $query->where('assets.id', $q);
            } else {
                $query->where(function ($qb) use ($pattern, $like) {
                    $qb->where('assets.id', $like, $pattern)
                        ->orWhere('original_filename', $like, $pattern)
                        ->orWhere('title', $like, $pattern)
                        ->orWhere('storage_root_path', $like, $pattern)
                        ->orWhereExists(function ($sub) use ($pattern, $like) {
                            $sub->select(DB::raw(1))
                                ->from('asset_tags')
                                ->whereColumn('asset_tags.asset_id', 'assets.id')
                                ->where('asset_tags.tag', $like, $pattern);
                        });
                });
            }
        }

        if (!empty($filters['tenant_id'])) {
            $query->where('tenant_id', $filters['tenant_id']);
        }
        if (!empty($filters['brand_id'])) {
            $query->where('brand_id', $filters['brand_id']);
        }
        if (!empty($filters['brand_slug'])) {
            $query->whereHas('brand', fn ($q) => $q->where('name', 'like', '%' . $filters['brand_slug'] . '%'));
        }
        if (!empty($filters['category_id'])) {
            $query->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.category_id')) = ?", [(string) $filters['category_id']]);
        }
        if (!empty($filters['created_by'])) {
            $query->where('user_id', $filters['created_by']);
        }
        if (!empty($filters['tag'])) {
            $query->whereExists(function ($sub) use ($filters) {
                $sub->select(DB::raw(1))
                    ->from('asset_tags')
                    ->whereColumn('asset_tags.asset_id', 'assets.id')
                    ->where('asset_tags.tag', $filters['tag']);
            });
        }
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['asset_type'])) {
            $query->where('type', $filters['asset_type']);
        }
        if (($filters['visible_in_grid'] ?? null) === true) {
            $query->visibleInGrid();
        } elseif (($filters['visible_in_grid'] ?? null) === false) {
            $query->notVisibleInGrid();
        }
        if (!empty($filters['analysis_status'])) {
            $query->where('analysis_status', $filters['analysis_status']);
        }
        if (!empty($filters['thumbnail_status'])) {
            $query->where('thumbnail_status', $filters['thumbnail_status']);
        }
        if (($filters['storage_missing'] ?? null) === true) {
            $query->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.storage_missing')) IN ('true', '1')");
        }
        if ($filters['has_incident'] === true) {
            $query->whereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('system_incidents')
                    ->whereColumn('system_incidents.source_id', 'assets.id')
                    ->where('system_incidents.source_type', 'asset')
                    ->whereNull('system_incidents.resolved_at');
            });
        }
        if (!empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }
        if (($filters['deleted'] ?? null) === true || $filters['deleted'] === '1') {
            $query->whereNotNull('deleted_at');
        } elseif (($filters['deleted'] ?? null) === false || $filters['deleted'] === '0') {
            $query->whereNull('deleted_at');
        }

        return $query;
    }

    protected function executeBulkAction(string $action, Asset $asset): void
    {
        switch ($action) {
            case 'restore':
                $asset->restore();
                break;
            case 'retry_pipeline':
                ProcessAssetJob::dispatch($asset->id);
                break;
            case 'regenerate_thumbnails':
                \App\Jobs\GenerateThumbnailsJob::dispatch($asset->id);
                break;
            case 'rerun_metadata':
                \App\Jobs\ExtractMetadataJob::dispatch($asset->id);
                break;
            case 'rerun_ai_tagging':
                \App\Jobs\AITaggingJob::dispatch($asset->id);
                break;
            case 'publish':
                app(\App\Services\AssetPublicationService::class)->publish($asset);
                break;
            case 'unpublish':
                app(\App\Services\AssetPublicationService::class)->unpublish($asset);
                break;
            case 'archive':
                app(\App\Services\AssetArchiveService::class)->archive($asset, Auth::user());
                break;
            case 'clear_thumbnail_timeout':
                $meta = $asset->metadata ?? [];
                unset($meta['thumbnail_timeout']);
                $asset->update(['metadata' => $meta]);
                break;
            case 'clear_promotion_failed':
                $meta = $asset->metadata ?? [];
                unset($meta['promotion_failed'], $meta['promotion_failed_at'], $meta['promotion_error']);
                $asset->update([
                    'metadata' => $meta,
                    'analysis_status' => 'complete',
                ]);
                break;
            case 'reconcile':
                $this->reconciliationService->reconcile($asset);
                break;
            case 'create_ticket':
                $incident = SystemIncident::where('source_type', 'asset')->where('source_id', $asset->id)->whereNull('resolved_at')->first();
                if ($incident) {
                    $this->recoveryService->createTicket($incident);
                } else {
                    $this->reliabilityEngine->report([
                        'source_type' => 'asset',
                        'source_id' => $asset->id,
                        'tenant_id' => $asset->tenant_id,
                        'severity' => 'warning',
                        'title' => 'Asset stuck in processing',
                        'retryable' => true,
                        'unique_signature' => "admin_asset_ticket:{$asset->id}",
                    ]);
                    $incident = SystemIncident::where('source_type', 'asset')->where('source_id', $asset->id)->whereNull('resolved_at')->first();
                    if ($incident) {
                        $this->recoveryService->createTicket($incident);
                    }
                }
                break;
            case 'delete':
                if (!$this->canDestructive()) {
                    throw new \RuntimeException('Not authorized for delete');
                }
                $asset->forceDelete();
                break;
            case 'export_ids':
                // No-op for bulk; handled client-side
                break;
        }
    }

    /**
     * Admin grid: signed CloudFront URL for thumbnail (no cookies). Redis cache 240s;
     * cache TTL must be shorter than signed URL TTL (config cloudfront.admin_signed_url_ttl, default 300).
     * No ?v= query param — signed URLs are short-lived and unique; adding ?v= would require
     * including it in the URL before signing or CloudFront returns 403.
     */
    protected function adminThumbnailSignedUrl(Asset $asset): ?string
    {
        $path = $this->assetUrlService->getAdminThumbnailPath($asset);
        if (! $path) {
            return null;
        }

        try {
            $cacheKey = 'admin:signed_url:' . $asset->id . ':' . ($asset->updated_at?->timestamp ?? 0);

            $signedUrl = Cache::remember($cacheKey, 240, function () use ($path) {
                return $this->assetUrlService->getSignedCloudFrontUrl($path);
            });

            return $signedUrl;
        } catch (\Throwable $e) {
            Log::warning('[AdminAssets] Failed to generate signed thumbnail URL', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    protected function formatAssetForList(Asset $asset): array
    {
        $metadata = $asset->metadata ?? [];
        $incidentCount = SystemIncident::where('source_type', 'asset')
            ->where('source_id', $asset->id)
            ->whereNull('resolved_at')
            ->count();

        $type = $asset->type ?? null;
        $assetTypeLabel = $type ? match ($type) {
            AssetType::ASSET => 'Asset',
            AssetType::DELIVERABLE => 'Execution',
            AssetType::AI_GENERATED => 'Generative',
            default => $type->value,
        } : '—';

        return [
            'id' => $asset->id,
            'id_short' => substr($asset->id, 0, 12),
            'original_filename' => $asset->original_filename ?? $asset->title,
            'title' => $asset->title,
            'tenant' => $asset->tenant ? ['id' => $asset->tenant->id, 'name' => $asset->tenant->name] : null,
            'brand' => $asset->brand ? ['id' => $asset->brand->id, 'name' => $asset->brand->name] : null,
            'asset_type' => ['value' => $type?->value ?? null, 'label' => $assetTypeLabel],
            'category_id' => $metadata['category_id'] ?? null,
            'analysis_status' => $asset->analysis_status ?? 'unknown',
            'thumbnail_status' => $asset->thumbnail_status?->value ?? 'unknown',
            'storage_missing' => $asset->isStorageMissing(),
            'incident_count' => $incidentCount,
            'created_by' => $asset->user ? ['id' => $asset->user->id, 'name' => $asset->user->first_name . ' ' . $asset->user->last_name] : null,
            'created_at' => $asset->created_at?->toIso8601String(),
            'deleted_at' => $asset->deleted_at?->toIso8601String(),
            'admin_thumbnail_url' => $this->adminThumbnailSignedUrl($asset),
        ];
    }

    /**
     * Fallback when formatAssetForList throws (e.g. AssetUrlService failure). Same shape, thumbnail_url = null.
     */
    protected function formatAssetForListFallback(Asset $asset): array
    {
        $metadata = $asset->metadata ?? [];
        $incidentCount = SystemIncident::where('source_type', 'asset')
            ->where('source_id', $asset->id)
            ->whereNull('resolved_at')
            ->count();

        $type = $asset->type ?? null;
        $assetTypeLabel = $type ? match ($type) {
            AssetType::ASSET => 'Asset',
            AssetType::DELIVERABLE => 'Execution',
            AssetType::AI_GENERATED => 'Generative',
            default => $type->value,
        } : '—';

        return [
            'id' => $asset->id,
            'id_short' => substr($asset->id, 0, 12),
            'original_filename' => $asset->original_filename ?? $asset->title,
            'title' => $asset->title,
            'tenant' => $asset->tenant ? ['id' => $asset->tenant->id, 'name' => $asset->tenant->name] : null,
            'brand' => $asset->brand ? ['id' => $asset->brand->id, 'name' => $asset->brand->name] : null,
            'asset_type' => ['value' => $type?->value ?? null, 'label' => $assetTypeLabel],
            'category_id' => $metadata['category_id'] ?? null,
            'analysis_status' => $asset->analysis_status ?? 'unknown',
            'thumbnail_status' => $asset->thumbnail_status?->value ?? 'unknown',
            'storage_missing' => $asset->isStorageMissing(),
            'incident_count' => $incidentCount,
            'created_by' => $asset->user ? ['id' => $asset->user->id, 'name' => $asset->user->first_name . ' ' . $asset->user->last_name] : null,
            'created_at' => $asset->created_at?->toIso8601String(),
            'deleted_at' => $asset->deleted_at?->toIso8601String(),
            'admin_thumbnail_url' => null,
        ];
    }

    protected function formatAssetForDetail(Asset $asset): array
    {
        $list = $this->formatAssetForList($asset);
        $metadata = $asset->metadata ?? [];
        $list['metadata'] = $metadata;
        $list['storage_root_path'] = $asset->storage_root_path;
        $list['storage_bucket_id'] = $asset->storage_bucket_id;
        $list['thumbnail_error'] = $asset->thumbnail_error;
        $list['thumbnail_view_urls'] = $this->adminThumbnailViewUrls($asset);
        $list['admin_download_url'] = $this->assetUrlService->getAdminDownloadUrl($asset);

        // Resolve category_id to category name for Overview display
        $categoryId = $metadata['category_id'] ?? null;
        if ($categoryId) {
            $category = Category::find($categoryId);
            $list['category'] = $category ? ['id' => $category->id, 'name' => $category->name] : null;
        } else {
            $list['category'] = null;
        }

        // Visibility in asset grid + recommended fix when not visible
        $list['visibility'] = app(\App\Services\AssetVisibilityService::class)->getVisibilityDetail($asset);

        return $list;
    }

    /**
     * URLs for viewing thumb, medium, large thumbnails in new window (admin only).
     * Only includes styles that exist when thumbnail_status is completed.
     */
    protected function adminThumbnailViewUrls(Asset $asset): array
    {
        $urls = [];

        foreach (['thumb', 'medium', 'large'] as $style) {
            $url = $this->assetUrlService->getAdminThumbnailUrlForStyle($asset, $style);
            if ($url) {
                $urls[$style] = $url;
            }
        }

        return $urls;
    }
}
