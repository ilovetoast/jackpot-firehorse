/**
 * Extra chrome on grid cards when deliverables grid mode is not plain original.
 *
 * @param {'standard' | 'original' | 'enhanced' | 'presentation' | 'clean' | null | undefined} executionThumbnailViewMode
 * @returns {string} Tailwind classes (empty in original)
 */
export function assetCardEnhancedExecutionChromeClass(executionThumbnailViewMode) {
    if (
        executionThumbnailViewMode == null ||
        executionThumbnailViewMode === 'standard' ||
        executionThumbnailViewMode === 'original' ||
        executionThumbnailViewMode === 'clean'
    ) {
        return ''
    }
    return 'shadow-lg ring-1 ring-slate-300/60 dark:ring-slate-500/40'
}

/**
 * @param {'standard' | 'original' | 'enhanced' | 'presentation' | 'clean' | null | undefined} executionThumbnailViewMode
 */
export function isExecutionPolishedGridMode(executionThumbnailViewMode) {
    if (
        executionThumbnailViewMode == null ||
        executionThumbnailViewMode === 'standard' ||
        executionThumbnailViewMode === 'original'
    ) {
        return false
    }
    if (executionThumbnailViewMode === 'clean') {
        return false
    }
    return true
}

/** @deprecated use {@link isExecutionPolishedGridMode} */
export function isExecutionEnhancedGridMode(executionThumbnailViewMode) {
    return isExecutionPolishedGridMode(executionThumbnailViewMode)
}
