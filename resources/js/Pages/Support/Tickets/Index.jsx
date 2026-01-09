import { Link, usePage } from '@inertiajs/react'
import AppNav from '../../../Components/AppNav'
import AppFooter from '../../../Components/AppFooter'
import Avatar from '../../../Components/Avatar'
import BrandAvatar from '../../../Components/BrandAvatar'
import { 
    CreditCardIcon, 
    WrenchScrewdriverIcon, 
    LightBulbIcon, 
    BugAntIcon,
    KeyIcon,
} from '@heroicons/react/24/outline'

export default function TicketsIndex({ tickets, pagination }) {
    const { auth } = usePage().props

    const getStatusBadge = (status) => {
        const statusConfig = {
            open: { label: 'Open', color: 'bg-blue-100 text-blue-800' },
            waiting_on_support: { label: 'Waiting on Support', color: 'bg-yellow-100 text-yellow-800' },
            in_progress: { label: 'In Progress', color: 'bg-purple-100 text-purple-800' },
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

    return (
        <div className="min-h-full bg-gray-50">
            <AppNav brand={auth.activeBrand} tenant={auth.tenant} />
            <main className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
                <div className="mb-8 flex items-center justify-between">
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight text-gray-900">Support Tickets</h1>
                        <p className="mt-2 text-sm text-gray-700">View and manage your support requests</p>
                    </div>
                    <Link
                        href="/app/support/tickets/create"
                        className="inline-flex items-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                    >
                        Create Ticket
                    </Link>
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
                            <h3 className="mt-2 text-sm font-semibold text-gray-900">No tickets</h3>
                            <p className="mt-1 text-sm text-gray-500">Get started by creating a new support ticket.</p>
                            <div className="mt-6">
                                <Link
                                    href="/app/support/tickets/create"
                                    className="inline-flex items-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                                >
                                    Create Ticket
                                </Link>
                            </div>
                        </div>
                    </div>
                ) : (
                    <div className="bg-white shadow-sm ring-1 ring-gray-200 rounded-lg overflow-hidden">
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-gray-200">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Subject
                                        </th>
                                        <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Status
                                        </th>
                                        <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Category
                                        </th>
                                        <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Created By
                                        </th>
                                        <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Brands
                                        </th>
                                        <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Created
                                        </th>
                                        <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Updated
                                        </th>
                                        <th scope="col" className="relative px-6 py-3">
                                            <span className="sr-only">Actions</span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="bg-white divide-y divide-gray-200">
                                    {tickets.map((ticket) => (
                                        <tr key={ticket.id} className="hover:bg-gray-50">
                                            <td className="px-6 py-4">
                                                <div className="text-sm font-medium text-gray-900">{ticket.subject || '—'}</div>
                                                <div className="text-xs text-gray-500 mt-0.5">{ticket.ticket_number}</div>
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap">
                                                {getStatusBadge(ticket.status)}
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap">
                                                <div className="flex items-center gap-2">
                                                    {ticket.category_value && getCategoryIcon(ticket.category_value)}
                                                    <span className="text-sm text-gray-900">{ticket.category || '—'}</span>
                                                </div>
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap">
                                                {ticket.created_by ? (
                                                    <div className="flex items-center gap-2">
                                                        <Avatar
                                                            avatarUrl={ticket.created_by.avatar_url}
                                                            firstName={ticket.created_by.first_name}
                                                            lastName={ticket.created_by.last_name}
                                                            email={ticket.created_by.email}
                                                            size="sm"
                                                        />
                                                        <span className="text-sm text-gray-900">{ticket.created_by.name}</span>
                                                    </div>
                                                ) : (
                                                    <span className="text-sm text-gray-500">—</span>
                                                )}
                                            </td>
                                            <td className="px-6 py-4">
                                                <div className="flex items-center gap-2 flex-wrap">
                                                    {ticket.brands.length > 0 ? (
                                                        ticket.brands.map((brand) => (
                                                            <div key={brand.id} className="flex items-center" title={brand.name}>
                                                                <BrandAvatar
                                                                    logoPath={brand.logo_path}
                                                                    iconPath={brand.icon_path}
                                                                    name={brand.name}
                                                                    primaryColor={brand.primary_color}
                                                                    icon={brand.icon}
                                                                    iconBgColor={brand.icon_bg_color}
                                                                    showIcon={true}
                                                                    size="sm"
                                                                />
                                                            </div>
                                                        ))
                                                    ) : (
                                                        <span className="text-sm text-gray-500">—</span>
                                                    )}
                                                </div>
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap">
                                                <div className="text-sm text-gray-500">{ticket.created_at}</div>
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap">
                                                <div className="text-sm text-gray-500">{ticket.updated_at}</div>
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                                <Link
                                                    href={`/app/support/tickets/${ticket.id}`}
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
                        {pagination.last_page > 1 && (
                            <div className="bg-white px-4 py-3 border-t border-gray-200 sm:px-6">
                                <div className="flex items-center justify-between">
                                    <div className="flex-1 flex justify-between sm:hidden">
                                        {pagination.current_page > 1 && (
                                            <Link
                                                href={`/app/support/tickets?page=${pagination.current_page - 1}`}
                                                className="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50"
                                                preserveScroll
                                            >
                                                Previous
                                            </Link>
                                        )}
                                        {pagination.current_page < pagination.last_page && (
                                            <Link
                                                href={`/app/support/tickets?page=${pagination.current_page + 1}`}
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
                                                {pagination.current_page > 1 && (
                                                    <Link
                                                        href={`/app/support/tickets?page=${pagination.current_page - 1}`}
                                                        className="relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50"
                                                        preserveScroll
                                                    >
                                                        Previous
                                                    </Link>
                                                )}
                                                {pagination.current_page < pagination.last_page && (
                                                    <Link
                                                        href={`/app/support/tickets?page=${pagination.current_page + 1}`}
                                                        className="relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50"
                                                        preserveScroll
                                                    >
                                                        Next
                                                    </Link>
                                                )}
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
        </div>
    )
}
