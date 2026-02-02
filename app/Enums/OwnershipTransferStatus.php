<?php

namespace App\Enums;

enum OwnershipTransferStatus: string
{
    case PENDING = 'pending';
    case CONFIRMED = 'confirmed';
    case ACCEPTED = 'accepted';
    case PENDING_BILLING = 'pending_billing'; // Phase AG-3: Awaiting billing confirmation
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';

    /**
     * Get all status values.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get all status names.
     */
    public static function names(): array
    {
        return array_column(self::cases(), 'name');
    }
}
