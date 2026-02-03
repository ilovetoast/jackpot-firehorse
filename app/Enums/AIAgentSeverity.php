<?php

namespace App\Enums;

/**
 * Standardized AI agent severity levels.
 *
 * Used so downstream systems can reason consistently about agent outputs.
 * Agents assign severity; downstream logic decides enforcement.
 *
 * Phase D-1: Structure only. No enforcement.
 */
enum AIAgentSeverity: string
{
    case INFO = 'info';       // benign, informational
    case WARNING = 'warning'; // recoverable, retry recommended
    case SYSTEM = 'system';   // infrastructure-level, escalation-worthy
    case DATA = 'data';       // asset-level (permissions, missing files)
}
