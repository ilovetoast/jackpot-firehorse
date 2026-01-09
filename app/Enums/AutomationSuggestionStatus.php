<?php

namespace App\Enums;

/**
 * Automation Suggestion Status
 *
 * Status of an AI suggestion (pending, accepted, rejected, expired).
 */
enum AutomationSuggestionStatus: string
{
    case PENDING = 'pending';
    case ACCEPTED = 'accepted';
    case REJECTED = 'rejected';
    case EXPIRED = 'expired';
}
