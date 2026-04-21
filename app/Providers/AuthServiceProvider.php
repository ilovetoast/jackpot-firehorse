<?php

namespace App\Providers;

use App\Models\Asset;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Collection;
use App\Models\DeletionError;
use App\Models\OwnershipTransfer;
use App\Models\StudioAnimationJob;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Policies\AssetPolicy;
use App\Policies\BrandPolicy;
use App\Policies\CategoryPolicy;
use App\Policies\CollectionPolicy;
use App\Policies\CompanyPolicy;
use App\Policies\DeletionErrorPolicy;
use App\Policies\OwnershipTransferPolicy;
use App\Policies\StudioAnimationJobPolicy;
use App\Policies\TicketPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Asset::class => AssetPolicy::class,
        Tenant::class => CompanyPolicy::class,
        Brand::class => BrandPolicy::class,
        Collection::class => CollectionPolicy::class,
        Category::class => CategoryPolicy::class,
        DeletionError::class => DeletionErrorPolicy::class,
        OwnershipTransfer::class => OwnershipTransferPolicy::class,
        Ticket::class => TicketPolicy::class,
        StudioAnimationJob::class => StudioAnimationJobPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        /*
         * Gate: brand-intelligence.view-decision-trace
         *
         * Allows the caller to view the "Why did it score this way?" admin panel
         * inside the Brand Intelligence asset drawer (per-pillar evidence, blockers,
         * reason codes, signal-family coverage, raw breakdown JSON).
         *
         * Granted to:
         *  - Brand-level admins and brand managers (for the currently active brand)
         *  - Tenant owners and tenant admins (for the currently active tenant)
         *
         * Other roles (viewers, prostaff/creators, reviewers) are intentionally
         * excluded: the trace is an internal triage surface, not a customer-facing
         * explanation of scoring.
         */
        Gate::define('brand-intelligence.view-decision-trace', function ($user) {
            $activeBrand = app()->bound('brand') ? app('brand') : null;
            $activeTenant = app()->bound('tenant') ? app('tenant') : null;

            if ($activeBrand && method_exists($user, 'getRoleForBrand')) {
                $role = strtolower((string) ($user->getRoleForBrand($activeBrand) ?? ''));
                if (in_array($role, ['admin', 'brand_manager'], true)) {
                    return true;
                }
            }

            if ($activeTenant && method_exists($user, 'getRoleForTenant')) {
                $role = strtolower((string) ($user->getRoleForTenant($activeTenant) ?? ''));
                if (in_array($role, ['owner', 'admin'], true)) {
                    return true;
                }
            }

            return false;
        });
    }
}
