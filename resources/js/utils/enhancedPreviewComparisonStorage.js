/**
 * localStorage for “first time” enhanced original vs enhanced comparison modal.
 * Dismissal is keyed by template identity + version so a template_version bump shows the modal again.
 */

const LS_DISMISSED_TEMPLATE_KEY = 'jackpot_enhancedComparisonDismissedTemplate'
/** @deprecated migrated into {@link LS_DISMISSED_TEMPLATE_KEY} */
const LS_LEGACY_SEEN = 'jackpot_hasSeenEnhancedComparison'

/**
 * Migrate legacy "seen once ever" flag so new template keys can show the modal again.
 */
function migrateLegacySeenFlag() {
    if (typeof window === 'undefined') {
        return
    }
    try {
        if (window.localStorage.getItem(LS_LEGACY_SEEN) === '1') {
            window.localStorage.setItem(LS_DISMISSED_TEMPLATE_KEY, '__legacy_seen__')
            window.localStorage.removeItem(LS_LEGACY_SEEN)
        }
    } catch {
        /* ignore */
    }
}

/**
 * @param {string} templateKey Stable id (e.g. `templateId|template_version` or `__default__`)
 */
export function shouldShowEnhancedComparisonForTemplate(templateKey) {
    if (typeof window === 'undefined') {
        return false
    }
    migrateLegacySeenFlag()
    try {
        const dismissed = window.localStorage.getItem(LS_DISMISSED_TEMPLATE_KEY)
        const key = String(templateKey ?? '')
        if (dismissed === null) {
            return true
        }
        return dismissed !== key
    } catch {
        return true
    }
}

/**
 * @param {string} templateKey Same key passed to {@link shouldShowEnhancedComparisonForTemplate}
 */
export function markEnhancedComparisonSeenForTemplate(templateKey) {
    if (typeof window === 'undefined') {
        return
    }
    try {
        window.localStorage.setItem(LS_DISMISSED_TEMPLATE_KEY, String(templateKey ?? ''))
        window.localStorage.removeItem(LS_LEGACY_SEEN)
    } catch {
        /* ignore */
    }
}
