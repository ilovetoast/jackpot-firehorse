import { router, usePage } from '@inertiajs/react'
import { useState, useEffect } from 'react'
import AppNav from '../../Components/AppNav'
import AppHead from '../../Components/AppHead'
import AppFooter from '../../Components/AppFooter'
import Avatar from '../../Components/Avatar'

export default function CompanyActivity({ tenant, events, pagination, filters, filter_options }) {
    const { auth } = usePage().props
    const [expandedRows, setExpandedRows] = useState(new Set())
    const [localFilters, setLocalFilters] = useState({
        event_type: filters?.event_type || '',
        brand_id: filters?.brand_id || '',
        date_from: filters?.date_from || '',
        date_to: filters?.date_to || '',
    })

    // Sync local filters when filters prop changes
    useEffect(() => {
        setLocalFilters({
            event_type: filters?.event_type || '',
            brand_id: filters?.brand_id || '',
            date_from: filters?.date_from || '',
            date_to: filters?.date_to || '',
        })
    }, [filters])

    const formatEventType = (eventType) => {
        return eventType
            .split('.')
            .map(word => word.charAt(0).toUpperCase() + word.slice(1))
            .join(' ')
            .replace(/Tenant/g, 'Company')
    }

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
        // Remove empty filters
        Object.keys(params).forEach(key => {
            if (!params[key]) delete params[key]
        })
        router.get('/app/companies/activity', params, { preserveState: false })
    }

    const clearFilters = () => {
        setLocalFilters({
            event_type: '',
            brand_id: '',
            date_from: '',
            date_to: '',
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
        <div className="min-h-full">
            <AppHead title="Activity Log" />
            <AppNav brand={auth.activeBrand} tenant={tenant} />
            <main className="bg-gray-50">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
                    {/* Header */}
                    <div className="mb-6">
                        <h1 className="text-3xl font-bold tracking-tight text-gray-900">Activity Log</h1>
                        <p className="mt-2 text-sm text-gray-700">A running history of important events in your company.</p>
                    </div>

                    {/* Filters */}
                    <div className="mb-6 rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200">
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            {/* Event Type Filter */}
                            <div>
                                <label htmlFor="event_type" className="block text-xs font-medium text-gray-700 mb-1">
                                    Event Type
                                </label>
                                <select
                                    id="event_type"
                                    value={localFilters.event_type}
                                    onChange={(e) => setLocalFilters({ ...localFilters, event_type: e.target.value })}
                                    className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                                >
                                    <option value="">All Events</option>
                                    {filter_options?.event_types?.map((type) => (
                                        <option key={type} value={type}>
                                            {formatEventType(type)}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            {/* Brand Filter */}
                            {filter_options?.brands && filter_options.brands.length > 0 && (
                                <div>
                                    <label htmlFor="brand_id" className="block text-xs font-medium text-gray-700 mb-1">
                                        Brand
                                    </label>
                                    <select
                                        id="brand_id"
                                        value={localFilters.brand_id}
                                        onChange={(e) => setLocalFilters({ ...localFilters, brand_id: e.target.value })}
                                        className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
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
                            <div>
                                <label htmlFor="date_from" className="block text-xs font-medium text-gray-700 mb-1">
                                    From Date
                                </label>
                                <input
                                    type="date"
                                    id="date_from"
                                    value={localFilters.date_from}
                                    onChange={(e) => setLocalFilters({ ...localFilters, date_from: e.target.value })}
                                    className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                                />
                            </div>

                            {/* Date To */}
                            <div>
                                <label htmlFor="date_to" className="block text-xs font-medium text-gray-700 mb-1">
                                    To Date
                                </label>
                                <input
                                    type="date"
                                    id="date_to"
                                    value={localFilters.date_to}
                                    onChange={(e) => setLocalFilters({ ...localFilters, date_to: e.target.value })}
                                    className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                                />
                            </div>
                        </div>

                        {/* Filter Actions */}
                        <div className="mt-4 flex gap-2">
                            <button
                                onClick={applyFilters}
                                className="inline-flex items-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                            >
                                Apply Filters
                            </button>
                            {(localFilters.event_type || localFilters.brand_id || localFilters.date_from || localFilters.date_to) && (
                                <button
                                    onClick={clearFilters}
                                    className="inline-flex items-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
                                >
                                    Clear
                                </button>
                            )}
                        </div>
                    </div>

                    {/* Events Timeline */}
                    <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                        <div className="px-6 py-5">
                            {events?.length === 0 ? (
                                <div className="py-10 text-center text-sm text-gray-500">No activity events found</div>
                            ) : (
                                <div className="flow-root">
                                    <ul role="list" className="-mb-8">
                                        {events?.map((event, idx) => {
                                            const isExpanded = expandedRows.has(event.id)
                                            const hasMetadata = event.metadata && Object.keys(event.metadata).length > 0
                                            const isLast = idx === events.length - 1
                                            const text = buildEventText(event)

                                            return (
                                                <li key={event.id}>
                                                    <div className="relative pb-8">
                                                        {!isLast && (
                                                            <span
                                                                aria-hidden="true"
                                                                className="absolute left-5 top-5 -ml-px h-full w-0.5 bg-gray-200"
                                                            />
                                                        )}

                                                        <div className="relative flex items-start space-x-3">
                                                            {/* Timeline dot / actor icon */}
                                                            <div className="relative">
                                                                <div className="flex h-10 w-10 items-center justify-center rounded-full bg-white ring-2 ring-gray-200">
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
                                                                <div className="flex items-start justify-between gap-4">
                                                                    <p className="text-sm leading-6 text-gray-900">
                                                                        <span className="font-semibold">{text.actor}</span>{' '}
                                                                        <span className="text-gray-600">{text.subtle}</span>{' '}
                                                                        {text.model ? <span className="font-semibold">{text.model}</span> : null}
                                                                    </p>
                                                                    <p className="shrink-0 whitespace-nowrap text-xs text-gray-500">
                                                                        {event.created_at_human || ''}
                                                                    </p>
                                                                </div>

                                                                {event.brand?.name ? (
                                                                    <div className="mt-1">
                                                                        <span className="inline-flex items-center rounded-md bg-gray-50 px-2 py-1 text-xs text-gray-600 ring-1 ring-inset ring-gray-200">
                                                                            {event.brand.name}
                                                                        </span>
                                                                    </div>
                                                                ) : null}

                                                                {hasMetadata ? (
                                                                    <div className="mt-2">
                                                                        <button
                                                                            onClick={() => toggleRowExpansion(event.id)}
                                                                            className="text-xs font-medium text-indigo-600 hover:text-indigo-700"
                                                                        >
                                                                            {isExpanded ? 'Hide details' : 'View details'}
                                                                        </button>
                                                                    </div>
                                                                ) : null}

                                                                {isExpanded && hasMetadata ? (
                                                                    <div className="mt-3 rounded-lg border border-gray-200 bg-gray-50 p-4">
                                                                        <h4 className="text-xs font-semibold text-gray-900">Event details</h4>
                                                                        <pre className="mt-2 max-h-96 overflow-auto rounded border border-gray-200 bg-white p-3 text-xs">
                                                                            {JSON.stringify(event.metadata, null, 2)}
                                                                        </pre>
                                                                        {event.user_agent ? (
                                                                            <div className="mt-3 text-xs text-gray-500">
                                                                                <span className="font-medium">User Agent:</span> {event.user_agent}
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
            <AppFooter />
        </div>
    )
}
