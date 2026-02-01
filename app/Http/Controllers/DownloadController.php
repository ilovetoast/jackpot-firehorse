<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Enums\DownloadAccessMode;
use App\Enums\EventType;
use App\Enums\DownloadSource;
use App\Enums\DownloadStatus;
use App\Enums\DownloadType;
use App\Enums\ZipStatus;
use App\Jobs\BuildDownloadZipJob;
use App\Models\Asset;
use App\Models\Download;
use App\Models\User;
use App\Models\Collection;
use App\Services\AssetEligibilityService;
use App\Services\CollectionAssetQueryService;
use App\Services\DownloadBucketService;
use App\Services\ActivityRecorder;
use App\Services\DownloadEventEmitter;
use App\Services\DownloadExpirationPolicy;
use App\Services\DownloadManagementService;
use App\Services\DownloadNameResolver;
use App\Services\EnterpriseDownloadPolicy;
use App\Services\FeatureGate;
use App\Services\PlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Aws\S3\S3Client;

/**
 * 🔒 Phase 3.1 — Downloader System (LOCKED)
 * Phase D1 — Secure Asset Downloader (Foundation): index, store, public download route.
 */
class DownloadController extends Controller
{
    public function __construct(
        protected DownloadBucketService $bucket,
        protected PlanService $planService,
        protected DownloadExpirationPolicy $expirationPolicy,
        protected DownloadManagementService $managementService,
        protected CollectionAssetQueryService $collectionAssetQueryService,
        protected FeatureGate $featureGate,
        protected AssetEligibilityService $assetEligibilityService,
        protected DownloadNameResolver $downloadNameResolver,
        protected EnterpriseDownloadPolicy $downloadPolicy
    ) {}

    /**
     * Show the downloads page (My Downloads / All Downloads). Phase D1 + D2.
     * D12.2: Enforce visibility by role — tenant admin all, brand manager by brand, contributor/viewer own only; collection-only denied.
     */
    public function index(Request $request): Response
    {
        $user = Auth::user();
        $tenant = app('tenant');

        if (! $tenant) {
            return Inertia::render('Downloads/Index', [
                'downloads' => [],
                'bucket_count' => 0,
                'can_manage' => false,
                'pagination' => null,
                'download_users' => [],
                'filters' => [
                    'scope' => 'mine',
                    'status' => '',
                    'access' => '',
                    'user_id' => '',
                    'sort' => 'date_desc',
                ],
                'download_features' => [],
                'brands' => [],
                'active_brand_id' => null,
            ]);
        }

        // D12.2: Collection-only users must not access downloads index
        if (app()->bound('collection_only') && app('collection_only')) {
            $collection = app()->bound('collection') ? app('collection') : null;
            if ($collection) {
                return redirect()->route('collection-invite.landing', ['collection' => $collection->id]);
            }
            abort(403, 'Downloads are not available in this context.');
        }

        $scope = $request->input('scope', 'mine');
        $canManage = $this->canManageDownload($user, $tenant);
        $sort = $request->input('sort', 'date_desc');
        $perPage = 15;

        $query = Download::query()
            ->where('tenant_id', $tenant->id)
            ->with(['assets' => fn ($q) => $q->select('assets.id', 'assets.original_filename', 'assets.metadata', 'assets.thumbnail_status'), 'createdBy', 'allowedUsers']);

        // D12.2: Enforce visibility by role (server-side)
        $tenantRole = $user->getRoleForTenant($tenant);
        $isTenantAdmin = $tenantRole && in_array(strtolower($tenantRole), ['owner', 'admin'], true);

        if ($isTenantAdmin) {
            // Tenant Admin / Owner: all downloads for tenant
            // no extra scope
        } else {
            $userBrandIds = DB::table('brand_user')
                ->join('brands', 'brands.id', '=', 'brand_user.brand_id')
                ->where('brand_user.user_id', $user->id)
                ->whereNull('brand_user.removed_at')
                ->where('brands.tenant_id', $tenant->id)
                ->pluck('brands.id')
                ->all();

            $hasManagerOrAdminRole = DB::table('brand_user')
                ->join('brands', 'brands.id', '=', 'brand_user.brand_id')
                ->where('brand_user.user_id', $user->id)
                ->whereNull('brand_user.removed_at')
                ->where('brands.tenant_id', $tenant->id)
                ->whereIn('brand_user.role', ['brand_manager', 'admin'])
                ->exists();

            if ($hasManagerOrAdminRole && ! empty($userBrandIds)) {
                // Brand Manager (or brand admin): downloads for brands they are assigned to
                $query->whereIn('brand_id', $userBrandIds);
            } else {
                // Contributor / Viewer: only downloads they created
                $query->where('created_by_user_id', $user->id);
            }
        }

        if ($scope === 'mine' || ! $canManage) {
            $query->where('created_by_user_id', $user->id);
        }

        if ($canManage && $request->filled('user_id')) {
            $query->where('created_by_user_id', $request->input('user_id'));
        }

        if ($status = $request->input('status')) {
            if ($status === 'active') {
                $query->where(function ($q) {
                    $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })->whereNull('revoked_at');
            } elseif ($status === 'expired') {
                $query->where(function ($q) {
                    $q->whereNotNull('expires_at')->where('expires_at', '<=', now());
                });
            } elseif ($status === 'revoked') {
                $query->whereNotNull('revoked_at');
            }
        }

        if ($access = $request->input('access')) {
            if ($access === 'public') {
                $query->where('access_mode', DownloadAccessMode::PUBLIC);
            } elseif ($access === 'restricted') {
                $query->where('access_mode', '!=', DownloadAccessMode::PUBLIC);
            }
        }

        if ($sort === 'date_asc') {
            $query->orderBy('created_at');
        } elseif ($sort === 'size_desc') {
            $query->orderByRaw('COALESCE(zip_size_bytes, 0) DESC')->orderByDesc('created_at');
        } elseif ($sort === 'size_asc') {
            $query->orderByRaw('COALESCE(zip_size_bytes, 0) ASC')->orderByDesc('created_at');
        } else {
            $query->orderByDesc('created_at');
        }

        $paginator = $query->paginate($perPage)->withQueryString();
        $features = $this->planService->getDownloadManagementFeatures($tenant);
        $downloads = $paginator->getCollection()->map(fn (Download $d) => $this->mapDownloadForHistory($d, $canManage, $user, $features))->values()->all();

        $bucketCount = $this->bucket->count();

        // D12.2: Brand filter data (no UI styling yet) — Admin: all tenant brands; Brand manager: only their brands
        $brandsForFilter = $isTenantAdmin
            ? $tenant->brands()->orderBy('name')->get(['id', 'name', 'slug'])->map(fn ($b) => ['id' => $b->id, 'name' => $b->name, 'slug' => $b->slug])->values()->all()
            : $user->brands()->where('brands.tenant_id', $tenant->id)->wherePivotNull('removed_at')->orderBy('brands.name')->get(['brands.id', 'brands.name', 'brands.slug'])->map(fn ($b) => ['id' => $b->id, 'name' => $b->name, 'slug' => $b->slug])->values()->all();

        $downloadUsers = [];
        if ($canManage) {
            $downloadUsers = $tenant->users()
                ->orderBy('users.first_name')
                ->orderBy('users.last_name')
                ->get(['users.id', 'users.first_name', 'users.last_name', 'users.email', 'users.avatar_url'])
                ->map(fn ($u) => [
                    'id' => $u->id,
                    'name' => trim($u->first_name . ' ' . $u->last_name) ?: $u->email,
                    'first_name' => $u->first_name,
                    'last_name' => $u->last_name,
                    'email' => $u->email,
                    'avatar_url' => $u->avatar_url,
                ])
                ->values()
                ->all();
        }

        return Inertia::render('Downloads/Index', [
            'downloads' => $downloads,
            'bucket_count' => $bucketCount,
            'can_manage' => $canManage,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'download_users' => $downloadUsers,
            'filters' => [
                'scope' => $scope,
                'status' => $request->input('status', ''),
                'access' => $request->input('access', ''),
                'user_id' => $request->input('user_id', ''),
                'sort' => $sort,
            ],
            'brands' => $brandsForFilter,
            'active_brand_id' => $request->input('active_brand_id') ? (int) $request->input('active_brand_id') : null,
            'download_features' => [
                'extend_expiration' => $features['extend_expiration'] ?? false,
                'revoke' => $features['revoke'] ?? false,
                'restrict_access_brand' => $features['restrict_access_brand'] ?? false,
                'restrict_access_company' => $features['restrict_access_company'] ?? false,
                'password_protection' => $features['password_protection'] ?? false, // D7
                'branding' => $features['branding'] ?? false, // D7
                'restrict_access_users' => $features['restrict_access_users'] ?? false,
                'non_expiring' => $features['non_expiring'] ?? false,
                'regenerate' => $features['regenerate'] ?? false,
                'rename' => $features['rename'] ?? false,
                'max_expiration_days' => $features['max_expiration_days'] ?? 30,
            ],
        ]);
    }

    /**
     * Create a download from the current bucket. Phase D1.
     * Validates plan limits, creates Download record, attaches assets, dispatches job, clears bucket.
     */
    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $user = Auth::user();
        $tenant = app('tenant');
        $brand = app('brand');

        if (! $tenant) {
            return response()->json(['message' => 'No company selected.'], 422);
        }

        $source = $request->input('source', DownloadSource::GRID->value);
        $isPublicCollection = $source === DownloadSource::PUBLIC_COLLECTION->value;

        if ($isPublicCollection) {
            $collectionId = $request->input('collection_id');
            if (! $collectionId) {
                return response()->json(['message' => 'Collection is required for public collection download.'], 422);
            }
            $collection = Collection::query()
                ->where('id', $collectionId)
                ->where('tenant_id', $tenant->id)
                ->where('is_public', true)
                ->first();
            if (! $collection || ! $this->featureGate->publicCollectionDownloadsEnabled($tenant)) {
                return response()->json(['message' => 'Collection not found or public collection downloads are not enabled.'], 422);
            }
            $query = $this->collectionAssetQueryService->queryPublic($collection);
            $visibleIds = $query->pluck('id')->all();
            if (empty($visibleIds)) {
                return response()->json(['message' => 'This collection has no assets to download.'], 422);
            }
        } else {
            if (! in_array($source, ['grid', 'drawer', 'collection'], true)) {
                $source = DownloadSource::GRID->value;
            }
            $visibleIds = $this->bucket->visibleItems();
            if (empty($visibleIds)) {
                return response()->json(['message' => 'Add at least one asset to the download bucket.'], 422);
            }
        }

        // D6.1: Asset eligibility (published, non-archived) is enforced here. Do not bypass this for collections or downloads.
        $eligibleQuery = $this->assetEligibilityService->eligibleForDownloads()->where('tenant_id', $tenant->id);
        $visibleIds = $this->assetEligibilityService->filterIdsToEligible(
            $visibleIds,
            $eligibleQuery,
            $isPublicCollection ? 'public_collection' : 'download',
            $tenant->id
        );
        if (empty($visibleIds)) {
            if ($isPublicCollection) {
                return response()->json(['message' => 'This collection has no assets to download.'], 422);
            }
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

        $context = $isPublicCollection ? 'public_collection' : ($brand ? 'brand' : 'collection');

        // Phase D3: Optional name (Enterprise only). D6: Public collection default name. Company template fallback.
        $title = null;
        if ($isPublicCollection && isset($collection)) {
            $title = $this->downloadNameResolver->sanitizeFilename(
                $collection->name . '-download-' . now()->format('Y-m-d')
            );
        }
        $requestName = $request->input('name');
        if ($requestName !== null && $requestName !== '') {
            if (! $this->planService->canRenameDownload($tenant)) {
                return response()->json(['message' => 'Upgrade to rename downloads.'], 422);
            }
            $title = $this->downloadNameResolver->sanitizeFilename(trim((string) $requestName));
        }
        if ($title === null) {
            $template = $tenant->settings['download_name_template'] ?? null;
            if ($template !== null && $template !== '') {
                $title = $this->downloadNameResolver->resolve(
                    $template,
                    $tenant,
                    $brand ?? $tenant->defaultBrand,
                    null
                );
            } else {
                $title = $this->downloadNameResolver->resolve(
                    \App\Services\DownloadNameResolver::DEFAULT_TEMPLATE,
                    $tenant,
                    $brand ?? $tenant->defaultBrand,
                    null
                );
            }
        }

        // Phase D3: Optional expires_at (ISO string, "never", or omit for default 30 days)
        $expiresAt = $this->expirationPolicy->calculateExpiresAt($tenant, DownloadType::SNAPSHOT);
        $requestExpiresAt = $request->input('expires_at');
        $maxDays = $this->planService->getMaxDownloadExpirationDays($tenant);
        if ($requestExpiresAt === 'never' || $requestExpiresAt === null) {
            if ($requestExpiresAt === 'never' && $this->planService->canCreateNonExpiringDownload($tenant)) {
                $expiresAt = null;
            } elseif ($requestExpiresAt === 'never') {
                return $this->downloadCreateError($request, 'expires_at', 'Upgrade to create non-expiring downloads.');
            }
            // omit or null without "never" → keep default (already set)
        } else {
            $expiresAt = Carbon::parse($requestExpiresAt);
            if ($expiresAt->isPast()) {
                return $this->downloadCreateError($request, 'expires_at', 'Expiration date must be in the future.');
            }
            $daysFromNow = (int) now()->diffInDays($expiresAt, false);
            if ($daysFromNow > $maxDays && ! $this->planService->canCreateNonExpiringDownload($tenant)) {
                return $this->downloadCreateError(
                    $request,
                    'expires_at',
                    "This plan allows up to {$maxDays} days. Upgrade for longer or non-expiring downloads."
                );
            }
        }

        // D11: Enterprise Download Policy — enforce organizational rules (Enterprise only)
        if ($this->downloadPolicy->disallowNonExpiring($tenant) && $requestExpiresAt === 'never') {
            return $this->downloadCreateError($request, 'expires_at', 'Your organization requires an expiration date.');
        }
        $forcedDays = $this->downloadPolicy->forceExpirationDays($tenant);
        if ($forcedDays !== null) {
            $expiresAt = now()->addDays($forcedDays);
        }

        $hardDeleteAt = $expiresAt ? $this->expirationPolicy->calculateHardDeleteAt(
            (new Download)->setRelation('tenant', $tenant),
            $expiresAt
        ) : null;

        // Phase D3: Optional access_mode (plan-gated). D6: Public collection downloads are always public.
        $accessMode = $isPublicCollection ? DownloadAccessMode::PUBLIC : DownloadAccessMode::PUBLIC;
        $requestAccessMode = $isPublicCollection ? null : $request->input('access_mode');
        if (is_string($requestAccessMode) && $requestAccessMode !== '') {
            $mode = strtolower($requestAccessMode);
            if ($mode === 'brand' && $this->planService->canRestrictDownloadToBrand($tenant)) {
                $accessMode = DownloadAccessMode::BRAND;
            } elseif (($mode === 'company' || $mode === 'team') && $this->planService->canRestrictDownloadToCompany($tenant)) {
                $accessMode = DownloadAccessMode::COMPANY;
            } elseif (($mode === 'users' || $mode === 'restricted') && $this->planService->canRestrictDownloadToUsers($tenant)) {
                $accessMode = DownloadAccessMode::USERS;
            } elseif ($mode !== 'public') {
                return $this->downloadCreateError($request, 'access_mode', 'Your plan does not allow this access scope. Upgrade to unlock.');
            }
        }

        $allowedUserIds = [];
        if ($accessMode === DownloadAccessMode::USERS) {
            $raw = $request->input('allowed_users');
            if (is_array($raw)) {
                $allowedUserIds = array_values(array_filter(array_map('intval', $raw)));
            }
            $allowedUserIds = User::query()
                ->where('id', $allowedUserIds)
                ->whereHas('tenants', fn ($q) => $q->where('tenants.id', $tenant->id))
                ->pluck('id')
                ->all();
        }

        $slug = $this->uniqueSlug($tenant->id);

        // D7: Optional password protection (Enterprise only). Plain text → bcrypt.
        $passwordHash = null;
        $requestPassword = $request->input('password');
        if ($requestPassword !== null && $requestPassword !== '') {
            if (! $this->planService->canPasswordProtectDownload($tenant)) {
                return response()->json(['message' => 'Password protection requires Enterprise plan.'], 422);
            }
            $passwordHash = bcrypt($requestPassword);
        }

        // D11: Enterprise Download Policy — public downloads may require password
        if ($accessMode === DownloadAccessMode::PUBLIC && $this->downloadPolicy->requirePasswordForPublic($tenant) && ! $passwordHash) {
            return $this->downloadCreateError($request, 'password', 'Your organization requires a password for public downloads.');
        }

        // D7/R3.1: Landing page — opt-in + copy overrides only. Logo/accent from brand (R3.2).
        $usesLandingPage = false;
        $landingCopy = null;
        if ($this->planService->canBrandDownload($tenant)) {
            $usesLandingPage = $request->boolean('uses_landing_page');
            $requestLandingCopy = $request->input('landing_copy');
            if (is_array($requestLandingCopy) && ! empty($requestLandingCopy)) {
                $landingCopy = $this->sanitizeLandingCopy($requestLandingCopy);
            }
        }
        // D11: Enterprise Download Policy — public downloads may require landing page
        if ($accessMode === DownloadAccessMode::PUBLIC && $this->downloadPolicy->requireLandingPageForPublic($tenant)) {
            $usesLandingPage = true;
        }
        // Legacy: still accept branding_options but ignore logo_url and accent_color (R3.1)
        $brandingOptions = null;
        $requestBranding = $request->input('branding_options');
        if (is_array($requestBranding) && ! empty($requestBranding)) {
            if (! $this->planService->canBrandDownload($tenant)) {
                return $this->downloadCreateError($request, 'branding_options', 'Branded download pages require Pro or Enterprise plan.');
            }
            $brandingOptions = $this->sanitizeBrandingOptions($requestBranding);
        }

        DB::beginTransaction();
        try {
            $downloadOptions = ['context' => $context];
            if ($isPublicCollection && isset($collection)) {
                $downloadOptions['collection_id'] = $collection->id;
            }

            $download = Download::create([
                'tenant_id' => $tenant->id,
                'brand_id' => $brand?->id,
                'created_by_user_id' => $user->id,
                'download_type' => DownloadType::SNAPSHOT,
                'source' => $isPublicCollection ? DownloadSource::PUBLIC_COLLECTION->value : $source,
                'title' => $title,
                'slug' => $slug,
                'version' => 1,
                'status' => DownloadStatus::READY,
                'zip_status' => ZipStatus::NONE,
                'expires_at' => $expiresAt,
                'hard_delete_at' => $hardDeleteAt,
                'download_options' => $downloadOptions,
                'access_mode' => $accessMode,
                'allow_reshare' => true,
                'password_hash' => $passwordHash,
                'branding_options' => $brandingOptions,
                'uses_landing_page' => $usesLandingPage,
                'landing_copy' => $landingCopy,
            ]);

            foreach ($visibleIds as $i => $assetId) {
                $download->assets()->attach($assetId, ['is_primary' => $i === 0]);
            }

            if ($accessMode === DownloadAccessMode::USERS && ! empty($allowedUserIds)) {
                $download->allowedUsers()->sync($allowedUserIds);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[DownloadController] Failed to create download', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return $this->downloadCreateError($request, 'message', 'Failed to create download.', 500);
        }

        if (! $isPublicCollection) {
            $this->bucket->clear();
        }

        BuildDownloadZipJob::dispatch($download->id);

        // Inertia requests (e.g. from Create Download panel) receive redirect. Phase D3: flash for toast.
        if ($request->header('X-Inertia')) {
            return redirect()->route('downloads.index')->with('success', 'Download created');
        }

        $publicUrl = route('downloads.public', ['download' => $download->id]);

        return response()->json([
            'download_id' => $download->id,
            'public_url' => $publicUrl,
            'expires_at' => $expiresAt?->toIso8601String(),
            'asset_count' => count($visibleIds),
        ]);
    }

    /**
     * UX-R2: Single-asset download. POST /app/assets/{asset}/download.
     * Authorize view, enforce eligibility, create Download (source SINGLE_ASSET), stream/redirect file, log access.
     */
    public function downloadSingleAsset(Request $request, Asset $asset): JsonResponse|RedirectResponse
    {
        Gate::authorize('view', $asset);

        $user = Auth::user();
        $tenant = app('tenant');
        if (! $tenant || $asset->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Asset not found.'], 404);
        }

        // D11: Enterprise Download Policy — single-asset download may be disabled
        if ($this->downloadPolicy->disableSingleAssetDownloads($tenant)) {
            Log::info('download.policy.enforced', [
                'tenant_id' => $tenant->id,
                'policy' => 'disable_single_asset_downloads',
                'action' => 'blocked_creation',
            ]);

            return response()->json(['message' => 'Your organization requires downloads to be packaged.'], 403);
        }

        if (! $this->assetEligibilityService->isEligibleForDownloads($asset)) {
            return response()->json(['message' => 'This asset is not available for download.'], 403);
        }

        if (! $asset->storage_root_path) {
            return response()->json(['message' => 'File not available.'], 404);
        }

        $expiresAt = $this->expirationPolicy->calculateExpiresAt($tenant, DownloadType::SNAPSHOT);
        $hardDeleteAt = $expiresAt ? $this->expirationPolicy->calculateHardDeleteAt(
            (new Download)->setRelation('tenant', $tenant),
            $expiresAt
        ) : null;

        $slug = $this->uniqueSlug($tenant->id);
        $brand = app('brand');

        DB::beginTransaction();
        try {
            $download = Download::create([
                'tenant_id' => $tenant->id,
                'brand_id' => $brand?->id,
                'created_by_user_id' => $user->id,
                'download_type' => DownloadType::SNAPSHOT,
                'source' => DownloadSource::SINGLE_ASSET->value,
                'title' => null,
                'slug' => $slug,
                'version' => 1,
                'status' => DownloadStatus::READY,
                'zip_status' => ZipStatus::NONE,
                'zip_path' => null,
                'direct_asset_path' => $asset->storage_root_path,
                'zip_size_bytes' => $asset->size_bytes ?? 0,
                'expires_at' => $expiresAt,
                'hard_delete_at' => $hardDeleteAt,
                'download_options' => ['asset_id' => $asset->id],
                'access_mode' => DownloadAccessMode::PUBLIC,
                'allow_reshare' => true,
            ]);

            $download->assets()->attach($asset->id, ['is_primary' => true]);
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[DownloadController] Single-asset download create failed', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $message = 'Failed to create download.';
            if (config('app.debug')) {
                $message .= ' ' . $e->getMessage();
            }
            return response()->json(['message' => $message], 500);
        }

        DownloadEventEmitter::emitDownloadGroupCreated($download);
        $this->logDownloadAccess($download, 'download.access.granted', 'single_asset');
        app(\App\Services\AssetDownloadMetricService::class)->recordFromDownload($download, 'single_asset');

        try {
            $filename = $asset->original_filename ?: basename($asset->storage_root_path) ?: 'download';
            $filename = preg_replace('/[\r\n"\\\\]/', '', $filename) ?: 'download';
            $signedUrl = $this->createPresignedDownloadUrl($asset->storage_root_path, $filename, 15);

            if ($request->wantsJson() || $request->header('X-Requested-With') === 'XMLHttpRequest') {
                return response()->json([
                    'download_url' => $signedUrl,
                    'filename' => $filename,
                ]);
            }

            return redirect($signedUrl);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['message' => 'Failed to generate download link.'], 500);
        }
    }

    /**
     * Create a presigned S3 URL that forces download (Content-Disposition: attachment).
     */
    private function createPresignedDownloadUrl(string $key, string $filename, int $minutes = 15): string
    {
        $bucket = config('filesystems.disks.s3.bucket');
        $client = new S3Client([
            'version' => 'latest',
            'region' => config('filesystems.disks.s3.region'),
            'credentials' => [
                'key' => config('filesystems.disks.s3.key'),
                'secret' => config('filesystems.disks.s3.secret'),
            ],
            'endpoint' => config('filesystems.disks.s3.endpoint'),
            'use_path_style_endpoint' => config('filesystems.disks.s3.use_path_style_endpoint', false),
        ]);

        $cmd = $client->getCommand('GetObject', [
            'Bucket' => $bucket,
            'Key' => $key,
            'ResponseContentDisposition' => 'attachment; filename="' . str_replace('"', '\\"', $filename) . '"',
        ]);
        $request = $client->createPresignedRequest($cmd, '+' . $minutes . ' minutes');

        return (string) $request->getUri();
    }

    /**
     * Phase D3: Return current tenant users for "Specific users" picker (Enterprise).
     */
    public function companyUsers(Request $request): JsonResponse
    {
        $tenant = app('tenant');
        if (! $tenant) {
            return response()->json(['users' => []], 200);
        }
        $users = $tenant->users()
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'email'])
            ->map(fn ($u) => [
                'id' => $u->id,
                'first_name' => $u->first_name,
                'last_name' => $u->last_name,
                'email' => $u->email,
            ])
            ->values()
            ->all();

        return response()->json(['users' => $users]);
    }

    private function uniqueSlug(int $tenantId): string
    {
        do {
            $slug = Str::lower(Str::random(12));
        } while (Download::where('tenant_id', $tenantId)->where('slug', $slug)->exists());

        return $slug;
    }

    // IMPORTANT (Download mutation errors — Inertia-safe, phase locked):
    // Download actions must never return raw JSON to Inertia requests.
    // Use downloadCreateError() for store(); use downloadActionError() for revoke, regenerate, updateSettings, extend, changeAccess.

    /**
     * Return download create validation/error response. Inertia requests get redirect back with errors
     * so the Create Download panel can show them inline; non-Inertia gets JSON.
     */
    private function downloadCreateError(Request $request, string $key, string $message, int $status = 422): JsonResponse|RedirectResponse
    {
        if ($request->header('X-Inertia')) {
            return redirect()->back()
                ->withErrors([$key => $message])
                ->withInput($request->only([
                    'name', 'expires_at', 'access_mode', 'allowed_users', 'password',
                    'uses_landing_page', 'landing_copy', 'landing_headline', 'landing_subtext', 'branding_options',
                ]));
        }

        return response()->json(['message' => $message], $status);
    }

    /**
     * Return download action error (revoke, regenerate, updateSettings, extend, changeAccess).
     * Inertia requests get redirect back with errors + input; optional flash so frontend can reopen the dialog.
     *
     * @param  array<int, string>  $inputKeys  Keys to preserve in session for Inertia (e.g. access_mode, password).
     * @param  string|null  $action  Action name for flash (revoke, regenerate, settings, extend) so frontend reopens the right dialog.
     * @param  string|null  $downloadId  Download id for flash so frontend can reopen dialog for that download.
     */
    private function downloadActionError(Request $request, string $key, string $message, int $status = 422, array $inputKeys = [], ?string $action = null, ?string $downloadId = null): JsonResponse|RedirectResponse
    {
        if ($request->header('X-Inertia')) {
            $redirect = redirect()->back()
                ->withErrors([$key => $message])
                ->withInput($inputKeys ? $request->only($inputKeys) : []);
            if ($action !== null && $downloadId !== null) {
                $redirect->with('download_action', $action)->with('download_action_id', $downloadId);
            }

            return $redirect;
        }

        return response()->json(['message' => $message], $status);
    }

    /**
     * Phase D4: Map download for index with derived state, can_regenerate, can_extend, can_revoke, password_protected.
     */
    private function mapDownloadForHistory(Download $d, bool $canManage = false, $user = null, array $features = []): array
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

        $isSingleAsset = $d->source === DownloadSource::SINGLE_ASSET;
        $landingCopy = $d->landing_copy ?? [];
        $planRevoke = ($features['revoke'] ?? false) === true;
        $isCreator = $user && $d->created_by_user_id !== null && (int) $d->created_by_user_id === (int) $user->id;
        $canRevoke = $planRevoke && ($canManage || $isCreator);

        return [
            'id' => $d->id,
            'slug' => $d->slug,
            'title' => $d->title,
            'source' => $d->source->value,
            'status' => $d->status->value,
            'zip_status' => $d->zip_status->value,
            'state' => $d->getState(),
            'can_regenerate' => ! $isSingleAsset && $canManage && ($features['regenerate'] ?? false),
            'can_extend' => $canManage && ($features['extend_expiration'] ?? false),
            'can_revoke' => $canRevoke,
            'access_mode' => $d->access_mode?->value ?? $d->access_mode,
            'allowed_user_ids' => $d->relationLoaded('allowedUsers') ? $d->allowedUsers->pluck('id')->all() : [],
            'uses_landing_page' => (bool) ($d->uses_landing_page ?? false),
            'password_protected' => ! empty($d->password_hash),
            'landing_copy' => [
                'headline' => $landingCopy['headline'] ?? '',
                'subtext' => $landingCopy['subtext'] ?? '',
            ],
            'expires_at' => $d->expires_at?->toIso8601String(),
            'revoked_at' => $d->revoked_at?->toIso8601String(),
            'asset_count' => $d->assets->count(),
            'zip_size_bytes' => $d->zip_size_bytes,
            'public_url' => $isSingleAsset ? null : route('downloads.public', ['download' => $d->id]),
            'created_at' => $d->created_at->toIso8601String(),
            'created_by' => $d->createdBy ? [
                'id' => $d->createdBy->id,
                'name' => $d->createdBy->name ?? $d->createdBy->email,
                'avatar_url' => $d->createdBy->avatar_url ?? null,
            ] : null,
            'thumbnails' => $thumbnails,
        ];
    }

    /**
     * Public download page or ZIP redirect. GET /d/{download}.
     * D7: If password_hash is set, require session unlock before serving ZIP.
     */
    public function download(Request $request, Download $download): RedirectResponse|JsonResponse|Response|\Illuminate\Http\Response
    {
        // Verify download exists and is accessible — HTML-facing error page for browsers
        if ($download->trashed()) {
            return $this->downloadPublicErrorResponse($request, $download, 'not_found', 'This link is invalid or has been removed.', 404);
        }

        // Phase D2: Revoked downloads are inaccessible
        if ($download->isRevoked()) {
            return $this->downloadPublicErrorResponse($request, $download, 'revoked', 'This download has been revoked.', 410);
        }

        // For non-public downloads, verify tenant scope (Phase D1: only resolve tenant when needed)
        if ($download->access_mode !== DownloadAccessMode::PUBLIC) {
            $tenant = app()->bound('tenant') ? app('tenant') : null;
            if (! $tenant || $download->tenant_id !== $tenant->id) {
                return $this->downloadPublicErrorResponse($request, $download, 'not_found', 'This link is invalid or has been removed.', 404);
            }
        }

        // D7: Password-protected public downloads — require session unlock before ZIP or processing view
        if ($download->password_hash && $download->access_mode === DownloadAccessMode::PUBLIC) {
            $unlocked = session('download_unlocked.' . $download->id, false);
            if (! $unlocked) {
                $this->logDownloadAccess($download, 'download.access.attempt');
                if (! $request->expectsJson()) {
                    return Inertia::render('Downloads/Public', $this->publicPageProps($download, 'password_required', 'This download is protected.', [
                        'password_required' => true,
                        'download_id' => $download->id,
                        'unlock_url' => route('downloads.public.unlock', ['download' => $download->id]),
                    ]));
                }
                return response()->json(['message' => 'This download is password protected.'], 403);
            }
        }

        // Phase D4: When download is not ready, show HTML page for browsers (trust signals) with correct HTTP status
        $state = $download->getState();
        if ($state !== 'ready') {
            $message = $this->getPublicStateMessage($state);
            $httpStatus = in_array($state, ['revoked', 'expired'], true) ? 410 : 422;
            return $this->downloadPublicErrorResponse($request, $download, $state, $message, $httpStatus, ['password_required' => false]);
        }

        // Validate download status
        if ($download->status !== DownloadStatus::READY) {
            return $this->downloadPublicErrorResponse($request, $download, 'failed', $this->getStatusErrorMessage($download->status), 422);
        }

        // Validate ZIP status
        if ($download->zip_status !== ZipStatus::READY) {
            return $this->downloadPublicErrorResponse($request, $download, 'failed', $this->getZipStatusErrorMessage($download->zip_status), 422);
        }

        // Validate ZIP path exists
        if (! $download->zip_path) {
            Log::error('[DownloadController] Download ZIP path is missing', [
                'download_id' => $download->id,
            ]);
            return $this->downloadPublicErrorResponse($request, $download, 'failed', 'ZIP file not available.', 404);
        }

        // Validate access
        $accessAllowed = $this->validateAccess($download);
        if (! $accessAllowed) {
            return $this->downloadPublicErrorResponse($request, $download, 'access_denied', 'Access denied.', 403);
        }

        // Check if download is expired (Phase 2.8: also check if assets are archived)
        if ($download->isExpired()) {
            return $this->downloadPublicErrorResponse($request, $download, 'expired', 'This download has expired.', 410);
        }

        // Redirect to rate-limited delivery route; actual file delivery happens there (throttle:20,10)
        return redirect()->route('downloads.public.file', ['download' => $download->id]);
    }

    /**
     * Public file delivery. GET /d/{download}/file.
     * Rate-limited (throttle:20,10). Validates and redirects to signed S3 URL; logs and metrics on success.
     */
    public function deliverFile(Request $request, Download $download): RedirectResponse|JsonResponse|\Illuminate\Http\Response
    {
        if ($download->trashed()) {
            return $this->downloadPublicErrorResponse($request, $download, 'not_found', 'This link is invalid or has been removed.', 404);
        }
        if ($download->isRevoked()) {
            return $this->downloadPublicErrorResponse($request, $download, 'revoked', 'This download has been revoked.', 410);
        }
        if ($download->access_mode !== DownloadAccessMode::PUBLIC) {
            $tenant = app()->bound('tenant') ? app('tenant') : null;
            if (! $tenant || $download->tenant_id !== $tenant->id) {
                return $this->downloadPublicErrorResponse($request, $download, 'not_found', 'This link is invalid or has been removed.', 404);
            }
        }
        if ($download->password_hash && $download->access_mode === DownloadAccessMode::PUBLIC) {
            $unlocked = session('download_unlocked.' . $download->id, false);
            if (! $unlocked) {
                return $this->downloadPublicErrorResponse($request, $download, 'access_denied', 'Session expired. Please open the link again and enter the password.', 403);
            }
        }
        $state = $download->getState();
        if ($state !== 'ready') {
            $message = $this->getPublicStateMessage($state);
            $httpStatus = in_array($state, ['revoked', 'expired'], true) ? 410 : 422;
            return $this->downloadPublicErrorResponse($request, $download, $state, $message, $httpStatus, ['password_required' => false]);
        }
        if ($download->status !== DownloadStatus::READY) {
            return $this->downloadPublicErrorResponse($request, $download, 'failed', $this->getStatusErrorMessage($download->status), 422);
        }
        if ($download->zip_status !== ZipStatus::READY) {
            return $this->downloadPublicErrorResponse($request, $download, 'failed', $this->getZipStatusErrorMessage($download->zip_status), 422);
        }
        if (! $download->zip_path) {
            Log::error('[DownloadController] Download ZIP path is missing', ['download_id' => $download->id]);
            return $this->downloadPublicErrorResponse($request, $download, 'failed', 'ZIP file not available.', 404);
        }
        if (! $this->validateAccess($download)) {
            return $this->downloadPublicErrorResponse($request, $download, 'access_denied', 'Access denied.', 403);
        }
        if ($download->isExpired()) {
            return $this->downloadPublicErrorResponse($request, $download, 'expired', 'This download has expired.', 410);
        }

        // D11: Enterprise Download Policy — block delivery if public download does not meet organizational rules
        if ($download->access_mode === DownloadAccessMode::PUBLIC) {
            $tenant = $download->tenant;
            if ($tenant && $this->downloadPolicy->requireLandingPageForPublic($tenant) && ! $download->uses_landing_page) {
                Log::info('download.policy.enforced', [
                    'tenant_id' => $tenant->id,
                    'download_id' => $download->id,
                    'policy' => 'require_landing_page_for_public',
                    'action' => 'blocked_delivery',
                ]);

                return $this->downloadPublicErrorResponse($request, $download, 'access_denied', 'This download does not meet your organization\'s delivery requirements.', 403);
            }
            if ($tenant && $this->downloadPolicy->requirePasswordForPublic($tenant) && ! $download->password_hash) {
                Log::info('download.policy.enforced', [
                    'tenant_id' => $tenant->id,
                    'download_id' => $download->id,
                    'policy' => 'require_password_for_public',
                    'action' => 'blocked_delivery',
                ]);

                return $this->downloadPublicErrorResponse($request, $download, 'access_denied', 'This download does not meet your organization\'s delivery requirements.', 403);
            }
        }

        try {
            $base = $this->downloadNameResolver->sanitizeFilename($download->title ?? 'download');
            $filename = preg_replace('/[\r\n"\\\\]/', '', $base . '.zip') ?: 'download.zip';
            $signedUrl = $this->createPresignedDownloadUrl($download->zip_path, $filename, 10);

            DownloadEventEmitter::emitDownloadZipRequested($download);
            DownloadEventEmitter::emitDownloadZipCompleted($download);
            $this->logDownloadAccess($download, 'download.access.granted', 'zip');
            app(\App\Services\AssetDownloadMetricService::class)->recordFromDownload($download, 'zip');

            return redirect($signedUrl);
        } catch (\Exception $e) {
            Log::error('[DownloadController] Failed to generate download URL', [
                'download_id' => $download->id,
                'zip_path' => $download->zip_path,
                'error' => $e->getMessage(),
            ]);
            return $this->downloadPublicErrorResponse($request, $download, 'failed', 'Failed to generate download URL. Please try again later.', 500);
        }
    }

    /**
     * D7: Unlock password-protected public download. POST /d/{download}/unlock.
     * On success: session flag set, redirect to GET /d/{download}. On failure: log denied, redirect back with error.
     */
    public function unlock(Request $request, Download $download): RedirectResponse|JsonResponse
    {
        if ($download->trashed() || $download->isRevoked()) {
            return response()->json(['message' => 'Download not found.'], 404);
        }
        if ($download->access_mode !== DownloadAccessMode::PUBLIC || ! $download->password_hash) {
            return response()->json(['message' => 'This download does not require a password.'], 400);
        }

        $password = $request->input('password');
        if (! is_string($password) || $password === '') {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Password is required.'], 422);
            }
            return redirect()->route('downloads.public', ['download' => $download->id])
                ->withInput($request->only('password'))
                ->withErrors(['password' => 'Password is required.']);
        }

        if (! Hash::check($password, $download->password_hash)) {
            $this->logDownloadAccess($download, 'download.access.denied');
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Incorrect password.'], 403);
            }
            return redirect()->route('downloads.public', ['download' => $download->id])
                ->withInput($request->only('password'))
                ->withErrors(['password' => 'Incorrect password.']);
        }

        session(['download_unlocked.' . $download->id => true]);
        $this->logDownloadAccess($download, 'download.access.granted', 'zip');

        if ($request->expectsJson()) {
            return response()->json(['unlocked' => true, 'redirect' => route('downloads.public', ['download' => $download->id])]);
        }
        return redirect()->route('downloads.public', ['download' => $download->id]);
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

        // Map legacy TEAM/RESTRICTED to COMPANY/USERS
        $mode = $download->access_mode;
        if ($mode === DownloadAccessMode::TEAM) {
            $mode = DownloadAccessMode::COMPANY;
        }
        if ($mode === DownloadAccessMode::RESTRICTED) {
            $mode = DownloadAccessMode::USERS;
        }

        switch ($mode) {
            case DownloadAccessMode::PUBLIC:
                return true;

            case DownloadAccessMode::BRAND:
                if (! $user) {
                    return false;
                }
                if (! $download->brand_id) {
                    return false;
                }
                return $user->brands()
                    ->where('brands.id', $download->brand_id)
                    ->wherePivotNull('removed_at')
                    ->exists();

            case DownloadAccessMode::COMPANY:
                if (! $user) {
                    return false;
                }
                return $user->tenants()
                    ->where('tenants.id', $download->tenant_id)
                    ->exists();

            case DownloadAccessMode::USERS:
                if (! $user) {
                    return false;
                }
                return $download->allowedUsers()->where('users.id', $user->id)->exists();

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

    /**
     * Return HTML (Inertia Public page) or JSON for public download errors. Browser gets branded or default Jackpot page.
     *
     * @param  array<string, mixed>  $extra
     */
    private function downloadPublicErrorResponse(Request $request, Download $download, string $state, string $message, int $status, array $extra = []): JsonResponse|\Illuminate\Http\Response
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], $status);
        }
        $response = Inertia::render('Downloads/Public', $this->publicPageProps($download, $state, $message, $extra))
            ->toResponse($request)
            ->setStatusCode($status);

        return $response;
    }

    /**
     * D10: Build props for public download page. Copy from landing_copy then brand defaults; visuals from brand (logo_asset_id, color_role, background_asset_ids).
     * Legacy: if brand settings empty and download has branding_options.logo_url or accent_color, use those read-only (do not write back).
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function publicPageProps(Download $download, string $state, string $message, array $extra = []): array
    {
        $download->loadMissing('brand');
        $landingCopy = $download->landing_copy ?? [];
        $legacy = $download->branding_options ?? [];
        $brand = $download->brand_id ? $download->brand : null;
        $brandSettings = $brand ? ($brand->download_landing_settings ?? []) : [];

        // Copy: download landing_copy > legacy > brand default_headline/default_subtext
        $brandingOptions = [
            'headline' => $landingCopy['headline'] ?? $legacy['headline'] ?? $brandSettings['default_headline'] ?? null,
            'subtext' => $landingCopy['subtext'] ?? $legacy['subtext'] ?? $brandSettings['default_subtext'] ?? null,
        ];

        // D10: Logo — brand logo_asset_id → thumbnail URL; legacy fallback (read-only) when brand has no logo_asset_id
        if (! empty($brandSettings['logo_asset_id']) && $brand) {
            $logoAsset = Asset::where('id', $brandSettings['logo_asset_id'])->where('brand_id', $brand->id)->first();
            if ($logoAsset) {
                $brandingOptions['logo_url'] = route('assets.thumbnail.final', ['asset' => $logoAsset->id, 'style' => 'thumb']);
            }
        }
        if (empty($brandingOptions['logo_url']) && ! empty($legacy['logo_url'])) {
            // Backward compatibility: existing download with legacy logo_url; brand settings empty. Do NOT write back.
            $brandingOptions['logo_url'] = $legacy['logo_url'];
        }

        // D10: Accent — resolve from brand palette via color_role; no raw hex in DB. Legacy fallback when brand has no color_role.
        $colorRole = $brandSettings['color_role'] ?? null;
        if ($colorRole && $brand) {
            $resolved = match ($colorRole) {
                'primary' => $brand->primary_color,
                'secondary' => $brand->secondary_color,
                'accent' => $brand->accent_color,
                default => $brand->primary_color,
            };
            if (! empty($resolved)) {
                $brandingOptions['accent_color'] = $resolved;
            }
        }
        if (empty($brandingOptions['accent_color']) && ! empty($legacy['accent_color'])) {
            // Backward compatibility: existing download with legacy accent_color. Do NOT write back.
            $brandingOptions['accent_color'] = $legacy['accent_color'];
        }
        if (empty($brandingOptions['accent_color'])) {
            $brandingOptions['accent_color'] = '#4F46E5';
        }
        $brandingOptions['overlay_color'] = $brandingOptions['accent_color'];

        // D10.1: Random background — choose one image per request; use optimized thumbnail (medium for full-screen)
        $backgroundImageUrl = null;
        if (! empty($brandSettings['background_asset_ids']) && is_array($brandSettings['background_asset_ids']) && $brand) {
            $backgroundIds = $brandSettings['background_asset_ids'];
            $chosenId = Arr::random($backgroundIds);
            $backgroundAsset = Asset::where('brand_id', $brand->id)->where('id', $chosenId)->first();
            if ($backgroundAsset) {
                $backgroundImageUrl = route('assets.thumbnail.final', ['asset' => $backgroundAsset->id, 'style' => 'medium']);
            }
        }
        $brandingOptions['background_image_url'] = $backgroundImageUrl;

        return array_merge([
            'state' => $state,
            'message' => $message,
            'uses_landing_page' => $download->uses_landing_page ?? false,
            'landing_copy' => $landingCopy,
            'branding_options' => $brandingOptions,
        ], $extra);
    }

    /**
     * D7: Sanitize branding options — no arbitrary HTML. R3.1: Ignore logo_url and accent_color (from brand).
     *
     * @param array<string, mixed> $input
     * @return array{headline?: string, subtext?: string}
     */
    private function sanitizeBrandingOptions(array $input): array
    {
        $out = [];
        if (isset($input['headline']) && is_string($input['headline'])) {
            $out['headline'] = substr(trim(strip_tags($input['headline'])), 0, 200);
        }
        if (isset($input['subtext']) && is_string($input['subtext'])) {
            $out['subtext'] = substr(trim(strip_tags($input['subtext'])), 0, 500);
        }
        return $out;
    }

    /**
     * R3.1: Sanitize landing copy (headline, subtext only).
     *
     * @param array<string, mixed> $input
     * @return array{headline?: string, subtext?: string}
     */
    private function sanitizeLandingCopy(array $input): array
    {
        $out = [];
        if (isset($input['headline']) && is_string($input['headline'])) {
            $out['headline'] = substr(trim(strip_tags($input['headline'])), 0, 200);
        }
        if (isset($input['subtext']) && is_string($input['subtext'])) {
            $out['subtext'] = substr(trim(strip_tags($input['subtext'])), 0, 500);
        }
        return $out;
    }

    /**
     * D7: Audit log for download access (attempt, granted, denied). Read-only; no UI.
     * D9: When access is granted, also record to ActivityEvent for internal analytics.
     */
    private function logDownloadAccess(Download $download, string $event, ?string $context = null): void
    {
        $request = request();
        $ipHash = hash('sha256', $request->ip() . config('app.key'));
        Log::info($event, [
            'download_id' => $download->id,
            'tenant_id' => $download->tenant_id,
            'ip_hash' => $ipHash,
            'user_agent' => $request->userAgent(),
        ]);
        if ($event === 'download.access.granted' && $context !== null) {
            try {
                ActivityRecorder::record(
                    $download->tenant_id,
                    EventType::DOWNLOAD_ACCESS_GRANTED,
                    $download,
                    Auth::user(),
                    $download->brand_id,
                    ['ip_hash' => $ipHash, 'context' => $context]
                );
            } catch (\Throwable $e) {
                Log::warning('[DownloadController] Failed to record download.access.granted to ActivityEvent', [
                    'download_id' => $download->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    protected function getPublicStateMessage(string $state): string
    {
        return match ($state) {
            'processing' => "We're preparing this download. Please check back shortly.",
            'expired' => 'This download has expired.',
            'revoked' => 'This download is no longer available.',
            'failed' => "This download couldn't be prepared. Please contact the owner.",
            default => 'This download is not available.',
        };
    }

    /**
     * Phase D2: Revoke a download (plan-gated).
     */
    public function revoke(Request $request, Download $download): JsonResponse|RedirectResponse
    {
        $user = Auth::user();
        $tenant = app('tenant');

        if (! $tenant) {
            return $this->downloadActionError($request, 'message', 'You cannot manage downloads.', 403, [], 'revoke', $download->id);
        }

        if ($download->tenant_id !== $tenant->id) {
            return $this->downloadActionError($request, 'message', 'Download not found.', 404, [], 'revoke', $download->id);
        }

        if (! $this->planService->canRevokeDownload($tenant)) {
            return $this->downloadActionError($request, 'message', 'Your plan does not allow revoking downloads.', 403, [], 'revoke', $download->id);
        }

        if ($download->isRevoked()) {
            return $this->downloadActionError($request, 'message', 'Download is already revoked.', 422, [], 'revoke', $download->id);
        }

        // Allow tenant/brand managers or the creator to revoke
        $isCreator = $download->created_by_user_id !== null && (int) $download->created_by_user_id === (int) $user->id;
        if (! $this->canManageDownload($user, $tenant) && ! $isCreator) {
            return $this->downloadActionError($request, 'message', 'You cannot manage this download.', 403, [], 'revoke', $download->id);
        }

        $this->managementService->revoke($download, $user);

        if ($request->header('X-Inertia')) {
            return redirect()->route('downloads.index');
        }

        return response()->json(['message' => 'Download revoked.']);
    }

    /**
     * Phase D2: Extend expiration (plan-gated).
     */
    public function extend(Request $request, Download $download): JsonResponse|RedirectResponse
    {
        $user = Auth::user();
        $tenant = app('tenant');

        if (! $tenant || ! $this->canManageDownload($user, $tenant)) {
            return $this->downloadActionError($request, 'message', 'You cannot manage downloads.', 403, [], 'extend', $download->id);
        }

        if ($download->tenant_id !== $tenant->id) {
            return $this->downloadActionError($request, 'message', 'Download not found.', 404, [], 'extend', $download->id);
        }

        if (! $this->planService->canExtendDownloadExpiration($tenant)) {
            return $this->downloadActionError($request, 'message', 'Your plan does not allow extending download expiration.', 403, [], 'extend', $download->id);
        }

        $request->validate([
            'expires_at' => 'required|date|after:now',
        ]);

        $newExpiresAt = \Carbon\Carbon::parse($request->input('expires_at'));
        $this->managementService->extend($download, $newExpiresAt, $user);

        if ($request->header('X-Inertia')) {
            return redirect()->route('downloads.index');
        }

        return response()->json([
            'message' => 'Expiration extended.',
            'expires_at' => $download->fresh()->expires_at?->toIso8601String(),
        ]);
    }

    /**
     * Phase D2: Change access scope (plan-gated).
     */
    public function changeAccess(Request $request, Download $download): JsonResponse|RedirectResponse
    {
        $user = Auth::user();
        $tenant = app('tenant');

        if (! $tenant || ! $this->canManageDownload($user, $tenant)) {
            return $this->downloadActionError($request, 'message', 'You cannot manage downloads.', 403, [], 'settings', $download->id);
        }

        if ($download->tenant_id !== $tenant->id) {
            return $this->downloadActionError($request, 'message', 'Download not found.', 404, [], 'settings', $download->id);
        }

        $accessMode = $request->input('access_mode');
        $validModes = [DownloadAccessMode::PUBLIC->value];
        if ($this->planService->canRestrictDownloadToBrand($tenant)) {
            $validModes[] = DownloadAccessMode::BRAND->value;
        }
        if ($this->planService->canRestrictDownloadToCompany($tenant)) {
            $validModes[] = DownloadAccessMode::COMPANY->value;
        }
        if ($this->planService->canRestrictDownloadToUsers($tenant)) {
            $validModes[] = DownloadAccessMode::USERS->value;
        }

        if (! in_array($accessMode, $validModes, true)) {
            return $this->downloadActionError($request, 'message', 'Invalid access mode or not allowed by your plan.', 422, ['access_mode', 'user_ids'], 'settings', $download->id);
        }

        $userIds = $accessMode === DownloadAccessMode::USERS->value
            ? $request->input('user_ids', [])
            : null;

        $this->managementService->changeAccess($download, $accessMode, $userIds, $user);

        if ($request->header('X-Inertia')) {
            return redirect()->route('downloads.index');
        }

        return response()->json(['message' => 'Access updated.']);
    }

    /**
     * Update download settings (access, landing page, password). Plan-gated. Not for single-asset downloads.
     */
    public function updateSettings(Request $request, Download $download): JsonResponse|RedirectResponse
    {
        $user = Auth::user();
        $tenant = app('tenant');
        $settingsInputKeys = ['access_mode', 'user_ids', 'uses_landing_page', 'landing_copy', 'password'];

        if (! $tenant || ! $this->canManageDownload($user, $tenant)) {
            return $this->downloadActionError($request, 'message', 'You cannot manage downloads.', 403, [], 'settings', $download->id);
        }

        if ($download->tenant_id !== $tenant->id) {
            return $this->downloadActionError($request, 'message', 'Download not found.', 404, [], 'settings', $download->id);
        }

        if ($download->source === DownloadSource::SINGLE_ASSET) {
            return $this->downloadActionError($request, 'message', 'Settings cannot be changed for individual asset downloads.', 422, $settingsInputKeys, 'settings', $download->id);
        }

        $updates = [];

        if ($request->has('access_mode')) {
            $accessMode = $request->input('access_mode');
            $validModes = [DownloadAccessMode::PUBLIC->value];
            if ($this->planService->canRestrictDownloadToBrand($tenant)) {
                $validModes[] = DownloadAccessMode::BRAND->value;
            }
            if ($this->planService->canRestrictDownloadToCompany($tenant)) {
                $validModes[] = DownloadAccessMode::COMPANY->value;
            }
            if ($this->planService->canRestrictDownloadToUsers($tenant)) {
                $validModes[] = DownloadAccessMode::USERS->value;
            }
            if (! in_array($accessMode, $validModes, true)) {
                return $this->downloadActionError($request, 'message', 'Invalid access mode or not allowed by your plan.', 422, $settingsInputKeys, 'settings', $download->id);
            }
            $updates['access_mode'] = $accessMode;
            $updates['user_ids'] = $accessMode === DownloadAccessMode::USERS->value
                ? $request->input('user_ids', [])
                : null;
        }

        if ($this->planService->canBrandDownload($tenant)) {
            if ($request->has('uses_landing_page')) {
                $updates['uses_landing_page'] = $request->boolean('uses_landing_page');
            }
            $landingCopy = $request->input('landing_copy');
            if (is_array($landingCopy)) {
                $updates['landing_copy'] = $this->sanitizeLandingCopy($landingCopy);
            }
        }

        if ($this->planService->canPasswordProtectDownload($tenant) && $request->has('password')) {
            $password = $request->input('password');
            $updates['password_hash'] = is_string($password) && $password !== '' ? Hash::make($password) : null;
        }

        if (empty($updates)) {
            return $this->downloadActionError($request, 'message', 'No settings to update.', 422, $settingsInputKeys, 'settings', $download->id);
        }

        $this->managementService->updateSettings($download, $updates, $user);

        if ($request->header('X-Inertia')) {
            return redirect()->route('downloads.index');
        }

        return response()->json(['message' => 'Settings updated.']);
    }

    /**
     * Phase D2: Regenerate download (Enterprise only).
     */
    public function regenerate(Download $download): JsonResponse|RedirectResponse
    {
        $user = Auth::user();
        $tenant = app('tenant');
        $request = request();

        if (! $tenant || ! $this->canManageDownload($user, $tenant)) {
            return $this->downloadActionError($request, 'message', 'You cannot manage downloads.', 403, [], 'regenerate', $download->id);
        }

        if ($download->tenant_id !== $tenant->id) {
            return $this->downloadActionError($request, 'message', 'Download not found.', 404, [], 'regenerate', $download->id);
        }

        if (! $this->planService->canRegenerateDownload($tenant)) {
            return $this->downloadActionError($request, 'message', 'Regenerating downloads requires Enterprise plan.', 403, [], 'regenerate', $download->id);
        }

        $this->managementService->regenerate($download, $user);

        if ($request->header('X-Inertia')) {
            return redirect()->route('downloads.index');
        }

        return response()->json(['message' => 'Download regeneration started.']);
    }

    /**
     * Phase D2: Check if user can manage downloads (not collection-only, manager/admin).
     */
    protected function canManageDownload($user, $tenant): bool
    {
        if (! $user) {
            return false;
        }

        if (app()->bound('collection_only') && app('collection_only')) {
            return false;
        }

        $tenantRole = $user->getRoleForTenant($tenant);
        if (in_array(strtolower($tenantRole ?? ''), ['owner', 'admin'])) {
            return true;
        }

        $brand = app('brand');
        if (! $brand) {
            return false;
        }

        $brandRole = $user->getRoleForBrand($brand);
        return in_array(strtolower($brandRole ?? ''), ['brand_manager', 'admin']);
    }
}
