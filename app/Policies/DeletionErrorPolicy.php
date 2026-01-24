<?php

namespace App\Policies;

use App\Models\DeletionError;
use App\Models\User;

class DeletionErrorPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('manage assets') || 
               $user->hasRole('admin') || 
               $user->hasRole('owner');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, DeletionError $deletionError): bool
    {
        return ($user->hasPermission('manage assets') || 
                $user->hasRole('admin') || 
                $user->hasRole('owner')) &&
                $user->tenant_id === $deletionError->tenant_id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return false; // Deletion errors are created by the system only
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, DeletionError $deletionError): bool
    {
        return ($user->hasPermission('manage assets') || 
                $user->hasRole('admin') || 
                $user->hasRole('owner')) &&
                $user->tenant_id === $deletionError->tenant_id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, DeletionError $deletionError): bool
    {
        return ($user->hasRole('admin') || 
                $user->hasRole('owner')) &&
                $user->tenant_id === $deletionError->tenant_id;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, DeletionError $deletionError): bool
    {
        return false; // Not applicable for deletion errors
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, DeletionError $deletionError): bool
    {
        return $user->hasRole('owner') &&
                $user->tenant_id === $deletionError->tenant_id;
    }
}