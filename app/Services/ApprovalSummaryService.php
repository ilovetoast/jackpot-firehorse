<?php

namespace App\Services;

use App\Enums\AITaskType;
use App\Models\Asset;
use App\Models\AssetApprovalComment;
use App\Services\FeatureGate;
use Illuminate\Support\Facades\Log;

/**
 * Phase AF-6: Approval Summary Service
 * 
 * Generates AI-powered summaries of approval feedback.
 * Read-only: summaries are informational only and never affect approval state.
 * Failures must not block approval workflow.
 */
class ApprovalSummaryService
{
    protected AIService $aiService;
    protected FeatureGate $featureGate;

    public function __construct(AIService $aiService, FeatureGate $featureGate)
    {
        $this->aiService = $aiService;
        $this->featureGate = $featureGate;
    }

    /**
     * Generate summary for an asset's approval history.
     * 
     * This method:
     * - Fetches all approval comments for the asset
     * - Builds a prompt with comment history
     * - Calls AI service to generate neutral summary
     * - Saves summary to asset (non-blocking on failure)
     * 
     * @param Asset $asset The asset to generate summary for
     * @return bool True if summary was generated successfully, false otherwise
     */
    public function generateSummary(Asset $asset): bool
    {
        try {
            // Phase AF-5: Check if approval summaries are enabled for tenant plan
            $tenant = $asset->brand?->tenant;
            if (!$tenant || !$this->featureGate->approvalSummariesEnabled($tenant)) {
                Log::info('[ApprovalSummaryService] Approval summaries disabled for tenant plan', [
                    'asset_id' => $asset->id,
                    'tenant_id' => $tenant?->id,
                ]);
                return false;
            }

            // Fetch all approval comments ordered by creation time
            $comments = AssetApprovalComment::where('asset_id', $asset->id)
                ->orderBy('created_at', 'asc')
                ->with('user:id,first_name,last_name,email')
                ->get();

            if ($comments->isEmpty()) {
                Log::info('[ApprovalSummaryService] No approval comments found for asset', [
                    'asset_id' => $asset->id,
                ]);
                return false;
            }

            // Build prompt from comment history
            $prompt = $this->buildPrompt($comments);

            // Generate summary using AI service
            // Use a generic text agent if approval_summarizer is not configured
            // The AI service will handle agent resolution and fallback
            try {
                $result = $this->aiService->executeAgent(
                    'approval_summarizer', // Agent ID - may need to be configured in config/ai.php
                    AITaskType::APPROVAL_FEEDBACK_SUMMARY,
                    $prompt,
                    [
                        'tenant' => $tenant,
                        'max_tokens' => 200, // Keep summaries concise (2-4 sentences)
                        'temperature' => 0.3, // Lower temperature for more consistent, neutral summaries
                    ]
                );
            } catch (\InvalidArgumentException $e) {
                // Agent not configured - log and return false (non-blocking)
                Log::info('[ApprovalSummaryService] Agent not configured, skipping summary generation', [
                    'asset_id' => $asset->id,
                    'agent_id' => 'approval_summarizer',
                    'error' => $e->getMessage(),
                ]);
                return false;
            }

            $summary = trim($result['text'] ?? '');

            if (empty($summary)) {
                Log::warning('[ApprovalSummaryService] AI returned empty summary', [
                    'asset_id' => $asset->id,
                ]);
                return false;
            }

            // Save summary to asset (non-blocking - if this fails, approval workflow continues)
            $asset->approval_summary = $summary;
            $asset->approval_summary_generated_at = now();
            $asset->save();

            Log::info('[ApprovalSummaryService] Summary generated successfully', [
                'asset_id' => $asset->id,
                'summary_length' => strlen($summary),
            ]);

            return true;
        } catch (\Exception $e) {
            // Phase AF-6: Failures must not block workflow
            Log::error('[ApprovalSummaryService] Failed to generate summary', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    /**
     * Build prompt from approval comment history.
     * 
     * Creates a neutral prompt that asks for a 2-4 sentence summary
     * without user names, instructions, or scoring.
     * 
     * @param \Illuminate\Database\Eloquent\Collection $comments
     * @return string
     */
    protected function buildPrompt(\Illuminate\Database\Eloquent\Collection $comments): string
    {
        $history = [];
        
        foreach ($comments as $comment) {
            $actionLabel = match($comment->action->value) {
                'submitted' => 'Submitted for approval',
                'approved' => 'Approved',
                'rejected' => 'Rejected',
                'resubmitted' => 'Resubmitted for approval',
                'comment' => 'Comment added',
                default => ucfirst($comment->action->value),
            };

            $entry = $actionLabel;
            if ($comment->comment) {
                $entry .= ': ' . $comment->comment;
            }
            
            $history[] = $entry;
        }

        $historyText = implode("\n", $history);

        return "Summarize the following approval workflow history in 2-4 neutral sentences. Do not include names, instructions, or scoring. Focus on the key actions and feedback:\n\n{$historyText}";
    }
}
