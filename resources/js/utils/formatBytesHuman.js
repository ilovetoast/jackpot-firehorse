/**
 * Format byte counts for UI (B through TB, en-US grouping, one decimal above B).
 * Values round down so the displayed amount never exceeds the actual byte count.
 *
 * @param {number|null|undefined} bytes
 * @returns {string}
 */
export function formatBytesHuman(bytes) {
    if (bytes == null || Number.isNaN(bytes)) {
        return '—'
    }
    const n = Math.floor(Number(bytes))
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
    const displayVal = decimals === 0 ? Math.floor(v) : Math.floor(v * 10) / 10
    const formatted = displayVal.toLocaleString('en-US', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    })
    return `${formatted} ${units[u]}`
}
