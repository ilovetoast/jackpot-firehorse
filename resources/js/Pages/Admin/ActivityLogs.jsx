import { Link, router, usePage } from '@inertiajs/react'
import { useState } from 'react'
import AppNav from '../../Components/AppNav'
import AppFooter from '../../Components/AppFooter'
import Avatar from '../../Components/Avatar'
import BrandAvatar from '../../Components/BrandAvatar'

export default function ActivityLogs({ events, pagination, filters, filter_options }) {
    const { auth } = usePage().props
    const [showFilters, setShowFilters] = useState(false)
    const [expandedRows, setExpandedRows] = useState(new Set())
    const [localFilters, setLocalFilters] = useState({
        tenant_id: filters?.tenant_id || '',
        event_type: filters?.event_type || '',
        actor_type: filters?.actor_type || '',
        subject_type: filters?.subject_type || '',
        brand_id: filters?.brand_id || '',
        date_from: filters?.date_from || '',
        date_to: filters?.date_to || '',
        per_page: filters?.per_page || 50,
        search: filters?.search || '',
    })

    const applyFilters = () => {
        router.get('/app/admin/activity-logs', localFilters, {
            preserveState: false,
            preserveScroll: false,
        })
    }

    const clearFilters = () => {
        const cleared = {
            tenant_id: '',
            event_type: '',
            actor_type: '',
            subject_type: '',
            brand_id: '',
            date_from: '',
            date_to: '',
            per_page: 50,
            search: '',
        }
        setLocalFilters(cleared)
        router.get('/app/admin/activity-logs', cleared, {
            preserveState: false,
            preserveScroll: false,
        })
    }

    const formatEventType = (eventType) => {
        return eventType
            .split('.')
            .map(word => word.charAt(0).toUpperCase() + word.slice(1))
            .join(' ')
            .replace(/Tenant/g, 'Company')
    }

    const getEventTypeColor = (eventType) => {
        if (eventType.startsWith('asset.')) return 'bg-blue-100 text-blue-800'
        if (eventType.startsWith('user.')) return 'bg-green-100 text-green-800'
        if (eventType.startsWith('tenant.')) return 'bg-purple-100 text-purple-800'
        if (eventType.startsWith('system.')) return 'bg-red-100 text-red-800'
        if (eventType.startsWith('subscription.')) return 'bg-yellow-100 text-yellow-800'
        return 'bg-gray-100 text-gray-800'
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

    return (
        <div className="min-h-full">
            <AppNav brand={auth.activeBrand} tenant={null} />
            <main className="bg-gray-50">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
                    {/* Header */}
                    <div className="mb-8">
                        <Link href="/app/admin" className="text-sm font-medium text-gray-500 hover:text-gray-700 mb-4 inline-block">
                            ← Back to Admin Dashboard
                        </Link>
                        <div className="flex items-center justify-between mb-4">
                            <div>
                                <h1 className="text-3xl font-bold tracking-tight text-gray-900">Activity Logs</h1>
                                <p className="mt-2 text-sm text-gray-700">View and filter all system activity events</p>
                            </div>
                            <button
                                onClick={() => setShowFilters(!showFilters)}
                                className="inline-flex items-center rounded-md bg-white px-4 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
                            >
                                {showFilters ? 'Hide' : 'Show'} Filters
                            </button>
                        </div>
                        
                        {/* Search Field */}
                        <div className="max-w-md">
                            <label htmlFor="search" className="sr-only">Search</label>
                            <div className="relative">
                                <div className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                                    <svg className="h-5 w-5 text-gray-400" viewBox="0 0 20 20" fill="currentColor">
                                        <path fillRule="evenodd" d="M9 3.5a5.5 5.5 0 100 11 5.5 5.5 0 000-11zM2 9a7 7 0 1112.452 4.391l3.328 3.329a.75.75 0 11-1.06 1.06l-3.329-3.328A7 7 0 012 9z" clipRule="evenodd" />
                                    </svg>
                                </div>
                                <input
                                    id="search"
                                    type="text"
                                    value={localFilters.search}
                                    onChange={(e) => setLocalFilters({ ...localFilters, search: e.target.value })}
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter') {
                                            applyFilters()
                                        }
                                    }}
                                    placeholder="Search events, names, emails, metadata..."
                                    className="block w-full rounded-md border-0 py-1.5 pl-10 pr-3 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                                />
                                {localFilters.search && (
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setLocalFilters({ ...localFilters, search: '' })
                                            applyFilters()
                                        }}
                                        className="absolute inset-y-0 right-0 flex items-center pr-3 text-gray-400 hover:text-gray-600"
                                    >
                                        <svg className="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                                            <path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z" />
                                        </svg>
                                    </button>
                                )}
                            </div>
                            <p className="mt-1 text-xs text-gray-500">
                                Search across event types, company names, brand names, user names/emails, and metadata
                            </p>
                        </div>
                    </div>

                    {/* Filters */}
                    {showFilters && (
                        <div className="mb-6 overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                            <div className="px-6 py-5 border-b border-gray-200">
                                <h2 className="text-lg font-semibold text-gray-900">Filters</h2>
                            </div>
                            <div className="px-6 py-5">
                                <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4">
                                    {/* Tenant Filter */}
                                    <div>
                                        <label className="block text-sm font-medium text-gray-700 mb-2">Tenant</label>
                                        <select
                                            value={localFilters.tenant_id}
                                            onChange={(e) => setLocalFilters({ ...localFilters, tenant_id: e.target.value })}
                                            className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                        >
                                            <option value="">All Tenants</option>
                                            {filter_options?.tenants?.map((tenant) => (
                                                <option key={tenant.id} value={tenant.id}>{tenant.name}</option>
                                            ))}
                                        </select>
                                    </div>

                                    {/* Event Type Filter */}
                                    <div>
                                        <label className="block text-sm font-medium text-gray-700 mb-2">Event Type</label>
                                        <select
                                            value={localFilters.event_type}
                                            onChange={(e) => setLocalFilters({ ...localFilters, event_type: e.target.value })}
                                            className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                        >
                                            <option value="">All Event Types</option>
                                            {filter_options?.event_types?.map((eventType) => (
                                                <option key={eventType} value={eventType}>{formatEventType(eventType)}</option>
                                            ))}
                                        </select>
                                    </div>

                                    {/* Actor Type Filter */}
                                    <div>
                                        <label className="block text-sm font-medium text-gray-700 mb-2">Actor Type</label>
                                        <select
                                            value={localFilters.actor_type}
                                            onChange={(e) => setLocalFilters({ ...localFilters, actor_type: e.target.value })}
                                            className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                        >
                                            <option value="">All Actors</option>
                                            {filter_options?.actor_types?.map((actorType) => (
                                                <option key={actorType} value={actorType}>{actorType.charAt(0).toUpperCase() + actorType.slice(1)}</option>
                                            ))}
                                        </select>
                                    </div>

                                    {/* Brand Filter */}
                                    <div>
                                        <label className="block text-sm font-medium text-gray-700 mb-2">Brand</label>
                                        <select
                                            value={localFilters.brand_id}
                                            onChange={(e) => setLocalFilters({ ...localFilters, brand_id: e.target.value })}
                                            className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                        >
                                            <option value="">All Brands</option>
                                            <option value="null">No Brand</option>
                                            {filter_options?.brands?.map((brand) => (
                                                <option key={brand.id} value={brand.id}>{brand.name}</option>
                                            ))}
                                        </select>
                                    </div>

                                    {/* Subject Type Filter */}
                                    <div>
                                        <label className="block text-sm font-medium text-gray-700 mb-2">Subject Type</label>
                                        <select
                                            value={localFilters.subject_type}
                                            onChange={(e) => setLocalFilters({ ...localFilters, subject_type: e.target.value })}
                                            className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                        >
                                            <option value="">All Subjects</option>
                                            {filter_options?.subject_types?.map((subjectType) => (
                                                <option key={subjectType} value={subjectType}>{subjectType}</option>
                                            ))}
                                        </select>
                                    </div>

                                    {/* Date From */}
                                    <div>
                                        <label className="block text-sm font-medium text-gray-700 mb-2">Date From</label>
                                        <input
                                            type="date"
                                            value={localFilters.date_from}
                                            onChange={(e) => setLocalFilters({ ...localFilters, date_from: e.target.value })}
                                            className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                        />
                                    </div>

                                    {/* Date To */}
                                    <div>
                                        <label className="block text-sm font-medium text-gray-700 mb-2">Date To</label>
                                        <input
                                            type="date"
                                            value={localFilters.date_to}
                                            onChange={(e) => setLocalFilters({ ...localFilters, date_to: e.target.value })}
                                            className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                        />
                                    </div>

                                    {/* Per Page */}
                                    <div>
                                        <label className="block text-sm font-medium text-gray-700 mb-2">Per Page</label>
                                        <select
                                            value={localFilters.per_page}
                                            onChange={(e) => setLocalFilters({ ...localFilters, per_page: e.target.value })}
                                            className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                        >
                                            <option value="25">25</option>
                                            <option value="50">50</option>
                                            <option value="100">100</option>
                                            <option value="200">200</option>
                                        </select>
                                    </div>
                                </div>

                                <div className="mt-6 flex items-center gap-3">
                                    <button
                                        onClick={applyFilters}
                                        className="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                                    >
                                        Apply Filters
                                    </button>
                                    <button
                                        onClick={clearFilters}
                                        className="inline-flex items-center rounded-md bg-white px-4 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
                                    >
                                        Clear Filters
                                    </button>
                                    {pagination?.total && (
                                        <span className="text-sm text-gray-500">
                                            Showing {((pagination.current_page - 1) * pagination.per_page) + 1} - {Math.min(pagination.current_page * pagination.per_page, pagination.total)} of {pagination.total} events
                                        </span>
                                    )}
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Events Table */}
                    <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                        <div className="px-6 py-5 border-b border-gray-200">
                            <h2 className="text-lg font-semibold text-gray-900">Activity Events</h2>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-gray-200">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date & Company</th>
                                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Event Type</th>
                                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Brand</th>
                                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actor</th>
                                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Details</th>
                                    </tr>
                                </thead>
                                <tbody className="bg-white divide-y divide-gray-200">
                                    {events?.length === 0 ? (
                                        <tr>
                                            <td colSpan="5" className="px-4 py-8 text-center text-sm text-gray-500">
                                                No activity events found
                                            </td>
                                        </tr>
                                    ) : (
                                        events?.map((event) => {
                                            const isExpanded = expandedRows.has(event.id)
                                            const hasMetadata = event.metadata && Object.keys(event.metadata).length > 0
                                            
                                            return (
                                                <>
                                                    <tr key={event.id} className="hover:bg-gray-50">
                                                        <td className="px-4 py-4 text-sm">
                                                            <div className="flex items-center gap-2">
                                                                <div className="flex-1 min-w-0">
                                                                    <div 
                                                                        className="text-gray-900 font-medium truncate"
                                                                        title={event.created_at}
                                                                    >
                                                                        {event.tenant?.name || 'N/A'}
                                                                    </div>
                                                                    <div className="text-xs text-gray-500" title={event.created_at}>
                                                                        {event.created_at_human}
                                                                    </div>
                                                                </div>
                                                                {event.tenant?.id && event.tenant?.has_paid_plan && (
                                                                    <a
                                                                        href={`/app/admin/stripe-status?tenant_id=${event.tenant.id}`}
                                                                        target="_blank"
                                                                        rel="noopener noreferrer"
                                                                        className="inline-flex items-center text-indigo-600 hover:text-indigo-900 flex-shrink-0"
                                                                        title="View Stripe info for this tenant"
                                                                    >
                                                                        <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                                                            <path strokeLinecap="round" strokeLinejoin="round" d="M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75M15 10.5a3 3 0 11-6 0 3 3 0 016 0zm3 0h.008v.008H18V10.5zm-12 0h.008v.008H6V10.5z" />
                                                                        </svg>
                                                                    </a>
                                                                )}
                                                            </div>
                                                        </td>
                                                        <td className="px-4 py-4 whitespace-nowrap">
                                                            <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${getEventTypeColor(event.event_type)}`}>
                                                                {formatEventType(event.event_type)}
                                                            </span>
                                                        </td>
                                                        <td className="px-4 py-4 whitespace-nowrap text-sm text-gray-500">
                                                            {event.brand ? (
                                                                <div className="flex items-center gap-2">
                                                                    <BrandAvatar
                                                                        logoPath={event.brand.logo_path}
                                                                        name={event.brand.name}
                                                                        primaryColor={event.brand.primary_color}
                                                                        size="sm"
                                                                    />
                                                                    <span className="truncate max-w-[120px]">{event.brand.name}</span>
                                                                </div>
                                                            ) : (
                                                                '-'
                                                            )}
                                                        </td>
                                                        <td className="px-4 py-4 whitespace-nowrap text-sm text-gray-500 max-w-xs">
                                                            {event.actor ? (
                                                                <div className="flex items-center gap-2 min-w-0">
                                                                    <Avatar
                                                                        avatarUrl={event.actor.avatar_url}
                                                                        firstName={event.actor.first_name}
                                                                        lastName={event.actor.last_name}
                                                                        email={event.actor.email}
                                                                        size="sm"
                                                                    />
                                                                    <div className="min-w-0 flex-1">
                                                                        <div className="flex items-center gap-2">
                                                                            <span className="font-medium truncate">{event.actor.name}</span>
                                                                            {event.actor.is_system_action && (
                                                                                <span className="inline-flex items-center rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-800" title="System action performed by admin">
                                                                                    Admin
                                                                                </span>
                                                                            )}
                                                                        </div>
                                                                        {event.actor.email && (
                                                                            <div className="text-xs text-gray-400 truncate" title={event.actor.email}>{event.actor.email}</div>
                                                                        )}
                                                                    </div>
                                                                </div>
                                                            ) : (
                                                                <div className="flex items-center gap-2">
                                                                    <div className="h-8 w-8 rounded-full bg-gray-200 flex items-center justify-center text-xs font-medium text-gray-600">
                                                                        S
                                                                    </div>
                                                                    <span>System</span>
                                                                </div>
                                                            )}
                                                        </td>
                                                        <td className="px-4 py-4 text-sm text-gray-500">
                                                            {hasMetadata && (
                                                                <button
                                                                    type="button"
                                                                    onClick={() => toggleRowExpansion(event.id)}
                                                                    className="text-indigo-600 hover:text-indigo-900 font-medium text-sm flex items-center gap-1"
                                                                >
                                                                    {isExpanded ? (
                                                                        <>
                                                                            <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                                                                <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                                                                            </svg>
                                                                            Hide
                                                                        </>
                                                                    ) : (
                                                                        <>
                                                                            <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                                                                <path strokeLinecap="round" strokeLinejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                                                                            </svg>
                                                                            Details
                                                                        </>
                                                                    )}
                                                                </button>
                                                            )}
                                                            {!hasMetadata && <span>-</span>}
                                                        </td>
                                                    </tr>
                                                    {isExpanded && hasMetadata && (
                                                        <tr key={`${event.id}-metadata`} className="bg-gray-50">
                                                            <td colSpan="5" className="px-4 py-4">
                                                                <div className="bg-white rounded-lg border border-gray-200 p-4">
                                                                    <h4 className="text-sm font-semibold text-gray-900 mb-3">Event Metadata</h4>
                                                                    <pre className="text-xs bg-gray-50 p-4 rounded overflow-auto max-h-96 border border-gray-200">
                                                                        {JSON.stringify(event.metadata, null, 2)}
                                                                    </pre>
                                                                    <div className="mt-3 space-y-2 text-xs text-gray-500">
                                                                        {event.ip_address && (
                                                                            <div>
                                                                                <span className="font-medium">IP Address:</span> {event.ip_address}
                                                                            </div>
                                                                        )}
                                                                        {event.user_agent && (
                                                                            <div>
                                                                                <span className="font-medium">User Agent:</span> {event.user_agent}
                                                                            </div>
                                                                        )}
                                                                    </div>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                    )}
                                                </>
                                            )
                                        })
                                    )}
                                </tbody>
                            </table>
                        </div>

                        {/* Pagination */}
                        {pagination && pagination.last_page > 1 && (
                            <div className="px-6 py-4 border-t border-gray-200 flex items-center justify-between">
                                <div className="text-sm text-gray-700">
                                    Showing page {pagination.current_page} of {pagination.last_page}
                                </div>
                                <div className="flex gap-2">
                                    {pagination.current_page > 1 && (
                                        <button
                                            onClick={() => {
                                                const newFilters = { ...filters, page: pagination.current_page - 1 }
                                                router.get('/app/admin/activity-logs', newFilters, { preserveState: false })
                                            }}
                                            className="inline-flex items-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
                                        >
                                            Previous
                                        </button>
                                    )}
                                    {pagination.current_page < pagination.last_page && (
                                        <button
                                            onClick={() => {
                                                const newFilters = { ...filters, page: pagination.current_page + 1 }
                                                router.get('/app/admin/activity-logs', newFilters, { preserveState: false })
                                            }}
                                            className="inline-flex items-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
                                        >
                                            Next
                                        </button>
                                    )}
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
