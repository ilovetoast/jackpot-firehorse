import { useState, useEffect, useRef } from 'react'
import { Link, usePage, useForm, router } from '@inertiajs/react'
import { 
    BellIcon, 
    DocumentTextIcon, 
    QuestionMarkCircleIcon, 
    CogIcon, 
    BoltIcon, 
    BookOpenIcon,
    CreditCardIcon,
    EnvelopeIcon,
    LockClosedIcon,
    FolderIcon,
    UserPlusIcon,
    XMarkIcon,
    TagIcon,
    CheckCircleIcon,
    ExclamationTriangleIcon,
    BuildingOffice2Icon as BuildingOfficeIcon,
    UsersIcon,
    DocumentIcon,
    ChartBarIcon,
} from '@heroicons/react/24/outline'
import AppNav from '../../Components/AppNav'
import AppFooter from '../../Components/AppFooter'
import UserSelector from '../../Components/UserSelector'
import Avatar from '../../Components/Avatar'
import BrandRoleSelector from '../../Components/BrandRoleSelector'

export default function AdminIndex({ companies, users, stats, all_users }) {
    const { auth } = usePage().props
    const [activeTab, setActiveTab] = useState('companies')
    const [expandedCompany, setExpandedCompany] = useState(null)
    const [expandedDetails, setExpandedDetails] = useState(null)
    const [showBrandRoles, setShowBrandRoles] = useState(false)
    const { data, setData, post, processing, errors, reset } = useForm({
        user_id: null,
        role: 'member',
        brands: [],
    })
    const [showRoleModal, setShowRoleModal] = useState(null)
    const [selectedUserForRole, setSelectedUserForRole] = useState(null)
    const { data: roleData, setData: setRoleData, post: postRole, processing: roleProcessing } = useForm({
        role: '',
    })
    // Track pending changes per user for quick save
    const [pendingChanges, setPendingChanges] = useState({}) // { userId: { brandId: role, tenantRole: role } }
    const [savingUserId, setSavingUserId] = useState(null)
    // Track dropdown state for user actions
    const [openUserDropdown, setOpenUserDropdown] = useState(null)
    const dropdownRefs = useRef({})

    // Close dropdown when clicking outside - using backdrop overlay instead of document listener
    // This is more reliable and doesn't interfere with button clicks

    const adminTools = [
        { name: 'Notifications', icon: BellIcon, description: 'Manage email templates', href: '/app/admin/notifications' },
        { name: 'Email Test', icon: EnvelopeIcon, description: 'Test email sending', href: '/app/admin/email-test' },
        { name: 'Activity Logs', icon: DocumentTextIcon, description: 'View system activity and events', href: '/app/admin/activity-logs' },
        { name: 'Support', icon: QuestionMarkCircleIcon, description: 'Manage support tickets', href: '#' },
        { name: 'System Status', icon: CogIcon, description: 'Monitor system health', href: '#' },
        { name: 'AI Agents', icon: BoltIcon, description: 'Manage AI agents', href: '#' },
        { name: 'Documentation', icon: BookOpenIcon, description: 'View system documentation', href: '/app/admin/documentation' },
        { name: 'Stripe Management', icon: CreditCardIcon, description: 'Manage Stripe integration, subscriptions, and billing', href: '/app/admin/stripe-status' },
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
                                        companies.map((company) => {
                                            const isExpanded = expandedCompany === company.id
                                            const isDetailsExpanded = expandedDetails === company.id
                                            const handleUserSelect = (user) => {
                                                setData('user_id', user.id)
                                            }
                                            const handleSubmit = (e) => {
                                                e.preventDefault()
                                                post(`/app/admin/companies/${company.id}/add-user`, {
                                                    preserveScroll: true,
                                                    onSuccess: () => {
                                                        reset()
                                                        setExpandedCompany(null)
                                                    },
                                                })
                                            }
                                            const handleBrandAssignmentsChange = (brandAssignments) => {
                                                setData('brands', brandAssignments)
                                            }
                                            const handleRemoveUser = (companyId, userId) => {
                                                if (confirm('Are you sure you want to remove this user from the company?')) {
                                                    router.delete(`/app/admin/companies/${companyId}/users/${userId}`, {
                                                        preserveScroll: true,
                                                        onSuccess: () => {
                                                            // Page will refresh with updated data
                                                        },
                                                    })
                                                }
                                            }
                                            
                                            const handleCancelAccount = (companyId, userId) => {
                                                const userName = company.users.find(u => u.id === userId)
                                                const userNameStr = userName && userName.first_name && userName.last_name 
                                                    ? `${userName.first_name} ${userName.last_name}` 
                                                    : userName?.email || 'this user'
                                                
                                                if (confirm(`Are you sure you want to CANCEL ${userNameStr}'s account from ${company.name}? This will remove them from the company but keep their account active. They will receive a notification email.`)) {
                                                    router.post(`/app/admin/companies/${companyId}/users/${userId}/cancel`, {
                                                        preserveScroll: true,
                                                        onSuccess: () => {
                                                            router.reload({ only: ['companies'] })
                                                        },
                                                    })
                                                }
                                            }
                                            
                                            const handleDeleteAccount = (companyId, userId) => {
                                                const userName = company.users.find(u => u.id === userId)
                                                const userNameStr = userName && userName.first_name && userName.last_name 
                                                    ? `${userName.first_name} ${userName.last_name}` 
                                                    : userName?.email || 'this user'
                                                
                                                if (confirm(`WARNING: Are you sure you want to PERMANENTLY DELETE ${userNameStr}'s account from ${company.name}? This action cannot be undone. The account will be completely deleted and they will receive a notification email.`)) {
                                                    if (confirm(`Final confirmation: This will permanently delete the account. Continue?`)) {
                                                        router.post(`/app/admin/companies/${companyId}/users/${userId}/delete`, {
                                                            preserveScroll: true,
                                                            onSuccess: () => {
                                                                router.reload({ only: ['companies'] })
                                                            },
                                                        })
                                                    }
                                                }
                                            }
                                            const handleRoleChange = (companyId, userId, newRole) => {
                                                router.put(`/app/admin/companies/${companyId}/users/${userId}/role`, {
                                                    role: newRole,
                                                }, {
                                                    preserveScroll: true,
                                                    onSuccess: () => {
                                                        // Refresh the page data to update owner and roles
                                                        router.reload({ only: ['companies'] })
                                                    },
                                                })
                                            }

                                            return (
                                                <div key={company.id} className="border-b border-gray-200 last:border-0">
                                                    <div className="flex items-center justify-between py-4">
                                                        <div className="flex-1">
                                                            <div className="flex items-center gap-3">
                                                                <BuildingOfficeIcon className="h-5 w-5 text-gray-400" />
                                                                <div>
                                                                    <div className="flex items-center gap-2">
                                                                        <h3 className="text-sm font-semibold text-gray-900">{company.name}</h3>
                                                                        <span className={`inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ${
                                                                            company.plan_name === 'free' ? 'bg-gray-100 text-gray-800' :
                                                                            company.plan_name === 'starter' ? 'bg-green-100 text-green-800' :
                                                                            company.plan_name === 'pro' ? 'bg-blue-100 text-blue-800' :
                                                                            'bg-purple-100 text-purple-800'
                                                                        }`}>
                                                                            {company.plan_management?.plan_prefix && (
                                                                                <span className="mr-1 font-semibold">{company.plan_management.plan_prefix}:</span>
                                                                            )}
                                                                            {company.plan}
                                                                        </span>
                                                                        {/* Plan Selector Dropdown */}
                                                                        {company.plan_management && !company.plan_management.is_externally_managed && company.can_manage_plan && (
                                                                            <select
                                                                                value={company.plan_name}
                                                                                onChange={(e) => {
                                                                                    if (confirm(`Are you sure you want to change ${company.name}'s plan from ${company.plan} to ${e.target.value}? This action cannot be undone easily.`)) {
                                                                                        router.put(`/app/admin/companies/${company.id}/plan`, {
                                                                                            plan: e.target.value,
                                                                                            management_source: company.plan_management.source,
                                                                                        }, {
                                                                                            preserveScroll: true,
                                                                                            onSuccess: () => {
                                                                                                router.reload()
                                                                                            },
                                                                                            onError: (errors) => {
                                                                                                if (errors.plan) {
                                                                                                    alert(errors.plan)
                                                                                                }
                                                                                            },
                                                                                        })
                                                                                    }
                                                                                }}
                                                                                className="rounded-md border-gray-300 text-xs font-medium shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                                                            >
                                                                                <option value="free">Free</option>
                                                                                <option value="starter">Starter</option>
                                                                                <option value="pro">Pro</option>
                                                                                <option value="enterprise">Enterprise</option>
                                                                            </select>
                                                                        )}
                                                                        {company.plan_management && company.plan_management.is_externally_managed && (
                                                                            <span className="inline-flex items-center rounded-md bg-gray-100 px-2 py-1 text-xs font-medium text-gray-600" title="Plan is managed externally (e.g., Shopify) and cannot be adjusted from backend">
                                                                                {company.plan_management.source === 'shopify' ? 'Shopify Managed' : 'Externally Managed'}
                                                                            </span>
                                                                        )}
                                                                        {company.plan_management && !company.plan_management.is_externally_managed && !company.can_manage_plan && (
                                                                            <span className="inline-flex items-center rounded-md bg-yellow-100 px-2 py-1 text-xs font-medium text-yellow-800" title="Plan changes are disabled in production for safety">
                                                                                Production Protected
                                                                            </span>
                                                                        )}
                                                                        <span className={`inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ${
                                                                            company.stripe_status === 'active' ? 'bg-green-100 text-green-800' :
                                                                            company.stripe_status === 'incomplete' ? 'bg-yellow-100 text-yellow-800' :
                                                                            company.stripe_status === 'past_due' ? 'bg-orange-100 text-orange-800' :
                                                                            company.stripe_status === 'trialing' ? 'bg-blue-100 text-blue-800' :
                                                                            company.stripe_status === 'canceled' ? 'bg-red-100 text-red-800' :
                                                                            company.stripe_status === 'inactive' ? 'bg-gray-100 text-gray-800' :
                                                                            'bg-gray-100 text-gray-800'
                                                                        }`}>
                                                                            {company.stripe_status || 'not connected'}
                                                                        </span>
                                                                    </div>
                                                                    <div className="mt-1 text-sm text-gray-500">
                                                                        {company.owner && (
                                                                            <span>Owner: {company.owner.name} ({company.owner.email})</span>
                                                                        )}
                                                                        {company.owner && company.users_count > 0 && <span className="mx-2">•</span>}
                                                                        <span>{company.users_count} member{company.users_count !== 1 ? 's' : ''}</span>
                                                                        {company.brands_count > 0 && (
                                                                            <span className="ml-2">• {company.brands_count} brand{company.brands_count !== 1 ? 's' : ''}</span>
                                                                        )}
                                                                        {company.stripe_connected && (
                                                                            <span className="ml-2 flex items-center gap-1">
                                                                                <span className="h-2 w-2 rounded-full bg-green-500"></span>
                                                                                Stripe Connected
                                                                            </span>
                                                                        )}
                                                                    </div>
                                                                    {/* Plan Limit Alerts */}
                                                                    {company.plan_limit_info && (
                                                                        <div className="mt-2 space-y-1">
                                                                            {company.plan_limit_info.brand_limit_exceeded && (
                                                                                <div className="inline-flex items-center rounded-md bg-yellow-100 px-2 py-1 text-xs font-medium text-yellow-800">
                                                                                    <svg className="h-3 w-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                                                                        <path fillRule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clipRule="evenodd" />
                                                                                    </svg>
                                                                                    Brand limit exceeded ({company.plan_limit_info.current_brand_count}/{company.plan_limit_info.max_brands})
                                                                                </div>
                                                                            )}
                                                                            {company.plan_limit_info.user_limit_exceeded && (
                                                                                <div className="inline-flex items-center rounded-md bg-yellow-100 px-2 py-1 text-xs font-medium text-yellow-800">
                                                                                    <svg className="h-3 w-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                                                                        <path fillRule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clipRule="evenodd" />
                                                                                    </svg>
                                                                                    User limit exceeded ({company.plan_limit_info.current_user_count}/{company.plan_limit_info.max_users})
                                                                                </div>
                                                                            )}
                                                                        </div>
                                                                    )}
                                                                    {company.created_at && (
                                                                        <div className="mt-1 text-xs text-gray-400">
                                                                            Created {company.created_at}
                                                                        </div>
                                                                    )}
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div className="ml-4 flex gap-2">
                                                            {company.stripe_connected && (
                                                                <Link
                                                                    href={`/app/admin/stripe-status?tenant_id=${company.id}`}
                                                                    className="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                                                                >
                                                                    Manage Subscription
                                                                </Link>
                                                            )}
                                                            <button
                                                                type="button"
                                                                onClick={() => {
                                                                    setExpandedCompany(isExpanded ? null : company.id)
                                                                    if (!isExpanded) {
                                                                        reset()
                                                                    }
                                                                }}
                                                                className="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                                                            >
                                                                {isExpanded ? 'Cancel' : 'Add User'}
                                                            </button>
                                                            <button
                                                                type="button"
                                                                onClick={() => {
                                                                    setExpandedDetails(isDetailsExpanded ? null : company.id)
                                                                    // Close add user form if open
                                                                    if (isExpanded) {
                                                                        setExpandedCompany(null)
                                                                        reset()
                                                                    }
                                                                }}
                                                                className="rounded-md bg-gray-200 px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm hover:bg-gray-300"
                                                            >
                                                                {isDetailsExpanded ? 'Hide Details' : 'View Details'}
                                                            </button>
                                                        </div>
                                                    </div>

                                                    {/* Company Details Section */}
                                                    {isDetailsExpanded && (
                                                        <div className="border-t border-gray-200 bg-gray-50 px-6 py-4">
                                                            <h4 className="text-sm font-semibold text-gray-900 mb-4">Company Members</h4>
                                                            <div className="space-y-3">
                                                                {company.users && company.users.length > 0 ? (
                                                                    company.users.map((user) => {
                                                                        // Check if user is the company owner
                                                                        const isCompanyOwner = company.owner && company.owner.id === user.id || user.is_owner || (user.tenant_role && user.tenant_role.toLowerCase() === 'owner')
                                                                        // Owner is NEVER disabled, even if backend says so (extra safety check)
                                                                        const isDisabledByPlanLimit = isCompanyOwner ? false : (user.is_disabled_by_plan_limit || false)
                                                                        
                                                                        return (
                                                                            <div key={user.id} className={`flex flex-col py-2 px-3 rounded-md border ${
                                                                                isDisabledByPlanLimit 
                                                                                    ? 'bg-yellow-50 border-yellow-200 opacity-75' 
                                                                                    : 'bg-white border-gray-200'
                                                                            }`}>
                                                                            <div className="flex items-start justify-between">
                                                                                <div className="flex items-start gap-3 flex-1">
                                                                                    <Avatar
                                                                                        avatarUrl={user.avatar_url}
                                                                                        firstName={user.first_name}
                                                                                        lastName={user.last_name}
                                                                                        email={user.email}
                                                                                        size="sm"
                                                                                    />
                                                                                    <div className="flex-1 min-w-0">
                                                                                        <div className="flex items-center gap-2 flex-wrap mb-2">
                                                                                            <span className={`text-sm font-medium ${
                                                                                                isDisabledByPlanLimit ? 'text-gray-500' : 'text-gray-900'
                                                                                            }`}>
                                                                                                {user.first_name && user.last_name
                                                                                                    ? `${user.first_name} ${user.last_name}`
                                                                                                    : user.first_name || user.email}
                                                                                            </span>
                                                                                            {isDisabledByPlanLimit && (
                                                                                                <span className="inline-flex items-center rounded-full bg-yellow-100 px-2 py-0.5 text-xs font-medium text-yellow-800">
                                                                                                    Disabled (Plan Limit)
                                                                                                </span>
                                                                                            )}
                                                                                            {isCompanyOwner && (
                                                                                                <span className="inline-flex items-center rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-medium text-indigo-800">
                                                                                                    Owner
                                                                                                </span>
                                                                                            )}
                                                                                            {user.join_order && (
                                                                                                <span className="text-xs text-gray-400">
                                                                                                    #{user.join_order}
                                                                                                </span>
                                                                                            )}
                                                                                        </div>
                                                                                        <p className={`text-xs mb-2 ${
                                                                                            isDisabledByPlanLimit ? 'text-gray-400' : 'text-gray-500'
                                                                                        }`}>{user.email}</p>
                                                                                        
                                                                                        {/* Tenant-level role selector */}
                                                                                        <div className="mb-2 flex items-center gap-2 text-xs">
                                                                                            <span className="text-gray-600 min-w-[120px] font-medium">Company Role:</span>
                                                                                            <select
                                                                                                value={pendingChanges[user.id]?.tenantRole !== undefined ? pendingChanges[user.id].tenantRole : (user.tenant_role || 'member')}
                                                                                                onChange={(e) => {
                                                                                                    setPendingChanges(prev => ({
                                                                                                        ...prev,
                                                                                                        [user.id]: {
                                                                                                            ...prev[user.id],
                                                                                                            tenantRole: e.target.value,
                                                                                                        }
                                                                                                    }))
                                                                                                }}
                                                                                                className="w-32 rounded-md border-0 py-1 px-2 text-xs text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600"
                                                                                            >
                                                                                                <option value="member">Member</option>
                                                                                                <option value="admin">Admin</option>
                                                                                                {company.has_access_to_brand_manager && (
                                                                                                    <option value="brand_manager">Brand Manager</option>
                                                                                                )}
                                                                                                <option value="owner">Owner</option>
                                                                                            </select>
                                                                                            {pendingChanges[user.id]?.tenantRole !== undefined && pendingChanges[user.id].tenantRole !== user.tenant_role && (
                                                                                                <button
                                                                                                    type="button"
                                                                                                    onClick={() => {
                                                                                                        setSavingUserId(user.id)
                                                                                                        router.put(`/app/admin/companies/${company.id}/users/${user.id}/role`, {
                                                                                                            role: pendingChanges[user.id].tenantRole,
                                                                                                        }, {
                                                                                                            preserveScroll: true,
                                                                                                            onSuccess: () => {
                                                                                                                setPendingChanges(prev => {
                                                                                                                    const updated = { ...prev }
                                                                                                                    delete updated[user.id]?.tenantRole
                                                                                                                    if (Object.keys(updated[user.id] || {}).length === 0) {
                                                                                                                        delete updated[user.id]
                                                                                                                    }
                                                                                                                    return updated
                                                                                                                })
                                                                                                                setSavingUserId(null)
                                                                                                                router.reload({ only: ['companies'] })
                                                                                                            },
                                                                                                            onError: () => {
                                                                                                                setSavingUserId(null)
                                                                                                            },
                                                                                                        })
                                                                                                    }}
                                                                                                    disabled={savingUserId === user.id}
                                                                                                    className="ml-2 rounded-md bg-green-600 px-2 py-1 text-xs font-semibold text-white shadow-sm hover:bg-green-500 disabled:opacity-50"
                                                                                                >
                                                                                                    {savingUserId === user.id ? 'Saving...' : 'Save'}
                                                                                                </button>
                                                                                            )}
                                                                                        </div>

                                                                                        {/* Brand roles - list each brand with selectable role */}
                                                                                        {company.brands && company.brands.length > 0 && (
                                                                                            <div className="mt-2 space-y-1.5">
                                                                                                {company.brands.map((brand) => {
                                                                                                    const assignment = user.brand_assignments?.find(ba => ba.id === brand.id)
                                                                                                    const originalRole = assignment?.role || null
                                                                                                    const pendingRole = pendingChanges[user.id]?.brands?.[brand.id]
                                                                                                    const displayRole = pendingRole !== undefined ? pendingRole : (originalRole || '')
                                                                                                    const hasPendingChange = pendingRole !== undefined && pendingRole !== (originalRole || '')
                                                                                                    
                                                                                                    const handleBrandRoleChange = (brandId, newRole) => {
                                                                                                        const value = newRole || null
                                                                                                        setPendingChanges(prev => ({
                                                                                                            ...prev,
                                                                                                            [user.id]: {
                                                                                                                ...prev[user.id],
                                                                                                                brands: {
                                                                                                                    ...prev[user.id]?.brands,
                                                                                                                    [brandId]: value === null ? '' : value,
                                                                                                                }
                                                                                                            }
                                                                                                        }))
                                                                                                    }

                                                                                                    const handleSaveBrandRole = (brandId) => {
                                                                                                        const newRole = pendingChanges[user.id]?.brands?.[brandId]
                                                                                                        setSavingUserId(`${user.id}-${brandId}`)
                                                                                                        // Send empty string for "not assigned" - backend will convert to null
                                                                                                        const roleValue = (newRole === '' || newRole === null || newRole === undefined) ? '' : newRole
                                                                                                        router.put(`/app/admin/companies/${company.id}/users/${user.id}/brands/${brandId}/role`, {
                                                                                                            role: roleValue,
                                                                                                        }, {
                                                                                                            preserveScroll: true,
                                                                                                            onSuccess: () => {
                                                                                                                setPendingChanges(prev => {
                                                                                                                    const updated = { ...prev }
                                                                                                                    if (updated[user.id]?.brands) {
                                                                                                                        delete updated[user.id].brands[brandId]
                                                                                                                        if (Object.keys(updated[user.id].brands).length === 0) {
                                                                                                                            if (updated[user.id].tenantRole === undefined) {
                                                                                                                                delete updated[user.id]
                                                                                                                            } else {
                                                                                                                                delete updated[user.id].brands
                                                                                                                            }
                                                                                                                        }
                                                                                                                    }
                                                                                                                    if (updated[user.id] && Object.keys(updated[user.id]).length === 0) {
                                                                                                                        delete updated[user.id]
                                                                                                                    }
                                                                                                                    return updated
                                                                                                                })
                                                                                                                setSavingUserId(null)
                                                                                                                // Full page reload to refresh auth.brands from HandleInertiaRequests
                                                                                                                // Shared props should refresh, but use full reload to ensure brands list updates immediately
                                                                                                                window.location.reload()
                                                                            },
                                                                            onError: () => {
                                                                                setSavingUserId(null)
                                                                            },
                                                                        })
                                                                    }
                                                                                                    
                                                                                                    return (
                                                                                                        <div key={brand.id} className="flex items-center gap-2 text-xs">
                                                                                                            <span className="text-gray-600 min-w-[120px]">
                                                                                                                {brand.name}{brand.is_default && ' (Default)'}:
                                                                                                            </span>
                                                                                                            <select
                                                                                                                value={displayRole}
                                                                                                                onChange={(e) => handleBrandRoleChange(brand.id, e.target.value)}
                                                                                                                className={`w-32 rounded-md border-0 py-1 px-2 text-xs text-gray-900 shadow-sm ring-1 ring-inset ${
                                                                                                                    hasPendingChange ? 'ring-yellow-400 bg-yellow-50' : 'ring-gray-300'
                                                                                                                } focus:ring-2 focus:ring-inset focus:ring-indigo-600`}
                                                                                                            >
                                                                                                                <option value="">No access - not assigned to brand</option>
                                                                                                                <option value="member">Member</option>
                                                                                                                <option value="admin">Admin</option>
                                                                                                                {company.has_access_to_brand_manager && (
                                                                                                                    <option value="brand_manager">Brand Manager</option>
                                                                                                                )}
                                                                                                                <option value="owner">Owner</option>
                                                                                                            </select>
                                                                                                            {hasPendingChange && (
                                                                                                                <button
                                                                                                                    type="button"
                                                                                                                    onClick={() => handleSaveBrandRole(brand.id)}
                                                                                                                    disabled={savingUserId === `${user.id}-${brand.id}`}
                                                                                                                    className="ml-1 rounded-md bg-green-600 px-2 py-1 text-xs font-semibold text-white shadow-sm hover:bg-green-500 disabled:opacity-50"
                                                                                                                >
                                                                                                                    {savingUserId === `${user.id}-${brand.id}` ? 'Saving...' : 'Save'}
                                                                                                                </button>
                                                                                                            )}
                                                                                                        </div>
                                                                                                    )
                                                                                                })}
                                                                                            </div>
                                                                                        )}
                                                                                    </div>
                                                                                </div>
                                                                                
                                                                                {/* Remove from Company Button */}
                                                                                <div className="ml-4 flex items-center">
                                                                                    <button
                                                                                        type="button"
                                                                                        onClick={() => {
                                                                                            if (confirm(`Remove ${user.first_name && user.last_name ? `${user.first_name} ${user.last_name}` : user.email} from ${company.name}?`)) {
                                                                                                handleRemoveUser(company.id, user.id)
                                                                                            }
                                                                                        }}
                                                                                        disabled={isCompanyOwner}
                                                                                        className={`rounded-md px-2 py-1 text-xs font-medium text-gray-700 shadow-sm ${
                                                                                            isCompanyOwner 
                                                                                                ? 'bg-gray-100 cursor-not-allowed text-gray-400' 
                                                                                                : 'bg-white hover:bg-gray-50 border border-gray-300'
                                                                                        }`}
                                                                                        title={isCompanyOwner ? "Cannot remove company owner" : "Remove user from company"}
                                                                                    >
                                                                                        Remove
                                                                                    </button>
                                                                                </div>
                                                                            </div>
                                                                        </div>
                                                                        )
                                                                    })
                                                                ) : (
                                                                    <p className="text-sm text-gray-500 py-4 text-center">No members found</p>
                                                                )}
                                                            </div>
                                                        </div>
                                                    )}

                                                    {/* Add User Form */}
                                                    {isExpanded && (
                                                        <div className="border-t border-gray-200 bg-gray-50 px-6 py-4">
                                                            <form onSubmit={handleSubmit} className="space-y-4">
                                                                <UserSelector
                                                                    users={all_users || []}
                                                                    selectedUser={all_users?.find(u => u.id === data.user_id) || null}
                                                                    onSelect={handleUserSelect}
                                                                    placeholder="Search for a user..."
                                                                    label="Select User"
                                                                />
                                                                {errors.user_id && (
                                                                    <p className="text-sm text-red-600">{errors.user_id}</p>
                                                                )}

                                                                {/* Role Selection */}
                                                                <div>
                                                                    <label className="block text-sm font-medium leading-6 text-gray-900 mb-2">
                                                                        Company Role
                                                                    </label>
                                                                    <select
                                                                        value={data.role}
                                                                        onChange={(e) => setData('role', e.target.value)}
                                                                        className="block w-full rounded-md border-0 py-1.5 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                                                                    >
                                                                        <option value="member">Member</option>
                                                                        <option value="admin">Admin</option>
                                                                        {company.has_access_to_brand_manager && (
                                                                            <option value="brand_manager">Brand Manager</option>
                                                                        )}
                                                                        <option value="owner">Owner</option>
                                                                    </select>
                                                                    {errors.role && (
                                                                        <p className="mt-1 text-sm text-red-600">{errors.role}</p>
                                                                    )}
                                                                </div>

                                                                {/* Brand Selection */}
                                                                {company.brands && company.brands.length > 0 && (
                                                                    <BrandRoleSelector
                                                                        brands={company.brands}
                                                                        selectedBrands={data.brands}
                                                                        onChange={handleBrandAssignmentsChange}
                                                                        errors={errors}
                                                                        required={true}
                                                                    />
                                                                )}

                                                                <div className="flex justify-end">
                                                                    <button
                                                                        type="submit"
                                                                        disabled={!data.user_id || processing || !data.brands || data.brands.length === 0}
                                                                        className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50 disabled:cursor-not-allowed"
                                                                    >
                                                                        {processing ? 'Adding...' : 'Add User to Company'}
                                                                    </button>
                                                                </div>
                                                            </form>
                                                        </div>
                                                    )}
                                                </div>
                                            )
                                        })
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
                                    <div className="flex items-center gap-4">
                                        <label className="flex items-center">
                                            <input
                                                type="checkbox"
                                                checked={showBrandRoles}
                                                onChange={(e) => setShowBrandRoles(e.target.checked)}
                                                className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600"
                                            />
                                            <span className="ml-2 text-sm text-gray-700">Show Brand Roles</span>
                                        </label>
                                        <div className="flex-1 max-w-md">
                                            <input
                                                type="text"
                                                placeholder="Search users..."
                                                className="block w-full rounded-md border-0 py-1.5 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                                            />
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div className="px-6 py-4">
                                <div className="space-y-4">
                                    {users.length === 0 ? (
                                        <p className="text-sm text-gray-500 text-center py-8">No users found</p>
                                    ) : (
                                        users.map((user) => {
                                            const handleAssignSiteRole = () => {
                                                setSelectedUserForRole(user)
                                                setShowRoleModal(user.id)
                                                setRoleData('role', user.site_roles?.[0] || '')
                                            }
                                            const handleSubmitSiteRole = (e) => {
                                                e.preventDefault()
                                                postRole(`/app/admin/users/${user.id}/assign-site-role`, {
                                                    preserveScroll: true,
                                                    onSuccess: () => {
                                                        setShowRoleModal(null)
                                                        setSelectedUserForRole(null)
                                                        setRoleData('role', '')
                                                    },
                                                })
                                            }

                                            return (
                                                <div key={user.id} className="flex items-center justify-between py-4 border-b border-gray-200 last:border-0">
                                                    <div className="flex-1">
                                                        <div className="flex items-center gap-3">
                                                            <Avatar
                                                                avatarUrl={user.avatar_url}
                                                                firstName={user.first_name}
                                                                lastName={user.last_name}
                                                                email={user.email}
                                                                size="md"
                                                            />
                                                            <div>
                                                                <div className="flex items-center gap-2">
                                                                    <h3 className="text-sm font-semibold text-gray-900">
                                                                        {user.first_name && user.last_name
                                                                            ? `${user.first_name} ${user.last_name}`
                                                                            : user.first_name || user.email}
                                                                    </h3>
                                                                    {/* Site Roles Only - Remove Duplicates */}
                                                                    {user.site_roles && user.site_roles.length > 0 && (
                                                                        [...new Set(user.site_roles)].map((role) => (
                                                                            <span key={role} className={`inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ${
                                                                                role === 'site_owner' ? 'bg-yellow-100 text-yellow-800' :
                                                                                role === 'site_admin' ? 'bg-blue-100 text-blue-800' :
                                                                                'bg-gray-100 text-gray-800'
                                                                            }`}>
                                                                                {role === 'site_owner' && <span className="mr-1">👑</span>}
                                                                                {role.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase())}
                                                                            </span>
                                                                        ))
                                                                    )}
                                                                </div>
                                                                <p className="mt-1 text-sm text-gray-500">{user.email}</p>
                                                            </div>
                                                        </div>
                                                        <div className="mt-2 text-sm text-gray-500">
                                                            <span>Member of {user.companies_count} compan{user.companies_count !== 1 ? 'ies' : 'y'}</span>
                                                            {user.companies && user.companies.length > 0 && (
                                                                <span className="ml-2">
                                                                    • {user.companies.map(c => c.name).join(', ')}
                                                                </span>
                                                            )}
                                                        </div>
                                                        {user.brands && user.brands.length > 0 && (
                                                            <div className="mt-1">
                                                                <span className="text-sm text-gray-500">
                                                                    Added to {user.brands.length} brand{user.brands.length !== 1 ? 's' : ''}
                                                                </span>
                                                                {showBrandRoles && (
                                                                    <div className="mt-2 space-y-1">
                                                                        {user.companies && user.companies.length > 0 && user.companies.map((company) => {
                                                                            const companyBrands = user.brands.filter(b => b.tenant_id === company.id || b.tenant_name === company.name)
                                                                            if (companyBrands.length === 0) return null
                                                                            return (
                                                                                <div key={company.id} className="ml-4 text-sm">
                                                                                    <span className="font-medium text-gray-700">{company.name}:</span>
                                                                                    <span className="ml-2 text-gray-600">
                                                                                        {companyBrands.map((brand, idx) => (
                                                                                            <span key={brand.id}>
                                                                                                {idx > 0 && ', '}
                                                                                                {brand.name} ({brand.role || 'member'})
                                                                                            </span>
                                                                                        ))}
                                                                                    </span>
                                                                                </div>
                                                                            )
                                                                        })}
                                                                    </div>
                                                                )}
                                                            </div>
                                                        )}
                                                    </div>
                                                    <div className="ml-4 flex gap-2 relative">
                                                        {/* Actions Dropdown */}
                                                        <div 
                                                            className="relative" 
                                                            ref={(el) => {
                                                                if (el) {
                                                                    dropdownRefs.current[user.id] = el
                                                                } else {
                                                                    delete dropdownRefs.current[user.id]
                                                                }
                                                            }}
                                                        >
                                                            <button
                                                                type="button"
                                                                onClick={(e) => {
                                                                    e.preventDefault()
                                                                    e.stopPropagation()
                                                                    const newState = openUserDropdown === user.id ? null : user.id
                                                                    setOpenUserDropdown(newState)
                                                                }}
                                                                className="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 flex items-center gap-2"
                                                            >
                                                                Actions
                                                                <svg className={`h-4 w-4 transition-transform ${openUserDropdown === user.id ? 'rotate-180' : ''}`} fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                                                                </svg>
                                                            </button>
                                                            
                                                            {openUserDropdown === user.id && (
                                                                <>
                                                                    {/* Backdrop overlay to capture outside clicks */}
                                                                    <div 
                                                                        className="fixed inset-0 z-40"
                                                                        onClick={() => setOpenUserDropdown(null)}
                                                                        onMouseDown={(e) => e.stopPropagation()}
                                                                    ></div>
                                                                    <div 
                                                                        className="absolute right-0 mt-2 w-56 rounded-md shadow-lg bg-white ring-1 ring-black ring-opacity-5 z-50"
                                                                        onClick={(e) => {
                                                                            e.preventDefault()
                                                                            e.stopPropagation()
                                                                        }}
                                                                        onMouseDown={(e) => {
                                                                            e.preventDefault()
                                                                            e.stopPropagation()
                                                                        }}
                                                                    >
                                                                        <div className="py-1">
                                                                        <button
                                                                            type="button"
                                                                            onMouseDown={(e) => {
                                                                                e.preventDefault()
                                                                                e.stopPropagation()
                                                                            }}
                                                                            onClick={(e) => {
                                                                                e.preventDefault()
                                                                                e.stopPropagation()
                                                                                setOpenUserDropdown(null)
                                                                                // Small delay to ensure dropdown closes before opening modal
                                                                                setTimeout(() => {
                                                                                    handleAssignSiteRole()
                                                                                }, 10)
                                                                            }}
                                                                            className="block w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100"
                                                                        >
                                                                            Assign Role
                                                                        </button>
                                                                        <Link
                                                                            href={`/app/admin/users/${user.id}`}
                                                                            className="block w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100"
                                                                            onMouseDown={(e) => {
                                                                                e.preventDefault()
                                                                                e.stopPropagation()
                                                                            }}
                                                                            onClick={(e) => {
                                                                                e.stopPropagation()
                                                                                setOpenUserDropdown(null)
                                                                            }}
                                                                        >
                                                                            View User Profile
                                                                        </Link>
                                                                        <div className="border-t border-gray-200 my-1"></div>
                                                                        {user.is_suspended ? (
                                                                            <button
                                                                                type="button"
                                                                                onMouseDown={(e) => {
                                                                                    e.preventDefault()
                                                                                    e.stopPropagation()
                                                                                }}
                                                                                onClick={(e) => {
                                                                                    e.preventDefault()
                                                                                    e.stopPropagation()
                                                                                    const userNameStr = user.first_name && user.last_name 
                                                                                        ? `${user.first_name} ${user.last_name}` 
                                                                                        : user.email || 'this user'
                                                                                    
                                                                                    if (confirm(`Are you sure you want to UNSUSPEND ${userNameStr}'s account? They will regain access to the platform.`)) {
                                                                                        router.post(`/app/admin/users/${user.id}/unsuspend`, {
                                                                                            preserveScroll: true,
                                                                                            onSuccess: () => {
                                                                                                setOpenUserDropdown(null)
                                                                                                router.reload({ only: ['users'] })
                                                                                            },
                                                                                        })
                                                                                    }
                                                                                }}
                                                                                className="block w-full text-left px-4 py-2 text-sm text-green-600 hover:bg-gray-100"
                                                                            >
                                                                                Unsuspend Account
                                                                            </button>
                                                                        ) : (
                                                                            <button
                                                                                type="button"
                                                                                onMouseDown={(e) => {
                                                                                    e.preventDefault()
                                                                                    e.stopPropagation()
                                                                                }}
                                                                                onClick={(e) => {
                                                                                    e.preventDefault()
                                                                                    e.stopPropagation()
                                                                                    const userNameStr = user.first_name && user.last_name 
                                                                                        ? `${user.first_name} ${user.last_name}` 
                                                                                        : user.email || 'this user'
                                                                                    
                                                                                    if (confirm(`Are you sure you want to SUSPEND ${userNameStr}'s account? This will block them from accessing any pages. They will receive a notification email.`)) {
                                                                                        router.post(`/app/admin/users/${user.id}/suspend`, {
                                                                                            preserveScroll: true,
                                                                                            onSuccess: () => {
                                                                                                setOpenUserDropdown(null)
                                                                                                router.reload({ only: ['users'] })
                                                                                            },
                                                                                        })
                                                                                    }
                                                                                }}
                                                                                className="block w-full text-left px-4 py-2 text-sm text-red-600 hover:bg-gray-100"
                                                                            >
                                                                                Suspend Account
                                                                            </button>
                                                                        )}
                                                                        {user.companies && user.companies.length > 0 && (
                                                                            <>
                                                                                <div className="border-t border-gray-200 my-1"></div>
                                                                                <div className="px-4 py-2 text-xs font-semibold text-gray-500 uppercase tracking-wider">
                                                                                    Cancel Account
                                                                                </div>
                                                                                {user.companies.map((company) => {
                                                                                    const handleCancelFromCompany = (e) => {
                                                                                        e.preventDefault()
                                                                                        e.stopPropagation()
                                                                                        const userNameStr = user.first_name && user.last_name 
                                                                                            ? `${user.first_name} ${user.last_name}` 
                                                                                            : user.email || 'this user'
                                                                                        
                                                                                        if (confirm(`Are you sure you want to CANCEL ${userNameStr}'s account from ${company.name}? This will remove them from the company but keep their account active. They will receive a notification email.`)) {
                                                                                            router.post(`/app/admin/companies/${company.id}/users/${user.id}/cancel`, {
                                                                                                preserveScroll: true,
                                                                                                onSuccess: () => {
                                                                                                    setOpenUserDropdown(null)
                                                                                                    router.reload({ only: ['users', 'companies'] })
                                                                                                },
                                                                                            })
                                                                                        }
                                                                                    }
                                                                                    
                                                                                    return (
                                                                                        <button
                                                                                            key={company.id}
                                                                                            type="button"
                                                                                            onMouseDown={(e) => {
                                                                                                e.preventDefault()
                                                                                                e.stopPropagation()
                                                                                            }}
                                                                                            onClick={handleCancelFromCompany}
                                                                                            className="block w-full text-left px-4 py-2 pl-6 text-sm text-orange-600 hover:bg-gray-100"
                                                                                        >
                                                                                            Cancel from {company.name}
                                                                                        </button>
                                                                                    )
                                                                                })}
                                                                                <div className="border-t border-gray-200 my-1"></div>
                                                                                <button
                                                                                    type="button"
                                                                                    onMouseDown={(e) => {
                                                                                        e.preventDefault()
                                                                                        e.stopPropagation()
                                                                                    }}
                                                                                    onClick={(e) => {
                                                                                        e.preventDefault()
                                                                                        e.stopPropagation()
                                                                                        const userNameStr = user.first_name && user.last_name 
                                                                                            ? `${user.first_name} ${user.last_name}` 
                                                                                            : user.email || 'this user'
                                                                                        
                                                                                        if (confirm(`WARNING: Are you sure you want to PERMANENTLY DELETE ${userNameStr}'s account? This action cannot be undone. The account will be completely deleted from all companies and they will receive a notification email.`)) {
                                                                                            if (confirm(`Final confirmation: This will permanently delete the account. Continue?`)) {
                                                                                                // Delete from first company (the delete endpoint handles full account deletion)
                                                                                                if (user.companies && user.companies.length > 0) {
                                                                                                    router.post(`/app/admin/companies/${user.companies[0].id}/users/${user.id}/delete`, {
                                                                                                        preserveScroll: true,
                                                                                                        onSuccess: () => {
                                                                                                            setOpenUserDropdown(null)
                                                                                                            router.reload({ only: ['users', 'companies'] })
                                                                                                        },
                                                                                                    })
                                                                                                }
                                                                                            }
                                                                                        }
                                                                                    }}
                                                                                    className="block w-full text-left px-4 py-2 text-sm text-red-600 hover:bg-gray-100"
                                                                                >
                                                                                    Delete Account
                                                                                </button>
                                                                            </>
                                                                        )}
                                                                        </div>
                                                                    </div>
                                                                </>
                                                            )}
                                                        </div>
                                                    </div>
                                                    
                                                    {/* Site Role Assignment Modal */}
                                                    {showRoleModal === user.id && (
                                                        <div className="fixed inset-0 z-50 overflow-y-auto">
                                                            <div className="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                                                                <div className="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" onClick={() => setShowRoleModal(null)}></div>
                                                                <div className="relative transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:p-6">
                                                                    <form onSubmit={handleSubmitSiteRole}>
                                                                        <div>
                                                                            <h3 className="text-lg font-medium leading-6 text-gray-900 mb-4">
                                                                                Assign Site Role to {selectedUserForRole?.first_name} {selectedUserForRole?.last_name}
                                                                            </h3>
                                                                            <div>
                                                                                <label className="block text-sm font-medium text-gray-700 mb-2">
                                                                                    Site Role
                                                                                </label>
                                                                                <select
                                                                                    value={roleData.role}
                                                                                    onChange={(e) => setRoleData('role', e.target.value)}
                                                                                    className="block w-full rounded-md border-0 py-1.5 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                                                                                >
                                                                                    <option value="">No Site Role</option>
                                                                                    <option value="site_owner">Site Owner</option>
                                                                                    <option value="site_admin">Site Admin</option>
                                                                                    <option value="site_support">Site Support</option>
                                                                                    <option value="compliance">Compliance</option>
                                                                                </select>
                                                                            </div>
                                                                        </div>
                                                                        <div className="mt-5 sm:mt-6 sm:grid sm:grid-flow-row-dense sm:grid-cols-2 sm:gap-3">
                                                                            <button
                                                                                type="submit"
                                                                                disabled={roleProcessing}
                                                                                className="inline-flex w-full justify-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 sm:col-start-2 disabled:opacity-50"
                                                                            >
                                                                                {roleProcessing ? 'Assigning...' : 'Assign Role'}
                                                                            </button>
                                                                            <button
                                                                                type="button"
                                                                                onClick={() => {
                                                                                    setShowRoleModal(null)
                                                                                    setSelectedUserForRole(null)
                                                                                }}
                                                                                className="mt-3 inline-flex w-full justify-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 sm:col-start-1 sm:mt-0"
                                                                            >
                                                                                Cancel
                                                                            </button>
                                                                        </div>
                                                                    </form>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    )}
                                                </div>
                                            )
                                        })
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
