/**
 * Pipeline status labels for asset analysis progression.
 * Maps backend analysis_status to user-friendly display labels.
 */
export const PIPELINE_STAGE_LABELS = {
    uploading: 'Uploading',
    generating_thumbnails: 'Generating thumbnails',
    extracting_metadata: 'Extracting metadata',
    generating_embedding: 'Generating embedding',
    scoring: 'Scoring brand compliance',
    complete: 'Complete',
    promotion_failed: 'Promotion failed',
}

export const PIPELINE_STAGES = [
    'uploading',
    'generating_thumbnails',
    'extracting_metadata',
    'generating_embedding',
    'scoring',
    'complete',
]

/**
 * Tooltips for analysis_status (admin modal, hover to see).
 * Explains what each stage means, whether to fix, and recommended action.
 */
export const PIPELINE_STAGE_TOOLTIPS = {
    uploading: 'Asset is being uploaded or queued for processing. No action needed unless stuck.',
    generating_thumbnails: 'Creating preview images. No action needed unless stuck. If stuck: Re-run Analysis or Retry Pipeline.',
    extracting_metadata: 'Extracting colors, dimensions, and other metadata from the image. No action needed unless stuck.',
    generating_embedding: 'Creating a vector embedding (numerical representation) for similarity search and recommendations. Normal if in progress. Fix only if stuck for hours — use Re-run Analysis.',
    scoring: 'Calculating brand compliance score. No action needed unless stuck.',
    complete: 'Pipeline finished. Asset is fully processed.',
    promotion_failed: 'File could not be moved to permanent storage. Check storage/S3. Attempt Repair or Retry Pipeline.',
}

/**
 * Get display label for analysis_status.
 */
export function getPipelineStageLabel(status) {
    if (!status) return '—'
    const key = String(status).toLowerCase()
    return PIPELINE_STAGE_LABELS[key] ?? status
}

/**
 * Get tooltip for analysis_status.
 */
export function getPipelineStageTooltip(status) {
    if (!status) return ''
    const key = String(status).toLowerCase()
    return PIPELINE_STAGE_TOOLTIPS[key] ?? ''
}

/**
 * Get current step index (0-based) for progress indication.
 */
export function getPipelineStageIndex(status) {
    if (!status) return 0
    const key = String(status).toLowerCase()
    const idx = PIPELINE_STAGES.indexOf(key)
    return idx >= 0 ? idx : 0
}
