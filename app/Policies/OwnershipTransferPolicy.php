<?php

namespace App\Policies;

use App\Models\OwnershipTransfer;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * OwnershipTransferPolicy
 * 
 * Enforces strict authorization rules for ownership transfers.
 * Site admins CANNOT bypass these rules.
 */
class OwnershipTransferPolicy
{
    /**
     * Determine whether the user can initiate a transfer.
     * 
     * ONLY the current tenant owner can initiate.
     * Site admins are explicitly denied.
     */
    public function initiate(User $user, Tenant $tenant): bool
    {
        // Site admins cannot initiate transfers
        if ($user->hasRole('site_owner') || $user->hasRole('site_admin')) {
            return false;
        }

        // Only current tenant owner can initiate
        return $tenant->isOwner($user);
    }

    /**
     * Determine whether the user can confirm a transfer.
     * 
     * ONLY the from_user (current owner) can confirm.
     */
    public function confirm(User $user, OwnershipTransfer $transfer): bool
    {
        // Site admins cannot confirm transfers
        if ($user->hasRole('site_owner') || $user->hasRole('site_admin')) {
            return false;
        }

        // Only the current owner (from_user) can confirm
        return $transfer->from_user_id === $user->id;
    }

    /**
     * Determine whether the user can accept a transfer.
     * 
     * ONLY the to_user (new owner) can accept.
     */
    public function accept(User $user, OwnershipTransfer $transfer): bool
    {
        // Site admins cannot accept transfers
        if ($user->hasRole('site_owner') || $user->hasRole('site_admin')) {
            return false;
        }

        // Only the new owner (to_user) can accept
        return $transfer->to_user_id === $user->id;
    }

    /**
     * Determine whether the user can cancel a transfer.
     * 
     * ONLY the from_user or to_user can cancel.
     */
    public function cancel(User $user, OwnershipTransfer $transfer): bool
    {
        // Site admins cannot cancel transfers
        if ($user->hasRole('site_owner') || $user->hasRole('site_admin')) {
            return false;
        }

        // Only involved parties can cancel
        return $transfer->from_user_id === $user->id || $transfer->to_user_id === $user->id;
    }

    /**
     * Determine whether the user can force a transfer (break-glass).
     * 
     * ONLY the platform super-owner (user ID 1) can force transfers.
     */
    public function forceTransfer(User $user): bool
    {
        // Only platform super-owner can force transfers
        return $user->id === 1 && $user->hasRole('site_owner');
    }

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return false;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, OwnershipTransfer $ownershipTransfer): bool
    {
        // Users can only view transfers they're involved in
        return $ownershipTransfer->from_user_id === $user->id 
            || $ownershipTransfer->to_user_id === $user->id
            || $ownershipTransfer->initiated_by_user_id === $user->id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return false; // Use initiate() method instead
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, OwnershipTransfer $ownershipTransfer): bool
    {
        return false; // Use specific workflow methods instead
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, OwnershipTransfer $ownershipTransfer): bool
    {
        return false; // Use cancel() method instead
    }
}
