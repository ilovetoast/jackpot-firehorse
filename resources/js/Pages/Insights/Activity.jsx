import { useCallback, useEffect, useMemo, useState } from 'react'
import { router, usePage } from '@inertiajs/react'
import { LockClosedIcon, MagnifyingGlassIcon, XMarkIcon } from '@heroicons/react/24/outline'
import ActivityActorAvatar from '../../Components/ActivityActorAvatar'
import InsightsLayout from '../../layouts/InsightsLayout'
import {
    workbenchInputClass,
    workbenchLabelClass,
    workbenchSelectClass,
    workbenchToolbarClass,
    WorkbenchEmptyState,
    WorkbenchPageIntro,
    WorkbenchPanel,
} from '../../components/brand-workspace/workbenchPatterns'
import { productButtonPrimary } from '../../components/brand-workspace/brandWorkspaceTokens'

const ACTIVITY_PATH = '/app/insights/activity'

function isAssetSubjectType(type) {
    if (!type || typeof type !== 'string') return false
    return type === 'App\\Models\\Asset' || type.endsWith('\\Asset') || type === 'asset'
}

function formatActivityDayLabel(iso) {
    if (!iso || typeof iso !== 'string') return 'Unknown date'
    const d = new Date(iso)
    if (Number.isNaN(d.getTime())) return 'Unknown date'
    const today = new Date()
    const yesterday = new Date(today)
    yesterday.setDate(yesterday.getDate() - 1)
    if (d.toDateString() === today.toDateString()) return 'Today'
    if (d.toDateString() === yesterday.toDateString()) return 'Yesterday'
    return d.toLocaleDateString(undefined, { weekday: 'long', month: 'short', day: 'numeric', year: 'numeric' })
}

function dayKeyForEvent(iso) {
    if (!iso || typeof iso !== 'string') return 'unknown'
    const d = new Date(iso)
    if (Number.isNaN(d.getTime())) return 'unknown'
    return d.toISOString().slice(0, 10)
}

export default function InsightsActivity({
    activity = [],
    can_view_activity_logs = false,
    filters: filtersProp = {},
    filter_options = { actors: [], event_types: [] },
    pagination = null,
}) {
    const page = usePage()
    const actors = Array.isArray(filter_options?.actors) ? filter_options.actors : []
    const eventTypes = Array.isArray(filter_options?.event_types) ? filter_options.event_types : []

    const [actorId, setActorId] = useState(
        filtersProp?.actor_id != null && filtersProp.actor_id !== '' ? String(filtersProp.actor_id) : ''
    )
    const [eventType, setEventType] = useState(filtersProp?.event_type != null ? String(filtersProp.event_type) : '')
    const [subjectId, setSubjectId] = useState(
        filtersProp?.subject_id != null && filtersProp.subject_id !== '' ? String(filtersProp.subject_id) : ''
    )
    const [q, setQ] = useState(filtersProp?.q != null && filtersProp.q !== '' ? String(filtersProp.q) : '')

    const hasActiveFilters = useMemo(() => {
        return Boolean(actorId || eventType || subjectId.trim() || q.trim())
    }, [actorId, eventType, subjectId, q])

    const applyFilters = useCallback(
        (overrides = {}) => {
            const params = { page: overrides.page ?? 1 }
            const a = overrides.actorId !== undefined ? overrides.actorId : actorId
            const et = overrides.eventType !== undefined ? overrides.eventType : eventType
            const sid = overrides.subjectId !== undefined ? overrides.subjectId : subjectId
            const search = overrides.q !== undefined ? overrides.q : q
            if (a) params.actor_id = a
            if (et) params.event_type = et
            const s = (typeof sid === 'string' ? sid : String(sid || '')).trim()
            if (s) params.subject_id = s
            const sq = (typeof search === 'string' ? search : String(search || '')).trim()
            if (sq) params.q = sq
            if (pagination?.per_page) params.per_page = pagination.per_page
            router.get(ACTIVITY_PATH, params, { preserveState: true, preserveScroll: true, replace: true })
        },
        [actorId, eventType, subjectId, q, pagination?.per_page]
    )

    const clearFilters = useCallback(() => {
        setActorId('')
        setEventType('')
        setSubjectId('')
        setQ('')
        const params = {}
        if (pagination?.per_page) params.per_page = pagination.per_page
        router.get(ACTIVITY_PATH, params, { preserveState: true, preserveScroll: true, replace: true })
    }, [pagination?.per_page])

    const serverF = page.props.filters ?? {}
    useEffect(() => {
        setActorId(serverF.actor_id != null && serverF.actor_id !== '' ? String(serverF.actor_id) : '')
        setEventType(serverF.event_type != null && serverF.event_type !== '' ? String(serverF.event_type) : '')
        setSubjectId(serverF.subject_id != null && serverF.subject_id !== '' ? String(serverF.subject_id) : '')
        setQ(serverF.q != null && serverF.q !== '' ? String(serverF.q) : '')
    }, [serverF.actor_id, serverF.event_type, serverF.subject_id, serverF.q])

    const grouped = useMemo(() => {
        const groups = []
        let lastKey = null
        for (const event of activity) {
            const key = dayKeyForEvent(event.created_at)
            if (key !== lastKey) {
                lastKey = key
                groups.push({
                    key,
                    label: formatActivityDayLabel(event.created_at),
                    items: [event],
                })
            } else {
                groups[groups.length - 1].items.push(event)
            }
        }
        return groups
    }, [activity])

    return (
        <InsightsLayout title="Activity" activeSection="activity">
            <div className="space-y-5 sm:space-y-6">
                <WorkbenchPageIntro description="A chronological log of actions for this brand. Filter, search, or page through results. Tenant-wide events may appear when they affect shared resources." />

                {!can_view_activity_logs && (
                    <div
                        className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 flex gap-3"
                        role="status"
                    >
                        <LockClosedIcon className="h-5 w-5 shrink-0 text-amber-700" aria-hidden />
                        <div className="min-w-0 text-sm text-amber-950">
                            <p className="font-medium">Activity log not available for your role</p>
                            <p className="mt-1 text-amber-900/90">
                                Your account needs the <span className="font-medium">activity logs</span> permission
                                for this company to view this feed. Ask a company admin if you should have access.
                            </p>
                        </div>
                    </div>
                )}

                {can_view_activity_logs && (
                    <WorkbenchPanel
                        title="Filter and search"
                        headerRight={
                            hasActiveFilters ? (
                                <button
                                    type="button"
                                    onClick={clearFilters}
                                    className="inline-flex items-center gap-1 rounded-md border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-medium text-slate-700 shadow-sm hover:bg-slate-50"
                                >
                                    <XMarkIcon className="h-3.5 w-3.5" aria-hidden />
                                    Clear
                                </button>
                            ) : null
                        }
                    >
                        <div className="space-y-4">
                            <div className={workbenchToolbarClass}>
                                <div className="relative w-full min-w-0 sm:max-w-sm">
                                    <label htmlFor="activity-search" className="sr-only">
                                        Search
                                    </label>
                                    <MagnifyingGlassIcon
                                        className="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"
                                        aria-hidden
                                    />
                                    <input
                                        id="activity-search"
                                        type="search"
                                        value={q}
                                        onChange={(e) => setQ(e.target.value)}
                                        onKeyDown={(e) => {
                                            if (e.key === 'Enter') applyFilters({ q, page: 1 })
                                        }}
                                        placeholder="Search actions, descriptions, or IDs…"
                                        className={`${workbenchInputClass} pl-9`}
                                    />
                                </div>
                            </div>
                            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 lg:items-end">
                                <div>
                                    <label
                                        htmlFor="activity-filter-user"
                                        className={workbenchLabelClass + ' block'}
                                    >
                                        User
                                    </label>
                                    <select
                                        id="activity-filter-user"
                                        value={actorId}
                                        onChange={(e) => setActorId(e.target.value)}
                                        className={workbenchSelectClass + ' mt-1.5'}
                                    >
                                        <option value="">All users</option>
                                        {actors.map((a) => (
                                            <option key={a.id} value={String(a.id)}>
                                                {a.name || a.email || `User #${a.id}`}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                                <div>
                                    <label
                                        htmlFor="activity-filter-type"
                                        className={workbenchLabelClass + ' block'}
                                    >
                                        Action type
                                    </label>
                                    <select
                                        id="activity-filter-type"
                                        value={eventType}
                                        onChange={(e) => setEventType(e.target.value)}
                                        className={workbenchSelectClass + ' mt-1.5'}
                                    >
                                        <option value="">All actions</option>
                                        {eventTypes.map((t) => (
                                            <option key={t.value} value={t.value}>
                                                {t.label}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                                <div className="sm:col-span-2 lg:col-span-1">
                                    <label
                                        htmlFor="activity-filter-subject"
                                        className={workbenchLabelClass + ' block'}
                                    >
                                        Subject ID
                                    </label>
                                    <input
                                        id="activity-filter-subject"
                                        type="text"
                                        value={subjectId}
                                        onChange={(e) => setSubjectId(e.target.value)}
                                        placeholder="Asset UUID, category id…"
                                        autoComplete="off"
                                        className={workbenchInputClass + ' mt-1.5'}
                                    />
                                </div>
                                <div className="flex sm:col-span-2 lg:col-span-1">
                                    <button
                                        type="button"
                                        onClick={() => applyFilters({ page: 1 })}
                                        className={productButtonPrimary + ' mt-0 w-full sm:mt-6 lg:mt-0'}
                                    >
                                        Apply
                                    </button>
                                </div>
                            </div>
                            <p className="text-xs text-slate-500">
                                Search matches event types, text in the description, and subject id. Subject ID also
                                supports a partial match when not a full UUID.
                            </p>
                        </div>
                    </WorkbenchPanel>
                )}

                {can_view_activity_logs && activity.length === 0 && (
                    <WorkbenchEmptyState
                        title={hasActiveFilters ? 'No matching events' : 'No activity yet'}
                        description={
                            hasActiveFilters
                                ? 'Try clearing filters, broadening subject id, or adjusting search.'
                                : 'Actions for this brand will show up here as your team works in the library.'
                        }
                        action={
                            hasActiveFilters ? (
                                <button
                                    type="button"
                                    onClick={clearFilters}
                                    className={productButtonPrimary + ' !py-1.5 !px-3 text-sm'}
                                >
                                    Clear filters
                                </button>
                            ) : null
                        }
                    />
                )}

                {can_view_activity_logs && activity.length > 0 && (
                    <div>
                        {grouped.map((group) => (
                            <div key={group.key} className="mb-6 last:mb-0">
                                <p className="mb-2 text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                                    {group.label}
                                </p>
                                <div className="overflow-hidden rounded-xl border border-slate-200/90 bg-white shadow-sm">
                                    <ul className="divide-y divide-slate-100">
                                        {group.items.map((event) => (
                                            <li key={event.id} className="px-3 py-3.5 sm:px-4 sm:py-4">
                                                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:gap-4">
                                                    <ActivityActorAvatar actor={event.actor} />
                                                    <div className="min-w-0 flex-1">
                                                        <p className="text-sm text-slate-800">
                                                            <span className="font-semibold text-slate-900">
                                                                {event.actor?.name}
                                                            </span>{' '}
                                                            <span className="font-medium text-slate-600">
                                                                {event.event_type_label || '—'}
                                                            </span>
                                                            {event.company_name ? (
                                                                <span className="text-slate-500">
                                                                    {' '}
                                                                    · {event.company_name}
                                                                </span>
                                                            ) : null}
                                                        </p>
                                                        <p className="mt-0.5 text-sm">
                                                            <span className="text-slate-500">on </span>
                                                            <span className="font-medium text-slate-800">
                                                                {event.subject?.name}
                                                            </span>
                                                        </p>
                                                        {event.subject?.id && (
                                                            <p className="mt-1 font-mono text-[11px] text-slate-500">
                                                                {isAssetSubjectType(event.subject?.type) ? 'Asset' : 'Subject'}
                                                                {': '}
                                                                <span className="select-all break-all text-slate-500">
                                                                    {event.subject.id}
                                                                </span>
                                                            </p>
                                                        )}
                                                        {event.description && (
                                                            <p className="mt-1.5 text-xs leading-relaxed text-slate-500 line-clamp-3">
                                                                {event.description}
                                                            </p>
                                                        )}
                                                    </div>
                                                    <time
                                                        className="shrink-0 text-xs text-slate-500 sm:text-right"
                                                        dateTime={event.created_at}
                                                    >
                                                        {event.created_at_human}
                                                    </time>
                                                </div>
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            </div>
                        ))}
                    </div>
                )}

                {can_view_activity_logs && pagination && pagination.total > 0 && (
                    <div className="flex flex-col gap-3 border-t border-slate-200/90 pt-4 sm:flex-row sm:items-center sm:justify-between">
                        <p className="text-sm text-slate-600">
                            {pagination.from != null && pagination.to != null ? (
                                <>
                                    Showing{' '}
                                    <span className="font-medium text-slate-800">
                                        {pagination.from}–{pagination.to}
                                    </span>{' '}
                                    of{' '}
                                    <span className="font-medium text-slate-800">
                                        {pagination.total.toLocaleString()}
                                    </span>
                                </>
                            ) : (
                                <>{pagination.total.toLocaleString()} result{pagination.total === 1 ? '' : 's'}</>
                            )}
                        </p>
                        <div className="flex flex-wrap items-center gap-2">
                            <button
                                type="button"
                                disabled={pagination.current_page <= 1}
                                onClick={() => applyFilters({ page: pagination.current_page - 1 })}
                                className="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                Previous
                            </button>
                            <span className="text-sm tabular-nums text-slate-500">
                                Page {pagination.current_page} of {pagination.last_page}
                            </span>
                            <button
                                type="button"
                                disabled={pagination.current_page >= pagination.last_page}
                                onClick={() => applyFilters({ page: pagination.current_page + 1 })}
                                className="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                Next
                            </button>
                        </div>
                    </div>
                )}
            </div>
        </InsightsLayout>
    )
}
