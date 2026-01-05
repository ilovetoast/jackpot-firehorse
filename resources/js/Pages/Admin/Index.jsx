import { useState } from 'react'
import { Link, usePage } from '@inertiajs/react'
import AppNav from '../../Components/AppNav'
import AppFooter from '../../Components/AppFooter'

export default function AdminIndex({ companies, users, stats }) {
    const { auth } = usePage().props
    const [activeTab, setActiveTab] = useState('companies')

    const adminTools = [
        { name: 'Notifications', icon: BellIcon, description: 'Manage email templates', href: '#' },
        { name: 'Activity Logs', icon: DocumentTextIcon, description: 'View system activity', href: '#' },
        { name: 'Support', icon: QuestionMarkCircleIcon, description: 'Manage support tickets', href: '#' },
        { name: 'System Status', icon: CogIcon, description: 'Monitor system health', href: '#' },
        { name: 'AI Agents', icon: BoltIcon, description: 'Manage AI agents', href: '#' },
        { name: 'Documentation', icon: BookOpenIcon, description: 'View system docs', href: '#' },
        { name: 'Stripe Status', icon: CreditCardIcon, description: 'Check Stripe connection', href: '/app/admin/stripe-status' },
        { name: 'Test Email', icon: EnvelopeIcon, description: 'Send test email', href: '#' },
        { name: 'Permissions', icon: LockClosedIcon, description: 'Manage role permissions', href: '/app/admin/permissions' },
        { name: 'System Categories', icon: FolderIcon, description: 'Manage system category templates', href: '/app/admin/system-categories' },
    ]

    const summaryCards = [
        { name: 'Total Companies', value: stats.total_companies, subtitle: `${stats.total_companies} with Stripe accounts`, icon: BuildingOfficeIcon },
        { name: 'Total Users', value: stats.total_users, subtitle: 'Across all companies', icon: UsersIcon },
        { name: 'Active Subscriptions', value: stats.active_subscriptions || 0, subtitle: 'Currently active', icon: DocumentIcon },
        { name: 'Stripe Accounts', value: stats.stripe_accounts || 0, subtitle: 'Connected to Stripe', icon: ChartBarIcon },
        { name: 'Support Tickets', value: stats.support_tickets || 0, subtitle: '0 waiting on support', icon: QuestionMarkCircleIcon },
    ]

    return (
        <div className="min-h-full">
            <AppNav brand={auth.activeBrand} tenant={null} />
            <main className="bg-gray-50">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
                    {/* Header */}
                    <div className="mb-8">
                        <h1 className="text-3xl font-bold tracking-tight text-gray-900">Admin Dashboard</h1>
                        <p className="mt-2 text-sm text-gray-700">Platform administration and management</p>
                    </div>

                    {/* Admin Tools */}
                    <div className="mb-8">
                        <h2 className="text-lg font-semibold text-gray-900 mb-4">Admin Tools</h2>
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            {adminTools.map((tool) => {
                                const IconComponent = tool.icon
                                return (
                                    <Link
                                        key={tool.name}
                                        href={tool.href}
                                        className="block rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200 hover:ring-indigo-500 transition-colors"
                                    >
                                        <div className="flex items-center">
                                            <IconComponent className="h-6 w-6 text-gray-400 mr-3 flex-shrink-0" aria-hidden="true" />
                                            <div>
                                                <h3 className="text-sm font-semibold text-gray-900">{tool.name}</h3>
                                                <p className="mt-1 text-xs text-gray-500">{tool.description}</p>
                                            </div>
                                        </div>
                                    </Link>
                                )
                            })}
                        </div>
                    </div>

                    {/* Summary Statistics */}
                    <div className="mb-8">
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
                            {summaryCards.map((card) => {
                                const IconComponent = card.icon
                                return (
                                    <div key={card.name} className="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                                        <div className="flex items-center">
                                            <IconComponent className="h-6 w-6 text-gray-400 mr-3 flex-shrink-0" aria-hidden="true" />
                                            <div className="flex-1">
                                                <p className="text-sm font-medium text-gray-500">{card.name}</p>
                                                <p className="mt-1 text-2xl font-semibold text-gray-900">{card.value}</p>
                                                <p className="mt-1 text-xs text-gray-500">{card.subtitle}</p>
                                            </div>
                                        </div>
                                    </div>
                                )
                            })}
                        </div>
                    </div>

                    {/* Tabs Section */}
                    <div className="rounded-lg bg-white shadow-sm ring-1 ring-gray-200 mb-6">
                        <div className="border-b border-gray-200">
                            <nav className="-mb-px flex space-x-8 px-6" aria-label="Tabs">
                                <button
                                    onClick={() => setActiveTab('companies')}
                                    className={`
                                        ${activeTab === 'companies'
                                            ? 'border-indigo-500 text-indigo-600'
                                            : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
                                        }
                                        whitespace-nowrap border-b-2 py-4 px-1 text-sm font-medium
                                    `}
                                >
                                    Companies
                                    <span className="ml-2 bg-gray-100 text-gray-900 py-0.5 px-2.5 rounded-full text-xs font-medium">
                                        {companies.length}
                                    </span>
                                </button>
                                <button
                                    onClick={() => setActiveTab('users')}
                                    className={`
                                        ${activeTab === 'users'
                                            ? 'border-indigo-500 text-indigo-600'
                                            : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
                                        }
                                        whitespace-nowrap border-b-2 py-4 px-1 text-sm font-medium
                                    `}
                                >
                                    Users
                                    <span className="ml-2 bg-gray-100 text-gray-900 py-0.5 px-2.5 rounded-full text-xs font-medium">
                                        {users.length}
                                    </span>
                                </button>
                            </nav>
                        </div>
                    </div>

                    {/* Companies Tab Content */}
                    {activeTab === 'companies' && (
                        <div className="rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                            <div className="border-b border-gray-200 px-6 py-4">
                                <div className="flex items-center justify-between">
                                    <div>
                                        <h2 className="text-lg font-semibold text-gray-900">All Companies</h2>
                                        <p className="mt-1 text-sm text-gray-500">View and manage all companies on the platform</p>
                                    </div>
                                    <div className="flex-1 max-w-md ml-4">
                                        <input
                                            type="text"
                                            placeholder="Search companies..."
                                            className="block w-full rounded-md border-0 py-1.5 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                                        />
                                    </div>
                                </div>
                            </div>
                            <div className="px-6 py-4">
                                <div className="space-y-4">
                                    {companies.length === 0 ? (
                                        <p className="text-sm text-gray-500 text-center py-8">No companies found</p>
                                    ) : (
                                        companies.map((company) => (
                                            <div key={company.id} className="flex items-center justify-between py-4 border-b border-gray-200 last:border-0">
                                                <div className="flex-1">
                                                    <div className="flex items-center gap-2">
                                                        <h3 className="text-sm font-semibold text-gray-900">{company.name}</h3>
                                                    </div>
                                                    <div className="mt-1 text-sm text-gray-500">
                                                        <span>{company.users_count} member{company.users_count !== 1 ? 's' : ''}</span>
                                                        {company.brands_count > 0 && (
                                                            <span className="ml-2">• {company.brands_count} brand{company.brands_count !== 1 ? 's' : ''}</span>
                                                        )}
                                                    </div>
                                                </div>
                                                <div className="ml-4">
                                                    <Link
                                                        href={`/app/companies/${company.id}`}
                                                        className="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                                                    >
                                                        View Details
                                                    </Link>
                                                </div>
                                            </div>
                                        ))
                                    )}
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Users Tab Content */}
                    {activeTab === 'users' && (
                        <div className="rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                            <div className="border-b border-gray-200 px-6 py-4">
                                <div className="flex items-center justify-between">
                                    <div>
                                        <h2 className="text-lg font-semibold text-gray-900">All Users</h2>
                                        <p className="mt-1 text-sm text-gray-500">View and manage all users on the platform</p>
                                    </div>
                                    <div className="flex-1 max-w-md ml-4">
                                        <input
                                            type="text"
                                            placeholder="Search users..."
                                            className="block w-full rounded-md border-0 py-1.5 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                                        />
                                    </div>
                                </div>
                            </div>
                            <div className="px-6 py-4">
                                <div className="space-y-4">
                                    {users.length === 0 ? (
                                        <p className="text-sm text-gray-500 text-center py-8">No users found</p>
                                    ) : (
                                        users.map((user) => (
                                            <div key={user.id} className="flex items-center justify-between py-4 border-b border-gray-200 last:border-0">
                                                <div className="flex-1">
                                                    <div className="flex items-center gap-3">
                                                        <div className="flex h-10 w-10 items-center justify-center rounded-full bg-indigo-600 text-sm font-medium text-white">
                                                            {user.first_name?.charAt(0).toUpperCase() || user.email?.charAt(0).toUpperCase()}
                                                        </div>
                                                        <div>
                                                            <h3 className="text-sm font-semibold text-gray-900">
                                                                {user.first_name && user.last_name
                                                                    ? `${user.first_name} ${user.last_name}`
                                                                    : user.first_name || user.email}
                                                            </h3>
                                                            <p className="mt-1 text-sm text-gray-500">{user.email}</p>
                                                        </div>
                                                    </div>
                                                    <div className="mt-2 text-sm text-gray-500">
                                                        <span>{user.companies_count} compan{user.companies_count !== 1 ? 'ies' : 'y'}</span>
                                                        {user.companies.length > 0 && (
                                                            <span className="ml-2">
                                                                • {user.companies.map(c => c.name).join(', ')}
                                                            </span>
                                                        )}
                                                    </div>
                                                </div>
                                                <div className="ml-4">
                                                    <button
                                                        type="button"
                                                        className="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                                                    >
                                                        View Details
                                                    </button>
                                                </div>
                                            </div>
                                        ))
                                    )}
                                </div>
                            </div>
                        </div>
                    )}
                </div>
            </main>
        </div>
    )
}

// Heroicons imports
function BellIcon(props) {
    return (
        <svg fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor" {...props}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" />
        </svg>
    )
}

function DocumentTextIcon(props) {
    return (
        <svg fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor" {...props}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
        </svg>
    )
}

function QuestionMarkCircleIcon(props) {
    return (
        <svg fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor" {...props}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9 5.25h.008v.008H12v-.008z" />
        </svg>
    )
}

function CogIcon(props) {
    return (
        <svg fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor" {...props}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.163.781.873 1.396 1.684 1.396h1.593c.191 0 .38.048.546.142.166.094.294.237.374.416.08.18.108.381.08.578l-.564 2.419c-.071.304-.243.583-.487.798-.244.215-.547.347-.877.374a2.77 2.77 0 01-1.131-.129c-.36-.099-.738-.25-1.113-.442a2.772 2.772 0 01-1.305-2.678l.776-3.125c.02-.096.048-.19.084-.282a2.772 2.772 0 01.218-1.444c.094-.167.237-.295.416-.374.18-.08.381-.108.578-.08l2.419.564c.304.071.583.243.798.487.215.244.347.547.374.877.016.19.002.382-.038.574a2.78 2.78 0 01-.128 1.131 2.772 2.772 0 01-2.678 1.305l-3.125-.776a2.772 2.772 0 01-1.444-.218 2.771 2.771 0 01-1.374-.416 2.772 2.772 0 01-.08-3.576l1.281-.213zM15 12a3 3 0 11-6 0 3 3 0 016 0z" />
        </svg>
    )
}

function BoltIcon(props) {
    return (
        <svg fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor" {...props}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" />
        </svg>
    )
}

function BookOpenIcon(props) {
    return (
        <svg fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor" {...props}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25" />
        </svg>
    )
}

function CreditCardIcon(props) {
    return (
        <svg fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor" {...props}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z" />
        </svg>
    )
}

function EnvelopeIcon(props) {
    return (
        <svg fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor" {...props}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" />
        </svg>
    )
}

function LockClosedIcon(props) {
    return (
        <svg fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor" {...props}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
        </svg>
    )
}

function BuildingOfficeIcon(props) {
    return (
        <svg fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor" {...props}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3.75h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008z" />
        </svg>
    )
}

function UsersIcon(props) {
    return (
        <svg fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor" {...props}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
        </svg>
    )
}

function DocumentIcon(props) {
    return (
        <svg fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor" {...props}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
        </svg>
    )
}

function ChartBarIcon(props) {
    return (
        <svg fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor" {...props}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" />
        </svg>
    )
}

function FolderIcon(props) {
    return (
        <svg fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor" {...props}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M2.25 12.75V12A2.25 2.25 0 014.5 9.75h15A2.25 2.25 0 0121.75 12v.75m-8.69-6.44l-2.12-2.12a1.5 1.5 0 00-1.061-.44H4.5A2.25 2.25 0 002.25 6v12a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9a2.25 2.25 0 00-2.25-2.25h-5.379a1.5 1.5 0 01-1.06-.44z" />
        </svg>
    )
}
