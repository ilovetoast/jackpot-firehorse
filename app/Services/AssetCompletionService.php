<?php

namespace App\Services;

use App\Enums\AssetStatus;
use App\Enums\ThumbnailStatus;
use App\Models\Asset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Asset Completion Service
 *
 * Centralized service for evaluating completion criteria.
 * Asset.status represents VISIBILITY only (VISIBLE/HIDDEN/FAILED), not processing state.
 *
 * This service ensures:
 * - Single source of truth for completion rules
 * - Explicit, testable completion criteria
 * - Idempotent operations (safe for retries)
 *
 * Completion Criteria (all must be met):
 * 1. thumbnail_status === COMPLETED
 * 2. metadata['ai_tagging_completed'] === true
 * 3. metadata['metadata_extracted'] === true (if applicable)
 * 4. metadata['preview_generated'] === true (optional / future-safe)
 *
 * NOTE: This service does NOT mutate Asset.status. Status represents visibility only.
 * Processing completion is tracked via thumbnail_status, metadata flags, and pipeline_completed_at.
 */
class AssetCompletionService
{
    /**
     * Check if asset meets completion criteria.
     *
     * Idempotent: Safe to call multiple times (retries, race conditions).
     *
     * @param Asset $asset
     * @return bool True if asset meets all completion criteria, false otherwise
     */
    public function isComplete(Asset $asset): bool
    {
        return $this->meetsCompletionCriteria($asset);
    }

    /**
     * Evaluate if asset meets all completion criteria.
     *
     * Completion Criteria (all must be met):
     * 1. thumbnail_status === COMPLETED
     * 2. metadata['ai_tagging_completed'] === true
     * 3. metadata['metadata_extracted'] === true (if applicable)
     * 4. metadata['preview_generated'] === true (optional / future-safe)
     *
     * @param Asset $asset
     * @return bool True if all criteria are met
     */
    protected function meetsCompletionCriteria(Asset $asset): bool
    {
        $metadata = $asset->metadata ?? [];

        // Rule 1: Thumbnail generation complete
        if ($asset->thumbnail_status !== ThumbnailStatus::COMPLETED) {
            return false;
        }

        // Rule 2: AI tagging complete
        if (empty($metadata['ai_tagging_completed'])) {
            return false;
        }

        // Rule 3: Metadata extraction complete (if applicable)
        if (empty($metadata['metadata_extracted'])) {
            return false;
        }

        // Rule 4: Preview generated (optional / future-safe)
        // If key exists, it must be true. If key doesn't exist, assume not required.
        if (isset($metadata['preview_generated']) && !$metadata['preview_generated']) {
            return false;
        }

        // All criteria met
        return true;
    }
}
