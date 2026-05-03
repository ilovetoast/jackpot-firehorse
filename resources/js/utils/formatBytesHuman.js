/**
 * Format byte counts for UI (B through TB, en-US grouping, one decimal above B).
 *
 * @param {number|null|undefined} bytes
 * @returns {string}
 */
export function formatBytesHuman(bytes) {
    if (bytes == null || Number.isNaN(bytes)) {
        return '—'
    }
    const n = Number(bytes)
    if (n < 0) {
        return '—'
    }
    if (n === 0) {
        return '0 B'
    }
    const units = ['B', 'KB', 'MB', 'GB', 'TB']
    let u = 0
    let v = n
    while (v >= 1024 && u < units.length - 1) {
        v /= 1024
        u++
    }
    const decimals = u === 0 ? 0 : 1
    const formatted = v.toLocaleString('en-US', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    })
    return `${formatted} ${units[u]}`
}
