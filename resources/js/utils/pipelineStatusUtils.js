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
 * Get display label for analysis_status.
 */
export function getPipelineStageLabel(status) {
    if (!status) return '—'
    const key = String(status).toLowerCase()
    return PIPELINE_STAGE_LABELS[key] ?? status
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
