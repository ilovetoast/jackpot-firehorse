<?php

namespace App\Providers;

use App\Models\Asset;
use App\Models\Brand;
use App\Models\Category;
use App\Models\DeletionError;
use App\Models\OwnershipTransfer;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Policies\AssetPolicy;
use App\Policies\BrandPolicy;
use App\Policies\CategoryPolicy;
use App\Policies\CompanyPolicy;
use App\Policies\DeletionErrorPolicy;
use App\Policies\OwnershipTransferPolicy;
use App\Policies\TicketPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

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
        Category::class => CategoryPolicy::class,
        DeletionError::class => DeletionErrorPolicy::class,
        OwnershipTransfer::class => OwnershipTransferPolicy::class,
        Ticket::class => TicketPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        //
    }
}
