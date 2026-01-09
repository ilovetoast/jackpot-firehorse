import { Link, router, usePage, useForm } from '@inertiajs/react'
import { useState } from 'react'
import AppNav from '../../../../Components/AppNav'
import AppFooter from '../../../../Components/AppFooter'
import Avatar from '../../../../Components/Avatar'
import BrandAvatar from '../../../../Components/BrandAvatar'
import { 
    CreditCardIcon, 
    WrenchScrewdriverIcon, 
    LightBulbIcon, 
    BugAntIcon,
    KeyIcon,
    XMarkIcon,
    PlusIcon,
    SparklesIcon,
} from '@heroicons/react/24/outline'

export default function AdminTicketsIndex({ tickets, pagination, filterOptions, filters }) {
    const { auth } = usePage().props
    const [showCreateModal, setShowCreateModal] = useState(false)
    
    // Check if user can create engineering tickets
    // Handle both array and object formats (array_values ensures it's an array)
    const siteRoles = auth.user?.site_roles ? (Array.isArray(auth.user.site_roles) ? auth.user.site_roles : Object.values(auth.user.site_roles)) : []
    const canCreateEngineering = siteRoles.some(role => ['site_engineering', 'site_admin', 'site_owner'].includes(role))
    
    const { data, setData, post, processing, errors, reset } = useForm({
        subject: '',
        description: '',
        severity: '',
        environment: '',
        component: '',
        error_fingerprint: '',
        tenant_id: '',
    })

    const getStatusBadge = (status) => {
        const statusConfig = {
            open: { label: 'Open', color: 'bg-blue-100 text-blue-800' },
            waiting_on_user: { label: 'Waiting on User', color: 'bg-yellow-100 text-yellow-800' },
            waiting_on_support: { label: 'Waiting on Support', color: 'bg-yellow-100 text-yellow-800' },
            in_progress: { label: 'In Progress', color: 'bg-purple-100 text-purple-800' },
            blocked: { label: 'Blocked', color: 'bg-red-100 text-red-800' },
            resolved: { label: 'Resolved', color: 'bg-green-100 text-green-800' },
            closed: { label: 'Closed', color: 'bg-gray-100 text-gray-800' },
        }

        const config = statusConfig[status] || { label: status, color: 'bg-gray-100 text-gray-800' }
        return (
            <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${config.color}`}>
                {config.label}
            </span>
        )
    }

    const getTypeBadge = (type) => {
        const typeConfig = {
            tenant: { label: 'Tenant', color: 'bg-blue-100 text-blue-800' },
            tenant_internal: { label: 'Tenant Internal', color: 'bg-orange-100 text-orange-800' },
            internal: { label: 'Internal', color: 'bg-purple-100 text-purple-800' },
        }

        const config = typeConfig[type] || { label: type, color: 'bg-gray-100 text-gray-800' }
        return (
            <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${config.color}`}>
                {config.label}
            </span>
        )
    }

    const getSeverityBadge = (severity) => {
        if (!severity) {
            return <span className="text-sm text-gray-400">—</span>
        }

        const severityConfig = {
            P0: { label: 'P0', color: 'bg-red-100 text-red-800', description: 'Critical' },
            P1: { label: 'P1', color: 'bg-orange-100 text-orange-800', description: 'Major' },
            P2: { label: 'P2', color: 'bg-yellow-100 text-yellow-800', description: 'Moderate' },
            P3: { label: 'P3', color: 'bg-gray-100 text-gray-800', description: 'Minor' },
        }

        const config = severityConfig[severity] || { label: severity, color: 'bg-gray-100 text-gray-800', description: severity }
        return (
            <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${config.color}`} title={config.description}>
                {config.label}
            </span>
        )
    }

    const getCategoryIcon = (categoryValue) => {
        const iconConfig = {
            billing: { Icon: CreditCardIcon, color: 'text-green-600' },
            technical_issue: { Icon: WrenchScrewdriverIcon, color: 'text-blue-600' },
            bug: { Icon: BugAntIcon, color: 'text-red-600' },
            feature_request: { Icon: LightBulbIcon, color: 'text-yellow-600' },
            account_access: { Icon: KeyIcon, color: 'text-purple-600' },
        }

        const config = iconConfig[categoryValue] || { Icon: WrenchScrewdriverIcon, color: 'text-gray-600' }
        const { Icon, color } = config
        return <Icon className={`h-4 w-4 ${color}`} />
    }

    const getSLABadge = (slaState) => {
        if (!slaState) return null

        if (slaState.breached_first_response || slaState.breached_resolution) {
            return (
                <span className="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-red-100 text-red-800">
                    Breached
                </span>
            )
        }

        // Check if approaching breach (within 2 hours)
        const now = new Date()
        const firstResponseDeadline = slaState.first_response_deadline ? new Date(slaState.first_response_deadline) : null
        const resolutionDeadline = slaState.resolution_deadline ? new Date(slaState.resolution_deadline) : null

        if (firstResponseDeadline && !slaState.first_response_at) {
            const hoursUntil = (firstResponseDeadline - now) / (1000 * 60 * 60)
            if (hoursUntil > 0 && hoursUntil <= 2) {
                return (
                    <span className="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-yellow-100 text-yellow-800">
                        Approaching
                    </span>
                )
            }
        }

        if (resolutionDeadline && !slaState.resolved_at) {
            const hoursUntil = (resolutionDeadline - now) / (1000 * 60 * 60)
            if (hoursUntil > 0 && hoursUntil <= 2) {
                return (
                    <span className="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-yellow-100 text-yellow-800">
                        Approaching
                    </span>
                )
            }
        }

        return (
            <span className="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-green-100 text-green-800">
                On Track
            </span>
        )
    }

    const applyFilters = (newFilters) => {
        router.get('/app/admin/support/tickets', { ...filters, ...newFilters, page: 1 }, {
            preserveState: true,
            preserveScroll: true,
        })
    }

    const clearFilters = () => {
        router.get('/app/admin/support/tickets', {}, {
            preserveState: true,
            preserveScroll: true,
        })
    }

    const hasActiveFilters = Object.values(filters || {}).some(v => v !== null && v !== undefined && v !== '')

    return (
        <div className="min-h-full bg-gray-50">
            <AppNav brand={auth.activeBrand} tenant={auth.tenant} />
            <main className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
                <div className="mb-6">
                    <div className="mb-4 flex items-center justify-between">
                        <div>
                            <h1 className="text-3xl font-bold tracking-tight text-gray-900">Support Tickets</h1>
                            <p className="mt-2 text-sm text-gray-700">Manage all support tickets across all tenants</p>
                        </div>
                        {canCreateEngineering && (
                            <button
                                onClick={() => setShowCreateModal(true)}
                                className="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                            >
                                <PlusIcon className="h-5 w-5 mr-2" />
                                Create Engineering Ticket
                            </button>
                        )}
                    </div>

                    {/* Compact Horizontal Filters */}
                    <div className="bg-white shadow-sm ring-1 ring-gray-200 rounded-lg p-4">
                        <div className="flex flex-wrap items-center gap-3">
                            {/* Status Filter */}
                            <div className="flex-shrink-0">
                                <select
                                    value={filters?.status || ''}
                                    onChange={(e) => applyFilters({ status: e.target.value || null })}
                                    className="block w-full min-w-[140px] rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm py-1.5"
                                >
                                    <option value="">All Statuses</option>
                                    {filterOptions?.statuses?.map((status) => (
                                        <option key={status.value} value={status.value}>
                                            {status.label}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            {/* Category Filter */}
                            <div className="flex-shrink-0">
                                <select
                                    value={filters?.category || ''}
                                    onChange={(e) => applyFilters({ category: e.target.value || null })}
                                    className="block w-full min-w-[140px] rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm py-1.5"
                                >
                                    <option value="">All Categories</option>
                                    {filterOptions?.categories?.map((category) => (
                                        <option key={category.value} value={category.value}>
                                            {category.label}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            {/* Team Filter */}
                            <div className="flex-shrink-0">
                                <select
                                    value={filters?.assigned_team || ''}
                                    onChange={(e) => applyFilters({ assigned_team: e.target.value || null })}
                                    className="block w-full min-w-[140px] rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm py-1.5"
                                >
                                    <option value="">All Teams</option>
                                    {filterOptions?.teams?.map((team) => (
                                        <option key={team.value} value={team.value}>
                                            {team.label}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            {/* Assigned User Filter */}
                            <div className="flex-shrink-0">
                                <select
                                    value={filters?.assigned_to_user_id || ''}
                                    onChange={(e) => applyFilters({ assigned_to_user_id: e.target.value || null })}
                                    className="block w-full min-w-[180px] rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm py-1.5"
                                >
                                    <option value="">All Users</option>
                                    {filterOptions?.staff_users?.map((user) => (
                                        <option key={user.id} value={user.id}>
                                            {user.first_name} {user.last_name}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            {/* Tenant Filter */}
                            <div className="flex-shrink-0">
                                <select
                                    value={filters?.tenant_id || ''}
                                    onChange={(e) => applyFilters({ tenant_id: e.target.value || null })}
                                    className="block w-full min-w-[180px] rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm py-1.5"
                                >
                                    <option value="">All Tenants</option>
                                    {filterOptions?.tenants?.map((tenant) => (
                                        <option key={tenant.id} value={tenant.id}>
                                            {tenant.name}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            {/* SLA State Filter */}
                            <div className="flex-shrink-0">
                                <select
                                    value={filters?.sla_state || ''}
                                    onChange={(e) => applyFilters({ sla_state: e.target.value || null })}
                                    className="block w-full min-w-[140px] rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm py-1.5"
                                >
                                    <option value="">All SLA States</option>
                                    <option value="approaching_breach">Approaching Breach</option>
                                    <option value="breached">Breached</option>
                                </select>
                            </div>

                            {/* Engineering Filters - Only show if user can create engineering tickets */}
                            {canCreateEngineering && (
                                <>
                                    {/* Severity Filter */}
                                    <div className="flex-shrink-0">
                                        <select
                                            value={filters?.severity || ''}
                                            onChange={(e) => applyFilters({ severity: e.target.value || null })}
                                            className="block w-full min-w-[120px] rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm py-1.5"
                                        >
                                            <option value="">All Severities</option>
                                            {filterOptions?.severities?.map((severity) => (
                                                <option key={severity.value} value={severity.value}>
                                                    {severity.label}
                                                </option>
                                            ))}
                                        </select>
                                    </div>

                                    {/* Environment Filter */}
                                    <div className="flex-shrink-0">
                                        <select
                                            value={filters?.environment || ''}
                                            onChange={(e) => applyFilters({ environment: e.target.value || null })}
                                            className="block w-full min-w-[140px] rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm py-1.5"
                                        >
                                            <option value="">All Environments</option>
                                            {filterOptions?.environments?.map((env) => (
                                                <option key={env.value} value={env.value}>
                                                    {env.label}
                                                </option>
                                            ))}
                                        </select>
                                    </div>

                                    {/* Component Filter */}
                                    <div className="flex-shrink-0">
                                        <select
                                            value={filters?.component || ''}
                                            onChange={(e) => applyFilters({ component: e.target.value || null })}
                                            className="block w-full min-w-[140px] rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm py-1.5"
                                        >
                                            <option value="">All Components</option>
                                            {filterOptions?.components?.map((comp) => (
                                                <option key={comp.value} value={comp.value}>
                                                    {comp.label}
                                                </option>
                                            ))}
                                        </select>
                                    </div>

                                    {/* Engineering Only Filter */}
                                    <div className="flex-shrink-0">
                                        <label className="flex items-center">
                                            <input
                                                type="checkbox"
                                                checked={filters?.engineering_only || false}
                                                onChange={(e) => applyFilters({ engineering_only: e.target.checked || null })}
                                                className="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                            />
                                            <span className="ml-2 text-sm text-gray-700">Engineering Only</span>
                                        </label>
                                    </div>
                                </>
                            )}

                            {/* Sort */}
                            <div className="flex-shrink-0">
                                <select
                                    value={filters?.sort || 'oldest'}
                                    onChange={(e) => applyFilters({ sort: e.target.value })}
                                    className="block w-full min-w-[140px] rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm py-1.5"
                                >
                                    <option value="oldest">Oldest First</option>
                                    <option value="sla_urgency">SLA Urgency</option>
                                </select>
                            </div>

                            {/* Clear Filters Button */}
                            {hasActiveFilters && (
                                <div className="flex-shrink-0 ml-auto">
                                    <button
                                        onClick={clearFilters}
                                        className="inline-flex items-center rounded-md bg-white px-3 py-1.5 text-sm font-medium text-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
                                    >
                                        <XMarkIcon className="h-4 w-4 mr-1.5" />
                                        Clear
                                    </button>
                                </div>
                            )}
                        </div>
                    </div>
                </div>

                {tickets.length === 0 ? (
                    <div className="bg-white shadow-sm ring-1 ring-gray-200 rounded-lg overflow-hidden">
                        <div className="px-6 py-12 text-center">
                            <svg
                                className="mx-auto h-12 w-12 text-gray-400"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                                aria-hidden="true"
                            >
                                <path
                                    strokeLinecap="round"
                                    strokeLinejoin="round"
                                    strokeWidth={2}
                                    d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
                                />
                            </svg>
                            <h3 className="mt-2 text-sm font-semibold text-gray-900">No tickets found</h3>
                            <p className="mt-1 text-sm text-gray-500">Try adjusting your filters.</p>
                        </div>
                    </div>
                ) : (
                    <div className="bg-white shadow-sm ring-1 ring-gray-200 rounded-lg overflow-hidden">
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-gray-200">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th scope="col" className="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Subject
                                        </th>
                                        <th scope="col" className="px-2 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Type
                                        </th>
                                        <th scope="col" className="px-2 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Status
                                        </th>
                                        {canCreateEngineering && (
                                            <th scope="col" className="px-2 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Severity
                                            </th>
                                        )}
                                        <th scope="col" className="px-2 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Category
                                        </th>
                                        <th scope="col" className="px-2 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Tenant
                                        </th>
                                        <th scope="col" className="px-2 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Assigned Team
                                        </th>
                                        <th scope="col" className="px-2 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Assigned User
                                        </th>
                                        <th scope="col" className="px-2 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            SLA Status
                                        </th>
                                        <th scope="col" className="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Created
                                        </th>
                                        <th scope="col" className="relative px-6 py-3">
                                            <span className="sr-only">Actions</span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="bg-white divide-y divide-gray-200">
                                    {tickets.map((ticket) => (
                                        <tr key={ticket.id} className="hover:bg-gray-50">
                                            <td className="px-5 py-4">
                                                <div className="flex items-center gap-2">
                                                    <div className="flex-1">
                                                        <div className="text-sm font-medium text-gray-900">{ticket.subject || '—'}</div>
                                                        <div className="text-xs text-gray-500 mt-0.5">{ticket.ticket_number}</div>
                                                    </div>
                                                    {ticket.has_pending_suggestions && (
                                                        <div className="flex-shrink-0" title="Has pending AI suggestions">
                                                            <SparklesIcon className="h-5 w-5 text-indigo-600" />
                                                        </div>
                                                    )}
                                                </div>
                                            </td>
                                            <td className="px-2 py-4 whitespace-nowrap">
                                                {getTypeBadge(ticket.type)}
                                            </td>
                                            <td className="px-2 py-4 whitespace-nowrap">
                                                {getStatusBadge(ticket.status)}
                                            </td>
                                            {canCreateEngineering && (
                                                <td className="px-2 py-4 whitespace-nowrap">
                                                    {getSeverityBadge(ticket.severity)}
                                                </td>
                                            )}
                                            <td className="px-2 py-4 whitespace-nowrap">
                                                <div className="flex items-center gap-2">
                                                    {ticket.category_value && getCategoryIcon(ticket.category_value)}
                                                    <span className="text-sm text-gray-900">{ticket.category || '—'}</span>
                                                </div>
                                            </td>
                                            <td className="px-2 py-4">
                                                {ticket.tenant ? (
                                                    <div className="relative group">
                                                        <span className="text-sm text-gray-900 block truncate max-w-[150px]">
                                                            {ticket.tenant.name}
                                                        </span>
                                                        {ticket.tenant.name.length > 20 && (
                                                            <span className="absolute z-10 invisible group-hover:visible bg-gray-800 text-white text-xs rounded py-1 px-2 left-0 top-full mt-1 whitespace-nowrap">
                                                                {ticket.tenant.name}
                                                            </span>
                                                        )}
                                                    </div>
                                                ) : (
                                                    <span className="text-sm text-gray-500">—</span>
                                                )}
                                            </td>
                                            <td className="px-2 py-4 whitespace-nowrap">
                                                <span className="text-sm text-gray-900">{ticket.assigned_team ? ticket.assigned_team.charAt(0).toUpperCase() + ticket.assigned_team.slice(1) : '—'}</span>
                                            </td>
                                            <td className="px-2 py-4 whitespace-nowrap">
                                                {ticket.assigned_to ? (
                                                    <span className="text-sm text-gray-900">{ticket.assigned_to.name}</span>
                                                ) : (
                                                    <span className="text-sm text-gray-500">—</span>
                                                )}
                                            </td>
                                            <td className="px-2 py-4 whitespace-nowrap">
                                                {getSLABadge(ticket.sla_state)}
                                            </td>
                                            <td className="px-2 py-4 whitespace-nowrap">
                                                <div className="text-sm text-gray-500">{new Date(ticket.created_at).toLocaleDateString()}</div>
                                            </td>
                                            <td className="px-5 py-4 whitespace-nowrap text-right text-sm font-medium">
                                                <Link
                                                    href={`/app/admin/support/tickets/${ticket.id}`}
                                                    className="text-indigo-600 hover:text-indigo-900"
                                                >
                                                    View
                                                </Link>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        {/* Pagination */}
                        {pagination?.last_page > 1 && (
                            <div className="bg-white px-4 py-3 border-t border-gray-200 sm:px-6">
                                <div className="flex items-center justify-between">
                                    <div className="flex-1 flex justify-between sm:hidden">
                                        {pagination.current_page > 1 && (
                                            <Link
                                                href={pagination.links[pagination.current_page - 1]?.url || '#'}
                                                className="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50"
                                                preserveScroll
                                            >
                                                Previous
                                            </Link>
                                        )}
                                        {pagination.current_page < pagination.last_page && (
                                            <Link
                                                href={pagination.links[pagination.current_page + 1]?.url || '#'}
                                                className="ml-3 relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50"
                                                preserveScroll
                                            >
                                                Next
                                            </Link>
                                        )}
                                    </div>
                                    <div className="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
                                        <div>
                                            <p className="text-sm text-gray-700">
                                                Showing <span className="font-medium">{(pagination.current_page - 1) * pagination.per_page + 1}</span> to{' '}
                                                <span className="font-medium">
                                                    {Math.min(pagination.current_page * pagination.per_page, pagination.total)}
                                                </span>{' '}
                                                of <span className="font-medium">{pagination.total}</span> results
                                            </p>
                                        </div>
                                        <div>
                                            <nav className="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                                                {pagination.links?.map((link, index) => {
                                                    if (index === 0 || index === pagination.links.length - 1) {
                                                        return (
                                                            <Link
                                                                key={index}
                                                                href={link.url || '#'}
                                                                className={`relative inline-flex items-center px-2 py-2 border border-gray-300 bg-white text-sm font-medium ${
                                                                    link.active
                                                                        ? 'z-10 bg-indigo-50 border-indigo-500 text-indigo-600'
                                                                        : 'text-gray-500 hover:bg-gray-50'
                                                                } ${index === 0 ? 'rounded-l-md' : 'rounded-r-md'}`}
                                                                preserveScroll
                                                                dangerouslySetInnerHTML={{ __html: link.label }}
                                                            />
                                                        )
                                                    }
                                                    return null
                                                })}
                                            </nav>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        )}
                    </div>
                )}
            </main>
            <AppFooter />
            
            {/* Create Engineering Ticket Modal */}
            {showCreateModal && (
                <div className="fixed inset-0 z-50 overflow-y-auto">
                    <div className="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                        <div className="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" onClick={() => setShowCreateModal(false)}></div>
                        
                        <div className="relative transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-2xl sm:p-6">
                            <div className="absolute right-0 top-0 hidden pr-4 pt-4 sm:block">
                                <button
                                    type="button"
                                    className="rounded-md bg-white text-gray-400 hover:text-gray-500"
                                    onClick={() => {
                                        setShowCreateModal(false)
                                        reset()
                                    }}
                                >
                                    <XMarkIcon className="h-6 w-6" />
                                </button>
                            </div>
                            
                            <div className="sm:flex sm:items-start">
                                <div className="mt-3 text-center sm:ml-4 sm:mt-0 sm:text-left w-full">
                                    <h3 className="text-base font-semibold leading-6 text-gray-900 mb-4">
                                        Create Internal Engineering Ticket
                                    </h3>
                                    
                                    <form onSubmit={(e) => {
                                        e.preventDefault()
                                        post('/app/admin/support/tickets/engineering/create', {
                                            preserveScroll: true,
                                            onSuccess: () => {
                                                setShowCreateModal(false)
                                                reset()
                                            },
                                        })
                                    }} className="space-y-4">
                                        <div>
                                            <label htmlFor="subject" className="block text-sm font-medium text-gray-700">
                                                Subject <span className="text-red-500">*</span>
                                            </label>
                                            <input
                                                type="text"
                                                id="subject"
                                                value={data.subject}
                                                onChange={(e) => setData('subject', e.target.value)}
                                                className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                                required
                                            />
                                            {errors.subject && <p className="mt-1 text-sm text-red-600">{errors.subject}</p>}
                                        </div>
                                        
                                        <div>
                                            <label htmlFor="description" className="block text-sm font-medium text-gray-700">
                                                Description <span className="text-red-500">*</span>
                                            </label>
                                            <textarea
                                                id="description"
                                                rows={4}
                                                value={data.description}
                                                onChange={(e) => setData('description', e.target.value)}
                                                className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                                required
                                            />
                                            {errors.description && <p className="mt-1 text-sm text-red-600">{errors.description}</p>}
                                        </div>
                                        
                                        <div className="grid grid-cols-3 gap-4">
                                            <div>
                                                <label htmlFor="severity" className="block text-sm font-medium text-gray-700">
                                                    Severity
                                                </label>
                                                <select
                                                    id="severity"
                                                    value={data.severity}
                                                    onChange={(e) => setData('severity', e.target.value)}
                                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                                >
                                                    <option value="">Select...</option>
                                                    {filterOptions?.severities?.map((severity) => (
                                                        <option key={severity.value} value={severity.value}>
                                                            {severity.label}
                                                        </option>
                                                    ))}
                                                </select>
                                                {errors.severity && <p className="mt-1 text-sm text-red-600">{errors.severity}</p>}
                                            </div>
                                            
                                            <div>
                                                <label htmlFor="environment" className="block text-sm font-medium text-gray-700">
                                                    Environment
                                                </label>
                                                <select
                                                    id="environment"
                                                    value={data.environment}
                                                    onChange={(e) => setData('environment', e.target.value)}
                                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                                >
                                                    <option value="">Select...</option>
                                                    {filterOptions?.environments?.map((env) => (
                                                        <option key={env.value} value={env.value}>
                                                            {env.label}
                                                        </option>
                                                    ))}
                                                </select>
                                                {errors.environment && <p className="mt-1 text-sm text-red-600">{errors.environment}</p>}
                                            </div>
                                            
                                            <div>
                                                <label htmlFor="component" className="block text-sm font-medium text-gray-700">
                                                    Component
                                                </label>
                                                <select
                                                    id="component"
                                                    value={data.component}
                                                    onChange={(e) => setData('component', e.target.value)}
                                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                                >
                                                    <option value="">Select...</option>
                                                    {filterOptions?.components?.map((comp) => (
                                                        <option key={comp.value} value={comp.value}>
                                                            {comp.label}
                                                        </option>
                                                    ))}
                                                </select>
                                                {errors.component && <p className="mt-1 text-sm text-red-600">{errors.component}</p>}
                                            </div>
                                        </div>
                                        
                                        <div>
                                            <label htmlFor="error_fingerprint" className="block text-sm font-medium text-gray-700">
                                                Error Fingerprint (Optional)
                                            </label>
                                            <input
                                                type="text"
                                                id="error_fingerprint"
                                                value={data.error_fingerprint}
                                                onChange={(e) => setData('error_fingerprint', e.target.value)}
                                                className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                                placeholder="e.g., hash or identifier"
                                            />
                                            {errors.error_fingerprint && <p className="mt-1 text-sm text-red-600">{errors.error_fingerprint}</p>}
                                        </div>
                                        
                                        <div>
                                            <label htmlFor="tenant_id" className="block text-sm font-medium text-gray-700">
                                                Tenant (Optional)
                                            </label>
                                            <select
                                                id="tenant_id"
                                                value={data.tenant_id}
                                                onChange={(e) => setData('tenant_id', e.target.value)}
                                                className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                            >
                                                <option value="">No Tenant</option>
                                                {filterOptions?.tenants?.map((tenant) => (
                                                    <option key={tenant.id} value={tenant.id}>
                                                        {tenant.name}
                                                    </option>
                                                ))}
                                            </select>
                                            {errors.tenant_id && <p className="mt-1 text-sm text-red-600">{errors.tenant_id}</p>}
                                        </div>
                                        
                                        <div className="mt-5 sm:mt-4 sm:flex sm:flex-row-reverse">
                                            <button
                                                type="submit"
                                                disabled={processing}
                                                className="inline-flex w-full justify-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 sm:ml-3 sm:w-auto disabled:opacity-50"
                                            >
                                                {processing ? 'Creating...' : 'Create Ticket'}
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => {
                                                    setShowCreateModal(false)
                                                    reset()
                                                }}
                                                className="mt-3 inline-flex w-full justify-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 sm:mt-0 sm:w-auto"
                                            >
                                                Cancel
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            )}
        </div>
    )
}
