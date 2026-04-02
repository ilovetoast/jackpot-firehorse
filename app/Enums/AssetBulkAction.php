<?php

namespace App\Enums;

/**
 * Phase B1: Bulk Action System – supported actions for assets.
 *
 * One action per request. Do not combine actions.
 */
enum AssetBulkAction: string
{
    case PUBLISH = 'PUBLISH';
    case UNPUBLISH = 'UNPUBLISH';
    case ARCHIVE = 'ARCHIVE';
    case RESTORE_ARCHIVE = 'RESTORE_ARCHIVE';
    case APPROVE = 'APPROVE';
    case MARK_PENDING = 'MARK_PENDING';
    case REJECT = 'REJECT';
    case SOFT_DELETE = 'SOFT_DELETE';
    case RESTORE_TRASH = 'RESTORE_TRASH';
    case FORCE_DELETE = 'FORCE_DELETE'; // Phase B2: Permanent delete from trash (tenant admin only)
    case METADATA_ADD = 'METADATA_ADD';
    case METADATA_REPLACE = 'METADATA_REPLACE';
    case METADATA_CLEAR = 'METADATA_CLEAR';
    case ASSIGN_CATEGORY = 'ASSIGN_CATEGORY'; // Staged intake: set category_id + intake_state=normal
    case RENAME_ASSETS = 'RENAME_ASSETS'; // Batch rename title + original_filename (base name + index of total)

    /** Site admin / site engineering only — queue thumbnail regeneration (GenerateThumbnailsJob). */
    case SITE_RERUN_THUMBNAILS = 'SITE_RERUN_THUMBNAILS';

    /** Site admin / site engineering only — queue AI vision metadata + tag auto-apply (same chain as single-asset regenerate). */
    case SITE_RERUN_AI_METADATA_TAGGING = 'SITE_RERUN_AI_METADATA_TAGGING';

    public function isApprovalAction(): bool
    {
        return in_array($this, [
            self::APPROVE,
            self::MARK_PENDING,
            self::REJECT,
        ], true);
    }

    public function isMetadataAction(): bool
    {
        return in_array($this, [
            self::METADATA_ADD,
            self::METADATA_REPLACE,
            self::METADATA_CLEAR,
        ], true);
    }

    public function isSitePipelineAction(): bool
    {
        return in_array($this, [
            self::SITE_RERUN_THUMBNAILS,
            self::SITE_RERUN_AI_METADATA_TAGGING,
        ], true);
    }

    /** Operation type for BulkMetadataService: add, replace, clear */
    public function metadataOperationType(): ?string
    {
        return match ($this) {
            self::METADATA_ADD => 'add',
            self::METADATA_REPLACE => 'replace',
            self::METADATA_CLEAR => 'clear',
            default => null,
        };
    }
}
