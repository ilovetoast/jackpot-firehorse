import { useState } from 'react'
import { Link, usePage, useForm, router } from '@inertiajs/react'
import AppNav from '../../Components/AppNav'
import AppFooter from '../../Components/AppFooter'
import UserSelector from '../../Components/UserSelector'

export default function AdminIndex({ companies, users, stats, all_users }) {
    const { auth } = usePage().props
    const [activeTab, setActiveTab] = useState('companies')
    const [expandedCompany, setExpandedCompany] = useState(null)
    const [expandedDetails, setExpandedDetails] = useState(null)
    const { data, setData, post, processing, errors, reset } = useForm({
        user_id: null,
        role: 'member',
        brand_ids: [],
    })
    const [showRoleModal, setShowRoleModal] = useState(null)
    const [selectedUserForRole, setSelectedUserForRole] = useState(null)
    const { data: roleData, setData: setRoleData, post: postRole, processing: roleProcessing } = useForm({
        role: '',
    })

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
                                            const toggleBrand = (brandId) => {
                                                const currentBrands = data.brand_ids || []
                                                if (currentBrands.includes(brandId)) {
                                                    setData('brand_ids', currentBrands.filter(id => id !== brandId))
                                                } else {
                                                    setData('brand_ids', [...currentBrands, brandId])
                                                }
                                            }
                                            const handleRoleChange = (userId, newRole) => {
                                                router.put(`/app/admin/companies/${company.id}/users/${userId}/role`, {
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
                                                                            {company.plan}
                                                                        </span>
                                                                        <span className={`inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ${
                                                                            company.stripe_status === 'active' ? 'bg-green-100 text-green-800' :
                                                                            company.stripe_status === 'inactive' ? 'bg-yellow-100 text-yellow-800' :
                                                                            'bg-gray-100 text-gray-800'
                                                                        }`}>
                                                                            {company.stripe_status === 'active' ? 'active' :
                                                                             company.stripe_status === 'inactive' ? 'inactive' :
                                                                             'not connected'}
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
                                                                    {company.created_at && (
                                                                        <div className="mt-1 text-xs text-gray-400">
                                                                            Created {company.created_at}
                                                                        </div>
                                                                    )}
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div className="ml-4 flex gap-2">
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
                                                                    company.users.map((user) => (
                                                                        <div key={user.id} className="flex items-center justify-between py-2 px-3 bg-white rounded-md border border-gray-200">
                                                                            <div className="flex items-center gap-3 flex-1">
                                                                                <div className="flex h-8 w-8 items-center justify-center rounded-full bg-indigo-600 text-sm font-medium text-white">
                                                                                    {user.first_name?.charAt(0).toUpperCase() || user.email?.charAt(0).toUpperCase()}
                                                                                </div>
                                                                                <div className="flex-1">
                                                                                    <div className="flex items-center gap-2">
                                                                                        <span className="text-sm font-medium text-gray-900">
                                                                                            {user.first_name && user.last_name
                                                                                                ? `${user.first_name} ${user.last_name}`
                                                                                                : user.first_name || user.email}
                                                                                        </span>
                                                                                        {user.role && (
                                                                                            <span className={`inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ${
                                                                                                user.role === 'owner' ? 'bg-orange-100 text-orange-800' :
                                                                                                user.role === 'admin' ? 'bg-blue-100 text-blue-800' :
                                                                                                'bg-gray-100 text-gray-800'
                                                                                            }`}>
                                                                                                {user.role === 'owner' && <span className="mr-1">👑</span>}
                                                                                                {user.role.charAt(0).toUpperCase() + user.role.slice(1)}
                                                                                            </span>
                                                                                        )}
                                                                                    </div>
                                                                                    <p className="text-xs text-gray-500 mt-0.5">{user.email}</p>
                                                                                </div>
                                                                            </div>
                                                                            <div className="ml-4">
                                                                                <select
                                                                                    value={user.role || 'member'}
                                                                                    onChange={(e) => handleRoleChange(user.id, e.target.value)}
                                                                                    className="rounded-md border-gray-300 py-1.5 px-3 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                                                                                >
                                                                                    <option value="member">Member</option>
                                                                                    <option value="admin">Admin</option>
                                                                                    {company.has_access_to_brand_manager && (
                                                                                        <option value="brand_manager">Brand Manager</option>
                                                                                    )}
                                                                                    <option value="owner">Owner</option>
                                                                                </select>
                                                                            </div>
                                                                        </div>
                                                                    ))
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
                                                                    <div>
                                                                        <label className="block text-sm font-medium leading-6 text-gray-900 mb-2">
                                                                            Select Brands (optional - leave empty to add to all brands)
                                                                        </label>
                                                                        <div className="space-y-2">
                                                                            {company.brands.map((brand) => {
                                                                                const isSelected = (data.brand_ids || []).includes(brand.id)
                                                                                return (
                                                                                    <label key={brand.id} className="flex items-center">
                                                                                        <input
                                                                                            type="checkbox"
                                                                                            checked={isSelected}
                                                                                            onChange={() => toggleBrand(brand.id)}
                                                                                            className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600"
                                                                                        />
                                                                                        <span className="ml-2 flex items-center gap-2 text-sm text-gray-900">
                                                                                            <TagIcon className="h-4 w-4 text-gray-400" />
                                                                                            {brand.name}
                                                                                            {brand.is_default && (
                                                                                                <span className="ml-2 text-xs text-gray-500">(Default)</span>
                                                                                            )}
                                                                                        </span>
                                                                                    </label>
                                                                                )
                                                                            })}
                                                                        </div>
                                                                    </div>
                                                                )}

                                                                <div className="flex justify-end">
                                                                    <button
                                                                        type="submit"
                                                                        disabled={!data.user_id || processing}
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
                                                            <div className="flex h-10 w-10 items-center justify-center rounded-full bg-indigo-600 text-sm font-medium text-white">
                                                                {user.first_name?.charAt(0).toUpperCase() || user.email?.charAt(0).toUpperCase()}
                                                            </div>
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
                                                            <div className="mt-1 text-sm text-gray-500">
                                                                <span>Added to {user.brands.length} brand{user.brands.length !== 1 ? 's' : ''}</span>
                                                                <span className="ml-2">
                                                                    • {user.brands.map(b => `${b.name}${b.tenant_name ? ` (${b.tenant_name})` : ''}`).join(', ')}
                                                                </span>
                                                            </div>
                                                        )}
                                                    </div>
                                                    <div className="ml-4 flex gap-2">
                                                        <button
                                                            type="button"
                                                            onClick={handleAssignSiteRole}
                                                            className="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                                                        >
                                                            Assign Role
                                                        </button>
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

function TagIcon(props) {
    return (
        <svg fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor" {...props}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M9.568 3H5.25A2.25 2.25 0 003 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.469.469 1.229.469 1.698 0l4.182-4.182c.469-.469.469-1.229 0-1.698L11.16 3.66A2.25 2.25 0 009.568 3z" />
            <path strokeLinecap="round" strokeLinejoin="round" d="M6 6h.008v.008H6V6z" />
        </svg>
    )
}
