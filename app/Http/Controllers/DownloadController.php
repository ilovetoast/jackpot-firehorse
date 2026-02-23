<?php

namespace App\Http\Controllers;

use App\Enums\DownloadAccessMode;
use App\Enums\DownloadStatus;
use App\Enums\DownloadSource;
use App\Enums\DownloadType;
use App\Enums\ZipStatus;
use App\Jobs\BuildDownloadZipJob;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\Download;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AssetDeliveryService;
use App\Services\AssetUrlService;
use App\Services\DownloadBucketService;
use App\Services\TenantBucketService;
use App\Services\DownloadEventEmitter;
use App\Services\DownloadExpirationPolicy;
use App\Services\DownloadPublicPageBrandingResolver;
use App\Services\EnterpriseDownloadPolicy;
use App\Services\DownloadManagementService;
use App\Services\DownloadZipEstimateService;
use App\Services\ActivityRecorder;
use App\Services\AssetDownloadMetricService;
use App\Services\DownloadNameResolver;
use App\Services\PlanService;
use App\Services\StreamingZipService;
use App\Enums\EventType;
use App\Mail\DownloadShareEmail;
use App\Support\AssetVariant;
use App\Support\DeliveryContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 🔒 Phase 3.1 — Downloader System (LOCKED)
 * 
 * Do not refactor or change behavior.
 * Future phases may consume outputs only.
 */
class DownloadController extends Controller
{
    /**
     * Show the downloads page.
     * Returns downloads for the current tenant with scope (mine/all), status, access, user, sort filters.
     */
    public function index(Request $request): Response
    {
        $user = Auth::user();
        $tenant = app('tenant');

        $bucketService = app(DownloadBucketService::class);
        $bucketCount = $user && $tenant ? $bucketService->count() : 0;

        // Same role source as ResolveTenant: tenant admin/owner can see "All Downloads" (all tenant downloads).
        $tenantRole = $user && $tenant ? $user->getRoleForTenant($tenant) : null;
        $canManage = $tenantRole && in_array(strtolower((string) $tenantRole), ['admin', 'owner'], true);

        $scope = strtolower((string) ($request->input('scope', 'mine'))) === 'all' ? 'all' : 'mine';
        $statusFilter = $request->input('status', '');
        $accessFilter = $request->input('access', '');
        $brandIdFilter = $request->input('brand_id', '');
        $brandIdFilter = ($brandIdFilter !== null && $brandIdFilter !== '' && trim((string) $brandIdFilter) !== '')
            ? trim((string) $brandIdFilter)
            : '';
        $userIdFilterRaw = $request->input('user_id', '');
        $userIdFilter = ($userIdFilterRaw !== null && $userIdFilterRaw !== '' && trim((string) $userIdFilterRaw) !== '')
            ? trim((string) $userIdFilterRaw)
            : '';
        $sort = $request->input('sort', 'date_desc');
        $page = (int) $request->input('page', 1);

        $filters = [
            'scope' => $scope,
            'status' => $statusFilter,
            'access' => $accessFilter,
            'brand_id' => $brandIdFilter,
            'user_id' => $userIdFilter,
            'sort' => $sort,
        ];

        $downloads = [];
        $paginationMeta = null;
        $downloadUsers = [];

        if ($tenant && $user) {
            $query = Download::query()
                ->where('tenant_id', $tenant->id)
                ->whereNull('deleted_at');

            if ($scope === 'mine') {
                $query->where('created_by_user_id', $user->id);
            } else {
                if (! $canManage) {
                    $brandIds = $user->brands()->pluck('brands.id')->all();
                    $query->whereIn('brand_id', $brandIds);
                }
                // Only filter by creator when a specific user_id was chosen (empty = "All users").
                if ($userIdFilter !== '' && $userIdFilter !== '0') {
                    $query->where('created_by_user_id', $userIdFilter);
                }
            }

            if ($statusFilter === 'active') {
                $query->where(function ($q) {
                    $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })->whereNull('revoked_at');
            } elseif ($statusFilter === 'expired') {
                $query->whereNotNull('expires_at')->where('expires_at', '<=', now());
            } elseif ($statusFilter === 'revoked') {
                $query->whereNotNull('revoked_at');
            }

            if ($accessFilter === 'public') {
                $query->where('access_mode', DownloadAccessMode::PUBLIC->value);
            } elseif ($accessFilter === 'restricted') {
                $query->where('access_mode', '!=', DownloadAccessMode::PUBLIC->value);
            }

            if ($brandIdFilter !== '') {
                $query->where('brand_id', $brandIdFilter);
            }

            $sortColumn = match ($sort) {
                'date_asc' => ['created_at', 'asc'],
                'size_desc' => ['zip_size_bytes', 'desc'],
                'size_asc' => ['zip_size_bytes', 'asc'],
                default => ['created_at', 'desc'],
            };
            $query->orderBy($sortColumn[0], $sortColumn[1]);

            $perPage = 15;
            $paginator = $query->withCount('landingPageViewEvents')
                ->with(['assets' => function ($q) {
                    $q->select('assets.id', 'assets.original_filename', 'assets.metadata', 'assets.thumbnail_status')
                        ->orderBy('download_asset.is_primary', 'desc');
                }, 'createdBy:id,first_name,last_name,email', 'brand:id,name,slug,primary_color,logo_path,icon_path,icon'])
                ->paginate($perPage, ['*'], 'page', $page);

            $planService = app(PlanService::class);
            $features = $planService->getDownloadManagementFeatures($tenant);

            foreach ($paginator->items() as $download) {
                $downloads[] = $this->buildDownloadPayload($download, $features, $canManage);
            }

            $paginationMeta = [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ];

            if ($scope === 'all' && $canManage) {
                $creatorIds = Download::query()
                    ->where('tenant_id', $tenant->id)
                    ->whereNull('deleted_at')
                    ->distinct()
                    ->pluck('created_by_user_id')
                    ->filter()
                    ->all();
                if (! empty($creatorIds)) {
                    $downloadUsers = User::query()
                        ->whereIn('id', $creatorIds)
                        ->get(['id', 'first_name', 'last_name', 'email'])
                        ->map(fn (User $u) => [
                            'id' => $u->id,
                            'first_name' => $u->first_name,
                            'last_name' => $u->last_name,
                            'name' => trim($u->first_name . ' ' . $u->last_name) ?: $u->email,
                            'email' => $u->email,
                            'avatar_url' => $u->avatar_url ?? null,
                        ])
                        ->values()
                        ->all();
                }
            }

            $downloadBrands = Brand::query()
                ->where('tenant_id', $tenant->id)
                ->whereExists(function ($q) {
                    $q->selectRaw(1)
                        ->from('downloads')
                        ->whereColumn('downloads.brand_id', 'brands.id')
                        ->whereNull('downloads.deleted_at');
                })
                ->orderBy('name')
                ->get(['id', 'name', 'slug', 'logo_path', 'primary_color', 'icon', 'icon_path', 'icon_bg_color'])
                ->map(fn (Brand $b) => [
                    'id' => $b->id,
                    'name' => $b->name,
                    'slug' => $b->slug,
                    'logo_path' => $b->logo_path ?? null,
                    'primary_color' => $b->primary_color ?? null,
                    'icon' => $b->icon ?? null,
                    'icon_path' => $b->icon_path ?? null,
                    'icon_bg_color' => $b->icon_bg_color ?? null,
                ])
                ->values()
                ->all();
        } else {
            $downloadBrands = [];
        }

        // JSON poll: return only downloads + pagination so the frontend can refresh list without an Inertia visit (avoids full page reload).
        if ($request->wantsJson()) {
            return response()->json([
                'downloads' => $downloads,
                'pagination' => $paginationMeta,
            ]);
        }

        return Inertia::render('Downloads/Index', [
            'downloads' => $downloads,
            'bucket_count' => $bucketCount,
            'can_manage' => $canManage,
            'filters' => $filters,
            'pagination' => $paginationMeta,
            'download_users' => $downloadUsers,
            'download_brands' => $downloadBrands ?? [],
        ]);
    }

    /**
     * Build a single download payload for the index list.
     */
    protected function buildDownloadPayload(Download $download, array $planFeatures, bool $canManage = false): array
    {
        $state = $download->getState();
        $thumbnails = [];
        foreach ($download->assets as $asset) {
            $metadata = $asset->metadata ?? [];
            $thumbStatus = $asset->thumbnail_status instanceof \App\Enums\ThumbnailStatus
                ? $asset->thumbnail_status->value
                : ($asset->thumbnail_status ?? 'pending');
            $thumbUrl = null;
            if ($thumbStatus === 'completed' && $asset->thumbnailPathForStyle('thumb')) {
                $thumbUrl = $asset->deliveryUrl(\App\Support\AssetVariant::THUMB_SMALL, \App\Support\DeliveryContext::AUTHENTICATED) ?: null;
            } else {
                $thumbUrl = $asset->deliveryUrl(\App\Support\AssetVariant::THUMB_PREVIEW, \App\Support\DeliveryContext::AUTHENTICATED) ?: null;
            }
            $thumbnails[] = [
                'id' => $asset->id,
                'original_filename' => $asset->original_filename,
                'thumbnail_url' => $thumbUrl,
            ];
        }

        $accessMode = $download->access_mode instanceof DownloadAccessMode
            ? $download->access_mode->value
            : (string) $download->access_mode;

        $createdBy = $download->createdBy
            ? [
                'id' => $download->createdBy->id,
                'name' => trim($download->createdBy->first_name . ' ' . $download->createdBy->last_name) ?: $download->createdBy->email,
            ]
            : null;

        $brandPayload = null;
        if ($download->brand) {
            $b = $download->brand;
            $brandPayload = [
                'id' => $b->id,
                'name' => $b->name,
                'slug' => $b->slug,
                'primary_color' => $b->primary_color ?? null,
                'logo_path' => $b->logo_path ?? null,
                'icon_path' => $b->icon_path ?? null,
                'icon' => $b->icon ?? null,
            ];
        }

        $source = $download->source instanceof DownloadSource
            ? $download->source->value
            : (string) $download->source;

        $zipTimeEstimate = null;
        $estimatedBytes = null;
        $isPossiblyStuck = false;
        $zipProgressPct = null;
        $zipChunkIndex = null;
        $zipTotalChunks = null;
        $isZipStalled = false;
        if ($state === 'processing') {
            $estimatedBytes = (int) ($download->download_options['estimated_bytes'] ?? $download->zip_size_bytes ?? 0);
            if ($estimatedBytes > 0) {
                $zipTimeEstimate = app(DownloadZipEstimateService::class)->estimateZipBuildTimeRange($estimatedBytes);
            }
            $isPossiblyStuck = $download->isPossiblyStuck();
            $zipProgressPct = $download->getZipProgressPercentage();
            $zipChunkIndex = (int) ($download->zip_build_chunk_index ?? 0);
            $zipTotalChunks = $download->zip_total_chunks !== null ? (int) $download->zip_total_chunks : null;
            $isZipStalled = $download->isZipStalled(180);
        }

        return [
            'id' => $download->id,
            'title' => $download->title,
            'state' => $state,
            'zip_time_estimate' => $zipTimeEstimate,
            'estimated_bytes' => $estimatedBytes,
            'is_possibly_stuck' => $isPossiblyStuck,
            'zip_progress_percentage' => $zipProgressPct,
            'zip_chunk_index' => $zipChunkIndex,
            'zip_total_chunks' => $zipTotalChunks,
            'is_zip_stalled' => $isZipStalled,
            'thumbnails' => $thumbnails,
            'expires_at' => $download->expires_at?->toIso8601String(),
            'asset_count' => $download->assets->count(),
            'zip_size_bytes' => $download->zip_size_bytes,
            'can_revoke' => (bool) ($planFeatures['revoke'] ?? false) && ($canManage || ($createdBy && $createdBy['id'] === auth()->id())),
            'can_regenerate' => (bool) ($planFeatures['regenerate'] ?? false) && $download->canRegenerateZip(),
            'is_escalated_to_support' => $download->isEscalatedToSupport(),
            'can_extend' => (bool) ($planFeatures['extend_expiration'] ?? false),
            'public_url' => route('downloads.public', ['download' => $download->id]),
            'access_mode' => $accessMode,
            'password_protected' => $download->requiresPassword(),
            'brand' => $brandPayload,
            'brands' => $brandPayload ? [$brandPayload] : [],
            'source' => $source,
            'access_count' => (int) ($download->access_count ?? 0),
            'landing_page_views' => (int) ($download->landing_page_view_events_count ?? 0),
            'created_by' => $createdBy,
        ];
    }

    /**
     * Poll endpoint: return only mutable fields for given download IDs.
     * Used for patch-based polling (no Inertia). Same visibility as index (tenant + scope).
     *
     * GET /app/api/downloads/poll?ids=uuid1,uuid2
     */
    public function poll(Request $request): JsonResponse
    {
        $user = Auth::user();
        $tenant = app('tenant');
        if (! $tenant || ! $user) {
            return response()->json(['downloads' => []], 200);
        }

        $ids = $request->input('ids', []);
        if (is_string($ids)) {
            $ids = array_filter(explode(',', $ids));
        }
        if (! is_array($ids) || empty($ids)) {
            return response()->json(['downloads' => []], 200);
        }

        $ids = array_slice(array_unique($ids), 0, 50);
        $tenantRole = $user->getRoleForTenant($tenant);
        $canManage = $tenantRole && in_array(strtolower((string) $tenantRole), ['admin', 'owner'], true);
        $query = Download::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('id', $ids)
            ->whereNull('deleted_at');
        if (! $canManage) {
            $query->where('created_by_user_id', $user->id);
        }
        $downloads = $query->get();

        $planService = app(PlanService::class);
        $features = $planService->getDownloadManagementFeatures($tenant);
        $payloads = [];
        foreach ($downloads as $download) {
            $state = $download->getState();
            $patch = [
                'id' => $download->id,
                'state' => $state,
                'zip_total_chunks' => $download->zip_total_chunks !== null ? (int) $download->zip_total_chunks : null,
                'zip_chunk_index' => (int) ($download->zip_build_chunk_index ?? 0),
                'zip_progress_percentage' => $download->getZipProgressPercentage(),
                'is_zip_stalled' => $state === 'processing' ? $download->isZipStalled(180) : false,
                'is_possibly_stuck' => $state === 'processing' ? $download->isPossiblyStuck() : false,
                'estimated_bytes' => (int) ($download->download_options['estimated_bytes'] ?? $download->zip_size_bytes ?? 0),
            ];
            if ($state === 'processing' && $patch['estimated_bytes'] > 0) {
                $patch['zip_time_estimate'] = app(DownloadZipEstimateService::class)->estimateZipBuildTimeRange($patch['estimated_bytes']);
            } else {
                $patch['zip_time_estimate'] = null;
            }
            if ($state === 'ready') {
                $patch['zip_size_bytes'] = $download->zip_size_bytes;
                $patch['public_url'] = route('downloads.public', ['download' => $download->id]);
            }
            $payloads[] = $patch;
        }

        return response()->json(['downloads' => $payloads], 200);
    }

    /**
     * UX-R2: Create a single-asset download (POST /app/assets/{asset}/download).
     * Creates a Download with source SINGLE_ASSET and direct_asset_path; redirects to public download page.
     */
    public function downloadSingleAsset(Asset $asset): RedirectResponse|JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();
        if (! $tenant || ! $user) {
            if (request()->expectsJson()) {
                return response()->json(['message' => 'Unauthorized.'], 403);
            }
            return redirect()->route('login');
        }
        if (! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            if (request()->expectsJson()) {
                return response()->json(['message' => 'You do not have access to this asset.'], 403);
            }
            abort(403, 'You do not have access to this asset.');
        }
        if ($asset->tenant_id !== $tenant->id) {
            if (request()->expectsJson()) {
                return response()->json(['message' => 'This asset is not available for download.'], 403);
            }
            abort(403, 'This asset is not available for download.');
        }

        $enterprisePolicy = app(EnterpriseDownloadPolicy::class);
        if ($enterprisePolicy->disableSingleAssetDownloads($tenant)) {
            if (request()->expectsJson()) {
                return response()->json(['message' => 'Your organization requires downloads to be packaged.'], 403);
            }
            return redirect()->back()->withErrors(['download' => 'Your organization requires downloads to be packaged.']);
        }

        if (! $asset->published_at || $asset->archived_at) {
            if (request()->expectsJson()) {
                return response()->json(['message' => 'This asset is not available for download.'], 403);
            }
            return redirect()->back()->withErrors(['download' => 'This asset is not available for download.']);
        }

        $planService = app(PlanService::class);
        $expirationPolicy = app(DownloadExpirationPolicy::class);
        $expiresAt = $planService->canCreateNonExpiringDownload($tenant)
            ? null
            : now()->addDays($planService->getMaxDownloadExpirationDays($tenant));
        $forceDays = $enterprisePolicy->forceExpirationDays($tenant);
        if ($forceDays !== null) {
            $expiresAt = now()->addDays($forceDays);
        }

        $download = new Download();
        $download->tenant_id = $tenant->id;
        $download->brand_id = $asset->brand_id;
        $download->created_by_user_id = $user->id;
        $download->download_type = DownloadType::SNAPSHOT;
        $download->source = DownloadSource::SINGLE_ASSET;
        $download->title = null;
        $download->slug = Str::random(12);
        $download->version = 1;
        $download->status = DownloadStatus::READY;
        $download->zip_status = ZipStatus::NONE;
        $download->direct_asset_path = $asset->storage_root_path;
        $download->zip_size_bytes = $asset->size_bytes ?? 0;
        $download->expires_at = $expiresAt;
        $download->access_mode = DownloadAccessMode::COMPANY;
        $download->allow_reshare = true;
        $download->save();

        $download->update([
            'hard_delete_at' => $expirationPolicy->calculateHardDeleteAt($download, $expiresAt),
        ]);

        $download->assets()->attach($asset->id, ['is_primary' => true]);

        if (request()->expectsJson()) {
            return response()->json([
                'download_id' => $download->id,
                'public_url' => route('downloads.public', ['download' => $download->id]),
                'file_url' => route('downloads.public.file', ['download' => $download->id]),
                'expires_at' => $expiresAt?->toIso8601String(),
            ]);
        }

        return redirect()->route('downloads.public', ['download' => $download->id]);
    }

    /**
     * Create a download from the session bucket (POST /app/downloads).
     * Validates bucket not empty, plan/policy (password, expiration, access), then creates Download and dispatches BuildDownloadZipJob.
     */
    public function store(Request $request): \Illuminate\Http\RedirectResponse|JsonResponse
    {
        $request->validate([
            'source' => 'required|string|in:grid,drawer,collection,admin',
            'name' => 'nullable|string|max:255',
            'expires_at' => 'nullable|string',
            'access_mode' => 'nullable|string|in:public,brand,company,team,users,restricted',
            'allowed_users' => 'nullable|array',
            'allowed_users.*' => 'uuid',
            'password' => 'nullable|string|max:255',
            'landing_copy' => 'nullable|array',
            'landing_copy.headline' => 'nullable|string',
            'landing_copy.subtext' => 'nullable|string',
        ]);

        $tenant = app('tenant');
        $user = Auth::user();
        if (! $tenant || ! $user) {
            throw ValidationException::withMessages(['message' => ['Unauthorized.']]);
        }

        $bucketService = app(DownloadBucketService::class);
        $visibleIds = $bucketService->visibleItems();
        if (empty($visibleIds)) {
            throw ValidationException::withMessages(['message' => 'Add at least one asset to the download bucket.']);
        }

        $accessModeInput = $request->input('access_mode', 'public');
        $accessMode = $this->normalizeAccessMode($accessModeInput);
        if ($accessMode === DownloadAccessMode::BRAND) {
            $bucketService->assertCanRestrictToBrand();
        }

        $planService = app(PlanService::class);
        $enterprisePolicy = app(EnterpriseDownloadPolicy::class);

        if ($accessMode === DownloadAccessMode::PUBLIC && $enterprisePolicy->requirePasswordForPublic($tenant)) {
            if (! $request->filled('password') || ! trim((string) $request->input('password'))) {
                throw ValidationException::withMessages(['message' => 'Your organization requires a password for public downloads.']);
            }
        }

        $expiresAt = null;
        $expiresAtInput = $request->input('expires_at');
        if ($expiresAtInput === 'never' || $expiresAtInput === null) {
            if ($expiresAtInput === 'never') {
                if (! $planService->canCreateNonExpiringDownload($tenant)) {
                    throw ValidationException::withMessages(['message' => 'Upgrade to create non-expiring downloads.']);
                }
                if ($enterprisePolicy->disallowNonExpiring($tenant)) {
                    throw ValidationException::withMessages(['message' => 'Your organization requires an expiration date.']);
                }
            }
            $expiresAt = $planService->canCreateNonExpiringDownload($tenant) && $expiresAtInput === 'never'
                ? null
                : now()->addDays($planService->getMaxDownloadExpirationDays($tenant));
        } else {
            $expiresAt = \Carbon\Carbon::parse($expiresAtInput);
            $maxDays = $planService->getMaxDownloadExpirationDays($tenant);
            if ($expiresAt->gt(now()->addDays($maxDays))) {
                $expiresAt = now()->addDays($maxDays);
            }
        }

        $forceDays = $enterprisePolicy->forceExpirationDays($tenant);
        if ($forceDays !== null) {
            $expiresAt = now()->addDays($forceDays);
        }

        $firstAsset = Asset::query()->whereIn('id', $visibleIds)->first();
        $brandId = $firstAsset?->brand_id;

        $expirationPolicy = app(DownloadExpirationPolicy::class);
        $download = new Download();
        $download->tenant_id = $tenant->id;
        $download->brand_id = $brandId;
        $download->created_by_user_id = $user->id;
        $download->download_type = DownloadType::SNAPSHOT;
        $download->source = DownloadSource::tryFrom($request->input('source')) ?? DownloadSource::GRID;
        $download->title = $planService->canRenameDownload($tenant) && $request->filled('name')
            ? trim((string) $request->input('name'))
            : null;
        $download->slug = Str::random(12);
        $download->version = 1;
        $download->status = DownloadStatus::READY;
        $download->zip_status = ZipStatus::NONE;
        $download->expires_at = $expiresAt;
        $download->hard_delete_at = null;
        $download->access_mode = $accessMode;
        $download->allow_reshare = true;
        $download->landing_copy = is_array($request->input('landing_copy')) ? $request->input('landing_copy') : null;
        if ($request->filled('password') && trim((string) $request->input('password')) !== '') {
            $download->password_hash = Hash::make(trim((string) $request->input('password')));
        }
        $download->save();

        $download->update([
            'hard_delete_at' => $expirationPolicy->calculateHardDeleteAt($download, $expiresAt),
        ]);

        foreach ($visibleIds as $index => $assetId) {
            $download->assets()->attach($assetId, ['is_primary' => $index === 0]);
        }

        if ($accessMode === DownloadAccessMode::USERS && is_array($request->input('allowed_users'))) {
            $download->allowedUsers()->sync($request->input('allowed_users'));
        }

        // Phase D-4: Estimate total bytes and store; skip build job if streaming enabled and over threshold
        $estimatedBytes = Asset::whereIn('id', $visibleIds)->sum('size_bytes');
        $download->download_options = array_merge($download->download_options ?? [], ['estimated_bytes' => $estimatedBytes]);
        $download->saveQuietly();

        $streamingEnabled = config('features.streaming_downloads', false);
        $streamingThreshold = config('features.streaming_threshold_bytes', 500 * 1024 * 1024);
        $useStreaming = $streamingEnabled && $estimatedBytes > $streamingThreshold;

        if (! $useStreaming) {
            // D-Progress: Pre-set progress so UI shows "0 of N chunks" before job runs
            $chunkSize = 100; // same as BuildDownloadZipJob::CHUNK_SIZE
            $totalChunks = (int) ceil(count($visibleIds) / $chunkSize);
            $download->forceFill([
                'zip_total_chunks' => $totalChunks,
                'zip_build_chunk_index' => 0,
                'zip_last_progress_at' => now(),
            ])->saveQuietly();
            BuildDownloadZipJob::dispatch($download->id);
        }

        $bucketService->clear();

        $payload = [
            'download_id' => $download->id,
            'public_url' => route('downloads.public', ['download' => $download->id]),
            'expires_at' => $expiresAt?->toIso8601String(),
            'asset_count' => count($visibleIds),
        ];

        if ($request->expectsJson()) {
            return response()->json($payload);
        }

        // Inertia expects a 303 redirect for POST (not raw JSON). Send user to downloads page after creation.
        return redirect()->route('downloads.index')
            ->with('download_created', $payload)
            ->setStatusCode(303);
    }

    /**
     * Regenerate download ZIP (Enterprise only). Dispatches BuildDownloadZipJob.
     */
    public function regenerate(Request $request, Download $download): RedirectResponse|JsonResponse
    {
        $user = Auth::user();
        $tenant = app('tenant');
        if (! $user || ! $tenant) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Unauthorized.'], 403);
            }
            return redirect()->route('downloads.index')->withErrors(['message' => 'Unauthorized.']);
        }

        if ($download->tenant_id !== $tenant->id) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Download not found.'], 404);
            }
            return redirect()->route('downloads.index')->withErrors(['message' => 'Download not found.']);
        }

        $tenantRole = $user->getRoleForTenant($tenant);
        $canManage = $tenantRole && in_array(strtolower((string) $tenantRole), ['admin', 'owner'], true);
        $isCreator = $download->created_by_user_id === $user->id;
        if (! $canManage && ! $isCreator) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'You cannot manage downloads.'], 403);
            }
            return redirect()->route('downloads.index')
                ->with('download_action', 'regenerate')
                ->with('download_action_id', $download->id)
                ->withErrors(['download' => 'You cannot manage downloads.']);
        }

        $planService = app(PlanService::class);
        if (! $planService->canRegenerateDownload($tenant)) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Regenerate is not available on your plan.'], 403);
            }
            return redirect()->route('downloads.index')
                ->with('download_action', 'regenerate')
                ->with('download_action_id', $download->id)
                ->withErrors(['download' => 'Regenerate is not available on your plan.']);
        }

        try {
            app(DownloadManagementService::class)->regenerate($download, $user);
        } catch (ValidationException $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'errors' => $e->errors(),
                ], 422);
            }
            return redirect()->route('downloads.index')
                ->with('download_action', 'regenerate')
                ->with('download_action_id', $download->id)
                ->withErrors($e->errors());
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Download regeneration started.']);
        }
        return redirect()->route('downloads.index')
            ->with('success', 'Download regeneration started.')
            ->with('download_action', 'regenerate')
            ->with('download_action_id', $download->id);
    }

    /**
     * Revoke a download: invalidate link, delete artifact, mark revoked (plan-gated).
     */
    public function revoke(Request $request, Download $download): RedirectResponse|JsonResponse
    {
        $user = Auth::user();
        $tenant = app('tenant');
        if (! $user || ! $tenant) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Unauthorized.'], 403);
            }
            return redirect()->route('downloads.index')->withErrors(['message' => 'Unauthorized.']);
        }

        if ($download->tenant_id !== $tenant->id) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Download not found.'], 404);
            }
            return redirect()->route('downloads.index')->withErrors(['message' => 'Download not found.']);
        }

        $tenantRole = $user->getRoleForTenant($tenant);
        $canManage = $tenantRole && in_array(strtolower((string) $tenantRole), ['admin', 'owner'], true);
        $isCreator = $download->created_by_user_id === $user->id;
        if (! $canManage && ! $isCreator) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'You cannot revoke this download.'], 403);
            }
            return redirect()->route('downloads.index')
                ->with('download_action', 'revoke')
                ->with('download_action_id', $download->id)
                ->withErrors(['download' => 'You cannot revoke this download.']);
        }

        $planService = app(PlanService::class);
        if (! $planService->canRevokeDownload($tenant)) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Revoke is not available on your plan.'], 403);
            }
            return redirect()->route('downloads.index')
                ->with('download_action', 'revoke')
                ->with('download_action_id', $download->id)
                ->withErrors(['download' => 'Revoke is not available on your plan.']);
        }

        if ($download->isRevoked()) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Download is already revoked.'], 422);
            }
            return redirect()->route('downloads.index')
                ->with('download_action', 'revoke')
                ->with('download_action_id', $download->id)
                ->withErrors(['download' => 'Download is already revoked.']);
        }

        app(DownloadManagementService::class)->revoke($download, $user);

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Download revoked.']);
        }
        return redirect()->route('downloads.index')
            ->with('success', 'Download revoked.')
            ->with('download_action', 'revoke')
            ->with('download_action_id', $download->id);
    }

    /**
     * Map request access_mode string to enum (team/company → COMPANY, restricted → USERS).
     */
    protected function normalizeAccessMode(string $value): DownloadAccessMode
    {
        return match (strtolower($value)) {
            'brand' => DownloadAccessMode::BRAND,
            'company', 'team' => DownloadAccessMode::COMPANY,
            'users', 'restricted' => DownloadAccessMode::USERS,
            default => DownloadAccessMode::PUBLIC,
        };
    }

    /**
     * Public download page (GET /d/{download}).
     * When password-protected: show landing page with password form; download only after unlock.
     * When not password-protected: redirect to S3 signed URL.
     */
    public function download(Download $download): Response|RedirectResponse|\Symfony\Component\HttpFoundation\Response
    {
        if ($download->trashed()) {
            return $this->publicPage($download, 'not_found', 'Download not found');
        }

        $tenant = app()->bound('tenant') ? app('tenant') : null;

        // Public route /d/{download} has no ResolveTenant middleware, so tenant is often null when
        // the user opens the link (e.g. from Downloads page). For company/team/restricted downloads,
        // resolve tenant from the download's tenant when the user is authenticated and belongs to it,
        // so the creator and other company members can access their own download.
        if (! $tenant && $download->access_mode !== DownloadAccessMode::PUBLIC && Auth::check()) {
            $downloadTenant = Tenant::find($download->tenant_id);
            if ($downloadTenant && Auth::user()->tenants()->where('tenants.id', $downloadTenant->id)->exists()) {
                $tenant = $downloadTenant;
                app()->instance('tenant', $tenant);
            }
        }

        if ($download->access_mode !== DownloadAccessMode::PUBLIC) {
            if (! $tenant || $download->tenant_id !== $tenant->id) {
                return $this->publicPage($download, 'not_found', 'Download not found');
            }
        }

        if ($download->status !== DownloadStatus::READY) {
            return $this->publicPage($download, 'failed', $this->getStatusErrorMessage($download->status));
        }

        if (! $this->validateAccess($download)) {
            return $this->publicPage($download, 'access_denied', 'Access denied');
        }

        if ($download->isExpired()) {
            return $this->publicPage($download, 'expired', 'This download has expired.', false, null, 410);
        }

        if ($download->isRevoked()) {
            return $this->publicPage($download, 'revoked', 'This download has been revoked', false, null, 410);
        }

        // Password-protected: show landing page (HTML) until session is unlocked; never redirect to ZIP until then.
        $requiresPassword = $download->requiresPassword();
        $isUnlocked = session('download_unlocked.' . $download->id) === true;
        if ($requiresPassword && ! $isUnlocked) {
            $this->recordLandingPageView($download);

            return $this->publicPage($download, 'password_required', 'Enter the password to continue.', true);
        }

        // UX-R2: Single-asset download — no ZIP; show ready page with file_url to deliver direct_asset_path
        if (! empty($download->direct_asset_path)) {
            $tenant = $download->tenant;
            $planService = app(PlanService::class);
            $requiresLanding = $tenant && $planService->tenantRequiresLandingPage($tenant);
            if (! $requiresLanding) {
                return redirect()->route('downloads.public.file', ['download' => $download->id]);
            }

            $branding = app(DownloadPublicPageBrandingResolver::class)->resolve($download, '');
            $firstAsset = $download->assets()->first();
            $downloadTitle = $firstAsset?->original_filename ?? basename($download->direct_asset_path);

            $this->recordLandingPageView($download);

            return $this->publicPage($download, 'ready', '', false, null, 200, null, [
                'download_title' => $downloadTitle,
                'asset_count' => 1,
                'zip_size_bytes' => $download->zip_size_bytes,
                'expires_at' => $download->expires_at?->toIso8601String(),
                'file_url' => route('downloads.public.file', ['download' => $download->id]),
                'share_email_url' => route('downloads.public.share-email', ['download' => $download->id]),
                'show_jackpot_promo' => $branding['show_jackpot_promo'] ?? false,
                'footer_promo' => $branding['footer_promo'] ?? [],
            ]);
        }

        if ($download->zip_status !== ZipStatus::READY) {
            // Phase D-4: If streaming enabled and over threshold, stream instead of waiting for build
            $estimatedBytes = (int) ($download->download_options['estimated_bytes'] ?? $download->assets()->sum('size_bytes'));
            $streamingEnabled = config('features.streaming_downloads', false);
            $streamingThreshold = config('features.streaming_threshold_bytes', 500 * 1024 * 1024);
            if ($streamingEnabled && $estimatedBytes > $streamingThreshold && $download->zip_status === ZipStatus::NONE) {
                return $this->streamZipResponse($download);
            }

            $message = $download->zip_status === ZipStatus::BUILDING || $download->zip_status === ZipStatus::NONE
                ? 'We\'re preparing your download. Please try again in a moment.'
                : $this->getZipStatusErrorMessage($download->zip_status);
            $zipTimeEstimate = $estimatedBytes > 0 ? app(DownloadZipEstimateService::class)->estimateZipBuildTimeRange($estimatedBytes) : null;
            $zipProgress = $download->zip_status === ZipStatus::BUILDING ? [
                'zip_progress_percentage' => $download->getZipProgressPercentage(),
                'zip_chunk_index' => (int) ($download->zip_build_chunk_index ?? 0),
                'zip_total_chunks' => $download->zip_total_chunks !== null ? (int) $download->zip_total_chunks : null,
                'is_zip_stalled' => $download->isZipStalled(180),
            ] : null;

            return $this->publicPage($download, $download->zip_status === ZipStatus::BUILDING ? 'processing' : 'failed', $message, false, $zipTimeEstimate, 200, $zipProgress);
        }

        if (! $download->zip_path) {
            Log::error('[DownloadController] Download ZIP path is missing', ['download_id' => $download->id]);
            return $this->publicPage($download, 'failed', 'ZIP file not available');
        }

        // D-SHARE: Show share page with download info and Download button (links to /file for actual delivery)
        $tenant = $download->tenant;
        $planService = app(PlanService::class);
        $requiresLanding = $tenant && $planService->tenantRequiresLandingPage($tenant);
        if (! $requiresLanding) {
            return redirect()->route('downloads.public.file', ['download' => $download->id]);
        }

        $branding = app(DownloadPublicPageBrandingResolver::class)->resolve($download, '');

        $this->recordLandingPageView($download);

        return $this->publicPage($download, 'ready', '', false, null, 200, null, [
            'download_title' => $download->title ?? $this->getDownloadZipFilename($download),
            'asset_count' => $download->assets()->count(),
            'zip_size_bytes' => $download->zip_size_bytes,
            'expires_at' => $download->expires_at?->toIso8601String(),
            'file_url' => route('downloads.public.file', ['download' => $download->id]),
            'share_email_url' => route('downloads.public.share-email', ['download' => $download->id]),
            'show_jackpot_promo' => $branding['show_jackpot_promo'] ?? false,
            'footer_promo' => $branding['footer_promo'] ?? [],
        ]);
    }

    /**
     * Render the public download landing page (password form, expired, revoked, share page, etc.).
     *
     * @param  array{zip_progress_percentage?: int|null, zip_chunk_index?: int, zip_total_chunks?: int|null, is_zip_stalled?: bool}|null  $zipProgress  D-Progress: optional when state is processing
     * @param  array{download_title?: string, asset_count?: int, zip_size_bytes?: int|null, expires_at?: string|null, file_url?: string, share_email_url?: string, show_jackpot_promo?: bool, footer_promo?: array}|null  $shareProps  D-SHARE: when state is ready
     */
    protected function publicPage(Download $download, string $state, string $message = '', bool $passwordRequired = false, ?array $zipTimeEstimate = null, int $statusCode = 200, ?array $zipProgress = null, ?array $shareProps = null): \Symfony\Component\HttpFoundation\Response
    {
        $resolver = app(DownloadPublicPageBrandingResolver::class);
        $branding = $resolver->resolve($download, $message);

        $props = [
            'state' => $state,
            'message' => $message,
            'password_required' => $passwordRequired,
            'download_id' => $download->id,
            'unlock_url' => $passwordRequired ? route('downloads.public.unlock', ['download' => $download->id]) : '',
            'show_landing_layout' => $branding['show_landing_layout'],
            'branding_options' => $branding['branding_options'],
            'show_jackpot_promo' => $branding['show_jackpot_promo'] ?? false,
            'footer_promo' => $branding['footer_promo'] ?? [],
            'zip_time_estimate' => $zipTimeEstimate,
            'cdn_domain' => config('cloudfront.domain'),
        ];
        if ($zipProgress !== null) {
            $props['zip_progress_percentage'] = $zipProgress['zip_progress_percentage'] ?? null;
            $props['zip_chunk_index'] = $zipProgress['zip_chunk_index'] ?? 0;
            $props['zip_total_chunks'] = $zipProgress['zip_total_chunks'] ?? null;
            $props['is_zip_stalled'] = $zipProgress['is_zip_stalled'] ?? false;
        }
        if ($shareProps !== null) {
            $props = array_merge($props, $shareProps);
        }

        return Inertia::render('Downloads/Public', $props)->toResponse(request())->setStatusCode($statusCode);
    }

    /**
     * Verify password and unlock download (POST /d/{download}/unlock).
     * On success: set session and redirect to public page; next GET will redirect to S3.
     */
    public function unlock(Request $request, Download $download): RedirectResponse
    {
        $request->validate(['password' => 'required|string']);

        if ($download->trashed() || ! $download->requiresPassword()) {
            return redirect()->route('downloads.public', ['download' => $download->id]);
        }

        if (! Hash::check($request->input('password'), $download->password_hash)) {
            return redirect()->route('downloads.public', ['download' => $download->id])
                ->withErrors(['password' => 'The password is incorrect.']);
        }

        session()->put('download_unlocked.' . $download->id, true);

        return redirect()->route('downloads.public', ['download' => $download->id]);
    }

    /**
     * D-SHARE: Send download link via email (POST /d/{download}/share-email).
     * Rate-limited. Logs event download.share_email_sent (no recipient in metadata).
     */
    public function shareEmail(Request $request, Download $download): JsonResponse|RedirectResponse
    {
        $request->validate([
            'to' => 'required|email',
            'message' => 'nullable|string|max:1000',
        ]);

        if ($download->trashed()) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Download not found.'], 404);
            }

            return redirect()->back()->withErrors(['message' => 'Download not found.']);
        }

        $tenant = $download->tenant;
        if (! $tenant) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Download not found.'], 404);
            }

            return redirect()->back()->withErrors(['message' => 'Download not found.']);
        }

        if ($download->access_mode !== DownloadAccessMode::PUBLIC) {
            $user = Auth::user();
            $resolvedTenant = app()->bound('tenant') ? app('tenant') : null;
            if (! $resolvedTenant || $download->tenant_id !== $resolvedTenant->id || ! $user) {
                if ($request->expectsJson()) {
                    return response()->json(['message' => 'Access denied.'], 403);
                }

                return redirect()->back()->withErrors(['message' => 'Access denied.']);
            }
            if (! $this->validateAccess($download)) {
                if ($request->expectsJson()) {
                    return response()->json(['message' => 'Access denied.'], 403);
                }

                return redirect()->back()->withErrors(['message' => 'Access denied.']);
            }
        }

        if ($download->isExpired() || $download->isRevoked()) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'This download is no longer available.'], 410);
            }

            return redirect()->back()->withErrors(['message' => 'This download is no longer available.']);
        }

        $shareUrl = route('downloads.public', ['download' => $download->id]);
        $to = $request->input('to');
        $personalMessage = $request->input('message', '');

        try {
            Mail::to($to)->send(new DownloadShareEmail($download, $tenant, $shareUrl, $personalMessage));
        } catch (\Exception $e) {
            Log::error('[DownloadController] Failed to send share email', [
                'download_id' => $download->id,
                'error' => $e->getMessage(),
            ]);
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Failed to send email. Please try again.'], 500);
            }

            return redirect()->back()->withErrors(['message' => 'Failed to send email. Please try again.']);
        }

        ActivityRecorder::guest($tenant, \App\Enums\EventType::DOWNLOAD_SHARE_EMAIL_SENT, $download, [
            'download_id' => $download->id,
            'sent_at' => now()->toIso8601String(),
        ]);

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Email sent.']);
        }

        return redirect()->back()->with('share_email_sent', true);
    }

    /**
     * Deliver file (GET /d/{download}/file). Runs same access checks as download(), then redirects to S3 or streams.
     * Used by the Download button on the share page. Does NOT render the share page.
     */
    public function deliverFile(Download $download): Response|RedirectResponse|\Symfony\Component\HttpFoundation\Response
    {
        if ($download->trashed()) {
            return redirect()->route('downloads.public', ['download' => $download->id]);
        }

        $tenant = app()->bound('tenant') ? app('tenant') : null;
        if (! $tenant && $download->access_mode !== DownloadAccessMode::PUBLIC && Auth::check()) {
            $downloadTenant = Tenant::find($download->tenant_id);
            if ($downloadTenant && Auth::user()->tenants()->where('tenants.id', $downloadTenant->id)->exists()) {
                $tenant = $downloadTenant;
                app()->instance('tenant', $tenant);
            }
        }

        if ($download->access_mode !== DownloadAccessMode::PUBLIC) {
            if (! $tenant || $download->tenant_id !== $tenant->id) {
                return redirect()->route('downloads.public', ['download' => $download->id]);
            }
        }

        if ($download->status !== DownloadStatus::READY || ! $this->validateAccess($download)
            || $download->isExpired() || $download->isRevoked()) {
            return redirect()->route('downloads.public', ['download' => $download->id]);
        }

        $requiresPassword = $download->requiresPassword();
        $isUnlocked = session('download_unlocked.' . $download->id) === true;
        if ($requiresPassword && ! $isUnlocked) {
            return redirect()->route('downloads.public', ['download' => $download->id]);
        }

        // UX-R2: Single-asset download — redirect to CloudFront signed URL (no cookies)
        if (! empty($download->direct_asset_path)) {
            $download->increment('access_count');
            app(AssetDownloadMetricService::class)->recordFromDownload($download, 'single_asset');
            $signedUrl = app(AssetUrlService::class)->getSignedCloudFrontUrl($download->direct_asset_path, 1800);
            DownloadEventEmitter::emitDownloadZipRequested($download);
            DownloadEventEmitter::emitDownloadZipCompleted($download);

            return redirect()->away($signedUrl);
        }

        if ($download->zip_status !== ZipStatus::READY) {
            $estimatedBytes = (int) ($download->download_options['estimated_bytes'] ?? $download->assets()->sum('size_bytes'));
            $streamingEnabled = config('features.streaming_downloads', false);
            $streamingThreshold = config('features.streaming_threshold_bytes', 500 * 1024 * 1024);
            if ($streamingEnabled && $estimatedBytes > $streamingThreshold && $download->zip_status === ZipStatus::NONE) {
                return $this->streamZipResponse($download);
            }
            return redirect()->route('downloads.public', ['download' => $download->id]);
        }

        if (! $download->zip_path) {
            return redirect()->route('downloads.public', ['download' => $download->id]);
        }

        $download->increment('access_count');
        app(AssetDownloadMetricService::class)->recordFromDownload($download, 'zip');
        $signedUrl = app(AssetUrlService::class)->getSignedCloudFrontUrl($download->zip_path, 1800);
        DownloadEventEmitter::emitDownloadZipRequested($download);
        DownloadEventEmitter::emitDownloadZipCompleted($download);

        return redirect()->away($signedUrl);
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

            case DownloadAccessMode::COMPANY:
            case DownloadAccessMode::TEAM:
                // Company/team access - only authenticated users who are members of the tenant
                if (! $user) {
                    return false;
                }

                // Verify user belongs to the download's tenant (tenant is bound above or by ResolveTenant)
                $tenant = app('tenant');
                return $tenant && $download->tenant_id === $tenant->id;

            case DownloadAccessMode::BRAND:
                // Brand access - only authenticated users who are members of the download's brand
                if (! $user) {
                    return false;
                }

                $tenant = app('tenant');
                if (! $tenant || $download->tenant_id !== $tenant->id) {
                    return false;
                }
                if (! $download->brand_id) {
                    return true;
                }

                return $user->brands()->where('brands.id', $download->brand_id)->exists();

            case DownloadAccessMode::USERS:
            case DownloadAccessMode::RESTRICTED:
                // Restricted access - only users in download_user pivot, or any tenant member if none set
                if (! $user) {
                    return false;
                }

                $tenant = app('tenant');
                if (! $tenant || $download->tenant_id !== $tenant->id) {
                    return false;
                }
                $allowedIds = $download->allowedUsers()->pluck('users.id')->all();
                if (empty($allowedIds)) {
                    return true;
                }

                return in_array($user->id, $allowedIds, true);

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
     * Phase D-4: Stream ZIP directly to response (no temp file, no build job).
     */
    protected function streamZipResponse(Download $download): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $download->increment('access_count');
        app(AssetDownloadMetricService::class)->recordFromDownload($download, 'zip');

        $filename = $this->getDownloadZipFilename($download);
        DownloadEventEmitter::emitDownloadZipRequested($download);
        DownloadEventEmitter::emitDownloadZipCompleted($download);

        $service = app(StreamingZipService::class);

        return response()->stream(function () use ($service, $download, $filename) {
            $service->stream($download, $filename);
        }, 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="' . addcslashes($filename, '"\\') . '"',
        ]);
    }

    /**
     * Build filename for download ZIP using tenant template (e.g. Brand-download-2026-02-02.zip).
     */
    protected function getDownloadZipFilename(Download $download): string
    {
        $resolver = app(DownloadNameResolver::class);
        $tenant = $download->tenant;
        $brand = $download->brand;
        $template = ($tenant->settings['download_name_template'] ?? null)
            ?: DownloadNameResolver::DEFAULT_TEMPLATE;
        $base = $resolver->resolve($template, $tenant, $brand, now());
        $safe = preg_replace('/[\r\n"\\\\]/', '', $base);

        return (($safe !== null && $safe !== '') ? $safe : 'download') . '.zip';
    }

    /**
     * Record a landing page view for analytics (when user sees the share/password page).
     */
    protected function recordLandingPageView(Download $download): void
    {
        try {
            $tenant = $download->tenant;
            if (! $tenant) {
                return;
            }
            ActivityRecorder::guest($tenant, EventType::DOWNLOAD_LANDING_PAGE_VIEWED, $download, [
                'download_id' => $download->id,
                'viewed_at' => now()->toIso8601String(),
            ]);
        } catch (\Throwable $e) {
            Log::debug('[DownloadController] Failed to record landing page view', [
                'download_id' => $download->id,
                'error' => $e->getMessage(),
            ]);
        }
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
