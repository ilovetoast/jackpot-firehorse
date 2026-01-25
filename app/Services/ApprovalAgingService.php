<?php

namespace App\Services;

use App\Enums\ApprovalAction;
use App\Enums\ApprovalStatus;
use App\Models\Asset;
use App\Models\AssetApprovalComment;
use Carbon\Carbon;

/**
 * Phase AF-4: Approval Aging Service
 * 
 * Computes time-based metrics for approval workflows.
 * Read-only and observational - no enforcement or auto-actions.
 * 
 * Metrics:
 * - pending_since: When asset entered pending state (submitted/resubmitted)
 * - pending_days: Days since pending_since
 * - last_action_at: Most recent approval action timestamp
 * - aging_label: Human-readable aging description
 */
class ApprovalAgingService
{
    /**
     * Get aging metrics for an asset.
     * 
     * @param Asset $asset
     * @return array{pending_since: string|null, pending_days: int|null, last_action_at: string|null, aging_label: string}
     */
    public function getAgingMetrics(Asset $asset): array
    {
        // Only compute for pending assets
        if ($asset->approval_status !== ApprovalStatus::PENDING) {
            return [
                'pending_since' => null,
                'pending_days' => null,
                'last_action_at' => null,
                'aging_label' => null,
            ];
        }

        // Get approval history comments
        $comments = AssetApprovalComment::where('asset_id', $asset->id)
            ->orderBy('created_at', 'desc')
            ->get();

        // Find most recent submitted or resubmitted action
        $pendingSince = null;
        foreach ($comments as $comment) {
            if (in_array($comment->action, [ApprovalAction::SUBMITTED, ApprovalAction::RESUBMITTED])) {
                $pendingSince = $comment->created_at;
                break; // Most recent is first in desc order
            }
        }

        // If no comment found, fall back to asset created_at
        if (!$pendingSince) {
            $pendingSince = $asset->created_at;
        }

        // Calculate pending days (positive number of days since pending_since)
        // diffInDays with false returns negative if pendingSince is in the future (shouldn't happen, but defensive)
        $pendingDays = $pendingSince ? max(0, (int) now()->diffInDays($pendingSince, false)) : null;

        // Get most recent action timestamp
        $lastActionAt = $comments->first()?->created_at;

        // Generate human-readable aging label
        $agingLabel = $this->getAgingLabel($pendingDays);

        return [
            'pending_since' => $pendingSince?->toISOString(),
            'pending_days' => $pendingDays,
            'last_action_at' => $lastActionAt?->toISOString(),
            'aging_label' => $agingLabel,
        ];
    }

    /**
     * Get human-readable aging label.
     * 
     * @param int|null $pendingDays
     * @return string
     */
    protected function getAgingLabel(?int $pendingDays): string
    {
        if ($pendingDays === null) {
            return 'Pending';
        }

        if ($pendingDays < 1) {
            return 'Pending < 1 day';
        } elseif ($pendingDays === 1) {
            return 'Pending 1 day';
        } elseif ($pendingDays < 7) {
            return "Pending {$pendingDays} days";
        } else {
            return 'Pending 7+ days';
        }
    }

    /**
     * Check if asset should be visually highlighted (pending > X days).
     * 
     * Phase AF-4: Visual only, no enforcement.
     * 
     * @param Asset $asset
     * @param int $thresholdDays Threshold for highlighting (default: 7)
     * @return bool
     */
    public function shouldHighlight(Asset $asset, int $thresholdDays = 7): bool
    {
        $metrics = $this->getAgingMetrics($asset);
        return $metrics['pending_days'] !== null && $metrics['pending_days'] >= $thresholdDays;
    }
}
