/**
 * Theme for permission-denied modal: brand (scoped brand routes), workspace (/app), or jackpot fallback.
 */
export function resolvePermissionTheme(url, activeBrand) {
    if (!url || typeof url !== 'string') return 'jackpot'
    const path = url.split('?')[0] || ''
    if (activeBrand?.primary_color && /^\/app\/brands\/\d+/.test(path)) {
        return 'brand'
    }
    if (path.startsWith('/app')) {
        return 'workspace'
    }
    return 'jackpot'
}
