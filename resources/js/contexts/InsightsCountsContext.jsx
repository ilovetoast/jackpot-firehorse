import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react'
import { usePage } from '@inertiajs/react'

const InsightsCountsContext = createContext(null)

export function useInsightsCounts() {
    return useContext(InsightsCountsContext) ?? fallbackInsightsCounts
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

/** When no provider (should not happen on Insights pages); keeps consumers from seeing null. */
const fallbackInsightsCounts = {
    ...emptyCounts,
    aiTotal: 0,
    uploadTotal: 0,
    reviewNavTotal: 0,
    reload: () => {},
}

function seedAiCountsFromPayload(base, initialReviewTabCounts) {
    if (!initialReviewTabCounts || typeof initialReviewTabCounts !== 'object') {
        return base
    }
    return {
        ...base,
        tags: Number(initialReviewTabCounts.tags) || 0,
        categories: Number(initialReviewTabCounts.categories) || 0,
        values: Number(initialReviewTabCounts.values) || 0,
        fields: Number(initialReviewTabCounts.fields) || 0,
    }
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
            className={`inline-flex min-h-[1.25rem] min-w-[1.25rem] shrink-0 items-center justify-center rounded-full bg-violet-600 px-1.5 text-xs font-semibold text-white ${className}`}
        >
            {label}
        </span>
    )
}

export function InsightsCountsProvider({ children, initialReviewTabCounts = null }) {
    const { auth } = usePage().props
    const brandId = auth?.activeBrand?.id
    const [counts, setCounts] = useState(() => seedAiCountsFromPayload(emptyCounts, initialReviewTabCounts))

    useEffect(() => {
        if (!initialReviewTabCounts) {
            return
        }
        setCounts((prev) => ({
            ...prev,
            tags: Number(initialReviewTabCounts.tags) || 0,
            categories: Number(initialReviewTabCounts.categories) || 0,
            values: Number(initialReviewTabCounts.values) || 0,
            fields: Number(initialReviewTabCounts.fields) || 0,
        }))
    }, [
        initialReviewTabCounts?.tags,
        initialReviewTabCounts?.categories,
        initialReviewTabCounts?.values,
        initialReviewTabCounts?.fields,
    ])

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
            let aiPayload = null
            if (aiRes.ok) {
                aiPayload = await aiRes.json()
            }
            let upPayload = null
            if (upRes.ok) {
                upPayload = await upRes.json()
            }
            setCounts((prev) => {
                const next = {
                    ...prev,
                    loaded: true,
                }
                if (aiPayload) {
                    next.tags = Number(aiPayload.tags) || 0
                    next.categories = Number(aiPayload.categories) || 0
                    next.values = Number(aiPayload.values) || 0
                    next.fields = Number(aiPayload.fields) || 0
                }
                if (upPayload) {
                    next.uploadTeam = Number(upPayload.team) || 0
                    next.uploadCreator = Number(upPayload.creator) || 0
                }
                return next
            })
        } catch {
            setCounts((prev) => ({ ...prev, loaded: true }))
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
