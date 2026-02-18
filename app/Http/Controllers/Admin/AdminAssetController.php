<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AssetStatus;
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
use App\Models\SystemIncident;
use App\Models\Tenant;
use App\Models\Brand;
use App\Services\Assets\AssetStateReconciliationService;
use App\Services\Reliability\ReliabilityEngine;
use App\Services\SystemIncidentRecoveryService;
use App\Services\ThumbnailRetryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
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
        protected ThumbnailRetryService $thumbnailRetryService
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

        $filters = $this->parseFilters($request);
        $query = $this->buildQuery($filters);

        $perPage = min((int) $request->get('per_page', 25), 100);
        $assets = $query
            ->with(['tenant:id,name,slug', 'brand:id,name', 'user:id,first_name,last_name,email'])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage)
            ->withQueryString();

        $formatted = $assets->getCollection()->map(fn ($a) => $this->formatAssetForList($a));

        $filterOptions = [
            'tenants' => Tenant::select('id', 'name', 'slug')->orderBy('name')->get(),
            'brands' => Brand::select('id', 'name', 'tenant_id')->orderBy('name')->get(),
        ];

        return Inertia::render('Admin/Assets/Index', [
            'assets' => $formatted,
            'pagination' => $assets->toArray(),
            'totalMatching' => $assets->total(),
            'filters' => $filters,
            'filterOptions' => $filterOptions,
            'canDestructive' => $this->canDestructive(),
        ]);
    }

    /**
     * GET /app/admin/assets/{asset} - Single asset detail (for modal).
     */
    public function show(string $asset): JsonResponse
    {
        $this->authorizeAdmin();

        $asset = Asset::withTrashed()->findOrFail($asset);
        $asset->load([
            'tenant:id,name,slug',
            'brand:id,name',
            'user:id,first_name,last_name,email',
        ]);

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
        $pipelineFlags = [
            'pipeline_completed' => (bool) ($metadata['pipeline_completed_at'] ?? false),
            'metadata_extracted' => (bool) ($metadata['metadata_extracted'] ?? false),
            'thumbnails_generated' => (bool) ($metadata['thumbnails_generated'] ?? false),
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

        return response()->json([
            'asset' => $this->formatAssetForDetail($asset),
            'incidents' => $incidents,
            'pipeline_flags' => $pipelineFlags,
            'failed_jobs' => $failedJobs,
        ]);
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
            'publish', 'unpublish', 'archive', 'clear_thumbnail_timeout', 'reconcile', 'create_ticket', 'export_ids',
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

    protected function parseFilters(Request $request): array
    {
        $search = trim($request->get('search', ''));
        $parsed = [
            'search' => $search,
            'tenant_id' => $request->filled('tenant_id') ? (int) $request->tenant_id : null,
            'brand_id' => $request->filled('brand_id') ? (int) $request->brand_id : null,
            'category_id' => $request->filled('category_id') ? (int) $request->category_id : null,
            'created_by' => $request->filled('created_by') ? (int) $request->created_by : null,
            'tag' => $request->filled('tag') ? trim($request->tag) : null,
            'status' => $request->filled('status') ? trim($request->status) : null,
            'analysis_status' => $request->filled('analysis_status') ? trim($request->analysis_status) : null,
            'thumbnail_status' => $request->filled('thumbnail_status') ? trim($request->thumbnail_status) : null,
            'has_incident' => $request->has('has_incident') ? (bool) $request->has_incident : null,
            'date_from' => $request->filled('date_from') ? trim($request->date_from) : null,
            'date_to' => $request->filled('date_to') ? trim($request->date_to) : null,
        ];

        if ($search !== '') {
            $this->parseSmartFilter($search, $parsed);
        }

        return $parsed;
    }

    protected function parseSmartFilter(string $search, array &$parsed): void
    {
        $patterns = [
            '/tenant:(\d+)/i' => 'tenant_id',
            '/brand:(\d+)/i' => 'brand_id',
            '/brand:([a-z0-9_-]+)/i' => 'brand_slug',
            '/status:(\w+)/i' => 'status',
            '/analysis:(\w+)/i' => 'analysis_status',
            '/thumb:(\w+)/i' => 'thumbnail_status',
            '/incident:(true|false|1|0)/i' => 'has_incident',
            '/tag:([a-z0-9_-]+)/i' => 'tag',
            '/category:(\w+)/i' => 'category_slug',
            '/user:(\d+)/i' => 'created_by',
        ];

        foreach ($patterns as $regex => $key) {
            if (preg_match($regex, $search, $m)) {
                $val = $m[1];
                if ($key === 'has_incident') {
                    $parsed[$key] = in_array(strtolower($val), ['true', '1']);
                } elseif ($key === 'brand_slug' || $key === 'category_slug') {
                    $parsed[$key] = $val;
                } else {
                    $parsed[$key] = is_numeric($val) ? (int) $val : $val;
                }
            }
        }
    }

    protected function buildQuery(array $filters): \Illuminate\Database\Eloquent\Builder
    {
        $query = Asset::query()->withTrashed();

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
        if (!empty($filters['analysis_status'])) {
            $query->where('analysis_status', $filters['analysis_status']);
        }
        if (!empty($filters['thumbnail_status'])) {
            $query->where('thumbnail_status', $filters['thumbnail_status']);
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

    protected function adminThumbnailUrl(Asset $asset): ?string
    {
        // Prefer completed thumbnail when available
        if ($asset->thumbnail_status?->value === 'completed' && $asset->thumbnailPathForStyle('medium')) {
            return route('admin.assets.thumbnail', ['asset' => $asset->id]);
        }
        // Fallback: show preview when main thumbnail skipped/failed but preview exists
        $metadata = $asset->metadata ?? [];
        $previewPath = $metadata['preview_thumbnails']['preview']['path'] ?? null;
        if ($previewPath) {
            return route('admin.assets.thumbnail', ['asset' => $asset->id]);
        }
        return null;
    }

    protected function formatAssetForList(Asset $asset): array
    {
        $metadata = $asset->metadata ?? [];
        $incidentCount = SystemIncident::where('source_type', 'asset')
            ->where('source_id', $asset->id)
            ->whereNull('resolved_at')
            ->count();

        return [
            'id' => $asset->id,
            'id_short' => substr($asset->id, 0, 12),
            'original_filename' => $asset->original_filename ?? $asset->title,
            'title' => $asset->title,
            'tenant' => $asset->tenant ? ['id' => $asset->tenant->id, 'name' => $asset->tenant->name] : null,
            'brand' => $asset->brand ? ['id' => $asset->brand->id, 'name' => $asset->brand->name] : null,
            'category_id' => $metadata['category_id'] ?? null,
            'analysis_status' => $asset->analysis_status ?? 'unknown',
            'thumbnail_status' => $asset->thumbnail_status?->value ?? 'unknown',
            'incident_count' => $incidentCount,
            'created_by' => $asset->user ? ['id' => $asset->user->id, 'name' => $asset->user->first_name . ' ' . $asset->user->last_name] : null,
            'created_at' => $asset->created_at?->toIso8601String(),
            'deleted_at' => $asset->deleted_at?->toIso8601String(),
            'thumbnail_url' => $this->adminThumbnailUrl($asset),
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
        return $list;
    }

    /**
     * URLs for viewing thumb, medium, large thumbnails in new window (admin only).
     * Only includes styles that exist when thumbnail_status is completed.
     */
    protected function adminThumbnailViewUrls(Asset $asset): array
    {
        $urls = [];
        if ($asset->thumbnail_status?->value !== 'completed') {
            return $urls;
        }
        foreach (['thumb', 'medium', 'large'] as $style) {
            if ($asset->thumbnailPathForStyle($style)) {
                $urls[$style] = route('admin.assets.thumbnail', ['asset' => $asset->id, 'style' => $style]);
            }
        }
        return $urls;
    }
}
