<?php

namespace App\Enums;

/**
 * Phase AF-1: Asset Approval Status
 * 
 * Tracks the approval state of assets uploaded by users with requires_approval = true.
 */
enum ApprovalStatus: string
{
    case NOT_REQUIRED = 'not_required';
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
}
