<?php

use App\Http\Controllers\Auth\LoginController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;



Route::get('/', fn () => Inertia::render('Home'));

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
});

Route::middleware('auth')->prefix('app')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
    
    // Company management (no tenant middleware - can access when no tenant selected)
    Route::get('/companies', [\App\Http\Controllers\CompanyController::class, 'index'])->name('companies.index');
    Route::post('/companies/{tenant}/switch', [\App\Http\Controllers\CompanyController::class, 'switch'])->name('companies.switch');
    
    // Company settings (requires tenant to be selected)
    Route::middleware('tenant')->group(function () {
        Route::get('/companies/settings', [\App\Http\Controllers\CompanyController::class, 'settings'])->name('companies.settings');
        Route::put('/companies/settings', [\App\Http\Controllers\CompanyController::class, 'updateSettings'])->name('companies.settings.update');
    });
    
    // Profile routes
    Route::get('/profile', [\App\Http\Controllers\ProfileController::class, 'index'])->name('profile.index');
    Route::put('/profile', [\App\Http\Controllers\ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/password', [\App\Http\Controllers\ProfileController::class, 'updatePassword'])->name('profile.password.update');
    Route::delete('/profile', [\App\Http\Controllers\ProfileController::class, 'destroy'])->name('profile.destroy');

    // Site Admin routes (only user ID 1)
    Route::get('/admin', [\App\Http\Controllers\SiteAdminController::class, 'index'])->name('admin.index');
    Route::get('/admin/permissions', [\App\Http\Controllers\SiteAdminController::class, 'permissions'])->name('admin.permissions');
    Route::get('/admin/stripe-status', [\App\Http\Controllers\SiteAdminController::class, 'stripeStatus'])->name('admin.stripe-status');
    Route::post('/admin/permissions/site-role', [\App\Http\Controllers\SiteAdminController::class, 'saveSiteRolePermissions'])->name('admin.permissions.site-role');
    Route::post('/admin/permissions/company-role', [\App\Http\Controllers\SiteAdminController::class, 'saveCompanyRolePermissions'])->name('admin.permissions.company-role');
    
    // System Category management routes (site owner only)
    Route::get('/admin/system-categories', [\App\Http\Controllers\SystemCategoryController::class, 'index'])->name('admin.system-categories.index');
    Route::post('/admin/system-categories', [\App\Http\Controllers\SystemCategoryController::class, 'store'])->name('admin.system-categories.store');
    Route::put('/admin/system-categories/{systemCategory}', [\App\Http\Controllers\SystemCategoryController::class, 'update'])->name('admin.system-categories.update');
    Route::delete('/admin/system-categories/{systemCategory}', [\App\Http\Controllers\SystemCategoryController::class, 'destroy'])->name('admin.system-categories.destroy');
    
    // Billing routes (no tenant middleware - billing is company-level)
    Route::get('/billing', [\App\Http\Controllers\BillingController::class, 'index'])->name('billing');
    Route::post('/billing/subscribe', [\App\Http\Controllers\BillingController::class, 'subscribe'])->name('billing.subscribe');
    Route::post('/billing/update-subscription', [\App\Http\Controllers\BillingController::class, 'updateSubscription'])->name('billing.update-subscription');
    Route::post('/billing/payment-method', [\App\Http\Controllers\BillingController::class, 'updatePaymentMethod'])->name('billing.payment-method');
    Route::get('/billing/invoices', [\App\Http\Controllers\BillingController::class, 'invoices'])->name('billing.invoices');
    Route::get('/billing/invoices/{id}/download', [\App\Http\Controllers\BillingController::class, 'downloadInvoice'])->name('billing.invoices.download');
    Route::post('/billing/cancel', [\App\Http\Controllers\BillingController::class, 'cancel'])->name('billing.cancel');
    Route::post('/billing/resume', [\App\Http\Controllers\BillingController::class, 'resume'])->name('billing.resume');
    Route::get('/billing/success', [\App\Http\Controllers\BillingController::class, 'success'])->name('billing.success');
    
    Route::middleware('tenant')->group(function () {
        Route::get('/dashboard', function () {
            return Inertia::render('Dashboard', [
                'tenant' => app('tenant'),
                'brand' => app('brand'),
            ]);
        })->name('dashboard');

        // Asset routes (tenant-scoped)
        Route::get('/assets', [\App\Http\Controllers\AssetController::class, 'index'])->name('assets.index');
        Route::get('/marketing-assets', [\App\Http\Controllers\MarketingAssetController::class, 'index'])->name('marketing-assets.index');

        // Brand routes (tenant-scoped)
        Route::resource('brands', \App\Http\Controllers\BrandController::class);
        Route::post('/brands/{brand}/switch', [\App\Http\Controllers\BrandController::class, 'switch'])->name('brands.switch');

        // Category routes (tenant-scoped)
        Route::resource('categories', \App\Http\Controllers\CategoryController::class)->except(['create', 'edit', 'show']);
    });
});

// Stripe webhook (no auth, no CSRF)
Route::post('/webhook/stripe', [\App\Http\Controllers\WebhookController::class, 'handleWebhook'])->name('webhook.stripe');
