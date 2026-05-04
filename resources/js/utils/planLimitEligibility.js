/** Aligns with PHP PlanLimitUpgradePayload::UNLIMITED_NUMERIC_THRESHOLD_MB */
export const PLAN_LIMIT_UNLIMITED_THRESHOLD_MB = 999000

export const RECOGNIZED_PLAN_LIMIT_REASONS = new Set(['max_upload_size'])

export function isUnlimitedNumericMb(mb) {
    if (mb == null || mb === '') return true
    const n = Number(mb)
    return Number.isFinite(n) && n >= PLAN_LIMIT_UNLIMITED_THRESHOLD_MB
}

export function planSolvesMaxUploadMb(planMaxUploadMb, attemptedMb) {
    const am = Number(attemptedMb)
    if (!Number.isFinite(am)) return false
    if (isUnlimitedNumericMb(planMaxUploadMb)) return true
    const cap = Number(planMaxUploadMb)
    if (!Number.isFinite(cap)) return false
    return cap >= am
}

export function formatStorageFromMb(mb) {
    if (mb == null || mb === '') return '—'
    const n = Number(mb)
    if (!Number.isFinite(n)) return '—'
    if (isUnlimitedNumericMb(n)) return 'Unlimited'
    if (n >= 1024 * 1024) return `${(n / 1024 / 1024).toFixed(0)} TB`
    if (n >= 1024) return `${(n / 1024).toFixed(1)} GB`
    return `${n} MB`
}

export function formatUploadMbLabel(mb) {
    if (mb == null || mb === '') return '—'
    const n = Number(mb)
    if (!Number.isFinite(n)) return '—'
    if (isUnlimitedNumericMb(n)) return 'Unlimited'
    return `${n} MB`
}

export function formatCountLimit(value) {
    if (value == null || value === '') return '—'
    const n = Number(value)
    if (!Number.isFinite(n)) return '—'
    if (isUnlimitedNumericMb(n)) return 'Unlimited'
    return String(n)
}

/** AI credits: 0 in config means unlimited (see config/plans.php). */
export function formatAiCreditsPerMonth(value) {
    if (value == null || value === '') return '—'
    const n = Number(value)
    if (!Number.isFinite(n)) return '—'
    if (n === 0 || isUnlimitedNumericMb(n)) return 'Unlimited'
    return `${n.toLocaleString('en-US')}/mo`
}

/**
 * @param {Array<{id: string, limits?: object}>} visiblePlans
 * @param {string} currentPlanId
 * @param {string} reason
 * @param {number|string} attemptedFromQuery
 */
export function findFirstUpgradePlanThatSolves(visiblePlans, currentPlanId, reason, attemptedFromQuery) {
    if (!RECOGNIZED_PLAN_LIMIT_REASONS.has(reason)) return null
    const order = ['free', 'starter', 'pro', 'business']
    const normalize = (id) => (id === 'premium' ? 'business' : id)
    const cur = normalize(currentPlanId)
    const startIdx = order.indexOf(cur)
    const attempted = Number(attemptedFromQuery)
    if (!Number.isFinite(attempted)) return null
    if (startIdx < 0) return null
    for (let i = startIdx + 1; i < order.length; i++) {
        const id = order[i]
        const p = visiblePlans.find((x) => x.id === id)
        if (!p) continue
        const maxMb = p.limits?.max_upload_size_mb
        if (planSolvesMaxUploadMb(maxMb, attempted)) return id
    }
    return null
}

/**
 * @param {object} plan
 * @returns {Array<{ key: string, label: string, valueLabel: string }>}
 */
export function buildConfigurablePlanLimitRows(plan) {
    const limits = plan?.limits || {}
    const rows = [
        { key: 'max_storage_mb', label: 'Storage', valueLabel: formatStorageFromMb(limits.max_storage_mb) },
        { key: 'max_upload_size_mb', label: 'Max upload size', valueLabel: formatUploadMbLabel(limits.max_upload_size_mb) },
        { key: 'max_ai_credits_per_month', label: 'Monthly AI credits', valueLabel: formatAiCreditsPerMonth(limits.max_ai_credits_per_month) },
        { key: 'max_downloads_per_month', label: 'Downloads per month', valueLabel: formatCountLimit(limits.max_downloads_per_month) },
        { key: 'max_users', label: 'Users', valueLabel: formatCountLimit(limits.max_users) },
        { key: 'max_brands', label: 'Brands', valueLabel: formatCountLimit(limits.max_brands) },
        { key: 'max_custom_metadata_fields', label: 'Custom fields', valueLabel: formatCountLimit(limits.max_custom_metadata_fields) },
        {
            key: 'max_versions_per_asset',
            label: 'Versioning per file',
            valueLabel: formatCountLimit(plan?.max_versions_per_asset ?? limits.max_versions_per_asset),
        },
    ]
    return rows
}
