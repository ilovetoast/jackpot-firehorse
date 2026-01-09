<?php

namespace App\Services;

use App\Models\Tenant;

/**
 * TicketPlanGate Service
 *
 * Centralized plan gating for ticket features.
 * Enforces plan-based limits for attachments and provides SLA messaging.
 *
 * Plan Rules:
 * - All plans: 5MB max file size, 3 attachments per ticket
 *
 * SLA Messaging:
 * - Free/Starter: "Standard support"
 * - Pro/Enterprise: "Priority support"
 *
 * This service centralizes plan gating logic to avoid scattering checks across controllers.
 */
class TicketPlanGate
{
    public function __construct(
        protected PlanService $planService
    ) {
    }

    /**
     * Check if tenant can attach files to tickets.
     *
     * @param Tenant $tenant
     * @return bool
     */
    public function canAttachFiles(Tenant $tenant): bool
    {
        // All plans can attach files, but with different limits
        return true;
    }

    /**
     * Get maximum attachment file size in bytes.
     *
     * @param Tenant $tenant
     * @return int File size in bytes
     */
    public function getMaxAttachmentSize(Tenant $tenant): int
    {
        // All plans have the same limit: 5MB
        return 5 * 1024 * 1024; // 5MB
    }

    /**
     * Get maximum number of attachments per ticket.
     *
     * @param Tenant $tenant
     * @return int
     */
    public function getMaxAttachmentsPerTicket(Tenant $tenant): int
    {
        // All plans have the same limit: 3 files
        return 3;
    }

    /**
     * Get plan-specific SLA expectation message.
     * This is customer-facing messaging, not internal SLA targets.
     *
     * @param Tenant $tenant
     * @return string
     */
    public function getSLAExpectationMessage(Tenant $tenant): string
    {
        $planName = $this->planService->getCurrentPlan($tenant);

        return match ($planName) {
            'free', 'starter' => 'Standard support',
            'pro', 'enterprise' => 'Priority support',
            default => 'Standard support',
        };
    }

    /**
     * Get human-readable max file size for display.
     *
     * @param Tenant $tenant
     * @return string
     */
    public function getMaxAttachmentSizeDisplay(Tenant $tenant): string
    {
        $bytes = $this->getMaxAttachmentSize($tenant);
        $mb = $bytes / (1024 * 1024);
        return $mb . 'MB';
    }
}
