<?php

namespace App\Http\Controllers;

use App\Services\BillingService;
use App\Services\PlanService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Stripe\Price;
use Stripe\Stripe;

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
        // Query subscription directly instead of using Cashier's method (more reliable with Tenant model)
        $subscription = $tenant->subscriptions()
            ->where('name', 'default')
            ->orderBy('created_at', 'desc')
            ->first();
        $paymentMethod = $tenant->defaultPaymentMethod();
        $planService = new PlanService();

        // Get current usage counts
        $currentUsage = [
            'brands' => $tenant->brands()->count(),
            'users' => $tenant->users()->count(),
            'categories' => $tenant->brands()->withCount(['categories' => function ($query) {
                $query->where('is_system', false);
            }])->get()->sum('categories_count'),
            'storage_mb' => 0, // TODO: Calculate actual storage usage
        ];

        // Fetch Stripe price data for each plan
        $plans = collect(config('plans'))->map(function ($plan, $key) use ($currentPlan, $currentUsage) {
            $priceData = null;
            $monthlyPrice = null;
            
            // Skip free plan
            if ($key !== 'free' && $plan['stripe_price_id'] !== 'price_free') {
                try {
                    $stripeSecret = env('STRIPE_SECRET');
                    if ($stripeSecret) {
                        Stripe::setApiKey($stripeSecret);
                        $price = Price::retrieve($plan['stripe_price_id']);
                        $monthlyPrice = $price->unit_amount ? number_format($price->unit_amount / 100, 2) : null;
                        $priceData = [
                            'amount' => $price->unit_amount ? number_format($price->unit_amount / 100, 2) : null,
                            'currency' => strtoupper($price->currency ?? 'usd'),
                            'interval' => $price->recurring->interval ?? 'month',
                        ];
                    }
                } catch (\Exception $e) {
                    // Price not found or error fetching - will show without price
                    $priceData = null;
                    $monthlyPrice = null;
                }
            }

            return [
                'id' => $key,
                'name' => $plan['name'],
                'stripe_price_id' => $plan['stripe_price_id'],
                'limits' => $plan['limits'],
                'features' => $plan['features'],
                'is_current' => $key === $currentPlan,
                'price' => $priceData,
                'monthly_price' => $monthlyPrice,
            ];
        });

        $currentPlanLimits = $planService->getPlanLimits($tenant);
        
        // Use site-wide branding color (not tenant-specific)
        $sitePrimaryColor = '#6366f1'; // Default Jackpot brand color

        // Check for incomplete payment and get payment URL if needed
        $paymentUrl = null;
        $hasIncompletePayment = false;
        if ($subscription && method_exists($subscription, 'hasIncompletePayment') && $subscription->hasIncompletePayment()) {
            try {
                $latestPayment = $subscription->latestPayment();
                if ($latestPayment && $latestPayment->id) {
                    try {
                        $paymentUrl = route('cashier.payment', $latestPayment->id);
                    } catch (\Exception $routeError) {
                        // If route doesn't exist, construct URL manually
                        $paymentUrl = url('/subscription/payment/' . $latestPayment->id);
                    }
                    $hasIncompletePayment = true;
                }
            } catch (\Exception $e) {
                // If we can't get payment URL, that's okay
            }
        }

        return Inertia::render('Billing/Index', [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
            ],
            'site_primary_color' => $sitePrimaryColor,
            'current_plan' => $currentPlan,
            'current_plan_limits' => $currentPlanLimits,
            'current_usage' => $currentUsage,
            'plans' => $plans->values(),
            'subscription' => $subscription ? [
                'status' => $this->getSubscriptionStatus($subscription),
                'canceled' => method_exists($subscription, 'canceled') ? $subscription->canceled() : ($subscription->ends_at !== null),
                'on_grace_period' => method_exists($subscription, 'onGracePeriod') ? $subscription->onGracePeriod() : false,
                'ends_at' => $subscription->ends_at?->toDateTimeString(),
                'has_incomplete_payment' => $hasIncompletePayment,
                'payment_url' => $paymentUrl,
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
     * 
     * BEST PRACTICE: If user already has a subscription, redirect to updateSubscription instead.
     * This ensures proper proration and prevents duplicate subscriptions.
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
        
        // Check if tenant already has an active subscription
        $existingSubscription = $tenant->subscription('default');
        
        if ($existingSubscription && $existingSubscription->stripe_status === 'active') {
            // If they have an active subscription, use updateSubscription instead
            // This ensures proper proration and prevents duplicate subscriptions
            $planService = new PlanService();
            $oldPlan = $planService->getCurrentPlan($tenant);
            
            // Determine new plan ID from price ID
            $newPlanId = null;
            $plans = config('plans');
            foreach ($plans as $planKey => $planConfig) {
                if ($planConfig['stripe_price_id'] === $request->price_id) {
                    $newPlanId = $planKey;
                    break;
                }
            }
            
            try {
                $result = $this->billingService->updateSubscription(
                    $tenant, 
                    $request->price_id,
                    $oldPlan,
                    $newPlanId
                );
                
                // Format success message based on action
                $oldPlanName = config("plans.{$result['old_plan']}.name", ucfirst($result['old_plan']));
                $newPlanName = config("plans.{$result['new_plan']}.name", ucfirst($result['new_plan']));
                
                $message = match($result['action']) {
                    'upgrade' => "Successfully upgraded from {$oldPlanName} to {$newPlanName}. Stripe has automatically prorated the charges - you'll see the adjustment on your next invoice.",
                    'downgrade' => "Successfully downgraded from {$oldPlanName} to {$newPlanName}. Changes will take effect at the end of your billing period, and you'll receive a prorated credit.",
                    default => "Successfully updated subscription to {$newPlanName}.",
                };

                return redirect()->route('billing')->with('success', $message);
            } catch (\Exception $e) {
                return back()->withErrors([
                    'subscription' => 'Failed to update subscription: ' . $e->getMessage(),
                ]);
            }
        }
        
        // No existing subscription - create new one via checkout
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
            'plan_id' => 'nullable|string', // New plan ID for activity logging
        ]);

        $user = $request->user();
        $tenantId = session('tenant_id');
        $tenant = $tenantId ? \App\Models\Tenant::find($tenantId) : $user->tenants->first();
        
        if (! $tenant || ! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return back()->withErrors(['billing' => 'Invalid company.']);
        }

        try {
            $planService = new PlanService();
            $oldPlan = $planService->getCurrentPlan($tenant);
            
            $result = $this->billingService->updateSubscription(
                $tenant, 
                $request->price_id,
                $oldPlan,
                $request->plan_id
            );
            
            // Format success message based on action
            $oldPlanName = config("plans.{$result['old_plan']}.name", ucfirst($result['old_plan']));
            $newPlanName = config("plans.{$result['new_plan']}.name", ucfirst($result['new_plan']));
            
            $message = match($result['action']) {
                'upgrade' => "Successfully upgraded from {$oldPlanName} to {$newPlanName}. Stripe has automatically prorated the charges - you'll see the adjustment on your next invoice.",
                'downgrade' => "Successfully downgraded from {$oldPlanName} to {$newPlanName}. Changes will take effect at the end of your billing period, and you'll receive a prorated credit.",
                'created' => "Successfully subscribed to {$newPlanName}. Welcome!",
                default => "Successfully updated subscription to {$newPlanName}.",
            };

            return redirect()->route('billing')->with('success', $message);
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            
            // Check if error is about incomplete payment
            if (str_contains(strtolower($errorMessage), 'incomplete') || str_contains(strtolower($errorMessage), 'payment')) {
                // Try to get payment confirmation URL
                $subscription = $tenant->subscription('default');
                if ($subscription && method_exists($subscription, 'hasIncompletePayment') && $subscription->hasIncompletePayment()) {
                    try {
                        $latestPayment = $subscription->latestPayment();
                        if ($latestPayment && $latestPayment->id) {
                            // Return error with payment URL - Cashier provides this route
                            // The route name might be 'cashier.payment' or we need to construct the URL
                            try {
                                $paymentUrl = route('cashier.payment', $latestPayment->id);
                            } catch (\Exception $routeError) {
                                // If route doesn't exist, construct URL manually
                                $paymentUrl = url('/subscription/payment/' . $latestPayment->id);
                            }
                            
                            return back()->withErrors([
                                'subscription' => 'Your subscription has an incomplete payment. Please complete your payment before changing plans.',
                                'payment_url' => $paymentUrl,
                                'has_incomplete_payment' => true,
                            ]);
                        }
                    } catch (\Exception $paymentError) {
                        // If we can't get payment URL, just show the error
                    }
                }
            }
            
            return back()->withErrors([
                'subscription' => 'Failed to update subscription: ' . $errorMessage,
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
     * Show billing overview page with current plan, recent charges, and invoices.
     */
    public function overview(Request $request): Response
    {
        $user = $request->user();
        $tenantId = session('tenant_id');
        $tenant = $tenantId ? \App\Models\Tenant::find($tenantId) : $user->tenants->first();
        
        if (! $tenant || ! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return redirect()->route('companies.index')->withErrors(['billing' => 'Invalid company.']);
        }

        $currentPlan = $this->billingService->getCurrentPlan($tenant);
        // Query subscription directly instead of using Cashier's method (more reliable with Tenant model)
        $subscription = $tenant->subscriptions()
            ->where('name', 'default')
            ->orderBy('created_at', 'desc')
            ->first();
        $paymentMethod = $tenant->defaultPaymentMethod();
        $planService = new PlanService();
        
        // Get plan details
        $planConfig = config('plans.' . $currentPlan, []);
        $currentPlanName = $planConfig['name'] ?? ucfirst($currentPlan);
        
        // Get recent invoices (last 10)
        $allInvoices = $this->billingService->getInvoices($tenant);
        $recentInvoices = $allInvoices->take(10)->map(function ($invoice) {
            return [
                'id' => $invoice['id'],
                'amount' => $invoice['amount'],
                'currency' => $invoice['currency'],
                'status' => $invoice['status'],
                'date' => date('M d, Y', $invoice['date']),
                'date_raw' => $invoice['date'],
                'url' => $invoice['url'],
                'pdf' => $invoice['pdf'],
            ];
        })->sortByDesc('date_raw')->values();

        // Get subscription period dates and latest status from Stripe
        $periodStart = null;
        $periodEnd = null;
        $stripeStatus = null;
        if ($subscription && $subscription->stripe_id) {
            try {
                // Fetch Stripe subscription directly using the subscription's stripe_id
                // This ensures we get the latest status from Stripe, not stale database data
                $stripeSecret = env('STRIPE_SECRET');
                if ($stripeSecret) {
                    \Stripe\Stripe::setApiKey($stripeSecret);
                    $stripeSubscription = \Stripe\Subscription::retrieve($subscription->stripe_id);
                    if ($stripeSubscription) {
                        $periodStart = $stripeSubscription->current_period_start ? date('M d, Y', $stripeSubscription->current_period_start) : null;
                        $periodEnd = $stripeSubscription->current_period_end ? date('M d, Y', $stripeSubscription->current_period_end) : null;
                        // Get the actual current status from Stripe (this is the source of truth)
                        $stripeStatus = $stripeSubscription->status;
                        
                        // Sync the status back to database for future queries (keeps DB in sync)
                        if ($subscription->stripe_status !== $stripeStatus) {
                            $subscription->stripe_status = $stripeStatus;
                            $subscription->save();
                        }
                    }
                }
            } catch (\Exception $e) {
                // Fallback if subscription data unavailable - use database fields if available
                // We can't get period dates from Stripe, but that's okay
            }
        }

        // Format subscription status - use Stripe status if available, otherwise fall back to database
        $subscriptionStatus = 'No Subscription';
        $statusToDisplay = $stripeStatus ?? $subscription->stripe_status ?? null;
        if ($statusToDisplay) {
            $statusMap = [
                'active' => 'Active',
                'canceled' => 'Canceled',
                'incomplete' => 'Incomplete',
                'incomplete_expired' => 'Incomplete Expired',
                'past_due' => 'Past Due',
                'trialing' => 'Trialing',
                'unpaid' => 'Unpaid',
            ];
            $subscriptionStatus = $statusMap[$statusToDisplay] ?? $statusToDisplay;
        }

        return Inertia::render('Billing/Overview', [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
            ],
            'current_plan' => [
                'id' => $currentPlan,
                'name' => $currentPlanName,
            ],
            'subscription' => [
                'status' => $subscriptionStatus,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'has_subscription' => $subscription !== null,
            ],
            'payment_method' => $paymentMethod ? [
                'type' => $paymentMethod->type,
                'last_four' => $paymentMethod->last_four,
                'brand' => $paymentMethod->card->brand ?? null,
            ] : null,
            'recent_invoices' => $recentInvoices,
            'has_stripe_id' => $tenant->stripe_id !== null,
        ]);
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

    /**
     * Redirect to Stripe Customer Portal for self-service billing management.
     */
    public function customerPortal(Request $request)
    {
        $user = $request->user();
        $tenantId = session('tenant_id');
        $tenant = $tenantId ? \App\Models\Tenant::find($tenantId) : $user->tenants->first();
        
        if (! $tenant || ! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return back()->withErrors(['billing' => 'Invalid company.']);
        }

        if (! $tenant->stripe_id) {
            return back()->withErrors([
                'billing' => 'No billing account found. Please subscribe to a plan first.',
            ]);
        }

        try {
            $portalUrl = $this->billingService->getCustomerPortalUrl(
                $tenant,
                route('billing')
            );

            return redirect($portalUrl);
        } catch (\Exception $e) {
            return back()->withErrors([
                'billing' => 'Failed to access billing portal: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Get the current subscription status from Stripe (source of truth).
     * Falls back to database status if Stripe API is unavailable.
     */
    private function getSubscriptionStatus($subscription): string
    {
        if (!$subscription || !$subscription->stripe_id) {
            return 'unknown';
        }

        try {
            $stripeSecret = env('STRIPE_SECRET');
            if ($stripeSecret) {
                \Stripe\Stripe::setApiKey($stripeSecret);
                $stripeSubscription = \Stripe\Subscription::retrieve($subscription->stripe_id);
                if ($stripeSubscription) {
                    $stripeStatus = $stripeSubscription->status;
                    
                    // Sync status back to database
                    if ($subscription->stripe_status !== $stripeStatus) {
                        $subscription->stripe_status = $stripeStatus;
                        $subscription->save();
                    }
                    
                    return $stripeStatus;
                }
            }
        } catch (\Exception $e) {
            // Fallback to database status if Stripe API fails
        }

        return $subscription->stripe_status ?? 'unknown';
    }

    /**
     * Handle payment confirmation for incomplete payments.
     * This route mimics Cashier's payment confirmation route for tenants.
     */
    public function payment(Request $request, $paymentId)
    {
        $user = $request->user();
        $tenantId = session('tenant_id');
        $tenant = $tenantId ? \App\Models\Tenant::find($tenantId) : $user->tenants->first();
        
        if (! $tenant || ! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return redirect()->route('billing')->withErrors(['billing' => 'Invalid company.']);
        }

        try {
            $stripeSecret = env('STRIPE_SECRET');
            if (!$stripeSecret) {
                throw new \RuntimeException('Stripe is not configured.');
            }

            Stripe::setApiKey($stripeSecret);
            
            // Retrieve the payment intent from Stripe
            $paymentIntent = \Stripe\PaymentIntent::retrieve($paymentId);
            
            if (!$paymentIntent) {
                throw new \RuntimeException('Payment not found.');
            }

            // Get redirect URL from query parameter or default to billing page
            $redirectUrl = $request->get('redirect', route('billing'));
            
            // If payment requires action, redirect to Stripe's hosted confirmation page
            if ($paymentIntent->status === 'requires_action' || $paymentIntent->status === 'requires_payment_method') {
                // For 3D Secure or other payment confirmations, redirect to Stripe's hosted page
                if (isset($paymentIntent->next_action->redirect_to_url->url)) {
                    return redirect($paymentIntent->next_action->redirect_to_url->url);
                }
                
                // Fallback: redirect to billing with error
                return redirect()->route('billing')->withErrors([
                    'payment' => 'Payment requires additional confirmation. Please try again or contact support.'
                ]);
            }

            // If payment is already succeeded, redirect to success
            if ($paymentIntent->status === 'succeeded') {
                return redirect($redirectUrl)->with('success', 'Payment confirmed successfully.');
            }

            // For other statuses, redirect back with status
            return redirect($redirectUrl)->with('info', 'Payment status: ' . $paymentIntent->status);
            
        } catch (\Exception $e) {
            return redirect()->route('billing')->withErrors([
                'payment' => 'Failed to process payment confirmation: ' . $e->getMessage()
            ]);
        }
    }
}
