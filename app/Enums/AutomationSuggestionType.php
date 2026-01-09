<?php

namespace App\Enums;

/**
 * Automation Suggestion Type
 *
 * Types of AI suggestions that can be generated for tickets.
 */
enum AutomationSuggestionType: string
{
    case CLASSIFICATION = 'classification';
    case DUPLICATE = 'duplicate';
    case TICKET_CREATION = 'ticket_creation';
    case SEVERITY = 'severity';
}
