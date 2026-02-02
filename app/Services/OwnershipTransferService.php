<?php

namespace App\Services;

use App\Enums\EventType;
use App\Enums\OwnershipTransferStatus;
use App\Events\CompanyTransferCompleted;
use App\Mail\OwnershipTransferConfirmation;
use App\Mail\OwnershipTransferRequest;
use App\Mail\OwnershipTransferAcceptance;
use App\Mail\OwnershipTransferCompleted;
use App\Models\OwnershipTransfer;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use InvalidArgumentException;

/**
 * OwnershipTransferService
 * 
 * Handles secure, multi-step tenant ownership transfer workflow.
 * 
 * This is NOT a simple role change - it requires:
 * 1. Initiation by current owner
 * 2. Email confirmation from current owner
 * 3. Acceptance by new owner
 * 4. Completion (role change)
 * 
 * Site admins CANNOT initiate transfers - only current tenant owners can.
 */
class OwnershipTransferService
{
    /**
     * Initiate an ownership transfer.
     * 
     * @param Tenant $tenant
     * @param User $initiator The user initiating the transfer (must be current owner)
     * @param User $newOwner The user who will become the new owner
     * @return OwnershipTransfer
     * @throws InvalidArgumentException
     */
    public function initiateTransfer(Tenant $tenant, User $initiator, User $newOwner): OwnershipTransfer
    {
        // Validate current owner is initiating
        if (!$tenant->isOwner($initiator)) {
            throw new InvalidArgumentException('Only the current tenant owner can initiate an ownership transfer.');
        }

        // Prevent transferring to the same user
        if ($initiator->id === $newOwner->id) {
            throw new InvalidArgumentException('Cannot transfer ownership to the same user.');
        }

        // Verify new owner is an active tenant member
        if (!$tenant->users()->where('users.id', $newOwner->id)->exists()) {
            throw new InvalidArgumentException('The new owner must be an active member of the tenant.');
        }

        // Phase AG-3: Prevent multiple active transfers per tenant (including PENDING_BILLING)
        $activeTransfer = OwnershipTransfer::where('tenant_id', $tenant->id)
            ->whereIn('status', [
                OwnershipTransferStatus::PENDING,
                OwnershipTransferStatus::CONFIRMED,
                OwnershipTransferStatus::ACCEPTED,
                OwnershipTransferStatus::PENDING_BILLING,
            ])
            ->first();

        if ($activeTransfer) {
            throw new InvalidArgumentException('An ownership transfer is already in progress for this tenant.');
        }

        // Get current owner
        $currentOwner = $tenant->owner();
        if (!$currentOwner) {
            throw new InvalidArgumentException('Tenant has no active owner.');
        }

        // Create transfer record
        $transfer = OwnershipTransfer::create([
            'tenant_id' => $tenant->id,
            'initiated_by_user_id' => $initiator->id,
            'from_user_id' => $currentOwner->id,
            'to_user_id' => $newOwner->id,
            'status' => OwnershipTransferStatus::PENDING,
            'initiated_at' => now(),
        ]);

        // Log activity
        ActivityRecorder::record(
            $tenant,
            EventType::TENANT_OWNER_TRANSFER_INITIATED,
            $transfer,
            $initiator,
            null,
            [
                'from_user_id' => $currentOwner->id,
                'to_user_id' => $newOwner->id,
                'transfer_id' => $transfer->id,
            ]
        );

        // Send confirmation email to current owner
        $confirmationUrl = $this->getConfirmationUrl($transfer);
        Mail::to($currentOwner->email)->send(new OwnershipTransferConfirmation($tenant, $currentOwner, $newOwner, $confirmationUrl));

        // Send request email to new owner
        Mail::to($newOwner->email)->send(new OwnershipTransferRequest($tenant, $currentOwner, $newOwner));

        return $transfer;
    }

    /**
     * Confirm the transfer (current owner confirms via email link).
     * 
     * @param OwnershipTransfer $transfer
     * @param User $user The user confirming (must be from_user)
     * @return OwnershipTransfer
     * @throws InvalidArgumentException
     */
    public function confirmTransfer(OwnershipTransfer $transfer, User $user): OwnershipTransfer
    {
        // Validate user is the current owner
        if ($transfer->from_user_id !== $user->id) {
            throw new InvalidArgumentException('Only the current owner can confirm the transfer.');
        }

        // Validate transfer is in pending status
        if ($transfer->status !== OwnershipTransferStatus::PENDING) {
            throw new InvalidArgumentException('Transfer is not in a state that can be confirmed.');
        }

        // Verify current owner is still the owner
        if (!$transfer->tenant->isOwner($user)) {
            throw new InvalidArgumentException('You are no longer the owner of this tenant. Transfer cancelled.');
        }

        // Update transfer status
        $transfer->update([
            'status' => OwnershipTransferStatus::CONFIRMED,
            'confirmed_at' => now(),
        ]);

        // Log activity
        ActivityRecorder::record(
            $transfer->tenant,
            EventType::TENANT_OWNER_TRANSFER_CONFIRMED,
            $transfer,
            $user,
            null,
            [
                'from_user_id' => $transfer->from_user_id,
                'to_user_id' => $transfer->to_user_id,
                'transfer_id' => $transfer->id,
            ]
        );

        // Send acceptance email to new owner
        $acceptanceUrl = $this->getAcceptanceUrl($transfer);
        Mail::to($transfer->toUser->email)->send(new OwnershipTransferAcceptance($transfer->tenant, $transfer->fromUser, $transfer->toUser, $acceptanceUrl));

        return $transfer->fresh();
    }

    /**
     * Accept the transfer (new owner accepts via email link).
     * 
     * Phase AG-3: Now checks billing status before completing.
     * If billing is not active, transfer enters PENDING_BILLING status.
     * 
     * @param OwnershipTransfer $transfer
     * @param User $user The user accepting (must be to_user)
     * @return OwnershipTransfer
     * @throws InvalidArgumentException
     */
    public function acceptTransfer(OwnershipTransfer $transfer, User $user): OwnershipTransfer
    {
        // Validate user is the new owner
        if ($transfer->to_user_id !== $user->id) {
            throw new InvalidArgumentException('Only the new owner can accept the transfer.');
        }

        // Validate transfer is in confirmed status
        if ($transfer->status !== OwnershipTransferStatus::CONFIRMED) {
            throw new InvalidArgumentException('Transfer must be confirmed before it can be accepted.');
        }

        // Verify new owner is still a tenant member
        if (!$transfer->tenant->users()->where('users.id', $user->id)->exists()) {
            throw new InvalidArgumentException('You are no longer a member of this tenant. Transfer cancelled.');
        }

        // Update transfer status
        $transfer->update([
            'status' => OwnershipTransferStatus::ACCEPTED,
            'accepted_at' => now(),
        ]);

        // Log activity
        ActivityRecorder::record(
            $transfer->tenant,
            EventType::TENANT_OWNER_TRANSFER_ACCEPTED,
            $transfer,
            $user,
            null,
            [
                'from_user_id' => $transfer->from_user_id,
                'to_user_id' => $transfer->to_user_id,
                'transfer_id' => $transfer->id,
            ]
        );

        // Phase AG-3: Check billing status before completing
        // If billing is active, complete immediately
        // If not, enter PENDING_BILLING status
        if ($this->hasBillingActive($transfer->tenant)) {
            return $this->completeTransfer($transfer);
        } else {
            return $this->setPendingBilling($transfer);
        }
    }

    /**
     * Complete the transfer (perform the actual role change).
     * 
     * Phase AG-3: Now also accepts PENDING_BILLING status.
     * 
     * @param OwnershipTransfer $transfer
     * @return OwnershipTransfer
     * @throws InvalidArgumentException
     */
    public function completeTransfer(OwnershipTransfer $transfer): OwnershipTransfer
    {
        // Phase AG-3: Validate transfer is in accepted or pending_billing status
        if (!in_array($transfer->status, [OwnershipTransferStatus::ACCEPTED, OwnershipTransferStatus::PENDING_BILLING])) {
            throw new InvalidArgumentException('Transfer must be accepted before it can be completed.');
        }

        // Use database transaction to ensure atomicity
        DB::transaction(function () use ($transfer) {
            // Downgrade old owner to admin
            $transfer->fromUser->setRoleForTenant($transfer->tenant, 'admin', true);

            // Upgrade new owner to owner (bypass check since this is part of the transfer workflow)
            $transfer->toUser->setRoleForTenant($transfer->tenant, 'owner', true);

            // Mark transfer as completed
            $transfer->update([
                'status' => OwnershipTransferStatus::COMPLETED,
                'completed_at' => now(),
            ]);
        });

        // Log activity
        ActivityRecorder::record(
            $transfer->tenant,
            EventType::TENANT_OWNER_TRANSFER_COMPLETED,
            $transfer,
            'system',
            null,
            [
                'from_user_id' => $transfer->from_user_id,
                'to_user_id' => $transfer->to_user_id,
                'transfer_id' => $transfer->id,
            ]
        );

        // Send completion emails to both parties
        Mail::to($transfer->fromUser->email)->send(new OwnershipTransferCompleted($transfer->tenant, $transfer->fromUser, $transfer->toUser, false));
        Mail::to($transfer->toUser->email)->send(new OwnershipTransferCompleted($transfer->tenant, $transfer->fromUser, $transfer->toUser, true));

        // Phase AG-4: Fire event for partner reward attribution
        CompanyTransferCompleted::dispatch($transfer->fresh());

        return $transfer->fresh();
    }

    /**
     * Cancel a transfer.
     * 
     * @param OwnershipTransfer $transfer
     * @param User $user The user cancelling (must be from_user or to_user)
     * @return OwnershipTransfer
     * @throws InvalidArgumentException
     */
    public function cancelTransfer(OwnershipTransfer $transfer, User $user): OwnershipTransfer
    {
        // Validate user is involved in the transfer
        if ($transfer->from_user_id !== $user->id && $transfer->to_user_id !== $user->id) {
            throw new InvalidArgumentException('Only the current or new owner can cancel the transfer.');
        }

        // Phase AG-3: Validate transfer is in a cancellable state (including PENDING_BILLING)
        if (!in_array($transfer->status, [
            OwnershipTransferStatus::PENDING,
            OwnershipTransferStatus::CONFIRMED,
            OwnershipTransferStatus::ACCEPTED,
            OwnershipTransferStatus::PENDING_BILLING,
        ])) {
            throw new InvalidArgumentException('Transfer cannot be cancelled in its current state.');
        }

        // Update transfer status
        $transfer->update([
            'status' => OwnershipTransferStatus::CANCELLED,
        ]);

        // Log activity
        ActivityRecorder::record(
            $transfer->tenant,
            EventType::TENANT_OWNER_TRANSFER_CANCELLED,
            $transfer,
            $user,
            null,
            [
                'from_user_id' => $transfer->from_user_id,
                'to_user_id' => $transfer->to_user_id,
                'transfer_id' => $transfer->id,
                'cancelled_by' => $user->id,
            ]
        );

        return $transfer->fresh();
    }

    /**
     * Get signed URL for confirmation.
     * 
     * @param OwnershipTransfer $transfer
     * @return string
     */
    protected function getConfirmationUrl(OwnershipTransfer $transfer): string
    {
        return URL::temporarySignedRoute(
            'ownership-transfer.confirm',
            now()->addDays(7),
            ['transfer' => $transfer->id]
        );
    }

    /**
     * Get signed URL for acceptance.
     * 
     * @param OwnershipTransfer $transfer
     * @return string
     */
    protected function getAcceptanceUrl(OwnershipTransfer $transfer): string
    {
        return URL::temporarySignedRoute(
            'ownership-transfer.accept',
            now()->addDays(7),
            ['transfer' => $transfer->id]
        );
    }

    /**
     * Set transfer to pending billing status.
     * 
     * Phase AG-3: Called when transfer is accepted but billing is not active.
     * 
     * @param OwnershipTransfer $transfer
     * @return OwnershipTransfer
     */
    protected function setPendingBilling(OwnershipTransfer $transfer): OwnershipTransfer
    {
        $transfer->update([
            'status' => OwnershipTransferStatus::PENDING_BILLING,
        ]);

        // Log activity
        ActivityRecorder::record(
            $transfer->tenant,
            EventType::TENANT_OWNER_TRANSFER_PENDING_BILLING,
            $transfer,
            'system',
            null,
            [
                'from_user_id' => $transfer->from_user_id,
                'to_user_id' => $transfer->to_user_id,
                'transfer_id' => $transfer->id,
            ]
        );

        return $transfer->fresh();
    }

    /**
     * Complete a pending transfer when billing is confirmed.
     * 
     * Phase AG-3: Called when billing is activated for a tenant with pending transfer.
     * 
     * @param OwnershipTransfer $transfer
     * @return OwnershipTransfer
     * @throws InvalidArgumentException
     */
    public function completePendingTransfer(OwnershipTransfer $transfer): OwnershipTransfer
    {
        // Validate transfer is in pending_billing status
        if ($transfer->status !== OwnershipTransferStatus::PENDING_BILLING) {
            throw new InvalidArgumentException('Transfer must be in pending_billing status to complete via billing confirmation.');
        }

        // Verify billing is now active
        if (!$this->hasBillingActive($transfer->tenant)) {
            throw new InvalidArgumentException('Billing must be active to complete the transfer.');
        }

        // Log billing confirmation event
        ActivityRecorder::record(
            $transfer->tenant,
            EventType::TENANT_OWNER_TRANSFER_BILLING_CONFIRMED,
            $transfer,
            'system',
            null,
            [
                'from_user_id' => $transfer->from_user_id,
                'to_user_id' => $transfer->to_user_id,
                'transfer_id' => $transfer->id,
            ]
        );

        // Complete the transfer
        return $this->completeTransfer($transfer);
    }

    /**
     * Check if tenant has active billing.
     * 
     * Phase AG-3: Determines if transfer can complete.
     * 
     * @param Tenant $tenant
     * @return bool
     */
    protected function hasBillingActive(Tenant $tenant): bool
    {
        // Check if tenant has an active subscription via Cashier
        if ($tenant->subscribed('default')) {
            return true;
        }

        // Check if tenant has a valid payment method attached
        if ($tenant->hasDefaultPaymentMethod()) {
            return true;
        }

        // Check manual billing status overrides
        // billing_status can be: null (Stripe), 'paid' (Stripe), 'trial', 'comped'
        if (in_array($tenant->billing_status, ['paid', 'comped'])) {
            // Check if it hasn't expired
            if (!$tenant->billing_status_expires_at || $tenant->billing_status_expires_at->isFuture()) {
                return true;
            }
        }

        return false;
    }
}
