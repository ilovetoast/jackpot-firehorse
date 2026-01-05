<?php

namespace App\Http\Controllers;

use App\Services\BillingService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BillingController extends Controller
{
    public function __construct(
        protected BillingService $billingService
    ) {
    }

    /**
     * Show the billing/plans page.
     */
    public function index(Request $request): Response
    {
        // Billing is company-level, get tenant from user's companies
        $user = $request->user();
        $tenantId = session('tenant_id');
        
        if (! $tenantId) {
            // No active tenant, get first tenant from user
            $tenant = $user->tenants->first();
            if (! $tenant) {
                return redirect()->route('companies.index')->withErrors([
                    'billing' => 'You must belong to a company to view billing.',
                ]);
            }
        } else {
            $tenant = \App\Models\Tenant::find($tenantId);
            if (! $tenant || ! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
                return redirect()->route('companies.index')->withErrors([
                    'billing' => 'You do not have access to this company.',
                ]);
            }
        }

        $currentPlan = $this->billingService->getCurrentPlan($tenant);
        $subscription = $tenant->subscription('default');
        $paymentMethod = $tenant->defaultPaymentMethod();

        $plans = collect(config('plans'))->map(function ($plan, $key) use ($currentPlan) {
            return [
                'id' => $key,
                'name' => $plan['name'],
                'stripe_price_id' => $plan['stripe_price_id'],
                'limits' => $plan['limits'],
                'features' => $plan['features'],
                'is_current' => $key === $currentPlan,
            ];
        });

        return Inertia::render('Billing/Index', [
            'tenant' => $tenant,
            'current_plan' => $currentPlan,
            'plans' => $plans->values(),
            'subscription' => $subscription ? [
                'status' => $subscription->stripe_status,
                'canceled' => $subscription->canceled(),
                'on_grace_period' => $subscription->onGracePeriod(),
                'ends_at' => $subscription->ends_at?->toDateTimeString(),
            ] : null,
            'payment_method' => $paymentMethod ? [
                'type' => $paymentMethod->type,
                'last_four' => $paymentMethod->last_four,
                'brand' => $paymentMethod->card->brand ?? null,
            ] : null,
        ]);
    }

    /**
     * Handle subscription creation.
     */
    public function subscribe(Request $request)
    {
        $request->validate([
            'price_id' => 'required|string',
        ]);

        $user = $request->user();
        $tenantId = session('tenant_id');
        $tenant = $tenantId ? \App\Models\Tenant::find($tenantId) : $user->tenants->first();
        
        if (! $tenant || ! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return back()->withErrors(['billing' => 'Invalid company.']);
        }
        
        try {
            $checkoutUrl = $this->billingService->createCheckoutSession($tenant, $request->price_id);

            return Inertia::location($checkoutUrl);
        } catch (\Exception $e) {
            return back()->withErrors([
                'subscription' => 'Failed to create subscription: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle subscription updates (plan changes).
     */
    public function updateSubscription(Request $request)
    {
        $request->validate([
            'price_id' => 'required|string',
        ]);

        $user = $request->user();
        $tenantId = session('tenant_id');
        $tenant = $tenantId ? \App\Models\Tenant::find($tenantId) : $user->tenants->first();
        
        if (! $tenant || ! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return back()->withErrors(['billing' => 'Invalid company.']);
        }

        try {
            $this->billingService->updateSubscription($tenant, $request->price_id);

            return redirect()->route('billing')->with('success', 'Subscription updated successfully.');
        } catch (\Exception $e) {
            return back()->withErrors([
                'subscription' => 'Failed to update subscription: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle payment method updates.
     */
    public function updatePaymentMethod(Request $request)
    {
        $request->validate([
            'payment_method' => 'required|string',
        ]);

        $user = $request->user();
        $tenantId = session('tenant_id');
        $tenant = $tenantId ? \App\Models\Tenant::find($tenantId) : $user->tenants->first();
        
        if (! $tenant || ! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return back()->withErrors(['billing' => 'Invalid company.']);
        }

        try {
            $this->billingService->updatePaymentMethod($tenant, $request->payment_method);

            return redirect()->route('billing')->with('success', 'Payment method updated successfully.');
        } catch (\Exception $e) {
            return back()->withErrors([
                'payment_method' => 'Failed to update payment method: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * List invoices.
     */
    public function invoices(Request $request)
    {
        $user = $request->user();
        $tenantId = session('tenant_id');
        $tenant = $tenantId ? \App\Models\Tenant::find($tenantId) : $user->tenants->first();
        
        if (! $tenant || ! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return redirect()->route('companies.index')->withErrors(['billing' => 'Invalid company.']);
        }
        $invoices = $this->billingService->getInvoices($tenant);

        return Inertia::render('Billing/Invoices', [
            'invoices' => $invoices,
        ]);
    }

    /**
     * Download invoice PDF.
     */
    public function downloadInvoice(Request $request, $invoiceId)
    {
        $user = $request->user();
        $tenantId = session('tenant_id');
        $tenant = $tenantId ? \App\Models\Tenant::find($tenantId) : $user->tenants->first();
        
        if (! $tenant || ! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return back()->withErrors(['billing' => 'Invalid company.']);
        }

        try {
            return $tenant->downloadInvoice($invoiceId, [
                'vendor' => config('app.name'),
                'product' => 'Subscription',
            ]);
        } catch (\Exception $e) {
            return back()->withErrors([
                'invoice' => 'Failed to download invoice: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Cancel subscription.
     */
    public function cancel(Request $request)
    {
        $user = $request->user();
        $tenantId = session('tenant_id');
        $tenant = $tenantId ? \App\Models\Tenant::find($tenantId) : $user->tenants->first();
        
        if (! $tenant || ! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return back()->withErrors(['billing' => 'Invalid company.']);
        }

        try {
            $this->billingService->cancelSubscription($tenant);

            return redirect()->route('billing')->with('success', 'Subscription cancelled successfully.');
        } catch (\Exception $e) {
            return back()->withErrors([
                'subscription' => 'Failed to cancel subscription: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Resume cancelled subscription.
     */
    public function resume(Request $request)
    {
        $user = $request->user();
        $tenantId = session('tenant_id');
        $tenant = $tenantId ? \App\Models\Tenant::find($tenantId) : $user->tenants->first();
        
        if (! $tenant || ! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return back()->withErrors(['billing' => 'Invalid company.']);
        }

        try {
            $this->billingService->resumeSubscription($tenant);

            return redirect()->route('billing')->with('success', 'Subscription resumed successfully.');
        } catch (\Exception $e) {
            return back()->withErrors([
                'subscription' => 'Failed to resume subscription: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle successful checkout redirect.
     */
    public function success()
    {
        return redirect()->route('billing')->with('success', 'Subscription activated successfully!');
    }
}
