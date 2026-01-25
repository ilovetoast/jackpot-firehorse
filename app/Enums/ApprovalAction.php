<?php

namespace App\Enums;

/**
 * Phase AF-2: Approval Action Types
 * 
 * Actions that can be recorded in the approval history.
 */
enum ApprovalAction: string
{
    case SUBMITTED = 'submitted';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case RESUBMITTED = 'resubmitted';
    case COMMENT = 'comment';
}
