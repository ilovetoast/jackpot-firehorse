<?php

namespace App\Exceptions;

use App\Models\Tenant;
use App\Models\User;
use Exception;

/**
 * Exception thrown when someone tries to directly assign the owner role.
 * 
 * Owner role assignments must go through the ownership transfer workflow
 * for security and audit purposes. Only platform super-owner (user ID 1)
 * can bypass this restriction.
 */
class CannotAssignOwnerRoleException extends Exception
{
    protected Tenant $tenant;
    protected User $targetUser;
    protected ?User $currentUser;

    public function __construct(Tenant $tenant, User $targetUser, ?User $currentUser = null, string $message = 'Please use the ownership transfer process in the Company settings.', int $code = 422, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        
        $this->tenant = $tenant;
        $this->targetUser = $targetUser;
        $this->currentUser = $currentUser;
    }

    /**
     * Get the tenant for this exception.
     */
    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    /**
     * Get the target user (user being assigned owner role).
     */
    public function getTargetUser(): User
    {
        return $this->targetUser;
    }

    /**
     * Get the current user (user attempting the assignment).
     */
    public function getCurrentUser(): ?User
    {
        return $this->currentUser;
    }

    /**
     * Render the exception as an HTTP response.
     */
    public function render($request)
    {
        if ($request->expectsJson() || $request->wantsJson()) {
            return response()->json([
                'message' => $this->getMessage(),
                'error' => 'cannot_assign_owner_role',
                'tenant_id' => $this->tenant->id,
                'target_user_id' => $this->targetUser->id,
                'target_user_name' => $this->targetUser->name,
                'target_user_email' => $this->targetUser->email,
                'ownership_transfer_route' => route('ownership-transfer.initiate', ['tenant' => $this->tenant->id]),
                'settings_link' => route('companies.settings') . '#ownership-transfer',
            ], $this->getCode());
        }

        return redirect()->back()
            ->withErrors(['role' => $this->getMessage()]);
    }
}
