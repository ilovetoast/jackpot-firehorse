<?php

namespace App\Providers;

use App\Models\Brand;
use App\Models\Category;
use App\Models\OwnershipTransfer;
use App\Models\Tenant;
use App\Policies\BrandPolicy;
use App\Policies\CategoryPolicy;
use App\Policies\CompanyPolicy;
use App\Policies\OwnershipTransferPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Tenant::class => CompanyPolicy::class,
        Brand::class => BrandPolicy::class,
        Category::class => CategoryPolicy::class,
        OwnershipTransfer::class => OwnershipTransferPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        //
    }
}
