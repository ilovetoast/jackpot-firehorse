<?php

namespace App\Services;

use App\Enums\EventType;
use App\Enums\OwnershipTransferStatus;
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

        // Prevent multiple active transfers per tenant
        $activeTransfer = OwnershipTransfer::where('tenant_id', $tenant->id)
            ->whereIn('status', [
                OwnershipTransferStatus::PENDING,
                OwnershipTransferStatus::CONFIRMED,
                OwnershipTransferStatus::ACCEPTED,
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

        // Complete the transfer automatically
        return $this->completeTransfer($transfer);
    }

    /**
     * Complete the transfer (perform the actual role change).
     * 
     * @param OwnershipTransfer $transfer
     * @return OwnershipTransfer
     * @throws InvalidArgumentException
     */
    public function completeTransfer(OwnershipTransfer $transfer): OwnershipTransfer
    {
        // Validate transfer is in accepted status
        if ($transfer->status !== OwnershipTransferStatus::ACCEPTED) {
            throw new InvalidArgumentException('Transfer must be accepted before it can be completed.');
        }

        // Use database transaction to ensure atomicity
        DB::transaction(function () use ($transfer) {
            // Downgrade old owner to admin
            $transfer->fromUser->setRoleForTenant($transfer->tenant, 'admin');

            // Upgrade new owner to owner
            $transfer->toUser->setRoleForTenant($transfer->tenant, 'owner');

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

        // Validate transfer is in a cancellable state
        if (!in_array($transfer->status, [
            OwnershipTransferStatus::PENDING,
            OwnershipTransferStatus::CONFIRMED,
            OwnershipTransferStatus::ACCEPTED,
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
}
