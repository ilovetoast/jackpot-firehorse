<?php

namespace App\Services;

use App\Enums\ApprovalAction;
use App\Models\Asset;
use App\Models\AssetApprovalComment;
use App\Models\User;

/**
 * Phase AF-2: Asset Approval Comment Service
 * 
 * Records comments for approval actions.
 * Used by approve, reject, and resubmit flows.
 */
class AssetApprovalCommentService
{
    /**
     * Record an approval action with optional comment.
     * 
     * @param Asset $asset The asset
     * @param User $user The user performing the action
     * @param ApprovalAction $action The action type
     * @param string|null $comment Optional comment
     * @return AssetApprovalComment
     */
    public function record(Asset $asset, User $user, ApprovalAction $action, ?string $comment = null): AssetApprovalComment
    {
        return AssetApprovalComment::create([
            'asset_id' => $asset->id,
            'user_id' => $user->id,
            'action' => $action,
            'comment' => $comment,
        ]);
    }

    /**
     * Record a submitted action (when asset is first uploaded with requires_approval).
     * 
     * @param Asset $asset The asset
     * @param User $user The uploader
     * @return AssetApprovalComment
     */
    public function recordSubmitted(Asset $asset, User $user): AssetApprovalComment
    {
        return $this->record($asset, $user, ApprovalAction::SUBMITTED);
    }

    /**
     * Record an approved action.
     * 
     * @param Asset $asset The asset
     * @param User $user The approver
     * @param string|null $comment Optional comment
     * @return AssetApprovalComment
     */
    public function recordApproved(Asset $asset, User $user, ?string $comment = null): AssetApprovalComment
    {
        return $this->record($asset, $user, ApprovalAction::APPROVED, $comment);
    }

    /**
     * Record a rejected action.
     * 
     * @param Asset $asset The asset
     * @param User $user The rejector
     * @param string $comment Rejection reason (required)
     * @return AssetApprovalComment
     */
    public function recordRejected(Asset $asset, User $user, string $comment): AssetApprovalComment
    {
        return $this->record($asset, $user, ApprovalAction::REJECTED, $comment);
    }

    /**
     * Record a resubmitted action.
     * 
     * @param Asset $asset The asset
     * @param User $user The resubmitter
     * @param string|null $comment Optional comment
     * @return AssetApprovalComment
     */
    public function recordResubmitted(Asset $asset, User $user, ?string $comment = null): AssetApprovalComment
    {
        return $this->record($asset, $user, ApprovalAction::RESUBMITTED, $comment);
    }

    /**
     * Record a standalone comment.
     * 
     * @param Asset $asset The asset
     * @param User $user The commenter
     * @param string $comment The comment
     * @return AssetApprovalComment
     */
    public function recordComment(Asset $asset, User $user, string $comment): AssetApprovalComment
    {
        return $this->record($asset, $user, ApprovalAction::COMMENT, $comment);
    }
}
