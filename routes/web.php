<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\SignupController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;



// Handle OPTIONS preflight requests for CORS
Route::options('/{any}', function () {
    return response('', 200)
        ->header('Access-Control-Allow-Origin', '*')
        ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
        ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
})->where('any', '.*');

// PWA manifest — must be publicly accessible (no auth) so browsers can fetch it
Route::get('/manifest.webmanifest', function () {
    $path = public_path('manifest.webmanifest');
    if (! file_exists($path)) {
        abort(404);
    }

    return response()->file($path, [
        'Content-Type' => 'application/manifest+json',
    ]);
})->name('manifest');

// Home route - simple home page (subdomains disabled)
Route::get('/', fn () => Inertia::render('Home'));

// Contact / sales inquiry (e.g. Enterprise plan)
Route::get('/contact', fn (Request $request) => Inertia::render('Contact', [
    'plan' => $request->query('plan'),
]))->name('contact');

// Standalone cinematic experience (frontend-only, no auth)
Route::get('/experience', fn () => Inertia::render('Experience/Index'));

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
    Route::get('/signup', [SignupController::class, 'show'])->name('signup');
    Route::post('/signup', [SignupController::class, 'store']);
    Route::get('/forgot-password', [\App\Http\Controllers\Auth\ForgotPasswordController::class, 'show'])->name('password.request');
    Route::post('/forgot-password', [\App\Http\Controllers\Auth\ForgotPasswordController::class, 'store'])->name('password.email');
    Route::get('/reset-password/{token}', [\App\Http\Controllers\Auth\ForgotPasswordController::class, 'reset'])->name('password.reset');
    Route::post('/reset-password', [\App\Http\Controllers\Auth\ForgotPasswordController::class, 'update'])->name('password.update');
    Route::get('/invite/accept/{token}/{tenant}', [\App\Http\Controllers\TeamController::class, 'acceptInvite'])->name('invite.accept');
    Route::post('/invite/complete/{token}/{tenant}', [\App\Http\Controllers\TeamController::class, 'completeInviteRegistration'])->name('invite.complete');
});

// Phase C12.0: Collection-only invite accept (guest or auth; no tenant required)
Route::get('/invite/collection/{token}', [\App\Http\Controllers\CollectionAccessInviteController::class, 'acceptShow'])->name('collection-invite.accept');
Route::post('/invite/collection/{token}/accept', [\App\Http\Controllers\CollectionAccessInviteController::class, 'accept'])->middleware('auth')->name('collection-invite.accept.submit');
Route::post('/invite/collection/{token}/complete', [\App\Http\Controllers\CollectionAccessInviteController::class, 'complete'])->name('collection-invite.complete');

// Public collections (C8) — no auth, is_public only; brand-namespaced for uniqueness
Route::get('/b/{brand_slug}/collections/{collection_slug}', [\App\Http\Controllers\PublicCollectionController::class, 'show'])->name('public.collections.show');
Route::post('/b/{brand_slug}/collections/{collection_slug}/download', [\App\Http\Controllers\PublicCollectionController::class, 'createDownload'])->name('public.collections.download');
// D6: On-the-fly collection ZIP — signed URL, no Download record; throttle to prevent abuse
Route::get('/b/{brand_slug}/collections/{collection_slug}/zip', [\App\Http\Controllers\PublicCollectionController::class, 'streamZip'])->name('public.collections.zip')->middleware(['signed', 'throttle:10,1']);
Route::get('/b/{brand_slug}/collections/{collection_slug}/assets/{asset}/download', [\App\Http\Controllers\PublicCollectionController::class, 'download'])->name('public.collections.assets.download');
Route::get('/public/download/{asset}', \App\Http\Controllers\PublicDownloadController::class)->name('public.assets.download');
// Public collection branding (logo, background) — no auth; from Brand Settings > Public Pages
Route::get('/b/{brand_slug}/collections/{collection_slug}/logo', [\App\Http\Controllers\AssetThumbnailController::class, 'streamLogoForPublicCollection'])->name('public.collections.logo')->middleware(['web']);
Route::get('/b/{brand_slug}/collections/{collection_slug}/background', [\App\Http\Controllers\AssetThumbnailController::class, 'streamBackgroundForPublicCollection'])->name('public.collections.background')->middleware(['web']);

// Phase D1: Public download link (no auth — anyone with link can download)
Route::get('/d/{download}', [\App\Http\Controllers\DownloadController::class, 'download'])->name('downloads.public')->middleware(['web']);
// D-SHARE: Alias for share page (same as public)
Route::get('/downloads/{download}/share', fn (\App\Models\Download $download) => redirect()->route('downloads.public', ['download' => $download->id]))->name('downloads.share')->middleware(['web']);
// D10.1: Public background image for download landing (no auth — so background image loads for guests)
Route::get('/d/{download}/background', [\App\Http\Controllers\AssetThumbnailController::class, 'streamThumbnailForPublicDownload'])->name('downloads.public.background')->middleware(['web']);
// D10.1: Public logo for download landing (no auth — transparent, no gray block)
Route::get('/d/{download}/logo', [\App\Http\Controllers\AssetThumbnailController::class, 'streamLogoForPublicDownload'])->name('downloads.public.logo')->middleware(['web']);
// Public file delivery only (ZIP redirect) — rate-limited to prevent abuse; landing page remains unthrottled
Route::get('/d/{download}/file', [\App\Http\Controllers\DownloadController::class, 'deliverFile'])->name('downloads.public.file')->middleware(['web', 'throttle:20,10']);
// D7: Unlock password-protected download (light rate limit)
Route::post('/d/{download}/unlock', [\App\Http\Controllers\DownloadController::class, 'unlock'])->name('downloads.public.unlock')->middleware(['web', 'throttle:5,1']);
// D-SHARE: Send download link via email (rate-limited)
Route::post('/d/{download}/share-email', [\App\Http\Controllers\DownloadController::class, 'shareEmail'])->name('downloads.public.share-email')->middleware(['web', 'throttle:10,1']);

// Branded CDN 403 page — CloudFront custom error: 403 → /cdn-access-denied, response code 403, TTL 0
Route::get('/cdn-access-denied', [\App\Http\Controllers\ErrorController::class, 'cdnAccessDenied'])->name('errors.cdn-access-denied')->middleware(['web']);

// CDN test route (temporary — verify CloudFront signed cookies)
Route::get('/cdn-test', function () {
    return response()->json([
        'cookies' => request()->cookies->all(),
    ]);
})->middleware(['web', 'auth']);

// CSRF token refresh endpoint (for handling stale tokens after session regeneration)
// Accessible to authenticated users (session exists, just token may be stale)
Route::get('/csrf-token', function (Request $request) {
    return response()->json(['token' => csrf_token()]);
})->middleware(['web']);

// Performance client metrics (web + optional auth; guests can send, user_id will be null)
Route::post('/app/admin/performance/client-metric', [\App\Http\Controllers\Admin\PerformanceController::class, 'clientMetric'])
    ->middleware(['web'])
    ->name('admin.performance.client-metric');

Route::middleware(['auth', 'ensure.account.active', 'collect.asset_url_metrics', 'log.cloudfront.403'])->prefix('app')->group(function () {
    // GET /app → redirect to dashboard (avoids 405 from OPTIONS catch-all matching path /app)
    Route::get('', fn () => redirect()->route('dashboard'))->name('app');

    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
    
    // Company management (no tenant middleware - can access when no tenant selected)
    // These routes should be accessible even if user is disabled for current tenant
    Route::get('/companies', [\App\Http\Controllers\CompanyController::class, 'index'])->name('companies.index');
    Route::post('/companies/{tenant}/switch', [\App\Http\Controllers\CompanyController::class, 'switch'])->name('companies.switch');
    
    // Error pages
    // No companies error - doesn't require tenant (user has no companies)
    Route::get('/errors/no-companies', [\App\Http\Controllers\ErrorController::class, 'noCompanies'])->name('errors.no-companies');
    
    // No brand assignment error - requires tenant but should be accessible even if brand resolution fails
    // We'll handle this route specially in ResolveTenant middleware to prevent loops
    Route::get('/errors/no-brand-assignment', [\App\Http\Controllers\ErrorController::class, 'noBrandAssignment'])->name('errors.no-brand-assignment');
    
    // User limit error can be accessed even if tenant is resolved (user just can't access other routes)
    Route::get('/errors/user-limit-exceeded', [\App\Http\Controllers\ErrorController::class, 'userLimitExceeded'])->name('errors.user-limit-exceeded');
    
    // Company settings (requires tenant to be selected). C12: RestrictCollectionOnlyUser gates collection-only users.
    Route::middleware(['tenant', \App\Http\Middleware\RestrictCollectionOnlyUser::class])->group(function () {
        // Phase C12.0: Collection-only access landing (inside tenant so ResolveTenant can set collection_only)
        Route::get('/collection-access/{collection}', [\App\Http\Controllers\CollectionAccessInviteController::class, 'landing'])->name('collection-invite.landing');
        Route::get('/collection-access/{collection}/view', [\App\Http\Controllers\CollectionAccessInviteController::class, 'viewCollection'])->name('collection-invite.view');
        Route::post('/collection-access/switch/{collection}', [\App\Http\Controllers\CollectionAccessInviteController::class, 'switchCollection'])->name('collection-invite.switch');
        Route::get('/companies/settings', [\App\Http\Controllers\CompanyController::class, 'settings'])->name('companies.settings');
        Route::put('/companies/settings', [\App\Http\Controllers\CompanyController::class, 'updateSettings'])->name('companies.settings.update');
        Route::put('/companies/settings/download-policy', [\App\Http\Controllers\CompanyController::class, 'updateDownloadPolicy'])->name('companies.settings.download-policy');
        Route::put('/companies/settings/widgets', [\App\Http\Controllers\CompanyController::class, 'updateWidgetSettings'])->name('companies.settings.widgets.update');
        Route::get('/companies/permissions', [\App\Http\Controllers\CompanyController::class, 'permissions'])->name('companies.permissions');
        Route::get('/companies/team', [\App\Http\Controllers\TeamController::class, 'index'])->name('companies.team');
        Route::post('/companies/{tenant}/team/invite', [\App\Http\Controllers\TeamController::class, 'invite'])->name('companies.team.invite');
        Route::put('/companies/{tenant}/team/{user}/role', [\App\Http\Controllers\TeamController::class, 'updateTenantRole'])->name('companies.team.update-role');
        Route::put('/companies/{tenant}/team/{user}/brands/{brand}/role', [\App\Http\Controllers\TeamController::class, 'updateBrandRole'])->name('companies.team.update-brand-role');
        Route::post('/companies/{tenant}/team/{user}/add-to-brand', [\App\Http\Controllers\TeamController::class, 'addToBrand'])->name('companies.team.add-to-brand');
        Route::delete('/companies/{tenant}/team/{user}', [\App\Http\Controllers\TeamController::class, 'remove'])->name('companies.team.remove');
        Route::delete('/companies/{tenant}/team/{user}/delete-from-company', [\App\Http\Controllers\TeamController::class, 'deleteFromCompany'])->name('companies.team.delete-from-company');
        Route::get('/companies/activity', [\App\Http\Controllers\CompanyController::class, 'activity'])->name('companies.activity');
        
        // Phase AG-7: Agency Partner Dashboard (read-only)
        Route::get('/agency/dashboard', [\App\Http\Controllers\AgencyDashboardController::class, 'index'])->name('agency.dashboard');
        
        // Phase C3: Tenant metadata field management
        Route::get('/tenant/metadata/fields', [\App\Http\Controllers\TenantMetadataFieldController::class, 'index'])->name('tenant.metadata.fields.index');
        Route::get('/tenant/metadata/fields/{field}', [\App\Http\Controllers\TenantMetadataFieldController::class, 'show'])->name('tenant.metadata.fields.show');
        Route::post('/tenant/metadata/fields', [\App\Http\Controllers\TenantMetadataFieldController::class, 'store'])->name('tenant.metadata.fields.store');
        Route::put('/tenant/metadata/fields/{field}', [\App\Http\Controllers\TenantMetadataFieldController::class, 'update'])->name('tenant.metadata.fields.update');
        Route::post('/tenant/metadata/fields/{field}/disable', [\App\Http\Controllers\TenantMetadataFieldController::class, 'disable'])->name('tenant.metadata.fields.disable');
        Route::post('/tenant/metadata/fields/{field}/enable', [\App\Http\Controllers\TenantMetadataFieldController::class, 'enable'])->name('tenant.metadata.fields.enable');
        Route::post('/tenant/metadata/fields/{field}/archive', [\App\Http\Controllers\TenantMetadataFieldController::class, 'archive'])->name('tenant.metadata.fields.archive');
        Route::post('/tenant/metadata/fields/{field}/restore', [\App\Http\Controllers\TenantMetadataFieldController::class, 'restore'])->name('tenant.metadata.fields.restore');
        Route::post('/tenant/metadata/fields/{field}/ai-eligible', [\App\Http\Controllers\TenantMetadataFieldController::class, 'updateAiEligible'])->name('tenant.metadata.fields.ai-eligible');
        
        // Allowed values (options) management
        Route::post('/tenant/metadata/fields/{field}/values', [\App\Http\Controllers\TenantMetadataFieldController::class, 'addValue'])->name('tenant.metadata.fields.values.add');
        Route::delete('/tenant/metadata/fields/{field}/values/{option}', [\App\Http\Controllers\TenantMetadataFieldController::class, 'removeValue'])->name('tenant.metadata.fields.values.remove');
        
        // AI Usage tracking (admin only)
        Route::get('/api/companies/ai-usage', [\App\Http\Controllers\CompanyController::class, 'getAiUsage'])->name('companies.ai-usage');
        
            // Phase J.2.5: AI settings API endpoints (company admins only)
            Route::get('/api/companies/ai-settings', [\App\Http\Controllers\CompanyController::class, 'getAiSettings'])->name('companies.ai-settings');
            Route::patch('/api/companies/ai-settings', [\App\Http\Controllers\CompanyController::class, 'updateAiSettings'])->name('companies.ai-settings.update');
            
            // Phase J.2.6: Tag quality metrics API endpoints (company admins only)
            Route::get('/api/companies/ai-tag-metrics', [\App\Http\Controllers\CompanyController::class, 'getTagQualityMetrics'])->name('companies.ai-tag-metrics');
            Route::get('/api/companies/ai-tag-metrics/export', [\App\Http\Controllers\CompanyController::class, 'exportTagQualityMetrics'])->name('companies.ai-tag-metrics.export');
        
        // Company slug availability checking
        Route::get('/api/companies/check-slug', [\App\Http\Controllers\CompanyController::class, 'checkSlugAvailability'])->name('companies.check-slug');
        
        // Role API endpoints (canonical role lists for frontend)
        Route::get('/api/roles/tenant', [\App\Http\Controllers\RoleController::class, 'tenantRoles'])->name('api.roles.tenant');
        Route::get('/api/roles/brand', [\App\Http\Controllers\RoleController::class, 'brandRoles'])->name('api.roles.brand');
        Route::get('/api/roles/brand/approvers', [\App\Http\Controllers\RoleController::class, 'brandApproverRoles'])->name('api.roles.brand.approvers');
        
        // Permission API endpoints (canonical permission mappings for frontend)
        Route::get('/api/permissions/tenant', [\App\Http\Controllers\RoleController::class, 'tenantPermissions'])->name('api.permissions.tenant');
        Route::get('/api/permissions/brand', [\App\Http\Controllers\RoleController::class, 'brandPermissions'])->name('api.permissions.brand');
        
        // Phase AF-3: Notification API endpoints
        Route::get('/api/notifications', [\App\Http\Controllers\NotificationController::class, 'index'])->name('api.notifications.index');
        Route::post('/api/notifications/{notification}/read', [\App\Http\Controllers\NotificationController::class, 'markAsRead'])->name('api.notifications.read');

        // Downloads poll: mutable fields only for processing downloads (patch-based polling, no Inertia)
        Route::get('/api/downloads/poll', [\App\Http\Controllers\DownloadController::class, 'poll'])->name('api.downloads.poll');

        // Presence: Redis-based tenant/brand online indicator (admin/owner/brand manager only)
        Route::prefix('presence')->group(function () {
            Route::post('/heartbeat', [\App\Http\Controllers\PresenceController::class, 'heartbeat'])->name('presence.heartbeat');
            Route::get('/online', [\App\Http\Controllers\PresenceController::class, 'online'])->name('presence.online');
        });
        
        // Phase C4: Tenant metadata registry and visibility management
        Route::get('/tenant/metadata/registry', [\App\Http\Controllers\TenantMetadataRegistryController::class, 'index'])->name('tenant.metadata.registry.index');
        Route::get('/api/tenant/metadata/registry', [\App\Http\Controllers\TenantMetadataRegistryController::class, 'getRegistry'])->name('tenant.metadata.registry.api');
        Route::get('/api/tenant/metadata/fields/archived', [\App\Http\Controllers\TenantMetadataRegistryController::class, 'getArchivedFields'])->name('tenant.metadata.fields.archived');
        Route::post('/api/tenant/metadata/fields/{field}/visibility', [\App\Http\Controllers\TenantMetadataRegistryController::class, 'setVisibility'])->name('tenant.metadata.visibility.set');
        Route::delete('/api/tenant/metadata/fields/{field}/visibility', [\App\Http\Controllers\TenantMetadataRegistryController::class, 'removeVisibility'])->name('tenant.metadata.visibility.remove');
        Route::patch('/api/tenant/metadata/fields/{field}/categories/{category}/visibility', [\App\Http\Controllers\TenantMetadataRegistryController::class, 'patchCategoryFieldVisibility'])->name('tenant.metadata.category.field.visibility');
        Route::post('/api/tenant/metadata/fields/{field}/categories/{category}/suppress', [\App\Http\Controllers\TenantMetadataRegistryController::class, 'suppressForCategory'])->name('tenant.metadata.category.suppress');
        Route::delete('/api/tenant/metadata/fields/{field}/categories/{category}/suppress', [\App\Http\Controllers\TenantMetadataRegistryController::class, 'unsuppressForCategory'])->name('tenant.metadata.category.unsuppress');
        Route::get('/api/tenant/metadata/fields/{field}/categories', [\App\Http\Controllers\TenantMetadataRegistryController::class, 'getSuppressedCategories'])->name('tenant.metadata.category.list');
        Route::post('/api/tenant/metadata/categories/{targetCategory}/copy-from/{sourceCategory}', [\App\Http\Controllers\TenantMetadataRegistryController::class, 'copyCategoryFrom'])->name('tenant.metadata.category.copy-from');
        Route::post('/api/tenant/metadata/categories/{category}/reset', [\App\Http\Controllers\TenantMetadataRegistryController::class, 'resetCategory'])->name('tenant.metadata.category.reset');
        Route::get('/api/tenant/metadata/categories/{category}/apply-to-other-brands', [\App\Http\Controllers\TenantMetadataRegistryController::class, 'getApplyToOtherBrandsTargets'])->name('tenant.metadata.category.apply-to-other-brands.targets');
        Route::post('/api/tenant/metadata/categories/{category}/apply-to-other-brands', [\App\Http\Controllers\TenantMetadataRegistryController::class, 'applyToOtherBrands'])->name('tenant.metadata.category.apply-to-other-brands');
        Route::get('/api/tenant/metadata/profiles', [\App\Http\Controllers\TenantMetadataRegistryController::class, 'listProfiles'])->name('tenant.metadata.profiles.list');
        Route::get('/api/tenant/metadata/profiles/{profile}', [\App\Http\Controllers\TenantMetadataRegistryController::class, 'getProfile'])->name('tenant.metadata.profiles.show');
        Route::post('/api/tenant/metadata/profiles', [\App\Http\Controllers\TenantMetadataRegistryController::class, 'storeProfile'])->name('tenant.metadata.profiles.store');
        Route::post('/api/tenant/metadata/profiles/{profile}/apply', [\App\Http\Controllers\TenantMetadataRegistryController::class, 'applyProfile'])->name('tenant.metadata.profiles.apply');
        
        // Ownership transfer management routes
        Route::post('/companies/{tenant}/ownership-transfer/initiate', [\App\Http\Controllers\OwnershipTransferController::class, 'initiate'])
            ->name('ownership-transfer.initiate');
        Route::post('/ownership-transfer/{transfer}/cancel', [\App\Http\Controllers\OwnershipTransferController::class, 'cancel'])
            ->name('ownership-transfer.cancel');
    });
    
    // Ownership transfer routes (signed URLs for email links - outside tenant middleware for flexibility)
    Route::get('/ownership-transfer/{transfer}/confirm', [\App\Http\Controllers\OwnershipTransferController::class, 'confirm'])
        ->name('ownership-transfer.confirm')
        ->middleware('signed');
    Route::get('/ownership-transfer/{transfer}/accept', [\App\Http\Controllers\OwnershipTransferController::class, 'accept'])
        ->name('ownership-transfer.accept')
        ->middleware('signed');
    
    // Profile routes
    Route::get('/profile', [\App\Http\Controllers\ProfileController::class, 'index'])->name('profile.index');
    Route::put('/profile', [\App\Http\Controllers\ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile/avatar', [\App\Http\Controllers\ProfileController::class, 'removeAvatar'])->name('profile.avatar.remove');
    Route::put('/profile/password', [\App\Http\Controllers\ProfileController::class, 'updatePassword'])->name('profile.password.update');
    Route::delete('/profile', [\App\Http\Controllers\ProfileController::class, 'destroy'])->name('profile.destroy');

    // Site Admin routes - Command Center
    Route::get('/admin', [\App\Http\Controllers\Admin\AdminOverviewController::class, 'index'])->name('admin.index');
    Route::get('/admin/api/overview', [\App\Http\Controllers\Admin\AdminOverviewController::class, 'metrics'])->name('admin.api.overview');
    Route::get('/admin/organization', [\App\Http\Controllers\SiteAdminController::class, 'organization'])->name('admin.organization.index');
    
    // Admin API endpoints (AJAX)
    Route::get('/admin/api/stats', [\App\Http\Controllers\SiteAdminController::class, 'stats'])->name('admin.api.stats');
    Route::get('/admin/api/companies/{tenant}/details', [\App\Http\Controllers\SiteAdminController::class, 'companyDetails'])->name('admin.api.companies.details');
    Route::get('/admin/api/companies/{tenant}/users', [\App\Http\Controllers\SiteAdminController::class, 'companyUsers'])->name('admin.api.companies.users');
    Route::get('/admin/api/users', [\App\Http\Controllers\SiteAdminController::class, 'allUsers'])->name('admin.api.users');
    Route::get('/admin/api/users/selector', [\App\Http\Controllers\SiteAdminController::class, 'usersForSelector'])->name('admin.api.users.selector');
    
    Route::get('/admin/companies/{tenant}', [\App\Http\Controllers\Admin\CompanyViewController::class, 'show'])->name('admin.companies.view');
    Route::get('/admin/billing', [\App\Http\Controllers\Admin\BillingController::class, 'index'])->name('admin.billing');
    Route::get('/admin/permissions', [\App\Http\Controllers\SiteAdminController::class, 'permissions'])->name('admin.permissions');
    Route::post('/admin/permissions/debug', [\App\Http\Controllers\SiteAdminController::class, 'permissionDebug'])->name('admin.permissions.debug');
    Route::get('/admin/stripe-status', [\App\Http\Controllers\SiteAdminController::class, 'stripeStatus'])->name('admin.stripe-status');
    Route::get('/admin/documentation', [\App\Http\Controllers\SiteAdminController::class, 'documentation'])->name('admin.documentation');
    Route::get('/admin/system-status', [\App\Http\Controllers\Admin\SystemStatusController::class, 'index'])->name('admin.system-status');
    Route::get('/admin/performance', [\App\Http\Controllers\Admin\PerformanceController::class, 'index'])->name('admin.performance.index');
    Route::get('/admin/performance/api', [\App\Http\Controllers\Admin\PerformanceController::class, 'api'])->name('admin.performance.api');
    Route::get('/admin/assets', [\App\Http\Controllers\Admin\AdminAssetController::class, 'index'])->name('admin.assets.index');
    Route::post('/admin/assets/bulk-action', [\App\Http\Controllers\Admin\AdminAssetController::class, 'bulkAction'])->name('admin.assets.bulk-action');
    Route::post('/admin/assets/recover-category-id', [\App\Http\Controllers\Admin\AdminAssetController::class, 'recoverCategoryId'])->name('admin.assets.recover-category-id');
    Route::get('/admin/assets/{asset}', [\App\Http\Controllers\Admin\AdminAssetController::class, 'show'])->name('admin.assets.show');
    Route::get('/admin/assets/{asset}/download-source', [\App\Http\Controllers\Admin\AdminAssetController::class, 'downloadSource'])->name('admin.assets.download-source');
    Route::post('/admin/assets/{asset}/repair', [\App\Http\Controllers\Admin\AdminAssetController::class, 'repair'])->name('admin.assets.repair');
    Route::post('/admin/assets/{asset}/restore', [\App\Http\Controllers\Admin\AdminAssetController::class, 'restore'])->name('admin.assets.restore');
    Route::post('/admin/assets/{asset}/retry-pipeline', [\App\Http\Controllers\Admin\AdminAssetController::class, 'retryPipeline'])->name('admin.assets.retry-pipeline');
    Route::post('/admin/assets/{asset}/reanalyze', [\App\Http\Controllers\Admin\AdminAssetController::class, 'reanalyze'])->name('admin.assets.reanalyze');
    Route::post('/admin/assets/{asset}/clear-promotion-failed', [\App\Http\Controllers\Admin\AdminAssetController::class, 'clearPromotionFailed'])->name('admin.assets.clear-promotion-failed');
    Route::post('/admin/assets/{asset}/versions/{version}/restore', [\App\Http\Controllers\Admin\AdminAssetController::class, 'restoreVersion'])->name('admin.assets.versions.restore');
    Route::get('/admin/operations-center', [\App\Http\Controllers\Admin\OperationsCenterController::class, 'index'])->name('admin.operations-center.index');
    Route::post('/admin/incidents/bulk-actions', [\App\Http\Controllers\Admin\IncidentActionsController::class, 'bulkActions'])->name('admin.incidents.bulk-actions');
    Route::post('/admin/incidents/{incident}/attempt-repair', [\App\Http\Controllers\Admin\IncidentActionsController::class, 'attemptRepair'])->name('admin.incidents.attempt-repair');
    Route::post('/admin/incidents/{incident}/create-ticket', [\App\Http\Controllers\Admin\IncidentActionsController::class, 'createTicket'])->name('admin.incidents.create-ticket');
    Route::post('/admin/incidents/{incident}/resolve', [\App\Http\Controllers\Admin\IncidentActionsController::class, 'resolve'])->name('admin.incidents.resolve');
    Route::post('/admin/support-tickets/{supportTicket}/resolve-and-reconcile', [\App\Http\Controllers\Admin\SupportTicketResolveController::class, 'resolveAndReconcile'])->name('admin.support-tickets.resolve-and-reconcile');
    Route::get('/admin/logs', [\App\Http\Controllers\Admin\AdminLogController::class, 'index'])->name('admin.logs.index');
    Route::get('/admin/logs/{stream}', [\App\Http\Controllers\Admin\AdminLogController::class, 'api'])->name('admin.logs.api')->where('stream', 'web|worker');
    Route::get('/admin/notifications', [\App\Http\Controllers\Admin\NotificationController::class, 'index'])->name('admin.notifications');
    Route::post('/admin/notifications/seed', [\App\Http\Controllers\Admin\NotificationController::class, 'seed'])->name('admin.notifications.seed');
    Route::get('/admin/notifications/{template}', [\App\Http\Controllers\Admin\NotificationController::class, 'edit'])->name('admin.notifications.edit');
    Route::put('/admin/notifications/{template}', [\App\Http\Controllers\Admin\NotificationController::class, 'update'])->name('admin.notifications.update');
    Route::get('/admin/email-test', [\App\Http\Controllers\Admin\EmailTestController::class, 'index'])->name('admin.email-test');
    Route::post('/admin/email-test/send', [\App\Http\Controllers\Admin\EmailTestController::class, 'send'])->name('admin.email-test.send');
    Route::get('/admin/email-test/log', [\App\Http\Controllers\Admin\EmailTestController::class, 'log'])->name('admin.email-test.log');
    Route::post('/admin/stripe/sync-subscription/{tenant}', [\App\Http\Controllers\SiteAdminController::class, 'syncSubscription'])->name('admin.stripe.sync-subscription');
    Route::post('/admin/stripe/reset-subscriptions/{tenant}', [\App\Http\Controllers\SiteAdminController::class, 'resetSubscriptions'])->name('admin.stripe.reset-subscriptions');
    Route::post('/admin/stripe/refund', [\App\Http\Controllers\SiteAdminController::class, 'processRefund'])->name('admin.stripe.refund');
    Route::get('/admin/activity-logs', [\App\Http\Controllers\SiteAdminController::class, 'activityLogs'])->name('admin.activity-logs');
    Route::post('/admin/permissions/site-role', [\App\Http\Controllers\SiteAdminController::class, 'saveSiteRolePermissions'])->name('admin.permissions.site-role');
    Route::post('/admin/permissions/company-role', [\App\Http\Controllers\SiteAdminController::class, 'saveCompanyRolePermissions'])->name('admin.permissions.company-role');
    Route::post('/admin/permissions/create', [\App\Http\Controllers\SiteAdminController::class, 'createPermission'])->name('admin.permissions.create');
    Route::post('/admin/companies/{tenant}/add-user', [\App\Http\Controllers\SiteAdminController::class, 'addUserToCompany'])->name('admin.companies.add-user');
    Route::delete('/admin/companies/{tenant}/users/{user}', [\App\Http\Controllers\SiteAdminController::class, 'removeUserFromCompany'])->name('admin.companies.remove-user');
    Route::put('/admin/companies/{tenant}/users/{user}/role', [\App\Http\Controllers\SiteAdminController::class, 'updateUserRole'])->name('admin.companies.users.update-role');
    Route::post('/admin/companies/{tenant}/users/{user}/cancel', [\App\Http\Controllers\SiteAdminController::class, 'cancelAccount'])->name('admin.companies.users.cancel');
    Route::post('/admin/companies/{tenant}/users/{user}/delete', [\App\Http\Controllers\SiteAdminController::class, 'deleteAccount'])->name('admin.companies.users.delete');
    Route::post('/admin/users/{user}/delete', [\App\Http\Controllers\SiteAdminController::class, 'deleteUserAccount'])->name('admin.users.delete');
    Route::put('/admin/companies/{tenant}/users/{user}/brands/{brand}/role', [\App\Http\Controllers\SiteAdminController::class, 'updateUserBrandRole'])->name('admin.companies.users.brands.update-role');
    Route::get('/admin/users/{user}', [\App\Http\Controllers\SiteAdminController::class, 'viewUser'])->name('admin.users.view');
    Route::post('/admin/users/{user}/assign-site-role', [\App\Http\Controllers\SiteAdminController::class, 'assignSiteRole'])->name('admin.users.assign-site-role');
    Route::post('/admin/users/{user}/suspend', [\App\Http\Controllers\SiteAdminController::class, 'suspendAccount'])->name('admin.users.suspend');
    Route::post('/admin/users/{user}/unsuspend', [\App\Http\Controllers\SiteAdminController::class, 'unsuspendAccount'])->name('admin.users.unsuspend');
    Route::put('/admin/companies/{tenant}/plan', [\App\Http\Controllers\SiteAdminController::class, 'updatePlan'])->name('admin.companies.update-plan');
    Route::put('/admin/companies/{tenant}/infrastructure-tier', [\App\Http\Controllers\SiteAdminController::class, 'updateInfrastructureTier'])->name('admin.companies.update-infrastructure-tier');
    
    // Phase AG-11: Admin Agency Management
    Route::get('/admin/agencies', [\App\Http\Controllers\Admin\AdminAgencyController::class, 'index'])->name('admin.agencies.index');
    Route::get('/admin/agencies/api/stats', [\App\Http\Controllers\Admin\AdminAgencyController::class, 'stats'])->name('admin.agencies.api.stats');
    Route::get('/admin/agencies/{tenant}', [\App\Http\Controllers\Admin\AdminAgencyController::class, 'show'])->name('admin.agencies.show');
    Route::post('/admin/agencies/{tenant}/approve', [\App\Http\Controllers\Admin\AdminAgencyController::class, 'approve'])->name('admin.agencies.approve');
    Route::post('/admin/agencies/{tenant}/revoke-approval', [\App\Http\Controllers\Admin\AdminAgencyController::class, 'revokeApproval'])->name('admin.agencies.revoke-approval');
    Route::put('/admin/agencies/{tenant}/tier', [\App\Http\Controllers\Admin\AdminAgencyController::class, 'updateTier'])->name('admin.agencies.update-tier');
    Route::post('/admin/agencies/{tenant}/toggle-status', [\App\Http\Controllers\Admin\AdminAgencyController::class, 'toggleAgencyStatus'])->name('admin.agencies.toggle-status');
    
    // Admin ticket routes (no tenant middleware - staff can see all tickets)
    Route::get('/admin/support/tickets', [\App\Http\Controllers\AdminTicketController::class, 'index'])->name('admin.support.tickets.index');
    Route::get('/admin/support/tickets/{ticket}', [\App\Http\Controllers\AdminTicketController::class, 'show'])->name('admin.support.tickets.show');
    Route::put('/admin/support/tickets/{ticket}/assignment', [\App\Http\Controllers\AdminTicketController::class, 'updateAssignment'])->name('admin.support.tickets.assignment');
    Route::put('/admin/support/tickets/{ticket}/status', [\App\Http\Controllers\AdminTicketController::class, 'updateStatus'])->name('admin.support.tickets.status');
    Route::put('/admin/support/tickets/{ticket}/resolve', [\App\Http\Controllers\AdminTicketController::class, 'resolve'])->name('admin.support.tickets.resolve');
    Route::put('/admin/support/tickets/{ticket}/close', [\App\Http\Controllers\AdminTicketController::class, 'close'])->name('admin.support.tickets.close');
    Route::put('/admin/support/tickets/{ticket}/reopen', [\App\Http\Controllers\AdminTicketController::class, 'reopen'])->name('admin.support.tickets.reopen');
    Route::post('/admin/support/tickets/{ticket}/reply', [\App\Http\Controllers\AdminTicketController::class, 'publicReply'])->name('admin.support.tickets.reply');
    Route::post('/admin/support/tickets/{ticket}/internal-note', [\App\Http\Controllers\AdminTicketController::class, 'addInternalNote'])->name('admin.support.tickets.internal-note');
    Route::post('/admin/support/tickets/{ticket}/internal-attachment', [\App\Http\Controllers\AdminTicketController::class, 'uploadInternalAttachment'])->name('admin.support.tickets.internal-attachment');
    Route::post('/admin/support/tickets/{ticket}/convert', [\App\Http\Controllers\AdminTicketController::class, 'convert'])->name('admin.support.tickets.convert');
    Route::post('/admin/support/tickets/{ticket}/link', [\App\Http\Controllers\AdminTicketController::class, 'link'])->name('admin.support.tickets.link');
    Route::post('/admin/support/tickets/engineering/create', [\App\Http\Controllers\AdminTicketController::class, 'createEngineeringTicket'])->name('admin.support.tickets.engineering.create');
    Route::get('/admin/support/tickets/{ticket}/audit', [\App\Http\Controllers\AdminTicketController::class, 'auditLog'])->name('admin.support.tickets.audit');
    Route::post('/admin/support/tickets/suggestions/{suggestion}/accept', [\App\Http\Controllers\AdminTicketController::class, 'acceptSuggestion'])->name('admin.support.tickets.suggestions.accept');
    Route::post('/admin/support/tickets/suggestions/{suggestion}/reject', [\App\Http\Controllers\AdminTicketController::class, 'rejectSuggestion'])->name('admin.support.tickets.suggestions.reject');
    Route::post('/admin/support/tickets/suggestions/{suggestion}/create-ticket', [\App\Http\Controllers\AdminTicketController::class, 'createTicketFromSuggestion'])->name('admin.support.tickets.suggestions.create-ticket');
    
    // Deletion Error Management routes (admin only)
    // Phase D-2: Admin Download Failures (read-only)
    Route::get('/admin/download-failures', [\App\Http\Controllers\Admin\AdminDownloadFailuresController::class, 'index'])->name('admin.download-failures.index');
    Route::get('/admin/download-failures/{download:uuid}', [\App\Http\Controllers\Admin\AdminDownloadFailuresController::class, 'show'])->name('admin.download-failures.show');
    // Phase U-1: Admin Upload Failures (read-only)
    Route::get('/admin/upload-failures', [\App\Http\Controllers\Admin\AdminUploadFailuresController::class, 'index'])->name('admin.upload-failures.index');
    Route::get('/admin/upload-failures/{upload:uuid}', [\App\Http\Controllers\Admin\AdminUploadFailuresController::class, 'show'])->name('admin.upload-failures.show');
    // Phase T-1: Admin Derivative Failures (read-only)
    Route::get('/admin/derivative-failures', [\App\Http\Controllers\Admin\AdminDerivativeFailuresController::class, 'index'])->name('admin.derivative-failures.index');
    Route::get('/admin/derivative-failures/{failure}', [\App\Http\Controllers\Admin\AdminDerivativeFailuresController::class, 'show'])->name('admin.derivative-failures.show');
    // Phase A-1: AI Agent Health (observability, read-only)
    Route::get('/admin/ai-agents', [\App\Http\Controllers\Admin\AdminAIAgentHealthController::class, 'index'])->name('admin.ai-agent-health.index');
    Route::get('/admin/ai-error-monitoring', [\App\Http\Controllers\Admin\SentryAIController::class, 'index'])->name('admin.ai-error-monitoring.index');
    Route::post('/admin/ai-error-monitoring/sentry-issues/{issue}/toggle-heal', [\App\Http\Controllers\Admin\SentryAIController::class, 'toggleHeal'])->name('admin.ai-error-monitoring.toggle-heal');
    Route::post('/admin/ai-error-monitoring/sentry-issues/{issue}/dismiss', [\App\Http\Controllers\Admin\SentryAIController::class, 'dismiss'])->name('admin.ai-error-monitoring.dismiss');
    Route::post('/admin/ai-error-monitoring/sentry-issues/{issue}/resolve', [\App\Http\Controllers\Admin\SentryAIController::class, 'resolve'])->name('admin.ai-error-monitoring.resolve');
    Route::post('/admin/ai-error-monitoring/sentry-issues/{issue}/reanalyze', [\App\Http\Controllers\Admin\SentryAIController::class, 'reanalyze'])->name('admin.ai-error-monitoring.reanalyze');
    Route::post('/admin/ai-error-monitoring/sentry-issues/{issue}/confirm', [\App\Http\Controllers\Admin\SentryAIController::class, 'confirm'])->name('admin.ai-error-monitoring.confirm');
    Route::post('/admin/ai-error-monitoring/sentry-issues/bulk-action', [\App\Http\Controllers\Admin\SentryAIController::class, 'bulkAction'])->name('admin.ai-error-monitoring.bulk-action');

    Route::get('/admin/deletion-errors', [\App\Http\Controllers\DeletionErrorController::class, 'index'])->name('deletion-errors.index');
    Route::get('/admin/deletion-errors/{deletionError}', [\App\Http\Controllers\DeletionErrorController::class, 'show'])->name('deletion-errors.show');
    Route::post('/admin/deletion-errors/{deletionError}/resolve', [\App\Http\Controllers\DeletionErrorController::class, 'resolve'])->name('deletion-errors.resolve');
    Route::post('/admin/deletion-errors/{deletionError}/retry', [\App\Http\Controllers\DeletionErrorController::class, 'retry'])->name('deletion-errors.retry');
    Route::delete('/admin/deletion-errors/{deletionError}', [\App\Http\Controllers\DeletionErrorController::class, 'destroy'])->name('deletion-errors.destroy');
    Route::get('/admin/deletion-errors/api/stats', [\App\Http\Controllers\DeletionErrorController::class, 'stats'])->name('deletion-errors.stats');
    
    // AI Dashboard routes (no tenant middleware - system-level only)
        Route::get('/admin/ai', [\App\Http\Controllers\Admin\AIDashboardController::class, 'index'])->name('admin.ai.index');
        Route::get('/admin/ai/activity', [\App\Http\Controllers\Admin\AIDashboardController::class, 'activity'])->name('admin.ai.activity');
        Route::get('/admin/ai/models', [\App\Http\Controllers\Admin\AIDashboardController::class, 'models'])->name('admin.ai.models');
        Route::get('/admin/ai/agents', [\App\Http\Controllers\Admin\AIDashboardController::class, 'agents'])->name('admin.ai.agents');
        Route::get('/admin/ai/automations', [\App\Http\Controllers\Admin\AIDashboardController::class, 'automations'])->name('admin.ai.automations');
        Route::get('/admin/ai/reports', [\App\Http\Controllers\Admin\AIDashboardController::class, 'reports'])->name('admin.ai.reports');
        Route::get('/admin/ai/budgets', [\App\Http\Controllers\Admin\AIDashboardController::class, 'budgets'])->name('admin.ai.budgets');
        Route::post('/admin/ai/models/{modelKey}/override', [\App\Http\Controllers\Admin\AIDashboardController::class, 'updateModelOverride'])->name('admin.ai.models.override');
        Route::post('/admin/ai/agents/{agentId}/override', [\App\Http\Controllers\Admin\AIDashboardController::class, 'updateAgentOverride'])->name('admin.ai.agents.override');
    
    // Metadata Registry routes (no tenant middleware - system-level only)
    Route::get('/admin/metadata/registry', [\App\Http\Controllers\Admin\MetadataRegistryController::class, 'index'])->name('admin.metadata.registry.index');
    
    // Metadata Field Category Visibility routes (no tenant middleware - system-level only)
    Route::get('/admin/metadata/fields/{field}/categories', [\App\Http\Controllers\Admin\MetadataFieldCategoryVisibilityController::class, 'getCategories'])->name('admin.metadata.fields.categories');
    Route::post('/admin/metadata/fields/{field}/categories/{category}/suppress', [\App\Http\Controllers\Admin\MetadataFieldCategoryVisibilityController::class, 'suppress'])->name('admin.metadata.fields.categories.suppress');
    Route::delete('/admin/metadata/fields/{field}/categories/{category}/suppress', [\App\Http\Controllers\Admin\MetadataFieldCategoryVisibilityController::class, 'unsuppress'])->name('admin.metadata.fields.categories.unsuppress');
        Route::post('/admin/ai/automations/{triggerKey}/override', [\App\Http\Controllers\Admin\AIDashboardController::class, 'updateAutomationOverride'])->name('admin.ai.automations.override');
        Route::post('/admin/ai/budgets/{budgetId}/override', [\App\Http\Controllers\Admin\AIDashboardController::class, 'updateBudgetOverride'])->name('admin.ai.budgets.override');
        Route::post('/admin/ai/queue/retry/{uuid}', [\App\Http\Controllers\Admin\AIDashboardController::class, 'retryFailedJob'])->name('admin.ai.queue.retry');
        Route::get('/admin/ai/runs/{id}', [\App\Http\Controllers\Admin\AIDashboardController::class, 'showRun'])->name('admin.ai.runs.show');
        
        // Phase 5B Step 2: Admin Alert Actions
        Route::post('/admin/alerts/{alert}/acknowledge', [\App\Http\Controllers\Admin\AdminAlertController::class, 'acknowledge'])->name('admin.alerts.acknowledge');
        Route::post('/admin/alerts/{alert}/resolve', [\App\Http\Controllers\Admin\AdminAlertController::class, 'resolve'])->name('admin.alerts.resolve');
    
    // System Category management routes (site owner only)
    Route::get('/admin/system-categories', [\App\Http\Controllers\SystemCategoryController::class, 'index'])->name('admin.system-categories.index');
    Route::post('/admin/system-categories', [\App\Http\Controllers\SystemCategoryController::class, 'store'])->name('admin.system-categories.store');
    Route::put('/admin/system-categories/{systemCategory}', [\App\Http\Controllers\SystemCategoryController::class, 'update'])->name('admin.system-categories.update');
    Route::delete('/admin/system-categories/{systemCategory}', [\App\Http\Controllers\SystemCategoryController::class, 'destroy'])->name('admin.system-categories.destroy');
    Route::post('/admin/system-categories/update-order', [\App\Http\Controllers\SystemCategoryController::class, 'updateOrder'])->name('admin.system-categories.update-order');
    
    // Billing routes (no tenant middleware - billing is company-level)
    Route::get('/billing', [\App\Http\Controllers\BillingController::class, 'index'])->name('billing');
    Route::get('/billing/overview', [\App\Http\Controllers\BillingController::class, 'overview'])->name('billing.overview');
    Route::post('/billing/subscribe', [\App\Http\Controllers\BillingController::class, 'subscribe'])->name('billing.subscribe');
    Route::post('/billing/update-subscription', [\App\Http\Controllers\BillingController::class, 'updateSubscription'])->name('billing.update-subscription');
    Route::post('/billing/payment-method', [\App\Http\Controllers\BillingController::class, 'updatePaymentMethod'])->name('billing.payment-method');
    Route::get('/billing/invoices', [\App\Http\Controllers\BillingController::class, 'invoices'])->name('billing.invoices');
    Route::get('/billing/invoices/{id}/download', [\App\Http\Controllers\BillingController::class, 'downloadInvoice'])->name('billing.invoices.download');
    Route::post('/billing/cancel', [\App\Http\Controllers\BillingController::class, 'cancel'])->name('billing.cancel');
    Route::post('/billing/resume', [\App\Http\Controllers\BillingController::class, 'resume'])->name('billing.resume');
    Route::post('/billing/storage-addon', [\App\Http\Controllers\BillingController::class, 'addStorageAddon'])->name('billing.storage-addon');
    Route::delete('/billing/storage-addon', [\App\Http\Controllers\BillingController::class, 'removeStorageAddon'])->name('billing.storage-addon.remove');
    Route::get('/billing/success', [\App\Http\Controllers\BillingController::class, 'success'])->name('billing.success');
    Route::get('/billing/portal', [\App\Http\Controllers\BillingController::class, 'customerPortal'])->name('billing.portal');
    
    // Payment confirmation route for incomplete payments (Cashier-style)
    Route::get('/subscription/payment/{payment}', [\App\Http\Controllers\BillingController::class, 'payment'])->name('subscription.payment');
    
    // C12: RestrictCollectionOnlyUser gates collection-only users from dashboard/assets/collections/etc.
    Route::middleware(['tenant', \App\Http\Middleware\RestrictCollectionOnlyUser::class])->group(function () {
        // Routes that require user to be within plan limit
        Route::middleware('ensure.user.within.plan.limit')->group(function () {
            Route::get('/dashboard', [\App\Http\Controllers\DashboardController::class, 'index'])->name('dashboard');

            // Asset routes (tenant-scoped)
            Route::get('/assets', [\App\Http\Controllers\AssetController::class, 'index'])->name('assets.index');
            Route::get('/assets/processing', [\App\Http\Controllers\AssetController::class, 'activeProcessingJobs'])->name('assets.processing');
            
            // Metadata Analytics (Phase 7) — redirect /analytics to the actual page
            Route::get('/analytics', fn () => redirect()->route('analytics.metadata'))->name('analytics');
            Route::get('/analytics/metadata', [\App\Http\Controllers\MetadataAnalyticsController::class, 'index'])->name('analytics.metadata');
            Route::get('/analytics/metadata/data', [\App\Http\Controllers\MetadataAnalyticsController::class, 'data'])->name('analytics.metadata.data');
            Route::get('/assets/thumbnail-status/batch', [\App\Http\Controllers\AssetController::class, 'batchThumbnailStatus'])->name('assets.thumbnail-status.batch');
            Route::get('/assets/{asset}/processing-status', [\App\Http\Controllers\AssetController::class, 'processingStatus'])->name('assets.processing-status');
            Route::get('/assets/{asset}/preview-url', [\App\Http\Controllers\AssetController::class, 'previewUrl'])->name('assets.preview-url');
            Route::get('/assets/{asset}/view', [\App\Http\Controllers\AssetController::class, 'view'])->name('assets.view');
            Route::get('/assets/{asset}/activity', [\App\Http\Controllers\AssetController::class, 'activity'])->name('assets.activity');
            Route::get('/assets/{asset}/versions', [\App\Http\Controllers\AssetVersionController::class, 'index'])->name('assets.versions.index');
            Route::post('/assets/{asset}/versions/{version}/restore', [\App\Http\Controllers\AssetVersionController::class, 'restore'])->name('assets.versions.restore');
            Route::get('/assets/{asset}/collections', [\App\Http\Controllers\CollectionController::class, 'assetCollections'])->name('assets.collections.index');
            
            // AI metadata generation (Phase I)
            Route::post('/assets/{asset}/system-metadata/regenerate', [\App\Http\Controllers\AssetController::class, 'regenerateSystemMetadata'])->name('assets.system-metadata.regenerate');
            Route::post('/assets/{asset}/ai-metadata/regenerate', [\App\Http\Controllers\AssetController::class, 'regenerateAiMetadata'])->name('assets.ai-metadata.regenerate');
            Route::post('/assets/{asset}/ai-tagging/regenerate', [\App\Http\Controllers\AssetController::class, 'regenerateAiTagging'])->name('assets.ai-tagging.regenerate');
            
            // Asset metadata AI suggestions (Phase 2 – Step 5.5)
            Route::get('/assets/{asset}/metadata/ai-suggestions', [\App\Http\Controllers\AssetMetadataController::class, 'getAiSuggestions'])->name('assets.metadata.ai-suggestions');
            Route::post('/assets/{asset}/metadata/ai-suggestions/{suggestionId}/approve', [\App\Http\Controllers\AssetMetadataController::class, 'approveSuggestion'])->name('assets.metadata.ai-suggestions.approve');
            Route::post('/assets/{asset}/metadata/ai-suggestions/{suggestionId}/edit-accept', [\App\Http\Controllers\AssetMetadataController::class, 'editAndAcceptSuggestion'])->name('assets.metadata.ai-suggestions.edit-accept');
            Route::post('/assets/{asset}/metadata/ai-suggestions/{suggestionId}/reject', [\App\Http\Controllers\AssetMetadataController::class, 'rejectSuggestion'])->name('assets.metadata.ai-suggestions.reject');
            
            // AI Metadata Suggestions (new ephemeral format from asset.metadata['_ai_suggestions'])
            Route::get('/assets/{asset}/metadata/suggestions', [\App\Http\Controllers\AssetMetadataController::class, 'getSuggestions'])->name('assets.metadata.suggestions');
            Route::post('/assets/{asset}/metadata/suggestions/{fieldKey}/accept', [\App\Http\Controllers\AssetMetadataController::class, 'acceptSuggestion'])->name('assets.metadata.suggestions.accept');
            Route::post('/assets/{asset}/metadata/suggestions/{fieldKey}/dismiss', [\App\Http\Controllers\AssetMetadataController::class, 'dismissSuggestion'])->name('assets.metadata.suggestions.dismiss');
            
            // AI Tag Suggestions (from asset_tag_candidates table)
            Route::get('/assets/{asset}/tags/suggestions', [\App\Http\Controllers\AssetMetadataController::class, 'getTagSuggestions'])->name('assets.tags.suggestions');
            Route::post('/assets/{asset}/tags/suggestions/{candidateId}/accept', [\App\Http\Controllers\AssetMetadataController::class, 'acceptTagSuggestion'])->name('assets.tags.suggestions.accept');
            Route::post('/assets/{asset}/tags/suggestions/{candidateId}/dismiss', [\App\Http\Controllers\AssetMetadataController::class, 'dismissTagSuggestion'])->name('assets.tags.suggestions.dismiss');
            
            // Phase J.2.3: Tag UX API endpoints
            Route::get('/api/assets/{asset}/tags', [\App\Http\Controllers\AssetTagController::class, 'index'])->name('api.assets.tags.index');
            Route::post('/api/assets/{asset}/tags', [\App\Http\Controllers\AssetTagController::class, 'store'])->name('api.assets.tags.store');
            Route::delete('/api/assets/{asset}/tags/{tagId}', [\App\Http\Controllers\AssetTagController::class, 'destroy'])->name('api.assets.tags.destroy');
            Route::get('/api/assets/{asset}/tags/autocomplete', [\App\Http\Controllers\AssetTagController::class, 'autocomplete'])->name('api.assets.tags.autocomplete');
            Route::get('/api/tenants/{tenant}/tags/autocomplete', [\App\Http\Controllers\AssetTagController::class, 'tenantAutocomplete'])->name('api.tenants.tags.autocomplete');
            
            // Pending AI Suggestions API (dashboard tile)
            Route::get('/api/pending-ai-suggestions', [\App\Http\Controllers\AssetMetadataController::class, 'getAllPendingSuggestions'])->name('api.pending-ai-suggestions');
            
            // TASK 2: Pending metadata approvals endpoint (UI-only, does not alter approval logic)
            Route::get('/api/pending-metadata-approvals', [\App\Http\Controllers\AssetMetadataController::class, 'getAllPendingMetadataApprovals'])->name('api.pending-metadata-approvals');
            
            // Asset metadata manual editing (Phase 2 – Step 6)
            Route::post('/assets/{asset}/rescore', [\App\Http\Controllers\AssetMetadataController::class, 'rescore'])->name('assets.rescore');
            Route::post('/assets/{asset}/reanalyze', [\App\Http\Controllers\AssetMetadataController::class, 'reanalyze'])->name('assets.reanalyze');
            Route::get('/assets/{asset}/incidents', [\App\Http\Controllers\AssetMetadataController::class, 'getIncidents'])->name('assets.incidents');
            Route::post('/assets/{asset}/retry-processing', [\App\Http\Controllers\AssetMetadataController::class, 'retryProcessing'])->name('assets.retry-processing');
            Route::post('/assets/{asset}/reprocess', [\App\Http\Controllers\AssetMetadataController::class, 'reprocess'])->name('assets.reprocess');
            Route::post('/assets/{asset}/submit-ticket', [\App\Http\Controllers\AssetMetadataController::class, 'submitTicket'])->name('assets.submit-ticket');
            Route::get('/assets/{asset}/metadata/editable', [\App\Http\Controllers\AssetMetadataController::class, 'getEditableMetadata'])->name('assets.metadata.editable');
            Route::get('/assets/{asset}/metadata/all', [\App\Http\Controllers\AssetMetadataController::class, 'getAllMetadata'])->name('assets.metadata.all');
            Route::post('/assets/{asset}/metadata/edit', [\App\Http\Controllers\AssetMetadataController::class, 'editMetadata'])->name('assets.metadata.edit');
            Route::post('/assets/{asset}/metadata/override', [\App\Http\Controllers\AssetMetadataController::class, 'overrideHybridField'])->name('assets.metadata.override');
            Route::post('/assets/{asset}/metadata/revert', [\App\Http\Controllers\AssetMetadataController::class, 'revertToAutomatic'])->name('assets.metadata.revert');
            
            // Asset metadata bulk operations (Phase 2 – Step 7)
            Route::post('/assets/metadata/bulk/preview', [\App\Http\Controllers\AssetMetadataController::class, 'previewBulk'])->name('assets.metadata.bulk.preview');
            Route::post('/assets/metadata/bulk/execute', [\App\Http\Controllers\AssetMetadataController::class, 'executeBulk'])->name('assets.metadata.bulk.execute');
            
            // Asset metadata filtering and saved views (Phase 2 – Step 8)
            Route::get('/assets/metadata/filterable-schema', [\App\Http\Controllers\AssetMetadataController::class, 'getFilterableSchema'])->name('assets.metadata.filterable-schema');
            Route::get('/assets/metadata/saved-views', [\App\Http\Controllers\AssetMetadataController::class, 'getSavedViews'])->name('assets.metadata.saved-views');
            Route::post('/assets/metadata/saved-views', [\App\Http\Controllers\AssetMetadataController::class, 'saveView'])->name('assets.metadata.saved-views.save');
            Route::delete('/assets/metadata/saved-views/{viewId}', [\App\Http\Controllers\AssetMetadataController::class, 'deleteView'])->name('assets.metadata.saved-views.delete');
            
            // Asset metadata approval workflow (Phase 8)
            Route::get('/assets/{asset}/metadata/pending', [\App\Http\Controllers\AssetMetadataController::class, 'getPendingMetadata'])->name('assets.metadata.pending');
            Route::post('/metadata/{metadataId}/approve', [\App\Http\Controllers\AssetMetadataController::class, 'approveMetadata'])->name('metadata.approve');
            Route::post('/metadata/{metadataId}/edit-approve', [\App\Http\Controllers\AssetMetadataController::class, 'editAndApproveMetadata'])->name('metadata.edit-approve');
            Route::post('/metadata/{metadataId}/reject', [\App\Http\Controllers\AssetMetadataController::class, 'rejectMetadata'])->name('metadata.reject');
            Route::post('/assets/{asset}/metadata/approve-all', [\App\Http\Controllers\AssetMetadataController::class, 'approveAllMetadata'])->name('assets.metadata.approve-all');
            
            // Asset metadata candidate review workflow (Phase B9)
            Route::get('/assets/{asset}/metadata/review', [\App\Http\Controllers\AssetMetadataController::class, 'getReview'])->name('assets.metadata.review');
            Route::post('/metadata/candidates/{candidateId}/approve', [\App\Http\Controllers\AssetMetadataController::class, 'approveCandidate'])->name('metadata.candidates.approve');
            Route::post('/metadata/candidates/{candidateId}/reject', [\App\Http\Controllers\AssetMetadataController::class, 'rejectCandidate'])->name('metadata.candidates.reject');
            Route::post('/metadata/candidates/{candidateId}/defer', [\App\Http\Controllers\AssetMetadataController::class, 'deferCandidate'])->name('metadata.candidates.defer');
            // Asset download endpoint with metric tracking (GET = direct signed URL, no record)
            Route::get('/assets/{asset}/download', [\App\Http\Controllers\AssetController::class, 'download'])->name('assets.download');
            // UX-R2: Single-asset tracked download (POST = create Download record + redirect to file)
            Route::post('/assets/{asset}/download', [\App\Http\Controllers\DownloadController::class, 'downloadSingleAsset'])->name('assets.download.single');
            
            // Download group endpoints (Phase 3.1 Step 4)
            Route::get('/downloads/{download}/download', [\App\Http\Controllers\DownloadController::class, 'download'])->name('downloads.download');
            // Phase D1: Download bucket and create download
            Route::get('/download-bucket/items', [\App\Http\Controllers\DownloadBucketController::class, 'items'])->name('download-bucket.items');
            Route::post('/download-bucket/add', [\App\Http\Controllers\DownloadBucketController::class, 'add'])->name('download-bucket.add');
            Route::post('/download-bucket/add-batch', [\App\Http\Controllers\DownloadBucketController::class, 'addBatch'])->name('download-bucket.add_batch');
            Route::delete('/download-bucket/remove/{asset}', [\App\Http\Controllers\DownloadBucketController::class, 'remove'])->name('download-bucket.remove');
            Route::post('/download-bucket/clear', [\App\Http\Controllers\DownloadBucketController::class, 'clear'])->name('download-bucket.clear');
            Route::post('/downloads', [\App\Http\Controllers\DownloadController::class, 'store'])->name('downloads.store');
            Route::get('/downloads/company-users', [\App\Http\Controllers\DownloadController::class, 'companyUsers'])->name('downloads.company-users');
            
            // Metric endpoints
            Route::post('/assets/{asset}/metrics/track', [\App\Http\Controllers\AssetMetricController::class, 'track'])->name('assets.metrics.track');
            Route::get('/assets/{asset}/metrics', [\App\Http\Controllers\AssetMetricController::class, 'index'])->name('assets.metrics.index');
            Route::get('/assets/{asset}/metrics/downloads', [\App\Http\Controllers\AssetMetricController::class, 'downloads'])->name('assets.metrics.downloads');
            Route::get('/assets/{asset}/metrics/views', [\App\Http\Controllers\AssetMetricController::class, 'views'])->name('assets.metrics.views');
            // Thumbnail retry endpoint
            Route::post('/assets/{asset}/thumbnails/retry', [\App\Http\Controllers\AssetThumbnailController::class, 'retry'])->name('assets.thumbnails.retry');
            // Thumbnail generation endpoint (for existing assets without thumbnails)
            Route::post('/assets/{asset}/thumbnails/generate', [\App\Http\Controllers\AssetThumbnailController::class, 'generate'])->name('assets.thumbnails.generate');
            // Remove preview thumbnails endpoint
            Route::delete('/assets/{asset}/thumbnails/preview', [\App\Http\Controllers\AssetThumbnailController::class, 'removePreview'])->name('assets.thumbnails.remove-preview');
            // Admin thumbnail style regeneration endpoint (site roles only)
            Route::post('/assets/{asset}/thumbnails/regenerate-styles', [\App\Http\Controllers\AssetThumbnailController::class, 'regenerateStyles'])->name('assets.thumbnails.regenerate-styles');
            // Phase V-1: Video-specific regeneration endpoints
            Route::post('/assets/{asset}/thumbnails/regenerate-video-thumbnail', [\App\Http\Controllers\AssetThumbnailController::class, 'regenerateVideoThumbnail'])->name('assets.thumbnails.regenerate-video-thumbnail');
            Route::post('/assets/{asset}/thumbnails/regenerate-video-preview', [\App\Http\Controllers\AssetThumbnailController::class, 'regenerateVideoPreview'])->name('assets.thumbnails.regenerate-video-preview');
            // Phase L.6.1: Asset approval actions (publish/unpublish)
            Route::post('/assets/{asset}/publish', [\App\Http\Controllers\AssetController::class, 'publish'])->name('assets.publish');
            Route::post('/assets/{asset}/unpublish', [\App\Http\Controllers\AssetController::class, 'unpublish'])->name('assets.unpublish');
            // Phase J.3.1: File replacement for rejected contributor assets
            Route::patch('/assets/{asset}/filename', [\App\Http\Controllers\AssetController::class, 'updateFilename'])->name('assets.filename.update');
            Route::post('/assets/{asset}/replace-file', [\App\Http\Controllers\AssetController::class, 'initiateReplaceFile'])->name('assets.replace-file');
            // Phase L.3: Asset archive & restore actions
            Route::post('/assets/{asset}/archive', [\App\Http\Controllers\AssetController::class, 'archive'])->name('assets.archive');
            Route::post('/assets/{asset}/restore', [\App\Http\Controllers\AssetController::class, 'restore'])->name('assets.restore');
            Route::post('/assets/{asset}/restore-from-trash', [\App\Http\Controllers\AssetController::class, 'restoreFromTrash'])->name('assets.restore-from-trash');
            Route::delete('/assets/{asset}', [\App\Http\Controllers\AssetController::class, 'destroy'])->name('assets.destroy');
            Route::get('/executions', [\App\Http\Controllers\DeliverableController::class, 'index'])->name('executions.index');
            Route::get('/collections', [\App\Http\Controllers\CollectionController::class, 'index'])->name('collections.index');
            Route::get('/collections/list', [\App\Http\Controllers\CollectionController::class, 'listForDropdown'])->name('collections.list');
            Route::get('/collections/field-visibility', [\App\Http\Controllers\CollectionController::class, 'checkFieldVisibility'])->name('collections.field-visibility');
            Route::post('/collections', [\App\Http\Controllers\CollectionController::class, 'store'])->name('collections.store');
            Route::put('/collections/{collection}', [\App\Http\Controllers\CollectionController::class, 'update'])->name('collections.update');
            Route::post('/collections/{collection}/assets', [\App\Http\Controllers\CollectionController::class, 'addAsset'])->name('collections.assets.store');
            Route::delete('/collections/{collection}/assets/{asset}', [\App\Http\Controllers\CollectionController::class, 'removeAsset'])->name('collections.assets.destroy');
            Route::put('/assets/{asset}/collections', [\App\Http\Controllers\CollectionController::class, 'syncAssetCollections'])->name('assets.collections.sync');
            Route::post('/collections/{collection}/invite', [\App\Http\Controllers\CollectionInviteController::class, 'invite'])->name('collections.invite');
            Route::post('/collections/{collection}/accept', [\App\Http\Controllers\CollectionInviteController::class, 'accept'])->name('collections.accept');
            Route::post('/collections/{collection}/decline', [\App\Http\Controllers\CollectionInviteController::class, 'decline'])->name('collections.decline');
            // Phase C12.0: Collection-only access (private collections; creates collection_user grant, NOT brand membership)
            Route::post('/collections/{collection}/access-invite', [\App\Http\Controllers\CollectionAccessInviteController::class, 'invite'])->name('collections.access-invite');
            Route::get('/collections/{collection}/access-invites', [\App\Http\Controllers\CollectionAccessInviteController::class, 'index'])->name('collections.access-invites');
            Route::delete('/collections/{collection}/grants/{collection_user}', [\App\Http\Controllers\CollectionAccessInviteController::class, 'revoke'])->name('collections.grants.revoke');
            Route::get('/generative', [\App\Http\Controllers\GenerativeController::class, 'index'])->name('generative.index');
            Route::get('/downloads', [\App\Http\Controllers\DownloadController::class, 'index'])->name('downloads.index');
            Route::get('/brand-guidelines', [\App\Http\Controllers\BrandGuidelinesController::class, 'redirectToActive'])->name('brand-guidelines.index');
            Route::post('/downloads/{download}/revoke', [\App\Http\Controllers\DownloadController::class, 'revoke'])->name('downloads.revoke');
            Route::post('/downloads/{download}/extend', [\App\Http\Controllers\DownloadController::class, 'extend'])->name('downloads.extend');
            Route::post('/downloads/{download}/change-access', [\App\Http\Controllers\DownloadController::class, 'changeAccess'])->name('downloads.change-access');
            Route::put('/downloads/{download}/settings', [\App\Http\Controllers\DownloadController::class, 'updateSettings'])->name('downloads.settings');
            Route::post('/downloads/{download}/regenerate', [\App\Http\Controllers\DownloadController::class, 'regenerate'])->name('downloads.regenerate');
            Route::get('/downloads/{download}/analytics', [\App\Http\Controllers\DownloadAnalyticsController::class, 'show'])->name('downloads.analytics');
            Route::get('/downloads/limits', function () {
                $tenant = app('tenant');
                if (! $tenant) {
                    return response()->json(['max_download_assets' => 50, 'max_download_zip_mb' => 500]);
                }
                $plan = app(\App\Services\PlanService::class);
                return response()->json([
                    'max_download_assets' => $plan->getMaxDownloadAssets($tenant),
                    'max_download_zip_mb' => (int) round($plan->getMaxDownloadZipBytes($tenant) / 1024 / 1024),
                ]);
            })->name('downloads.limits');

            // Upload routes (tenant-scoped)
            Route::get('/uploads/storage-check', [\App\Http\Controllers\UploadController::class, 'checkStorageLimits'])->name('uploads.storage-check');
            Route::post('/uploads/validate', [\App\Http\Controllers\UploadController::class, 'validateUpload'])->name('uploads.validate');
            Route::post('/uploads/initiate', [\App\Http\Controllers\UploadController::class, 'initiate'])->name('uploads.initiate');
            Route::post('/uploads/initiate-batch', [\App\Http\Controllers\UploadController::class, 'initiateBatch'])->name('uploads.initiate-batch');
            Route::get('/uploads/metadata-schema', [\App\Http\Controllers\UploadController::class, 'getMetadataSchema'])->name('uploads.metadata-schema');
            Route::post('/uploads/diagnostics', [\App\Http\Controllers\UploadController::class, 'diagnostics'])->name('uploads.diagnostics');
            Route::get('/uploads/{uploadSession}/resume', [\App\Http\Controllers\UploadController::class, 'resume'])->name('uploads.resume');
            Route::post('/uploads/{uploadSession}/multipart-part-url', [\App\Http\Controllers\UploadController::class, 'getMultipartPartUrl'])->name('uploads.multipart-part-url');
            // Multipart upload endpoints (Phase 2.4)
            Route::post('/uploads/{uploadSession}/multipart/init', [\App\Http\Controllers\UploadController::class, 'initMultipart'])->name('uploads.multipart.init');
            Route::post('/uploads/{uploadSession}/multipart/sign-part', [\App\Http\Controllers\UploadController::class, 'signMultipartPart'])->name('uploads.multipart.sign-part');
            Route::post('/uploads/{uploadSession}/multipart/complete', [\App\Http\Controllers\UploadController::class, 'completeMultipart'])->name('uploads.multipart.complete');
            Route::post('/uploads/{uploadSession}/multipart/abort', [\App\Http\Controllers\UploadController::class, 'abortMultipart'])->name('uploads.multipart.abort');
            Route::put('/uploads/{uploadSession}/activity', [\App\Http\Controllers\UploadController::class, 'updateActivity'])->name('uploads.update-activity');
            Route::put('/uploads/{uploadSession}/start', [\App\Http\Controllers\UploadController::class, 'markAsUploading'])->name('uploads.start');
            Route::post('/uploads/{uploadSession}/cancel', [\App\Http\Controllers\UploadController::class, 'cancel'])->name('uploads.cancel');
            Route::post('/assets/upload/complete', [\App\Http\Controllers\UploadController::class, 'complete'])->name('assets.upload.complete');
            Route::post('/assets/upload/finalize', [\App\Http\Controllers\UploadController::class, 'finalize'])->name('assets.upload.finalize');
            // Phase J.3.1: Alias route for finalize (used by replace file modal)
            Route::post('/uploads/finalize', [\App\Http\Controllers\UploadController::class, 'finalize'])->name('uploads.finalize');

            // Brand routes (tenant-scoped)
            Route::resource('brands', \App\Http\Controllers\BrandController::class);
            Route::get('/brands/{brand}/download-branding-assets', [\App\Http\Controllers\BrandController::class, 'downloadBrandingAssets'])->name('brands.download-branding-assets');
            Route::get('/brands/{brand}/download-background-candidates', [\App\Http\Controllers\BrandController::class, 'downloadBackgroundCandidates'])->name('brands.download-background-candidates');
            Route::post('/brands/{brand}/switch', [\App\Http\Controllers\BrandController::class, 'switch'])->name('brands.switch');

            // Brand DNA (internal settings)
            Route::get('/brands/{brand}/dna', [\App\Http\Controllers\BrandDNAController::class, 'index'])->name('brands.dna.index');
            Route::get('/brands/{brand}/guidelines', [\App\Http\Controllers\BrandGuidelinesController::class, 'index'])->name('brands.guidelines.index');
            Route::post('/brands/{brand}/dna', [\App\Http\Controllers\BrandDNAController::class, 'store'])->name('brands.dna.store');
            Route::get('/brands/{brand}/dna/versions/{version}', [\App\Http\Controllers\BrandDNAController::class, 'showVersion'])->name('brands.dna.versions.show');
            Route::post('/brands/{brand}/dna/versions', [\App\Http\Controllers\BrandDNAController::class, 'createVersion'])->name('brands.dna.versions.store');
            Route::post('/brands/{brand}/dna/versions/{version}/activate', [\App\Http\Controllers\BrandDNAController::class, 'activateVersion'])->name('brands.dna.versions.activate');
            Route::post('/brands/{brand}/dna/visual-references', [\App\Http\Controllers\BrandDNAController::class, 'storeVisualReferences'])->name('brands.dna.visual_references.store');

            // Brand Bootstrap (foundation only)
            Route::get('/brands/{brand}/dna/bootstrap', [\App\Http\Controllers\BrandBootstrapController::class, 'index'])->name('brands.dna.bootstrap.index');
            Route::post('/brands/{brand}/dna/bootstrap', [\App\Http\Controllers\BrandBootstrapController::class, 'store'])->name('brands.dna.bootstrap.store');
            Route::get('/brands/{brand}/dna/bootstrap/{run}', [\App\Http\Controllers\BrandBootstrapController::class, 'show'])->name('brands.dna.bootstrap.show');
            Route::post('/brands/{brand}/dna/bootstrap/{run}/approve', [\App\Http\Controllers\BrandBootstrapController::class, 'approve'])->name('brands.dna.bootstrap.approve');
            Route::delete('/brands/{brand}/dna/bootstrap/{run}', [\App\Http\Controllers\BrandBootstrapController::class, 'destroy'])->name('brands.dna.bootstrap.destroy');

            // Brand user management routes
            Route::get('/brands/{brand}/users/available', [\App\Http\Controllers\BrandController::class, 'availableUsers'])->name('brands.users.available');
            Route::get('/api/brands/{brand}/category-form-data', [\App\Http\Controllers\BrandController::class, 'categoryFormData'])->name('api.brands.category-form-data');
            Route::post('/brands/{brand}/users/invite', [\App\Http\Controllers\BrandController::class, 'inviteUser'])->name('brands.users.invite');
            Route::post('/brands/{brand}/users/{user}/add', [\App\Http\Controllers\BrandController::class, 'addUser'])->name('brands.users.add');
            Route::put('/brands/{brand}/users/{user}/role', [\App\Http\Controllers\BrandController::class, 'updateUserRole'])->name('brands.users.update-role');
            Route::delete('/brands/{brand}/users/{user}', [\App\Http\Controllers\BrandController::class, 'removeUser'])->name('brands.users.remove');
            Route::post('/brands/{brand}/invitations/{invitation}/resend', [\App\Http\Controllers\BrandController::class, 'resendInvitation'])->name('brands.invitations.resend');
            
            // Phase AF-1: Asset approval workflow
            Route::get('/brands/{brand}/approvals', [\App\Http\Controllers\BrandController::class, 'approvals'])->name('brands.approvals');
            Route::get('/api/brands/{brand}/approvals', [\App\Http\Controllers\AssetApprovalController::class, 'index'])->name('api.brands.approvals');
            // Phase J.2: Pending assets for review modal
            Route::get('/api/brands/{brand}/pending-assets', [\App\Http\Controllers\AssetApprovalController::class, 'pendingAssets'])->name('api.brands.pending-assets');
            Route::post('/brands/{brand}/assets/{asset}/approve', [\App\Http\Controllers\AssetApprovalController::class, 'approve'])->name('brands.assets.approve');
            Route::post('/brands/{brand}/assets/{asset}/reject', [\App\Http\Controllers\AssetApprovalController::class, 'reject'])->name('brands.assets.reject');
            // Phase AF-2: Re-submission and comments
            Route::post('/brands/{brand}/assets/{asset}/resubmit', [\App\Http\Controllers\AssetApprovalController::class, 'resubmit'])->name('brands.assets.resubmit');
            Route::get('/brands/{brand}/assets/{asset}/approval-history', [\App\Http\Controllers\AssetApprovalController::class, 'history'])->name('brands.assets.approval-history');

            // Category routes (tenant-scoped)
            // DISABLED: Category management moved to brands pages
            // Route::resource('categories', \App\Http\Controllers\CategoryController::class)->except(['create', 'edit', 'show']);
            // Route::post('/categories/update-order', [\App\Http\Controllers\CategoryController::class, 'updateOrder'])->name('categories.update-order');
            // Route::get('/categories/{category}/upgrade/preview', [\App\Http\Controllers\CategoryController::class, 'previewUpgrade'])->name('categories.upgrade.preview');
            // Route::post('/categories/{category}/upgrade', [\App\Http\Controllers\CategoryController::class, 'applyUpgrade'])->name('categories.upgrade.apply');
            
            // Category routes moved to brands
            Route::post('/brands/{brand}/categories', [\App\Http\Controllers\CategoryController::class, 'store'])->name('brands.categories.store');
            Route::post('/brands/{brand}/categories/add-system-template', [\App\Http\Controllers\CategoryController::class, 'addSystemTemplate'])->name('brands.categories.add-system-template');
            Route::put('/brands/{brand}/categories/{category}', [\App\Http\Controllers\CategoryController::class, 'update'])->name('brands.categories.update');
            Route::patch('/api/brands/{brand}/categories/{category}/visibility', [\App\Http\Controllers\CategoryController::class, 'updateVisibility'])->name('brands.categories.visibility');
            Route::delete('/brands/{brand}/categories/{category}', [\App\Http\Controllers\CategoryController::class, 'destroy'])->name('brands.categories.destroy');
            Route::put('/api/brands/{brand}/categories/reorder', [\App\Http\Controllers\CategoryController::class, 'reorder'])->name('brands.categories.reorder');
            Route::post('/brands/{brand}/categories/update-order', [\App\Http\Controllers\CategoryController::class, 'updateOrder'])->name('brands.categories.update-order');
            Route::get('/brands/{brand}/categories/{category}/upgrade/preview', [\App\Http\Controllers\CategoryController::class, 'previewUpgrade'])->name('brands.categories.upgrade.preview');
            Route::post('/brands/{brand}/categories/{category}/upgrade', [\App\Http\Controllers\CategoryController::class, 'applyUpgrade'])->name('brands.categories.upgrade.apply');
            Route::post('/brands/{brand}/categories/{category}/accept-deletion', [\App\Http\Controllers\CategoryController::class, 'acceptDeletion'])->name('brands.categories.accept-deletion');
            Route::patch('/brands/{brand}/categories/{category}/fields/reorder', [\App\Http\Controllers\CategoryController::class, 'reorderFields'])->name('brands.categories.fields.reorder');

            // Support ticket routes (tenant-scoped)
            Route::resource('support/tickets', \App\Http\Controllers\TenantTicketController::class)->only(['index', 'create', 'store', 'show'])->names('support.tickets');
            Route::post('/support/tickets/{ticket}/reply', [\App\Http\Controllers\TenantTicketController::class, 'reply'])->name('support.tickets.reply');
            Route::post('/support/tickets/{ticket}/close', [\App\Http\Controllers\TenantTicketController::class, 'close'])->name('support.tickets.close');
        });
        
        // Routes that don't require user to be within plan limit (like billing, company settings)
        Route::get('/companies/settings', [\App\Http\Controllers\CompanyController::class, 'settings'])->name('companies.settings');
        Route::put('/companies/settings', [\App\Http\Controllers\CompanyController::class, 'updateSettings'])->name('companies.settings.update');
        Route::put('/companies/settings/download-policy', [\App\Http\Controllers\CompanyController::class, 'updateDownloadPolicy'])->name('companies.settings.download-policy');
        Route::delete('/companies/settings', [\App\Http\Controllers\CompanyController::class, 'destroy'])->name('companies.destroy');
        Route::get('/companies/team', [\App\Http\Controllers\TeamController::class, 'index'])->name('companies.team');
        Route::post('/companies/{tenant}/team/invite', [\App\Http\Controllers\TeamController::class, 'invite'])->name('companies.team.invite');
        Route::put('/companies/{tenant}/team/{user}/role', [\App\Http\Controllers\TeamController::class, 'updateTenantRole'])->name('companies.team.update-role');
        Route::put('/companies/{tenant}/team/{user}/brands/{brand}/role', [\App\Http\Controllers\TeamController::class, 'updateBrandRole'])->name('companies.team.update-brand-role');
        Route::post('/companies/{tenant}/team/{user}/add-to-brand', [\App\Http\Controllers\TeamController::class, 'addToBrand'])->name('companies.team.add-to-brand');
        Route::delete('/companies/{tenant}/team/{user}', [\App\Http\Controllers\TeamController::class, 'remove'])->name('companies.team.remove');
        Route::delete('/companies/{tenant}/team/{user}/delete-from-company', [\App\Http\Controllers\TeamController::class, 'deleteFromCompany'])->name('companies.team.delete-from-company');
        Route::get('/companies/activity', [\App\Http\Controllers\CompanyController::class, 'activity'])->name('companies.activity');
    });

    // PHASE 7: 410 safeguard — no silent fallback to deprecated proxy
    Route::get('/assets/{asset}/thumbnail/{any}', fn () => abort(410, 'Asset proxy removed. Use CDN.'))->where('any', '.*');
    Route::get('/assets/{asset}/thumbnail', fn () => abort(410, 'Asset proxy removed. Use CDN.'));
    Route::get('/admin/assets/{asset}/thumbnail', fn () => abort(410, 'Asset proxy removed. Use CDN.'));
});

// Stripe webhook (no auth, no CSRF)
Route::post('/webhook/stripe', [\App\Http\Controllers\WebhookController::class, 'handleWebhook'])->name('webhook.stripe');


