import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link, usePage } from '@inertiajs/react'
import { ChevronRightIcon, UserGroupIcon } from '@heroicons/react/24/outline'
import { parseProstaffDashboardResponse } from '../../utils/parseProstaffDashboardResponse'

function glassCard(brandColor) {
    return {
        boxShadow: `0 0 28px ${brandColor}14`,
    }
}

/** Matches BrandInsightEngine::resolveUploadApprovalsHref for creator-side deep links. */
function resolveCreatorUploadQueueHref(prostaffPending, teamPending, moduleOn) {
    if (!moduleOn || prostaffPending <= 0) {
        return null
    }
    if (teamPending === 0) {
        return '/app/insights/creator'
    }
    return '/app/insights/review?workspace=uploads&approval_queue=creator'
}

/**
 * Manager overview: Creators card + performance strip (loads dashboard API when allowed).
 */
export default function OverviewCreatorInsights({
    brandId,
    brandColor,
    iconAccentColor,
    prostaffPendingUploads = 0,
    teamPendingUploads = 0,
    creatorModuleEnabled: creatorModuleEnabledProp,
}) {
    const page = usePage()
    const moduleEnabled =
        creatorModuleEnabledProp !== undefined
            ? creatorModuleEnabledProp
            : page.props.creator_module_status?.enabled === true
    const canManageCreators = page.props.auth?.permissions?.can_manage_creators_dashboard === true

    const [rows, setRows] = useState([])
    const [pendingCount, setPendingCount] = useState(0)
    const [loading, setLoading] = useState(false)

    const load = useCallback(async () => {
        if (!brandId || !moduleEnabled || !canManageCreators) return
        setLoading(true)
        try {
            const res = await fetch(route('api.brands.prostaff.dashboard', { brand: brandId }), {
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            })
            const data = await res.json().catch(() => ({}))
            const parsed = parseProstaffDashboardResponse(data)
            setRows(parsed.active)
            setPendingCount(parsed.pendingInvitations.length)
        } catch {
            setRows([])
            setPendingCount(0)
        } finally {
            setLoading(false)
        }
    }, [brandId, moduleEnabled, canManageCreators])

    useEffect(() => {
        load()
    }, [load])

    const stats = useMemo(() => {
        if (!rows.length) {
            return { n: 0, avg: 0 }
        }
        const n = rows.length
        const avg = rows.reduce((s, r) => s + Number(r.completion_percentage || 0), 0) / n
        return { n, avg }
    }, [rows])

    if (!brandId) return null

    // Promo lives in Brand Settings → Creators, not on overview.
    if (!moduleEnabled) return null

    if (!canManageCreators) return null

    const creatorsUrl =
        typeof route === 'function' ? route('brands.creators', { brand: brandId }) : `/app/brands/${brandId}/creators`

    const creatorQueueHref = resolveCreatorUploadQueueHref(
        prostaffPendingUploads,
        teamPendingUploads,
        moduleEnabled
    )
    const creatorUploadLabel =
        prostaffPendingUploads === 1
            ? '1 creator upload awaits your approval'
            : `${prostaffPendingUploads} creator uploads await your approval`

    return (
        <>
            <div
                className="rounded-xl border border-white/[0.08] bg-white/[0.035] px-4 py-3 backdrop-blur-sm sm:px-5 sm:py-3.5"
                style={glassCard(brandColor)}
            >
                <div className="flex items-start gap-3">
                    <div
                        className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-white/10 bg-white/[0.06] sm:h-10 sm:w-10 sm:rounded-xl"
                        style={{ color: iconAccentColor }}
                    >
                        <UserGroupIcon className="h-5 w-5" />
                    </div>
                    <div className="min-w-0 flex-1 flex flex-col gap-3">
                        <div>
                            <p className="text-[11px] font-semibold uppercase tracking-wide text-white/45">Creators</p>
                            {loading ? (
                                <p className="mt-0.5 text-sm text-white/45">Loading…</p>
                            ) : (
                                <p className="mt-0.5 text-sm text-white/70">
                                    <span className="font-semibold text-white">{stats.n}</span> active
                                    {pendingCount > 0 ? (
                                        <>
                                            {' '}
                                            ·{' '}
                                            <span className="text-violet-200/90">
                                                {pendingCount} pending invite{pendingCount === 1 ? '' : 's'}
                                            </span>
                                        </>
                                    ) : null}
                                    {stats.n > 0 ? (
                                        <>
                                            {' '}
                                            · avg{' '}
                                            <span className="text-white/90">{stats.avg.toFixed(1)}%</span> completion
                                        </>
                                    ) : null}
                                </p>
                            )}
                        </div>
                        <div
                            className={`flex flex-col gap-2 sm:flex-row sm:items-center sm:gap-3 ${
                                creatorQueueHref && prostaffPendingUploads > 0 ? '' : 'sm:justify-end'
                            }`}
                        >
                            {creatorQueueHref && prostaffPendingUploads > 0 ? (
                                <Link
                                    href={creatorQueueHref}
                                    className="flex min-h-[2.5rem] w-full min-w-0 flex-1 items-center gap-1 rounded-lg border border-amber-400/35 bg-amber-500/[0.12] px-2.5 py-2 text-left text-xs leading-snug text-amber-50/95 transition hover:border-amber-400/50 hover:bg-amber-500/[0.18] sm:text-[13px]"
                                >
                                    <span className="min-w-0 flex-1 font-medium">{creatorUploadLabel}</span>
                                    <ChevronRightIcon className="h-4 w-4 shrink-0 opacity-80" aria-hidden />
                                </Link>
                            ) : null}
                            <Link
                                href={creatorsUrl}
                                className="inline-flex h-[2.5rem] w-full shrink-0 items-center justify-center rounded-lg border border-white/15 bg-white/[0.08] px-3 text-sm font-semibold text-white transition hover:border-white/25 hover:bg-white/[0.12] sm:w-auto sm:rounded-xl sm:px-4"
                            >
                                Open dashboard
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </>
    )
}
