import { useCallback, useEffect, useMemo, useState } from 'react'
import { router, usePage } from '@inertiajs/react'
import { LockClosedIcon, FunnelIcon, XMarkIcon } from '@heroicons/react/24/outline'
import ActivityActorAvatar from '../../Components/ActivityActorAvatar'
import InsightsLayout from '../../layouts/InsightsLayout'

const ACTIVITY_PATH = '/app/insights/activity'

function isAssetSubjectType(type) {
    if (!type || typeof type !== 'string') return false
    return type === 'App\\Models\\Asset' || type.endsWith('\\Asset') || type === 'asset'
}

export default function InsightsActivity({
    activity = [],
    can_view_activity_logs = false,
    filters: filtersProp = {},
    filter_options = { actors: [], event_types: [] },
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

    const hasActiveFilters = useMemo(() => {
        return Boolean(actorId || eventType || subjectId.trim())
    }, [actorId, eventType, subjectId])

    const applyFilters = useCallback(() => {
        const params = {}
        if (actorId) params.actor_id = actorId
        if (eventType) params.event_type = eventType
        const sid = subjectId.trim()
        if (sid) params.subject_id = sid
        router.get(ACTIVITY_PATH, params, { preserveState: true, preserveScroll: true, replace: true })
    }, [actorId, eventType, subjectId])

    const clearFilters = useCallback(() => {
        setActorId('')
        setEventType('')
        setSubjectId('')
        router.get(ACTIVITY_PATH, {}, { preserveState: true, preserveScroll: true, replace: true })
    }, [])

    const serverF = page.props.filters ?? {}
    useEffect(() => {
        setActorId(serverF.actor_id != null && serverF.actor_id !== '' ? String(serverF.actor_id) : '')
        setEventType(serverF.event_type != null && serverF.event_type !== '' ? String(serverF.event_type) : '')
        setSubjectId(serverF.subject_id != null && serverF.subject_id !== '' ? String(serverF.subject_id) : '')
    }, [serverF.actor_id, serverF.event_type, serverF.subject_id])

    return (
        <InsightsLayout title="Activity" activeSection="activity">
            <div className="space-y-6">
                <p className="text-sm text-gray-600">
                    Recent actions for this brand (uploads, edits, downloads, and more). Scoped like the overview
                    dashboard; tenant-wide events without a brand may appear here when they affect shared resources.
                </p>

                {!can_view_activity_logs && (
                    <div
                        className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 flex gap-3"
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
                    <div className="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                        <div className="mb-3 flex flex-wrap items-center gap-2">
                            <FunnelIcon className="h-4 w-4 text-gray-500" aria-hidden />
                            <span className="text-sm font-medium text-gray-900">Filters</span>
                            {hasActiveFilters && (
                                <button
                                    type="button"
                                    onClick={clearFilters}
                                    className="ml-auto inline-flex items-center gap-1 rounded-md border border-gray-200 bg-white px-2 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50"
                                >
                                    <XMarkIcon className="h-3.5 w-3.5" aria-hidden />
                                    Clear
                                </button>
                            )}
                        </div>
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 lg:items-end">
                            <div>
                                <label htmlFor="activity-filter-user" className="block text-xs font-medium text-gray-500">
                                    User
                                </label>
                                <select
                                    id="activity-filter-user"
                                    value={actorId}
                                    onChange={(e) => setActorId(e.target.value)}
                                    className="mt-1 block w-full rounded-md border border-gray-300 bg-white py-2 pl-2 pr-8 text-sm text-gray-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
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
                                <label htmlFor="activity-filter-type" className="block text-xs font-medium text-gray-500">
                                    Action type
                                </label>
                                <select
                                    id="activity-filter-type"
                                    value={eventType}
                                    onChange={(e) => setEventType(e.target.value)}
                                    className="mt-1 block w-full rounded-md border border-gray-300 bg-white py-2 pl-2 pr-8 text-sm text-gray-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
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
                                <label htmlFor="activity-filter-subject" className="block text-xs font-medium text-gray-500">
                                    Subject ID
                                </label>
                                <input
                                    id="activity-filter-subject"
                                    type="text"
                                    value={subjectId}
                                    onChange={(e) => setSubjectId(e.target.value)}
                                    placeholder="Asset UUID or category ID"
                                    autoComplete="off"
                                    className="mt-1 block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm placeholder:text-gray-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                                />
                            </div>
                            <div className="flex sm:col-span-2 lg:col-span-1">
                                <button
                                    type="button"
                                    onClick={applyFilters}
                                    className="mt-1 w-full rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 sm:mt-6 lg:mt-1"
                                >
                                    Apply
                                </button>
                            </div>
                        </div>
                        <p className="mt-2 text-xs text-gray-500">
                            Subject ID matches assets (full UUID), numeric category/brand IDs, or a partial ID substring.
                        </p>
                    </div>
                )}

                {can_view_activity_logs && activity.length === 0 && (
                    <div className="rounded-lg border border-gray-200 bg-white px-6 py-10 text-center text-sm text-gray-500">
                        {hasActiveFilters
                            ? 'No activity matches these filters. Try clearing filters or broadening Subject ID.'
                            : 'No recent activity recorded for this brand yet.'}
                    </div>
                )}

                {can_view_activity_logs && activity.length > 0 && (
                    <div className="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
                        <ul className="divide-y divide-gray-100">
                            {activity.map((event) => (
                                <li key={event.id} className="px-4 py-4 sm:px-6">
                                    <div className="flex items-center gap-4">
                                        <ActivityActorAvatar actor={event.actor} />
                                        <div className="min-w-0 flex-1">
                                            <p className="text-sm text-gray-900">
                                                <span className="font-medium">{event.actor?.name}</span>{' '}
                                                <span className="text-gray-500">
                                                    {(event.event_type_label || '').toLowerCase()}
                                                </span>{' '}
                                                <span className="font-medium">{event.subject?.name}</span>
                                            </p>
                                            {event.subject?.id && (
                                                <p className="mt-0.5 font-mono text-[11px] text-gray-400">
                                                    {isAssetSubjectType(event.subject?.type) ? 'Asset ID: ' : 'Subject ID: '}
                                                    <span className="select-all">{event.subject.id}</span>
                                                </p>
                                            )}
                                            {event.description && (
                                                <p className="mt-0.5 text-xs text-gray-500 line-clamp-2">
                                                    {event.description}
                                                </p>
                                            )}
                                        </div>
                                        <span className="shrink-0 text-xs text-gray-400">{event.created_at_human}</span>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
            </div>
        </InsightsLayout>
    )
}
