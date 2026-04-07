import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react'
import { usePage } from '@inertiajs/react'

const InsightsCountsContext = createContext(null)

export function useInsightsCounts() {
    return useContext(InsightsCountsContext)
}

const emptyCounts = {
    tags: 0,
    categories: 0,
    values: 0,
    fields: 0,
    uploadTeam: 0,
    uploadCreator: 0,
    loaded: false,
}

function formatBadgeCount(n) {
    if (n <= 0) return null
    if (n > 99) return '99+'
    return String(n)
}

export function InsightsBadge({ count, className = '' }) {
    const label = formatBadgeCount(count)
    if (!label) return null
    return (
        <span
            className={`inline-flex min-h-[1.25rem] min-w-[1.25rem] items-center justify-center rounded-full bg-indigo-600 px-1.5 text-xs font-semibold text-white ${className}`}
        >
            {label}
        </span>
    )
}

export function InsightsCountsProvider({ children }) {
    const { auth } = usePage().props
    const brandId = auth?.activeBrand?.id
    const [counts, setCounts] = useState(emptyCounts)

    const reload = useCallback(async () => {
        if (!brandId) {
            setCounts({ ...emptyCounts, loaded: true })
            return
        }
        const headers = { Accept: 'application/json' }
        try {
            const [aiRes, upRes] = await Promise.all([
                fetch('/app/api/ai/review/counts', { credentials: 'same-origin', headers }),
                fetch(`/app/api/brands/${brandId}/approvals?count_only=1`, { credentials: 'same-origin', headers }),
            ])
            const next = { ...emptyCounts, loaded: true }
            if (aiRes.ok) {
                const j = await aiRes.json()
                next.tags = Number(j.tags) || 0
                next.categories = Number(j.categories) || 0
                next.values = Number(j.values) || 0
                next.fields = Number(j.fields) || 0
            }
            if (upRes.ok) {
                const j = await upRes.json()
                next.uploadTeam = Number(j.team) || 0
                next.uploadCreator = Number(j.creator) || 0
            }
            setCounts(next)
        } catch {
            setCounts({ ...emptyCounts, loaded: true })
        }
    }, [brandId])

    useEffect(() => {
        reload()
    }, [reload])

    const aiTotal = counts.tags + counts.categories + counts.values + counts.fields
    const uploadTotal = counts.uploadTeam + counts.uploadCreator
    const reviewNavTotal = aiTotal + uploadTotal

    const value = useMemo(
        () => ({
            ...counts,
            aiTotal,
            uploadTotal,
            reviewNavTotal,
            reload,
        }),
        [counts, aiTotal, uploadTotal, reviewNavTotal, reload]
    )

    return <InsightsCountsContext.Provider value={value}>{children}</InsightsCountsContext.Provider>
}
