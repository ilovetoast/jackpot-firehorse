import { Link, router, usePage } from '@inertiajs/react'
import { useState } from 'react'
import AppNav from '../../Components/AppNav'
import AppFooter from '../../Components/AppFooter'
import Avatar from '../../Components/Avatar'

export default function CompanyActivity({ tenant, events, pagination, filters, filter_options }) {
    const { auth } = usePage().props
    const [expandedRows, setExpandedRows] = useState(new Set())

    const formatEventType = (eventType) => {
        return eventType
            .split('.')
            .map(word => word.charAt(0).toUpperCase() + word.slice(1))
            .join(' ')
            .replace(/Tenant/g, 'Company')
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
            <AppNav brand={auth.activeBrand} tenant={tenant} />
            <main className="bg-gray-50">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
                    {/* Header */}
                    <div className="mb-6">
                        <h1 className="text-2xl font-semibold text-gray-900">Activity Log</h1>
                    </div>

                    {/* Events Table */}
                    <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-gray-200">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Event</th>
                                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actor</th>
                                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Details</th>
                                    </tr>
                                </thead>
                                <tbody className="bg-white divide-y divide-gray-200">
                                    {events?.length === 0 ? (
                                        <tr>
                                            <td colSpan="4" className="px-6 py-8 text-center text-sm text-gray-500">
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
                                                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                            {new Date(event.created_at).toLocaleDateString()}
                                                        </td>
                                                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                            {event.description || formatEventType(event.event_type)}
                                                        </td>
                                                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500 max-w-xs">
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
                                                                        <div className="truncate">{event.actor.name}</div>
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
                                                        <td className="px-6 py-4 text-sm text-gray-500">
                                                            {hasMetadata ? (
                                                                <button
                                                                    onClick={() => toggleRowExpansion(event.id)}
                                                                    className="text-indigo-600 hover:text-indigo-900 text-sm"
                                                                >
                                                                    {isExpanded ? 'Hide Metadata' : 'View Metadata'}
                                                                </button>
                                                            ) : (
                                                                '-'
                                                            )}
                                                        </td>
                                                    </tr>
                                                    {isExpanded && hasMetadata && (
                                                        <tr key={`${event.id}-metadata`} className="bg-gray-50">
                                                            <td colSpan="4" className="px-6 py-4">
                                                                <div className="bg-white rounded-lg border border-gray-200 p-4">
                                                                    <h4 className="text-sm font-semibold text-gray-900 mb-3">Event Metadata</h4>
                                                                    <pre className="text-xs bg-gray-50 p-4 rounded overflow-auto max-h-96 border border-gray-200">
                                                                        {JSON.stringify(event.metadata, null, 2)}
                                                                    </pre>
                                                                    {event.user_agent && (
                                                                        <div className="mt-3 text-xs text-gray-500">
                                                                            <span className="font-medium">User Agent:</span> {event.user_agent}
                                                                        </div>
                                                                    )}
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
                                                router.get('/app/companies/activity', newFilters, { preserveState: false })
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
                                                router.get('/app/companies/activity', newFilters, { preserveState: false })
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
