/**
 * Summarize momentum data into display items.
 * Max 4 items, no duplicates, no passive logs.
 *
 * @param {Object} data
 * @param {number} data.sharedCount
 * @param {number} data.uploadCount
 * @param {number} data.aiCompleted
 * @param {number} data.teamChanges
 * @param {number} [data.sharedTrend] - optional % change
 * @returns {Array<{ icon: string, label: string, trend?: number }>}
 */
export function summarizeMomentum(data) {
    const items = []

    if (data.sharedCount > 0) {
        items.push({
            icon: 'arrow-up',
            label: `${data.sharedCount} assets shared this week`,
            trend: data.sharedTrend,
        })
    }

    if (data.uploadCount > 0) {
        items.push({
            icon: 'plus',
            label: `${data.uploadCount} new uploads`,
        })
    }

    if (data.aiCompleted > 0) {
        items.push({
            icon: 'check',
            label: `${data.aiCompleted} AI suggestions completed`,
        })
    }

    if (data.teamChanges > 0) {
        items.push({
            icon: 'user',
            label: 'Team updates made',
        })
    }

    return items.slice(0, 4)
}
