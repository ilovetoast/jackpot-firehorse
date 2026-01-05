<?php

namespace App\Policies;

use App\Models\Tenant;
use App\Models\User;

class CompanyPolicy
{
    /**
     * Determine if the user can view any companies.
     */
    public function viewAny(User $user): bool
    {
        return true; // Users can view their own companies
    }

    /**
     * Determine if the user can view the company.
     */
    public function view(User $user, Tenant $tenant): bool
    {
        return $user->tenants()->where('tenants.id', $tenant->id)->exists();
    }

    /**
     * Determine if the user can create companies.
     */
    public function create(User $user): bool
    {
        return $user->can('manage brands'); // Only users who can manage brands can create companies
    }

    /**
     * Determine if the user can update the company.
     */
    public function update(User $user, Tenant $tenant): bool
    {
        return $user->tenants()->where('tenants.id', $tenant->id)->exists()
            && $user->can('manage brands');
    }

    /**
     * Determine if the user can delete the company.
     */
    public function delete(User $user, Tenant $tenant): bool
    {
        return $user->tenants()->where('tenants.id', $tenant->id)->exists()
            && $user->can('manage brands');
    }
}
