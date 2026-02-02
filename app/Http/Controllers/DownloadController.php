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
use App\Models\User;
use App\Services\DownloadBucketService;
use App\Services\DownloadEventEmitter;
use App\Services\DownloadExpirationPolicy;
use App\Services\DownloadPublicPageBrandingResolver;
use App\Services\EnterpriseDownloadPolicy;
use App\Services\PlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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

            $sortColumn = match ($sort) {
                'date_asc' => ['created_at', 'asc'],
                'size_desc' => ['zip_size_bytes', 'desc'],
                'size_asc' => ['zip_size_bytes', 'asc'],
                default => ['created_at', 'desc'],
            };
            $query->orderBy($sortColumn[0], $sortColumn[1]);

            $perPage = 15;
            $paginator = $query->with(['assets' => function ($q) {
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
        }

        return Inertia::render('Downloads/Index', [
            'downloads' => $downloads,
            'bucket_count' => $bucketCount,
            'can_manage' => $canManage,
            'filters' => $filters,
            'pagination' => $paginationMeta,
            'download_users' => $downloadUsers,
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
                $thumbUrl = route('assets.thumbnail.final', ['asset' => $asset->id, 'style' => 'thumb']);
            } else {
                $previewThumbnails = $metadata['preview_thumbnails'] ?? [];
                if (! empty($previewThumbnails) && isset($previewThumbnails['preview'])) {
                    $thumbUrl = route('assets.thumbnail.preview', ['asset' => $asset->id, 'style' => 'preview']);
                }
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

        return [
            'id' => $download->id,
            'title' => $download->title,
            'state' => $state,
            'thumbnails' => $thumbnails,
            'expires_at' => $download->expires_at?->toIso8601String(),
            'asset_count' => $download->assets->count(),
            'zip_size_bytes' => $download->zip_size_bytes,
            'can_revoke' => (bool) ($planFeatures['revoke'] ?? false) && ($canManage || ($createdBy && $createdBy['id'] === auth()->id())),
            'can_regenerate' => (bool) ($planFeatures['regenerate'] ?? false),
            'can_extend' => (bool) ($planFeatures['extend_expiration'] ?? false),
            'public_url' => route('downloads.public', ['download' => $download->id]),
            'access_mode' => $accessMode,
            'password_protected' => $download->requiresPassword(),
            'brand' => $brandPayload,
            'brands' => $brandPayload ? [$brandPayload] : [],
            'source' => $source,
            'access_count' => (int) ($download->access_count ?? 0),
            'created_by' => $createdBy,
        ];
    }

    /**
     * Create a download from the session bucket (POST /app/downloads).
     * Validates bucket not empty, plan/policy (password, expiration, access), then creates Download and dispatches BuildDownloadZipJob.
     */
    public function store(Request $request): \Illuminate\Http\RedirectResponse
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

        BuildDownloadZipJob::dispatch($download->id);

        $bucketService->clear();

        // Inertia expects a 303 redirect for POST (not raw JSON). Send user to downloads page after creation.
        return redirect()->route('downloads.index')
            ->with('download_created', [
                'download_id' => $download->id,
                'public_url' => route('downloads.public', ['download' => $download->id]),
                'expires_at' => $expiresAt?->toIso8601String(),
                'asset_count' => count($visibleIds),
            ])
            ->setStatusCode(303);
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
    public function download(Download $download): Response|RedirectResponse
    {
        if ($download->trashed()) {
            return $this->publicPage($download, 'not_found', 'Download not found');
        }

        $tenant = app('tenant');
        if ($download->access_mode !== DownloadAccessMode::PUBLIC) {
            if (! $tenant || $download->tenant_id !== $tenant->id) {
                return $this->publicPage($download, 'not_found', 'Download not found');
            }
        }

        if ($download->status !== DownloadStatus::READY) {
            return $this->publicPage($download, 'failed', $this->getStatusErrorMessage($download->status));
        }

        if ($download->zip_status !== ZipStatus::READY) {
            $message = $download->zip_status === ZipStatus::BUILDING || $download->zip_status === ZipStatus::NONE
                ? 'We\'re preparing your download. Please try again in a moment.'
                : $this->getZipStatusErrorMessage($download->zip_status);
            return $this->publicPage($download, $download->zip_status === ZipStatus::BUILDING ? 'processing' : 'failed', $message);
        }

        if (! $download->zip_path) {
            Log::error('[DownloadController] Download ZIP path is missing', ['download_id' => $download->id]);
            return $this->publicPage($download, 'failed', 'ZIP file not available');
        }

        if (! $this->validateAccess($download)) {
            return $this->publicPage($download, 'access_denied', 'Access denied');
        }

        if ($download->isExpired()) {
            return $this->publicPage($download, 'expired', 'This download has expired');
        }

        if ($download->isRevoked()) {
            return $this->publicPage($download, 'revoked', 'This download has been revoked');
        }

        // Password-protected: show landing page (HTML) until session is unlocked; never redirect to ZIP until then.
        $requiresPassword = $download->requiresPassword();
        $isUnlocked = session('download_unlocked.' . $download->id) === true;
        if ($requiresPassword && ! $isUnlocked) {
            return $this->publicPage($download, 'password_required', 'Enter the password to continue.', true);
        }

        try {
            $disk = Storage::disk('s3');
            $signedUrl = $disk->temporaryUrl($download->zip_path, now()->addMinutes(10));

            DownloadEventEmitter::emitDownloadZipRequested($download);
            DownloadEventEmitter::emitDownloadZipCompleted($download);

            return redirect($signedUrl);
        } catch (\Exception $e) {
            Log::error('[DownloadController] Failed to generate download URL', [
                'download_id' => $download->id,
                'zip_path' => $download->zip_path,
                'error' => $e->getMessage(),
            ]);

            return $this->publicPage($download, 'failed', 'Failed to generate download URL');
        }
    }

    /**
     * Render the public download landing page (password form, expired, revoked, etc.).
     */
    protected function publicPage(Download $download, string $state, string $message = '', bool $passwordRequired = false): Response
    {
        $resolver = app(DownloadPublicPageBrandingResolver::class);
        $branding = $resolver->resolve($download, $message);

        return Inertia::render('Downloads/Public', [
            'state' => $state,
            'message' => $message,
            'password_required' => $passwordRequired,
            'download_id' => $download->id,
            'unlock_url' => $passwordRequired ? route('downloads.public.unlock', ['download' => $download->id]) : '',
            'show_landing_layout' => $branding['show_landing_layout'],
            'branding_options' => $branding['branding_options'],
        ]);
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
     * Deliver file (GET /d/{download}/file). Same logic as download(); used as alternate entry for direct file link.
     */
    public function deliverFile(Download $download): Response|RedirectResponse
    {
        return $this->download($download);
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
