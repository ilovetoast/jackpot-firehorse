<?php

namespace App\Exceptions;

use DomainException;

/**
 * Thrown when a prostaff / Creator flow requires an active tenant module entitlement.
 *
 * @see \App\Services\Prostaff\EnsureCreatorModuleEnabled
 */
final class CreatorModuleInactiveException extends DomainException
{
    /**
     * @return array{error: string, message: string, action: string}
     */
    public function clientPayload(): array
    {
        return [
            'error' => 'creator_module_inactive',
            'message' => $this->getMessage(),
            'action' => 'upgrade',
        ];
    }
}
