<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Cashier\Subscription;
use Stripe\Stripe;
use Stripe\Account;
use Stripe\Price;

class SiteAdminController extends Controller
{
    /**
     * Display the site admin dashboard.
     */
    public function index(): Response
    {
        // Only user ID 1 (Site Owner) can access
        if (Auth::id() !== 1) {
            abort(403, 'Only site owners can access this page.');
        }
        $companies = Tenant::with(['brands', 'users'])->get();
        
        $stats = [
            'total_companies' => Tenant::count(),
            'total_brands' => Brand::count(),
            'total_users' => User::count(),
            'active_subscriptions' => Subscription::where('stripe_status', 'active')->count(),
            'stripe_accounts' => Tenant::whereNotNull('stripe_id')->count(),
            'support_tickets' => 0, // Placeholder for future implementation
        ];

        // Get all users with their companies
        $allUsers = User::with('tenants')->get()->map(fn ($user) => [
            'id' => $user->id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'companies_count' => $user->tenants->count(),
            'companies' => $user->tenants->map(fn ($tenant) => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
            ]),
        ]);

        return Inertia::render('Admin/Index', [
            'companies' => $companies->map(fn ($company) => [
                'id' => $company->id,
                'name' => $company->name,
                'slug' => $company->slug,
                'brands_count' => $company->brands->count(),
                'users_count' => $company->users->count(),
                'brands' => $company->brands->map(fn ($brand) => [
                    'id' => $brand->id,
                    'name' => $brand->name,
                    'slug' => $brand->slug,
                    'is_default' => $brand->is_default,
                ]),
                'users' => $company->users->map(fn ($user) => [
                    'id' => $user->id,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'email' => $user->email,
                ]),
            ]),
            'users' => $allUsers,
            'stats' => $stats,
        ]);
    }

    /**
     * Display the permissions management page.
     */
    public function permissions(): Response
    {
        // Only user ID 1 (Site Owner) can access
        if (Auth::id() !== 1) {
            abort(403, 'Only site owners can access this page.');
        }

        return Inertia::render('Admin/Permissions');
    }

    /**
     * Display the Stripe status page.
     */
    public function stripeStatus(): Response
    {
        // Only user ID 1 (Site Owner) can access
        if (Auth::id() !== 1) {
            abort(403, 'Only site owners can access this page.');
        }

        // Laravel Cashier uses STRIPE_KEY and STRIPE_SECRET from env
        $stripeKey = env('STRIPE_KEY');
        $stripeSecret = env('STRIPE_SECRET');
        $hasKeys = !empty($stripeKey) && !empty($stripeSecret);
        
        // Test Stripe connection by making an API call
        $connectionTest = [
            'connected' => false,
            'error' => null,
        ];
        
        if ($hasKeys) {
            try {
                Stripe::setApiKey($stripeSecret);
                // Make a simple API call to verify connection
                $account = Account::retrieve();
                $connectionTest['connected'] = true;
                $connectionTest['account_id'] = $account->id ?? null;
                $connectionTest['account_name'] = $account->business_profile->name ?? $account->settings->dashboard->display_name ?? null;
            } catch (\Exception $e) {
                $connectionTest['connected'] = false;
                $connectionTest['error'] = $e->getMessage();
            }
        } else {
            $connectionTest['error'] = 'Stripe keys not configured (STRIPE_KEY and STRIPE_SECRET must be set in .env)';
        }

        // Check price sync status - verify prices in config exist in Stripe
        $priceSyncStatus = [];
        $plans = config('plans');
        
        if ($connectionTest['connected']) {
            try {
                foreach ($plans as $planKey => $planConfig) {
                    if ($planKey === 'free') {
                        // Free plan doesn't need a Stripe price
                        $priceSyncStatus[$planKey] = [
                            'name' => $planConfig['name'],
                            'price_id' => $planConfig['stripe_price_id'],
                            'exists' => true,
                            'note' => 'Free plan (no Stripe price required)',
                        ];
                        continue;
                    }
                    
                    $priceId = $planConfig['stripe_price_id'];
                    try {
                        $price = Price::retrieve($priceId);
                        $priceSyncStatus[$planKey] = [
                            'name' => $planConfig['name'],
                            'price_id' => $priceId,
                            'exists' => true,
                            'stripe_price_name' => $price->nickname ?? ($price->product ? 'Product: ' . $price->product : 'N/A'),
                            'amount' => $price->unit_amount ? '$' . number_format($price->unit_amount / 100, 2) : 'N/A',
                            'currency' => strtoupper($price->currency ?? 'usd'),
                            'active' => $price->active ?? false,
                        ];
                    } catch (\Stripe\Exception\InvalidRequestException $e) {
                        $priceSyncStatus[$planKey] = [
                            'name' => $planConfig['name'],
                            'price_id' => $priceId,
                            'exists' => false,
                            'error' => $e->getMessage(),
                        ];
                    } catch (\Exception $e) {
                        $priceSyncStatus[$planKey] = [
                            'name' => $planConfig['name'],
                            'price_id' => $priceId,
                            'exists' => false,
                            'error' => $e->getMessage(),
                        ];
                    }
                }
            } catch (\Exception $e) {
                // If we can't check prices, mark all as unknown
                foreach ($plans as $planKey => $planConfig) {
                    $priceSyncStatus[$planKey] = [
                        'name' => $planConfig['name'],
                        'price_id' => $planConfig['stripe_price_id'],
                        'exists' => null,
                        'error' => 'Could not verify: ' . $e->getMessage(),
                    ];
                }
            }
        } else {
            // If not connected, mark all prices as unknown
            foreach ($plans as $planKey => $planConfig) {
                $priceSyncStatus[$planKey] = [
                    'name' => $planConfig['name'],
                    'price_id' => $planConfig['stripe_price_id'],
                    'exists' => null,
                    'error' => 'Stripe not connected',
                ];
            }
        }

        // Get tenants with Stripe accounts
        $tenantsWithStripe = Tenant::whereNotNull('stripe_id')->get()->map(fn ($tenant) => [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'stripe_id' => $tenant->stripe_id,
        ]);

        // Get active subscriptions
        $subscriptions = Subscription::where('stripe_status', 'active')
            ->get()
            ->map(function ($subscription) {
                $tenant = Tenant::find($subscription->tenant_id);
                return [
                    'id' => $subscription->id,
                    'tenant_name' => $tenant->name ?? 'Unknown',
                    'stripe_price' => $subscription->stripe_price,
                    'stripe_status' => $subscription->stripe_status,
                    'ends_at' => $subscription->ends_at?->toDateTimeString(),
                ];
            });

        return Inertia::render('Admin/StripeStatus', [
            'stripe_status' => [
                'connected' => $connectionTest['connected'],
                'has_keys' => $hasKeys,
                'error' => $connectionTest['error'],
                'account_id' => $connectionTest['account_id'] ?? null,
                'account_name' => $connectionTest['account_name'] ?? null,
                'last_check' => now()->toDateTimeString(),
            ],
            'price_sync_status' => $priceSyncStatus,
            'tenants_with_stripe' => $tenantsWithStripe,
            'subscriptions' => $subscriptions,
        ]);
    }

    /**
     * Save site role permissions.
     */
    public function saveSiteRolePermissions(Request $request)
    {
        // Only user ID 1 (Site Owner) can access
        if (Auth::id() !== 1) {
            abort(403, 'Only site owners can access this page.');
        }

        // TODO: Implement permission saving logic
        return back()->with('success', 'Site role permissions updated successfully.');
    }

    /**
     * Save company role permissions.
     */
    public function saveCompanyRolePermissions(Request $request)
    {
        // Only user ID 1 (Site Owner) can access
        if (Auth::id() !== 1) {
            abort(403, 'Only site owners can access this page.');
        }

        // TODO: Implement permission saving logic
        return back()->with('success', 'Company role permissions updated successfully.');
    }
}
