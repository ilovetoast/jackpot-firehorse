<?php

namespace App\Http\Controllers;

use App\Enums\DownloadStatus;
use App\Models\Download;
use App\Services\AiUsageService;
use App\Services\BillingService;
use App\Services\PlanService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Stripe\Price;
use Stripe\Stripe;

class BillingController extends Controller
{
    public function __construct(
        protected BillingService $billingService,
        protected AiUsageService $aiUsageService
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
        $startOfMonth = now()->startOfMonth();
        $endOfMonth = now()->endOfMonth();
        
        // Get AI usage for current month
        $aiUsageStatus = $this->aiUsageService->getUsageStatus($tenant);
        
        $currentUsage = [
            'brands' => $tenant->brands()->count(),
            'users' => $tenant->users()->count(),
            'categories' => $tenant->brands()->withCount(['categories' => function ($query) {
                $query->where('is_system', false);
            }])->get()->sum('categories_count'),
            'storage_mb' => 0, // TODO: Calculate actual storage usage
            'download_links' => Download::where('tenant_id', $tenant->id)
                ->where('status', DownloadStatus::READY)
                ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
                ->count(),
            'custom_metadata_fields' => DB::table('metadata_fields')
                ->where('tenant_id', $tenant->id)
                ->where('scope', 'tenant')
                ->where('is_active', true)
                ->count(),
            'ai_tagging' => $aiUsageStatus['tagging']['usage'] ?? 0,
            'ai_suggestions' => $aiUsageStatus['suggestions']['usage'] ?? 0,
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
                    // Price not found or error fetching - use fallback if available
                    $priceData = null;
                    $monthlyPrice = null;
                }
                
                // Use fallback price if Stripe price not available
                if (!$monthlyPrice && isset($plan['fallback_monthly_price'])) {
                    $monthlyPrice = number_format($plan['fallback_monthly_price'], 2);
                }
            }

            return [
                'id' => $key,
                'name' => $plan['name'],
                'stripe_price_id' => $plan['stripe_price_id'],
                'limits' => $plan['limits'],
                'features' => $plan['features'],
                'download_features' => $plan['download_features'] ?? null,
                'download_management' => $plan['download_management'] ?? null,
                'is_current' => $key === $currentPlan,
                'price' => $priceData,
                'monthly_price' => $monthlyPrice,
            ];
        });

        $currentPlanLimits = $planService->getPlanLimits($tenant);
        
        // Use site-wide branding color (not tenant-specific)
        $sitePrimaryColor = '#6366f1'; // Default Jackpot brand color

        // Check for incomplete payment and get payment URL if needed
        // Only check if subscription has a Stripe customer (stripe_id) - forced plans may not have one
        $paymentUrl = null;
        $hasIncompletePayment = false;
        if ($subscription && $subscription->stripe_id && $tenant->stripe_id && method_exists($subscription, 'hasIncompletePayment')) {
            try {
                // Set the owner on the subscription so Cashier methods work properly
                // When we query subscriptions directly, the owner isn't automatically set
                if (method_exists($subscription, 'setOwner')) {
                    $subscription->setOwner($tenant);
                } elseif (property_exists($subscription, 'owner')) {
                    $subscription->owner = $tenant;
                } elseif (method_exists($subscription, 'setRelation')) {
                    $subscription->setRelation('owner', $tenant);
                }
                
                $hasIncomplete = $subscription->hasIncompletePayment();
                if ($hasIncomplete && method_exists($subscription, 'latestPayment')) {
                    try {
                        $latestPayment = $subscription->latestPayment();
                        if ($latestPayment && is_object($latestPayment) && isset($latestPayment->id)) {
                            try {
                                $paymentUrl = route('subscription.payment', $latestPayment->id);
                            } catch (\Exception $routeError) {
                                // If route doesn't exist, construct URL manually
                                $paymentUrl = url('/subscription/payment/' . $latestPayment->id);
                            }
                            $hasIncompletePayment = true;
                        }
                    } catch (\Exception $paymentError) {
                        // If we can't get latest payment, that's okay
                        // Log the error for debugging but don't break the page
                        \Log::warning('Failed to get latest payment for subscription', [
                            'subscription_id' => $subscription->id ?? null,
                            'error' => $paymentError->getMessage(),
                        ]);
                    }
                }
            } catch (\Exception $e) {
                // If hasIncompletePayment() throws an error, that's okay
                // This can happen if Stripe connection is not properly configured
                \Log::warning('Failed to check incomplete payment status', [
                    'subscription_id' => $subscription->id ?? null,
                    'error' => $e->getMessage(),
                ]);
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
                // Only check if subscription has a Stripe customer (stripe_id) - forced plans may not have one
                $subscription = $tenant->subscription('default');
                if ($subscription && $subscription->stripe_id && $tenant->stripe_id && method_exists($subscription, 'hasIncompletePayment') && $subscription->hasIncompletePayment()) {
                    try {
                        // Ensure owner is set on subscription before calling latestPayment()
                        // Even though subscription() should set it, we add this as a safety check
                        if (method_exists($subscription, 'setOwner')) {
                            $subscription->setOwner($tenant);
                        } elseif (property_exists($subscription, 'owner')) {
                            $subscription->owner = $tenant;
                        } elseif (method_exists($subscription, 'setRelation')) {
                            $subscription->setRelation('owner', $tenant);
                        }
                        
                        $latestPayment = $subscription->latestPayment();
                        if ($latestPayment && $latestPayment->id) {
                            // Return error with payment URL - Cashier provides this route
                            // Uses our custom subscription.payment route
                            try {
                                $paymentUrl = route('subscription.payment', $latestPayment->id);
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

        // Calculate on-demand usage and monthly average
        $onDemandUsage = 0.00;
        $monthlyAverage = 0.00;
        $currency = 'USD';
        
        if ($tenant->stripe_id && $subscription) {
            try {
                $stripeSecret = env('STRIPE_SECRET');
                if ($stripeSecret) {
                    \Stripe\Stripe::setApiKey($stripeSecret);
                    
                    // Get current subscription price
                    $subscriptionPrice = 0;
                    if ($subscription->stripe_id) {
                        try {
                            $stripeSubscription = \Stripe\Subscription::retrieve($subscription->stripe_id);
                            if ($stripeSubscription && isset($stripeSubscription->items->data[0])) {
                                $priceId = $stripeSubscription->items->data[0]->price->id;
                                $price = \Stripe\Price::retrieve($priceId);
                                $subscriptionPrice = ($price->unit_amount ?? 0) / 100;
                                $currency = strtoupper($price->currency ?? 'usd');
                            }
                        } catch (\Exception $e) {
                            // If we can't get subscription price, continue with 0
                        }
                    }
                    
                    // Get invoices from the last 12 months
                    $twelveMonthsAgo = time() - (12 * 30 * 24 * 60 * 60);
                    $invoices = \Stripe\Invoice::all([
                        'customer' => $tenant->stripe_id,
                        'limit' => 100,
                        'created' => ['gte' => $twelveMonthsAgo],
                    ]);
                    
                    $totalAmount = 0;
                    $invoiceCount = 0;
                    $onDemandTotal = 0;
                    
                    foreach ($invoices->data as $invoice) {
                        if ($invoice->status === 'paid' && $invoice->amount_paid > 0) {
                            $invoiceAmount = $invoice->amount_paid / 100;
                            $totalAmount += $invoiceAmount;
                            $invoiceCount++;
                            
                            // Calculate on-demand: invoice amount minus subscription price
                            // This is a simplified calculation - in reality, you'd need to parse line items
                            $onDemandTotal += max(0, $invoiceAmount - $subscriptionPrice);
                        }
                    }
                    
                    // Calculate monthly average (total / number of months with invoices)
                    if ($invoiceCount > 0) {
                        // Estimate months based on invoice count (assuming monthly billing)
                        $months = min($invoiceCount, 12);
                        $monthlyAverage = $totalAmount / $months;
                    }
                    
                    // On-demand usage for current period (simplified - would need to check current period invoices)
                    $onDemandUsage = $onDemandTotal;
                }
            } catch (\Exception $e) {
                // If we can't calculate, use defaults (0.00)
                \Log::warning('Failed to calculate on-demand usage and monthly average', [
                    'tenant_id' => $tenant->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

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

        // Check for incomplete payment and get payment URL if needed
        $hasIncompletePayment = false;
        $paymentUrl = null;
        if ($subscription && $subscription->stripe_id && $tenant->stripe_id && method_exists($subscription, 'hasIncompletePayment')) {
            try {
                // Set the owner on the subscription so Cashier methods work properly
                if (method_exists($subscription, 'setOwner')) {
                    $subscription->setOwner($tenant);
                } elseif (property_exists($subscription, 'owner')) {
                    $subscription->owner = $tenant;
                } elseif (method_exists($subscription, 'setRelation')) {
                    $subscription->setRelation('owner', $tenant);
                }
                
                $hasIncomplete = $subscription->hasIncompletePayment();
                if ($hasIncomplete && method_exists($subscription, 'latestPayment')) {
                    try {
                        $latestPayment = $subscription->latestPayment();
                        if ($latestPayment && is_object($latestPayment) && isset($latestPayment->id)) {
                            try {
                                $paymentUrl = route('subscription.payment', $latestPayment->id);
                            } catch (\Exception $routeError) {
                                // If route doesn't exist, construct URL manually
                                $paymentUrl = url('/subscription/payment/' . $latestPayment->id);
                            }
                            $hasIncompletePayment = true;
                        }
                    } catch (\Exception $paymentError) {
                        // If we can't get latest payment, that's okay
                        \Log::warning('Failed to get latest payment for subscription in overview', [
                            'subscription_id' => $subscription->id ?? null,
                            'error' => $paymentError->getMessage(),
                        ]);
                    }
                }
            } catch (\Exception $e) {
                // If hasIncompletePayment() throws an error, that's okay
                \Log::warning('Failed to check incomplete payment status in overview', [
                    'subscription_id' => $subscription->id ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
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
                'has_incomplete_payment' => $hasIncompletePayment,
                'payment_url' => $paymentUrl,
            ],
            'payment_method' => $paymentMethod ? [
                'type' => $paymentMethod->type,
                'last_four' => $paymentMethod->last_four,
                'brand' => $paymentMethod->card->brand ?? null,
            ] : null,
            'recent_invoices' => $recentInvoices,
            'has_stripe_id' => $tenant->stripe_id !== null,
            'on_demand_usage' => $onDemandUsage,
            'monthly_average' => $monthlyAverage,
            'currency' => $currency,
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
     * Always redirects to Stripe's hosted payment confirmation page.
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

            // Get redirect URL from query parameter or default to billing overview page
            $redirectUrl = $request->get('redirect', route('billing.overview'));
            
            // Always redirect to Stripe's hosted confirmation page if payment requires action
            // This ensures we never try to embed Stripe Elements in our React/Inertia app
            if ($paymentIntent->status === 'requires_action' || $paymentIntent->status === 'requires_payment_method') {
                // For 3D Secure or other payment confirmations, always redirect to Stripe's hosted page
                if (isset($paymentIntent->next_action->redirect_to_url->url)) {
                    // Full redirect to Stripe's hosted confirmation page (no Inertia)
                    return redirect()->away($paymentIntent->next_action->redirect_to_url->url);
                }
                
                // If no redirect URL but payment requires confirmation, try to get it
                try {
                    // Retrieve the payment intent again to get latest next_action
                    $updatedPaymentIntent = \Stripe\PaymentIntent::retrieve($paymentId);
                    
                    if (isset($updatedPaymentIntent->next_action->redirect_to_url->url)) {
                        return redirect()->away($updatedPaymentIntent->next_action->redirect_to_url->url);
                    }
                } catch (\Exception $retrieveError) {
                    // Continue to fallback
                }
                
                // Fallback: redirect to Stripe Customer Portal where they can update payment method
                if ($tenant->stripe_id) {
                    try {
                        $portalUrl = $this->billingService->getCustomerPortalUrl(
                            $tenant,
                            $redirectUrl
                        );
                        return redirect()->away($portalUrl);
                    } catch (\Exception $portalError) {
                        // Continue to error message
                    }
                }
                
                // Final fallback: redirect to billing with error
                return redirect()->route('billing')->withErrors([
                    'payment' => 'Payment requires additional confirmation. Please update your payment method in Stripe Customer Portal.'
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
