/**
 * Display helpers: plan "unlimited" sentinels differ by unit.
 * Do not use a single MB threshold (e.g. 999999) for storage — multi-TB caps exceed that and were wrongly shown as "Unlimited".
 */

export function isUnlimitedStorageMB(limit) {
    return (
        limit == null ||
        limit === 0 ||
        limit === Number.MAX_SAFE_INTEGER ||
        limit === 2147483647
    )
}

/** Count-style limits (downloads/month, etc.) where the product uses large sentinels. */
export function isUnlimitedCount(limit) {
    return (
        limit == null ||
        limit === 0 ||
        limit >= 999999 ||
        limit === Number.MAX_SAFE_INTEGER ||
        limit === 2147483647
    )
}
