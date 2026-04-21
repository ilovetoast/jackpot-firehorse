import { Link, router, usePage } from '@inertiajs/react'
import { useState, useEffect } from 'react'
import AppNav from '../../Components/AppNav'
import CompanyTabs from '../../Components/Company/CompanyTabs'
import AppHead from '../../Components/AppHead'
import AppFooter from '../../Components/AppFooter'
import Avatar from '../../Components/Avatar'
import { parseUserAgent } from '../../utils/userAgentParser'
import {
    ArrowUpTrayIcon,
    PencilSquareIcon,
    TrashIcon,
    UserPlusIcon,
    SparklesIcon,
    DocumentIcon,
    FolderIcon,
    BuildingOfficeIcon,
    Cog6ToothIcon,
} from '@heroicons/react/24/outline'

export default function CompanyActivity({ tenant, events, pagination, filters, filter_options }) {
    const { auth } = usePage().props
    const [expandedRows, setExpandedRows] = useState(new Set())
    const [localFilters, setLocalFilters] = useState({
        event_type: filters?.event_type || '',
        brand_id: filters?.brand_id || '',
        date_from: filters?.date_from || '',
        date_to: filters?.date_to || '',
        exclude_portal_views: filters?.exclude_portal_views ?? true,
    })

    // Sync local filters when filters prop changes
    useEffect(() => {
        setLocalFilters({
            event_type: filters?.event_type || '',
            brand_id: filters?.brand_id || '',
            date_from: filters?.date_from || '',
            date_to: filters?.date_to || '',
            exclude_portal_views: filters?.exclude_portal_views ?? true,
        })
    }, [filters])

    const formatEventType = (eventType) => {
        return eventType
            ?.split('.')
            .map(word => word.charAt(0).toUpperCase() + word.slice(1))
            .join(' ')
            .replace(/Tenant/g, 'Company') ?? ''
    }

    const getEventGroup = (createdAt) => {
        if (!createdAt) return 'Older'
        const d = new Date(createdAt)
        const now = new Date()
        const today = new Date(now.getFullYear(), now.getMonth(), now.getDate())
        const eventDate = new Date(d.getFullYear(), d.getMonth(), d.getDate())
        const diffDays = Math.floor((today - eventDate) / (1000 * 60 * 60 * 24))
        if (diffDays === 0) return 'Today'
        if (diffDays === 1) return 'Yesterday'
        if (diffDays <= 7) return 'This week'
        return 'Older'
    }

    const getEventIcon = (eventType) => {
        if (!eventType) return DocumentIcon
        if (eventType.includes('.deleted')) return TrashIcon
        if (eventType.includes('asset.uploaded') || eventType.includes('asset.created')) return ArrowUpTrayIcon
        if (eventType.includes('asset.') || eventType.includes('metadata')) return DocumentIcon
        if (eventType.includes('category')) return FolderIcon
        if (eventType.includes('user.') || eventType.includes('invited') || eventType.includes('added')) return UserPlusIcon
        if (eventType.includes('brand')) return BuildingOfficeIcon
        if (eventType.includes('tenant') || eventType.includes('plan') || eventType.includes('subscription')) return Cog6ToothIcon
        if (eventType.includes('ai_') || eventType.includes('ai.') || eventType.includes('suggestion')) return SparklesIcon
        return PencilSquareIcon
    }

    const applyTodayFilter = () => {
        const today = new Date().toISOString().slice(0, 10)
        setLocalFilters({ ...localFilters, date_from: today, date_to: today })
        router.get('/app/companies/activity', { date_from: today, date_to: today, exclude_portal_views: localFilters.exclude_portal_views }, { preserveState: false })
    }

    const dedupeKey = (event) => {
        const desc = event?.description || ''
        const actor = event?.actor?.name || event?.actor?.id || ''
        const brand = event?.brand?.name || event?.brand?.id || ''
        const subj = event?.subject?.id || ''
        return `${event?.event_type}|${desc}|${event?.created_at}|${actor}|${brand}|${subj}`
    }
    const seenKeys = new Set()
    const dedupedEvents = (events || []).filter((event) => {
        const key = dedupeKey(event)
        if (seenKeys.has(key)) return false
        seenKeys.add(key)
        return true
    })

    const groupedEvents = dedupedEvents.reduce((acc, event) => {
        const group = getEventGroup(event.created_at)
        if (!acc[group]) acc[group] = []
        acc[group].push(event)
        return acc
    }, {})
    const groupOrder = ['Today', 'Yesterday', 'This week', 'Older']

    const getActorLabel = (event) => {
        return event?.actor?.name || 'System'
    }

    const humanizeSubjectType = (subjectType) => {
        if (!subjectType || typeof subjectType !== 'string') return null
        const last = subjectType.split('\\').pop()
        if (!last) return null
        return last.replace(/([a-z0-9])([A-Z])/g, '$1 $2')
    }

    const getSubjectLabel = (event) => {
        if (event?.subject?.name) return event.subject.name
        if (event?.brand?.name) return event.brand.name
        if (typeof event?.tenant === 'string' && event.tenant.length > 0) return event.tenant
        return humanizeSubjectType(event?.subject?.type)
    }

    const getVerb = (event) => {
        const t = event?.event_type || ''
        if (t.endsWith('.created')) return 'created'
        if (t.endsWith('.updated')) return 'updated'
        if (t.endsWith('.deleted')) return 'deleted'
        if (t === 'user.invited') return 'invited'
        if (t === 'user.added_to_company') return 'added'
        if (t === 'user.removed_from_company') return 'removed'
        if (t === 'user.added_to_brand') return 'added'
        if (t === 'user.removed_from_brand') return 'removed'
        if (t === 'user.role_updated') return 'updated role for'
        if (t === 'plan.updated') return 'updated plan for'
        if (t === 'subscription.created') return 'started subscription for'
        if (t === 'subscription.updated') return 'updated subscription for'
        if (t === 'subscription.canceled') return 'canceled subscription for'
        if (t === 'invoice.paid') return 'paid invoice for'
        if (t === 'invoice.failed') return 'failed invoice payment for'
        return null
    }

    const buildEventText = (event) => {
        const actor = getActorLabel(event)
        const description = event?.description || formatEventType(event?.event_type || '')

        // Use the backend-provided description which already has proper formatting
        // Parse it to extract the model name and action
        if (typeof description === 'string') {
            // Check for patterns like "Company was updated", "Brand Name was created", etc.
            if (description.includes(' was ')) {
                const idx = description.indexOf(' was ')
                const modelName = description.slice(0, idx).trim()
                const action = description.slice(idx + 1).trim() // "was updated", "was created", etc.
                return { actor, subtle: action, model: modelName }
            }
            
            // Check for patterns like "Company account was updated"
            if (description.includes(' account was ')) {
                const idx = description.indexOf(' account was ')
                const modelName = description.slice(0, idx).trim()
                const action = description.slice(idx + 1).trim() // "account was updated", etc.
                return { actor, subtle: action, model: modelName }
            }
            
            // Fallback: show description as subtle text
            return { actor, subtle: description, model: null }
        }

        // If no description, fall back to old logic
        const subject = getSubjectLabel(event)
        const verb = getVerb(event)
        
        if (subject && verb) {
            // Special-case a couple events where the subject is better expressed via description/metadata
            if (event?.event_type === 'user.added_to_brand') {
                const role = event?.metadata?.role ? ` as ${event.metadata.role}` : ''
                const brandName = event?.brand?.name ? ` to ${event.brand.name}` : ' to brand'
                return { actor, subtle: `added${brandName}${role}`, model: null }
            }
            return { actor, subtle: verb, model: subject }
        }

        return { actor, subtle: description || formatEventType(event?.event_type || ''), model: subject }
    }

    const toggleRowExpansion = (eventId) => {
        setExpandedRows(prev => {
            const newSet = new Set(prev)
            if (newSet.has(eventId)) {
                newSet.delete(eventId)
            } else {
                newSet.add(eventId)
            }
            return newSet
        })
    }

    const applyFilters = () => {
        const params = { ...localFilters, page: 1 }
        // Remove empty filters (keep exclude_portal_views as boolean)
        Object.keys(params).forEach(key => {
            if (key !== 'exclude_portal_views' && !params[key]) delete params[key]
        })
        router.get('/app/companies/activity', params, { preserveState: false })
    }

    const clearFilters = () => {
        setLocalFilters({
            event_type: '',
            brand_id: '',
            date_from: '',
            date_to: '',
            exclude_portal_views: true,
        })
        router.get('/app/companies/activity', {}, { preserveState: false })
    }

    const goToPage = (page) => {
        const params = { ...filters, page }
        // Remove empty filters
        Object.keys(params).forEach(key => {
            if (!params[key]) delete params[key]
        })
        router.get('/app/companies/activity', params, { preserveState: false })
    }

    return (
        <div className="min-h-screen flex flex-col bg-gray-50">
            <AppHead title="Activity Log" />
            <AppNav brand={auth.activeBrand} tenant={tenant} />
            <main className="flex-1">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
                    {/* Header */}
                    <div className="mb-6">
                        <h1 className="text-3xl font-bold text-gray-900">Activity Log</h1>
                        <p className="mt-2 text-sm text-gray-600">A running history of important events in your company.</p>
                    </div>

                    <CompanyTabs />

                    {/* Filters */}
                    <div className="mb-6 space-y-4">
                        <div className="flex flex-wrap items-center gap-6">
                            {/* Event Type */}
                            <div className="flex items-center gap-2">
                                <label htmlFor="event_type" className="text-sm font-medium text-slate-600">
                                    Event Type
                                </label>
                                <select
                                    id="event_type"
                                    value={localFilters.event_type}
                                    onChange={(e) => setLocalFilters({ ...localFilters, event_type: e.target.value })}
                                    className="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-sm text-slate-900 shadow-sm focus:border-slate-300 focus:outline-none focus:ring-2 focus:ring-slate-400/20 min-w-[160px]"
                                >
                                    <option value="">All Events</option>
                                    {filter_options?.event_types?.map((type) => (
                                        <option key={type} value={type}>
                                            {formatEventType(type)}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            {/* Brand */}
                            {filter_options?.brands && filter_options.brands.length > 0 && (
                                <div className="flex items-center gap-2">
                                    <label htmlFor="brand_id" className="text-sm font-medium text-slate-600">
                                        Brand
                                    </label>
                                    <select
                                        id="brand_id"
                                        value={localFilters.brand_id}
                                        onChange={(e) => setLocalFilters({ ...localFilters, brand_id: e.target.value })}
                                        className="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-sm text-slate-900 shadow-sm focus:border-slate-300 focus:outline-none focus:ring-2 focus:ring-slate-400/20 min-w-[160px]"
                                    >
                                        <option value="">All Brands</option>
                                        {filter_options.brands.map((brand) => (
                                            <option key={brand.id} value={brand.id}>
                                                {brand.name}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                            )}

                            {/* Date From */}
                            <div className="flex items-center gap-2">
                                <label htmlFor="date_from" className="text-sm font-medium text-slate-600">
                                    From
                                </label>
                                <input
                                    type="date"
                                    id="date_from"
                                    value={localFilters.date_from}
                                    onChange={(e) => setLocalFilters({ ...localFilters, date_from: e.target.value })}
                                    className="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-sm text-slate-900 shadow-sm focus:border-slate-300 focus:outline-none focus:ring-2 focus:ring-slate-400/20"
                                />
                            </div>

                            {/* Date To */}
                            <div className="flex items-center gap-2">
                                <label htmlFor="date_to" className="text-sm font-medium text-slate-600">
                                    To
                                </label>
                                <input
                                    type="date"
                                    id="date_to"
                                    value={localFilters.date_to}
                                    onChange={(e) => setLocalFilters({ ...localFilters, date_to: e.target.value })}
                                    className="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-sm text-slate-900 shadow-sm focus:border-slate-300 focus:outline-none focus:ring-2 focus:ring-slate-400/20"
                                />
                            </div>

                            {/* Exclude portal views */}
                            <div className="flex items-center gap-2">
                                <label className="flex items-center gap-2 cursor-pointer">
                                    <input
                                        type="checkbox"
                                        checked={!!localFilters.exclude_portal_views}
                                        onChange={(e) => setLocalFilters({ ...localFilters, exclude_portal_views: e.target.checked })}
                                        className="rounded border-slate-300 text-indigo-600 focus:ring-slate-400/20"
                                    />
                                    <span className="text-sm font-medium text-slate-600">Exclude portal views</span>
                                </label>
                            </div>

                            {/* Filter Actions */}
                            <div className="flex items-center gap-2">
                                <button
                                    onClick={applyTodayFilter}
                                    className="inline-flex items-center rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 shadow-sm hover:border-slate-300 hover:bg-slate-50"
                                >
                                    Today
                                </button>
                                <button
                                    onClick={applyFilters}
                                    className="inline-flex items-center rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white shadow-sm hover:bg-indigo-500"
                                >
                                    Apply
                                </button>
                                {(localFilters.event_type || localFilters.brand_id || localFilters.date_from || localFilters.date_to || !localFilters.exclude_portal_views) && (
                                    <button
                                        onClick={clearFilters}
                                        className="inline-flex items-center rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 shadow-sm hover:border-slate-300 hover:bg-slate-50"
                                    >
                                        Clear
                                    </button>
                                )}
                            </div>
                        </div>
                    </div>

                    {/* Events Timeline */}
                    <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                        <div className="px-6 py-5 max-w-4xl">
                            {events?.length === 0 ? (
                                <div className="py-10 text-center text-sm text-gray-500">No activity events found</div>
                            ) : (
                                <div className="flow-root">
                                    {groupOrder.map((groupName) => {
                                        const groupEvents = groupedEvents[groupName]
                                        if (!groupEvents?.length) return null
                                        return (
                                            <div key={groupName} className="mb-8 last:mb-0">
                                                <h3 className="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-4">
                                                    {groupName}
                                                </h3>
                                                <ul role="list" className="-mb-8">
                                                    {groupEvents.map((event, idx) => {
                                                        const isExpanded = expandedRows.has(event.id)
                                                        const hasMetadata = event.metadata && Object.keys(event.metadata).length > 0
                                                        const metadataSummary = event.metadata_summary || []
                                                        const rawMetaKeys = ['changed', 'original']
                                                        const displayableMeta = metadataSummary.filter(
                                                            (item) => !rawMetaKeys.includes((item.label || '').toLowerCase())
                                                        )
                                                        const inlineMeta = displayableMeta.slice(0, 3)
                                                        const isLast = idx === groupEvents.length - 1
                                                        const text = buildEventText(event)
                                                        const EventIcon = getEventIcon(event.event_type)

                                                        return (
                                                            <li key={event.id}>
                                                                <div className="relative pb-8">
                                                                    {!isLast && (
                                                                        <span
                                                                            aria-hidden="true"
                                                                            className="absolute left-5 top-5 -ml-px h-full w-0.5 bg-gray-200"
                                                                        />
                                                                    )}

                                                                    <div className="relative flex items-start gap-3">
                                                                        {/* Event type icon + actor avatar - stacked so line aligns through center */}
                                                                        <div className="flex flex-col items-center gap-1.5 shrink-0 w-10">
                                                                            <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-gray-100 text-gray-500">
                                                                                <EventIcon className="h-4 w-4" />
                                                                            </div>
                                                                            <div className="flex h-8 w-8 items-center justify-center rounded-full ring-2 ring-gray-200 overflow-hidden">
                                                                                <Avatar
                                                                                    avatarUrl={event?.actor?.avatar_url}
                                                                                    firstName={event?.actor?.first_name || event?.actor?.name}
                                                                                    lastName={event?.actor?.last_name}
                                                                                    email={event?.actor?.email || event?.actor?.type}
                                                                                    size="sm"
                                                                                />
                                                                            </div>
                                                                        </div>

                                                                        {/* Content */}
                                                                        <div className="min-w-0 flex-1">
                                                                            <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                                                                                <p className="text-sm leading-6 text-gray-900">
                                                                                    <span className="font-semibold">{text.actor}</span>{' '}
                                                                                    <span className="text-gray-600">{text.subtle}</span>{' '}
                                                                                    {text.model ? <span className="font-semibold">{text.model}</span> : null}
                                                                                </p>
                                                                                <p className="shrink-0 text-xs text-gray-500">
                                                                                    {event.created_at_human || ''}
                                                                                </p>
                                                                            </div>

                                                                            {/* Inline metadata preview */}
                                                                            {inlineMeta.length > 0 && (
                                                                                <div className="mt-1.5 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-600">
                                                                                    {inlineMeta.map((item, i) => (
                                                                                        <span key={i}>
                                                                                            <span className="font-medium text-gray-500">{item.label}:</span> {String(item.value)}
                                                                                        </span>
                                                                                    ))}
                                                                                </div>
                                                                            )}

                                                                            {event.brand?.name ? (
                                                                                <div className="mt-1.5 flex flex-wrap items-center gap-2">
                                                                                    <span className="inline-flex items-center rounded-md bg-gray-50 px-2 py-0.5 text-xs text-gray-600 ring-1 ring-inset ring-gray-200">
                                                                                        {event.brand.name}
                                                                                    </span>
                                                                                    {event.subject_url && (
                                                                                        <Link
                                                                                            href={event.subject_url}
                                                                                            className="text-xs font-medium text-indigo-600 hover:text-indigo-700"
                                                                                        >
                                                                                            View →
                                                                                        </Link>
                                                                                    )}
                                                                                </div>
                                                                            ) : event.subject_url ? (
                                                                                <div className="mt-1.5">
                                                                                    <Link
                                                                                        href={event.subject_url}
                                                                                        className="text-xs font-medium text-indigo-600 hover:text-indigo-700"
                                                                                    >
                                                                                        View details →
                                                                                    </Link>
                                                                                </div>
                                                                            ) : null}

                                                                            {hasMetadata ? (
                                                                                <div className="mt-2">
                                                                                    <button
                                                                                        onClick={() => toggleRowExpansion(event.id)}
                                                                                        className="text-xs font-medium text-indigo-600 hover:text-indigo-700"
                                                                                    >
                                                                                        {isExpanded ? 'Hide details' : 'More details'}
                                                                                    </button>
                                                                                </div>
                                                                            ) : null}

                                                                            {isExpanded && hasMetadata ? (
                                                                                <div className="mt-3 rounded-lg border border-gray-200 bg-gray-50 p-4">
                                                                                    <h4 className="text-xs font-semibold text-gray-900">Event details</h4>
                                                                                    {metadataSummary.length > 0 ? (
                                                                                        <dl className="mt-2 space-y-3 text-xs">
                                                                                            {metadataSummary.map((item, i) => {
                                                                                                const val = String(item.value)
                                                                                                const isRawJson = /^[\{\[]/.test(val.trim())
                                                                                                return (
                                                                                                    <div key={i} className="flex flex-col gap-1">
                                                                                                        <dt className="font-medium text-gray-600">{item.label}</dt>
                                                                                                        <dd className="text-gray-900">
                                                                                                            {isRawJson ? (
                                                                                                                <pre className="mt-1 max-h-48 overflow-auto rounded border border-gray-200 bg-white p-3 text-xs whitespace-pre-wrap break-all">
                                                                                                                    {(() => {
                                                                                                                        try {
                                                                                                                            const jsonPart = val.replace(/,?\s*\d{4}-\d{2}-\d{2}.*$/, '').trim()
                                                                                                                            const parsed = JSON.parse(jsonPart)
                                                                                                                            return JSON.stringify(parsed, null, 2)
                                                                                                                        } catch {
                                                                                                                            return val
                                                                                                                        }
                                                                                                                    })()}
                                                                                                                </pre>
                                                                                                            ) : (
                                                                                                                val
                                                                                                            )}
                                                                                                        </dd>
                                                                                                    </div>
                                                                                                )
                                                                                            })}
                                                                                        </dl>
                                                                                    ) : (
                                                                                        <pre className="mt-2 max-h-96 overflow-auto rounded border border-gray-200 bg-white p-3 text-xs">
                                                                                            {JSON.stringify(event.metadata, null, 2)}
                                                                                        </pre>
                                                                                    )}
                                                                                    {event.user_agent ? (
                                                                                        <div className="mt-3 pt-3 border-t border-gray-200 text-xs text-gray-500">
                                                                                            {(() => {
                                                                                                const ua = parseUserAgent(event.user_agent)
                                                                                                const short = [ua.browser, ua.os].filter(Boolean).join(' on ')
                                                                                                return (
                                                                                                    <span>
                                                                                                        <span className="font-medium">Device:</span> {short}
                                                                                                        {ua.device && ua.device !== 'Desktop' ? ` (${ua.device})` : ''}
                                                                                                    </span>
                                                                                                )
                                                                                            })()}
                                                                                        </div>
                                                                                    ) : null}
                                                                                </div>
                                                                            ) : null}
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </li>
                                                        )
                                                    })}
                                                </ul>
                                            </div>
                                        )
                                    })}
                                </div>
                            )}
                        </div>

                        {/* Pagination */}
                        {pagination && pagination.last_page > 1 && (
                            <div className="px-6 py-4 border-t border-gray-200">
                                <div className="flex items-center justify-between">
                                    <div className="text-sm text-gray-700">
                                        Showing <span className="font-medium">{((pagination.current_page - 1) * pagination.per_page) + 1}</span> to{' '}
                                        <span className="font-medium">
                                            {Math.min(pagination.current_page * pagination.per_page, pagination.total)}
                                        </span> of{' '}
                                        <span className="font-medium">{pagination.total}</span> results
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <button
                                            onClick={() => goToPage(pagination.current_page - 1)}
                                            disabled={pagination.current_page === 1}
                                            className="inline-flex items-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
                                        >
                                            Previous
                                        </button>

                                        {/* Page Numbers */}
                                        <div className="flex items-center gap-1">
                                            {Array.from({ length: Math.min(5, pagination.last_page) }, (_, i) => {
                                                let pageNum
                                                if (pagination.last_page <= 5) {
                                                    pageNum = i + 1
                                                } else if (pagination.current_page <= 3) {
                                                    pageNum = i + 1
                                                } else if (pagination.current_page >= pagination.last_page - 2) {
                                                    pageNum = pagination.last_page - 4 + i
                                                } else {
                                                    pageNum = pagination.current_page - 2 + i
                                                }

                                                return (
                                                    <button
                                                        key={pageNum}
                                                        onClick={() => goToPage(pageNum)}
                                                        className={`inline-flex items-center rounded-md px-3 py-2 text-sm font-semibold ${
                                                            pageNum === pagination.current_page
                                                                ? 'bg-indigo-600 text-white'
                                                                : 'bg-white text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50'
                                                        }`}
                                                    >
                                                        {pageNum}
                                                    </button>
                                                )
                                            })}
                                        </div>

                                        <button
                                            onClick={() => goToPage(pagination.current_page + 1)}
                                            disabled={pagination.current_page === pagination.last_page}
                                            className="inline-flex items-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
                                        >
                                            Next
                                        </button>
                                    </div>
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            </main>
            <AppFooter variant="settings" />
        </div>
    )
}
