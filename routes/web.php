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

// Home route - simple home page (subdomains disabled)
Route::get('/', fn () => Inertia::render('Home'));

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

// Public collections (C8) — no auth, is_public only
Route::get('/collections/{slug}', [\App\Http\Controllers\PublicCollectionController::class, 'show'])->name('public.collections.show');
Route::get('/collections/{slug}/assets/{asset}/download', [\App\Http\Controllers\PublicCollectionController::class, 'download'])->name('public.collections.assets.download');

// CSRF token refresh endpoint (for handling stale tokens after session regeneration)
// Accessible to authenticated users (session exists, just token may be stale)
Route::get('/csrf-token', function (Request $request) {
    return response()->json(['token' => csrf_token()]);
})->middleware(['web']);

Route::middleware(['auth', 'ensure.account.active'])->prefix('app')->group(function () {
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
    
    // Company settings (requires tenant to be selected)
    Route::middleware('tenant')->group(function () {
        Route::get('/companies/settings', [\App\Http\Controllers\CompanyController::class, 'settings'])->name('companies.settings');
        Route::put('/companies/settings', [\App\Http\Controllers\CompanyController::class, 'updateSettings'])->name('companies.settings.update');
        Route::put('/companies/settings/widgets', [\App\Http\Controllers\CompanyController::class, 'updateWidgetSettings'])->name('companies.settings.widgets.update');
        Route::get('/companies/permissions', [\App\Http\Controllers\CompanyController::class, 'permissions'])->name('companies.permissions');
        Route::get('/companies/team', [\App\Http\Controllers\TeamController::class, 'index'])->name('companies.team');
        Route::post('/companies/{tenant}/team/invite', [\App\Http\Controllers\TeamController::class, 'invite'])->name('companies.team.invite');
        Route::put('/companies/{tenant}/team/{user}/role', [\App\Http\Controllers\TeamController::class, 'updateTenantRole'])->name('companies.team.update-role');
        Route::put('/companies/{tenant}/team/{user}/brands/{brand}/role', [\App\Http\Controllers\TeamController::class, 'updateBrandRole'])->name('companies.team.update-brand-role');
        Route::delete('/companies/{tenant}/team/{user}', [\App\Http\Controllers\TeamController::class, 'remove'])->name('companies.team.remove');
        Route::get('/companies/activity', [\App\Http\Controllers\CompanyController::class, 'activity'])->name('companies.activity');
        
        // Phase C3: Tenant metadata field management
        Route::get('/tenant/metadata/fields', [\App\Http\Controllers\TenantMetadataFieldController::class, 'index'])->name('tenant.metadata.fields.index');
        Route::get('/tenant/metadata/fields/create', [\App\Http\Controllers\TenantMetadataFieldController::class, 'create'])->name('tenant.metadata.fields.create');
        Route::get('/tenant/metadata/fields/{field}', [\App\Http\Controllers\TenantMetadataFieldController::class, 'show'])->name('tenant.metadata.fields.show');
        Route::post('/tenant/metadata/fields', [\App\Http\Controllers\TenantMetadataFieldController::class, 'store'])->name('tenant.metadata.fields.store');
        Route::put('/tenant/metadata/fields/{field}', [\App\Http\Controllers\TenantMetadataFieldController::class, 'update'])->name('tenant.metadata.fields.update');
        Route::post('/tenant/metadata/fields/{field}/disable', [\App\Http\Controllers\TenantMetadataFieldController::class, 'disable'])->name('tenant.metadata.fields.disable');
        Route::post('/tenant/metadata/fields/{field}/enable', [\App\Http\Controllers\TenantMetadataFieldController::class, 'enable'])->name('tenant.metadata.fields.enable');
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
        
        // Phase C4: Tenant metadata registry and visibility management
        Route::get('/tenant/metadata/registry', [\App\Http\Controllers\TenantMetadataRegistryController::class, 'index'])->name('tenant.metadata.registry.index');
        Route::get('/api/tenant/metadata/registry', [\App\Http\Controllers\TenantMetadataRegistryController::class, 'getRegistry'])->name('tenant.metadata.registry.api');
        Route::post('/api/tenant/metadata/fields/{field}/visibility', [\App\Http\Controllers\TenantMetadataRegistryController::class, 'setVisibility'])->name('tenant.metadata.visibility.set');
        Route::delete('/api/tenant/metadata/fields/{field}/visibility', [\App\Http\Controllers\TenantMetadataRegistryController::class, 'removeVisibility'])->name('tenant.metadata.visibility.remove');
        Route::post('/api/tenant/metadata/fields/{field}/categories/{category}/suppress', [\App\Http\Controllers\TenantMetadataRegistryController::class, 'suppressForCategory'])->name('tenant.metadata.category.suppress');
        Route::delete('/api/tenant/metadata/fields/{field}/categories/{category}/suppress', [\App\Http\Controllers\TenantMetadataRegistryController::class, 'unsuppressForCategory'])->name('tenant.metadata.category.unsuppress');
        Route::get('/api/tenant/metadata/fields/{field}/categories', [\App\Http\Controllers\TenantMetadataRegistryController::class, 'getSuppressedCategories'])->name('tenant.metadata.category.list');
        
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

    // Site Admin routes (only user ID 1)
    Route::get('/admin', [\App\Http\Controllers\SiteAdminController::class, 'index'])->name('admin.index');
    
    // Admin API endpoints (AJAX)
    Route::get('/admin/api/stats', [\App\Http\Controllers\SiteAdminController::class, 'stats'])->name('admin.api.stats');
    Route::get('/admin/api/companies/{tenant}/details', [\App\Http\Controllers\SiteAdminController::class, 'companyDetails'])->name('admin.api.companies.details');
    Route::get('/admin/api/companies/{tenant}/users', [\App\Http\Controllers\SiteAdminController::class, 'companyUsers'])->name('admin.api.companies.users');
    Route::get('/admin/api/users', [\App\Http\Controllers\SiteAdminController::class, 'allUsers'])->name('admin.api.users');
    Route::get('/admin/api/users/selector', [\App\Http\Controllers\SiteAdminController::class, 'usersForSelector'])->name('admin.api.users.selector');
    
    Route::get('/admin/companies/{tenant}', [\App\Http\Controllers\Admin\CompanyViewController::class, 'show'])->name('admin.companies.view');
    Route::get('/admin/billing', [\App\Http\Controllers\Admin\BillingController::class, 'index'])->name('admin.billing');
    Route::get('/admin/permissions', [\App\Http\Controllers\SiteAdminController::class, 'permissions'])->name('admin.permissions');
    Route::get('/admin/stripe-status', [\App\Http\Controllers\SiteAdminController::class, 'stripeStatus'])->name('admin.stripe-status');
    Route::get('/admin/documentation', [\App\Http\Controllers\SiteAdminController::class, 'documentation'])->name('admin.documentation');
    Route::get('/admin/system-status', [\App\Http\Controllers\Admin\SystemStatusController::class, 'index'])->name('admin.system-status');
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
    Route::get('/billing/success', [\App\Http\Controllers\BillingController::class, 'success'])->name('billing.success');
    Route::get('/billing/portal', [\App\Http\Controllers\BillingController::class, 'customerPortal'])->name('billing.portal');
    
    // Payment confirmation route for incomplete payments (Cashier-style)
    Route::get('/subscription/payment/{payment}', [\App\Http\Controllers\BillingController::class, 'payment'])->name('cashier.payment');
    
    Route::middleware('tenant')->group(function () {
        // Routes that require user to be within plan limit
        Route::middleware('ensure.user.within.plan.limit')->group(function () {
            Route::get('/dashboard', [\App\Http\Controllers\DashboardController::class, 'index'])->name('dashboard');

            // Asset routes (tenant-scoped)
            Route::get('/assets', [\App\Http\Controllers\AssetController::class, 'index'])->name('assets.index');
            Route::get('/assets/processing', [\App\Http\Controllers\AssetController::class, 'activeProcessingJobs'])->name('assets.processing');
            
            // Metadata Analytics (Phase 7)
            Route::get('/analytics/metadata', [\App\Http\Controllers\MetadataAnalyticsController::class, 'index'])->name('analytics.metadata');
            Route::get('/analytics/metadata/data', [\App\Http\Controllers\MetadataAnalyticsController::class, 'data'])->name('analytics.metadata.data');
            Route::get('/assets/thumbnail-status/batch', [\App\Http\Controllers\AssetController::class, 'batchThumbnailStatus'])->name('assets.thumbnail-status.batch');
            Route::get('/assets/{asset}/processing-status', [\App\Http\Controllers\AssetController::class, 'processingStatus'])->name('assets.processing-status');
            Route::get('/assets/{asset}/preview-url', [\App\Http\Controllers\AssetController::class, 'previewUrl'])->name('assets.preview-url');
            Route::get('/assets/{asset}/view', [\App\Http\Controllers\AssetController::class, 'view'])->name('assets.view');
            Route::get('/assets/{asset}/activity', [\App\Http\Controllers\AssetController::class, 'activity'])->name('assets.activity');
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
            // Asset download endpoint with metric tracking
            Route::get('/assets/{asset}/download', [\App\Http\Controllers\AssetController::class, 'download'])->name('assets.download');
            
            // Download group endpoints (Phase 3.1 Step 4)
            Route::get('/downloads/{download}/download', [\App\Http\Controllers\DownloadController::class, 'download'])->name('downloads.download');
            
            // Metric endpoints
            Route::post('/assets/{asset}/metrics/track', [\App\Http\Controllers\AssetMetricController::class, 'track'])->name('assets.metrics.track');
            Route::get('/assets/{asset}/metrics', [\App\Http\Controllers\AssetMetricController::class, 'index'])->name('assets.metrics.index');
            Route::get('/assets/{asset}/metrics/downloads', [\App\Http\Controllers\AssetMetricController::class, 'downloads'])->name('assets.metrics.downloads');
            Route::get('/assets/{asset}/metrics/views', [\App\Http\Controllers\AssetMetricController::class, 'views'])->name('assets.metrics.views');
            // Thumbnail endpoints - distinct URLs for preview and final to prevent cache confusion
            Route::get('/assets/{asset}/thumbnail/preview/{style}', [\App\Http\Controllers\AssetThumbnailController::class, 'preview'])->name('assets.thumbnail.preview');
            Route::get('/assets/{asset}/thumbnail/final/{style}', [\App\Http\Controllers\AssetThumbnailController::class, 'final'])->name('assets.thumbnail.final');
            // Legacy endpoint for backward compatibility (redirects to final)
            Route::get('/assets/{asset}/thumbnail/{style}', [\App\Http\Controllers\AssetThumbnailController::class, 'show'])->name('assets.thumbnail');
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
            Route::post('/assets/{asset}/replace-file', [\App\Http\Controllers\AssetController::class, 'initiateReplaceFile'])->name('assets.replace-file');
            // Phase L.3: Asset archive & restore actions
            Route::post('/assets/{asset}/archive', [\App\Http\Controllers\AssetController::class, 'archive'])->name('assets.archive');
            Route::post('/assets/{asset}/restore', [\App\Http\Controllers\AssetController::class, 'restore'])->name('assets.restore');
            Route::delete('/assets/{asset}', [\App\Http\Controllers\AssetController::class, 'destroy'])->name('assets.destroy');
            Route::get('/deliverables', [\App\Http\Controllers\DeliverableController::class, 'index'])->name('deliverables.index');
            Route::get('/collections', [\App\Http\Controllers\CollectionController::class, 'index'])->name('collections.index');
            Route::get('/collections/list', [\App\Http\Controllers\CollectionController::class, 'listForDropdown'])->name('collections.list');
            Route::post('/collections', [\App\Http\Controllers\CollectionController::class, 'store'])->name('collections.store');
            Route::post('/collections/{collection}/assets', [\App\Http\Controllers\CollectionController::class, 'addAsset'])->name('collections.assets.store');
            Route::delete('/collections/{collection}/assets/{asset}', [\App\Http\Controllers\CollectionController::class, 'removeAsset'])->name('collections.assets.destroy');
            Route::post('/collections/{collection}/invite', [\App\Http\Controllers\CollectionInviteController::class, 'invite'])->name('collections.invite');
            Route::post('/collections/{collection}/accept', [\App\Http\Controllers\CollectionInviteController::class, 'accept'])->name('collections.accept');
            Route::post('/collections/{collection}/decline', [\App\Http\Controllers\CollectionInviteController::class, 'decline'])->name('collections.decline');
            Route::get('/generative', [\App\Http\Controllers\GenerativeController::class, 'index'])->name('generative.index');
            Route::get('/downloads', [\App\Http\Controllers\DownloadController::class, 'index'])->name('downloads.index');

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
            Route::post('/brands/{brand}/switch', [\App\Http\Controllers\BrandController::class, 'switch'])->name('brands.switch');
            
            // Brand user management routes
            Route::get('/brands/{brand}/users/available', [\App\Http\Controllers\BrandController::class, 'availableUsers'])->name('brands.users.available');
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
            Route::delete('/brands/{brand}/categories/{category}', [\App\Http\Controllers\CategoryController::class, 'destroy'])->name('brands.categories.destroy');
            Route::post('/brands/{brand}/categories/update-order', [\App\Http\Controllers\CategoryController::class, 'updateOrder'])->name('brands.categories.update-order');
            Route::get('/brands/{brand}/categories/{category}/upgrade/preview', [\App\Http\Controllers\CategoryController::class, 'previewUpgrade'])->name('brands.categories.upgrade.preview');
            Route::post('/brands/{brand}/categories/{category}/upgrade', [\App\Http\Controllers\CategoryController::class, 'applyUpgrade'])->name('brands.categories.upgrade.apply');
            Route::post('/brands/{brand}/categories/{category}/accept-deletion', [\App\Http\Controllers\CategoryController::class, 'acceptDeletion'])->name('brands.categories.accept-deletion');

            // Support ticket routes (tenant-scoped)
            Route::resource('support/tickets', \App\Http\Controllers\TenantTicketController::class)->only(['index', 'create', 'store', 'show']);
            Route::post('/support/tickets/{ticket}/reply', [\App\Http\Controllers\TenantTicketController::class, 'reply'])->name('support.tickets.reply');
            Route::post('/support/tickets/{ticket}/close', [\App\Http\Controllers\TenantTicketController::class, 'close'])->name('support.tickets.close');
        });
        
        // Routes that don't require user to be within plan limit (like billing, company settings)
        Route::get('/companies/settings', [\App\Http\Controllers\CompanyController::class, 'settings'])->name('companies.settings');
        Route::put('/companies/settings', [\App\Http\Controllers\CompanyController::class, 'updateSettings'])->name('companies.settings.update');
        Route::delete('/companies/settings', [\App\Http\Controllers\CompanyController::class, 'destroy'])->name('companies.destroy');
        Route::get('/companies/team', [\App\Http\Controllers\TeamController::class, 'index'])->name('companies.team');
        Route::post('/companies/{tenant}/team/invite', [\App\Http\Controllers\TeamController::class, 'invite'])->name('companies.team.invite');
        Route::put('/companies/{tenant}/team/{user}/role', [\App\Http\Controllers\TeamController::class, 'updateTenantRole'])->name('companies.team.update-role');
        Route::put('/companies/{tenant}/team/{user}/brands/{brand}/role', [\App\Http\Controllers\TeamController::class, 'updateBrandRole'])->name('companies.team.update-brand-role');
        Route::delete('/companies/{tenant}/team/{user}', [\App\Http\Controllers\TeamController::class, 'remove'])->name('companies.team.remove');
        Route::get('/companies/activity', [\App\Http\Controllers\CompanyController::class, 'activity'])->name('companies.activity');
    });
});

// Stripe webhook (no auth, no CSRF)
Route::post('/webhook/stripe', [\App\Http\Controllers\WebhookController::class, 'handleWebhook'])->name('webhook.stripe');


