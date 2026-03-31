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
