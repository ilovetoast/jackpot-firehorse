<?php

use App\Http\Controllers\Auth\LoginController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;



Route::get('/', fn () => Inertia::render('Home'));

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
    
    // Company management (no tenant middleware - can access when no tenant selected)
    Route::get('/companies', [\App\Http\Controllers\CompanyController::class, 'index'])->name('companies.index');
    Route::post('/companies/{tenant}/switch', [\App\Http\Controllers\CompanyController::class, 'switch'])->name('companies.switch');
    
    // Site Admin routes (only user ID 1)
    Route::get('/admin', [\App\Http\Controllers\SiteAdminController::class, 'index'])->name('admin.index');
    
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

        // Brand routes (tenant-scoped)
        Route::resource('brands', \App\Http\Controllers\BrandController::class);

        // Category routes (tenant-scoped)
        Route::resource('categories', \App\Http\Controllers\CategoryController::class)->except(['create', 'edit', 'show']);
    });
});

// Stripe webhook (no auth, no CSRF)
Route::post('/webhook/stripe', [\App\Http\Controllers\WebhookController::class, 'handleWebhook'])->name('webhook.stripe');
