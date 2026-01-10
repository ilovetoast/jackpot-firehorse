import { Link, router, usePage } from '@inertiajs/react'
import { useForm } from '@inertiajs/react'
import { useState } from 'react'
import PlanLimitIndicator from '../../Components/PlanLimitIndicator'
import AppNav from '../../Components/AppNav'
import { CategoryIcon } from '../../Helpers/categoryIcons'
import BrandAvatar from '../../Components/BrandAvatar'
import Avatar from '../../Components/Avatar'
import ConfirmDialog from '../../Components/ConfirmDialog'

export default function BrandsIndex({ brands, limits }) {
    const { auth } = usePage().props
    const { post, processing } = useForm()
    const [expandedBrand, setExpandedBrand] = useState(null)
    const [categoryTab, setCategoryTab] = useState({}) // Track active tab per brand: { brandId: 'asset' | 'marketing' }
    const [deleteConfirm, setDeleteConfirm] = useState({ open: false, brandId: null, brandName: '' })

    const handleDelete = (brandId, brandName) => {
        setDeleteConfirm({ open: true, brandId, brandName })
    }

    const confirmDelete = () => {
        if (deleteConfirm.brandId) {
            router.delete(`/app/brands/${deleteConfirm.brandId}`, {
                preserveScroll: true,
                onSuccess: () => {
                    setDeleteConfirm({ open: false, brandId: null, brandName: '' })
                },
            })
        }
    }

    const toggleExpand = (brandId) => {
        setExpandedBrand(expandedBrand === brandId ? null : brandId)
    }

    return (
        <div className="min-h-full flex flex-col">
            <AppNav brand={auth.activeBrand} tenant={null} />
            <main className="flex-1 bg-gray-50">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
                    <div className="mb-8 flex items-center justify-between">
                        <div>
                            <h1 className="text-3xl font-bold tracking-tight text-gray-900">Brands</h1>
                            <p className="mt-2 text-sm text-gray-700">Manage your brands</p>
                        </div>
                        {limits.can_create && (
                            <Link
                                href="/app/brands/create"
                                className="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                            >
                                Create Brand
                            </Link>
                        )}
                    </div>

                    {/* Limit Indicator */}
                    {!limits.can_create && (
                        <div className="mb-6">
                            <PlanLimitIndicator
                                current={limits.current}
                                max={limits.max}
                                label="Brands"
                            />
                        </div>
                    )}

                    {/* Brands List */}
                    <div className="bg-white shadow-sm ring-1 ring-gray-200 rounded-lg overflow-hidden">
                        {brands.length === 0 ? (
                            <div className="text-center py-12">
                                <p className="text-sm text-gray-500">No brands found. Create your first one!</p>
                            </div>
                        ) : (
                            <ul className="divide-y divide-gray-200">
                                {brands.map((brand) => (
                                    <li 
                                        key={brand.id} 
                                        className={`transition-colors ${
                                            brand.is_disabled 
                                                ? 'opacity-60 bg-gray-50' 
                                                : 'hover:bg-gray-50'
                                        }`}
                                    >
                                        {/* Brand Row */}
                                        <div
                                            className="flex items-center px-6 py-4 cursor-pointer"
                                            onClick={() => !brand.is_disabled && toggleExpand(brand.id)}
                                        >
                                            {/* Logo */}
                                            <div className="flex-shrink-0 mr-4">
                                                <BrandAvatar
                                                    logoPath={brand.logo_path}
                                                    name={brand.name}
                                                    primaryColor={brand.primary_color}
                                                    size="lg"
                                                />
                                            </div>

                                            {/* Brand Info */}
                                            <div className="flex-1 min-w-0">
                                                <div className="flex items-center gap-2">
                                                    <p className={`text-sm font-semibold truncate ${
                                                        brand.is_disabled ? 'text-gray-400' : 'text-gray-900'
                                                    }`}>
                                                        {brand.name}
                                                    </p>
                                                    {brand.is_default && (
                                                        <span className="inline-flex items-center rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-medium text-indigo-800">
                                                            Default
                                                        </span>
                                                    )}
                                                    {brand.is_disabled && (
                                                        <span className="inline-flex items-center rounded-full bg-yellow-100 px-2 py-0.5 text-xs font-medium text-yellow-800">
                                                            Not Accessible (Plan Limit)
                                                        </span>
                                                    )}
                                                </div>
                                                <div className="mt-1 flex items-center gap-4 text-sm text-gray-500">
                                                    {brand.categories && brand.categories.length > 0 && (
                                                        <span>{brand.categories.length} categories</span>
                                                    )}
                                                    {brand.users && brand.users.length > 0 && (
                                                        <span>{brand.users.length} users</span>
                                                    )}
                                                </div>
                                            </div>

                                            {/* Action Buttons */}
                                            <div className="flex-shrink-0 ml-4 flex items-center gap-2">
                                                {!brand.is_disabled && (
                                                    <Link
                                                        href={`/app/brands/${brand.id}/edit`}
                                                        onClick={(e) => e.stopPropagation()}
                                                        className="rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                                                    >
                                                        Edit
                                                    </Link>
                                                )}
                                                {!brand.is_default && (
                                                    <button
                                                        type="button"
                                                        onClick={(e) => {
                                                            e.stopPropagation()
                                                            handleDelete(brand.id, brand.name)
                                                        }}
                                                        className="rounded-md bg-red-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-600"
                                                    >
                                                        Delete
                                                    </button>
                                                )}
                                            </div>

                                            {/* Expand/Collapse Icon */}
                                            <div className="flex-shrink-0 ml-4">
                                                <svg
                                                    className={`h-5 w-5 text-gray-400 transition-transform ${
                                                        expandedBrand === brand.id ? 'rotate-180' : ''
                                                    }`}
                                                    viewBox="0 0 20 20"
                                                    fill="currentColor"
                                                >
                                                    <path
                                                        fillRule="evenodd"
                                                        d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z"
                                                        clipRule="evenodd"
                                                    />
                                                </svg>
                                            </div>
                                        </div>

                                        {/* Expanded Content */}
                                        {expandedBrand === brand.id && (
                                            <div className="px-6 py-4 bg-gray-50 border-t border-gray-200">
                                                <div className="space-y-6">
                                                    {/* Branding Section */}
                                                    <div>
                                                        <h3 className="text-base font-semibold leading-6 text-gray-900 mb-4">
                                                            Branding
                                                        </h3>
                                                        <dl className="grid grid-cols-1 gap-x-4 gap-y-4 sm:grid-cols-2">
                                                            <div>
                                                                <dt className="text-sm font-medium text-gray-500">Brand Name</dt>
                                                                <dd className="mt-1 text-sm text-gray-900">{brand.name}</dd>
                                                            </div>
                                                            {brand.primary_color && (
                                                                <div>
                                                                    <dt className="text-sm font-medium text-gray-500">Primary Color</dt>
                                                                    <dd className="mt-1 flex items-center gap-2">
                                                                        <div
                                                                            className="h-6 w-6 rounded border border-gray-300"
                                                                            style={{ backgroundColor: brand.primary_color }}
                                                                        />
                                                                        <span className="text-sm text-gray-900">{brand.primary_color}</span>
                                                                    </dd>
                                                                </div>
                                                            )}
                                                            {brand.secondary_color && (
                                                                <div>
                                                                    <dt className="text-sm font-medium text-gray-500">Secondary Color</dt>
                                                                    <dd className="mt-1 flex items-center gap-2">
                                                                        <div
                                                                            className="h-6 w-6 rounded border border-gray-300"
                                                                            style={{ backgroundColor: brand.secondary_color }}
                                                                        />
                                                                        <span className="text-sm text-gray-900">{brand.secondary_color}</span>
                                                                    </dd>
                                                                </div>
                                                            )}
                                                            {brand.accent_color && (
                                                                <div>
                                                                    <dt className="text-sm font-medium text-gray-500">Accent Color</dt>
                                                                    <dd className="mt-1 flex items-center gap-2">
                                                                        <div
                                                                            className="h-6 w-6 rounded border border-gray-300"
                                                                            style={{ backgroundColor: brand.accent_color }}
                                                                        />
                                                                        <span className="text-sm text-gray-900">{brand.accent_color}</span>
                                                                    </dd>
                                                                </div>
                                                            )}
                                                        </dl>
                                                    </div>

                                                    {/* Categories Section */}
                                                    <div>
                                                        <div className="flex items-center justify-between mb-3">
                                                            <h3 className="text-base font-semibold leading-6 text-gray-900">
                                                                Categories
                                                            </h3>
                                                            {brand.categories && brand.categories.some(cat => cat.upgrade_available && cat.is_system) && (
                                                                <span className="inline-flex items-center rounded-md px-2.5 py-1.5 text-xs font-medium ring-1 ring-inset bg-amber-50 text-amber-700 ring-amber-600/20">
                                                                    Update available
                                                                </span>
                                                            )}
                                                        </div>
                                                        {brand.categories && brand.categories.length > 0 ? (
                                                            <div className="overflow-hidden bg-white rounded-lg border border-gray-200">
                                                                {/* Tab Navigation */}
                                                                <div className="border-b border-gray-200">
                                                                    <nav className="-mb-px flex space-x-8 px-4" aria-label="Tabs">
                                                                        <button
                                                                            onClick={() => setCategoryTab({ ...categoryTab, [brand.id]: 'asset' })}
                                                                            className={`
                                                                                group inline-flex items-center border-b-2 py-3 px-1 text-sm font-medium transition-colors
                                                                                ${(categoryTab[brand.id] || 'asset') === 'asset'
                                                                                    ? 'border-indigo-500 text-indigo-600'
                                                                                    : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
                                                                                }
                                                                            `}
                                                                        >
                                                                            <svg
                                                                                className={`
                                                                                    -ml-0.5 mr-2 h-5 w-5
                                                                                    ${(categoryTab[brand.id] || 'asset') === 'asset' ? 'text-indigo-500' : 'text-gray-400 group-hover:text-gray-500'}
                                                                                `}
                                                                                fill="none"
                                                                                viewBox="0 0 24 24"
                                                                                strokeWidth="1.5"
                                                                                stroke="currentColor"
                                                                            >
                                                                                <path strokeLinecap="round" strokeLinejoin="round" d="M2.25 12.75V12A2.25 2.25 0 014.5 9.75h15A2.25 2.25 0 0121.75 12v.75m-8.69-6.44l-2.12-2.12a1.5 1.5 0 00-1.061-.44H4.5A2.25 2.25 0 002.25 6v12a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9a2.25 2.25 0 00-2.25-2.25h-5.379a1.5 1.5 0 01-1.06-.44z" />
                                                                            </svg>
                                                                            Asset
                                                                        </button>
                                                                        <button
                                                                            onClick={() => setCategoryTab({ ...categoryTab, [brand.id]: 'marketing' })}
                                                                            className={`
                                                                                group inline-flex items-center border-b-2 py-3 px-1 text-sm font-medium transition-colors
                                                                                ${(categoryTab[brand.id] || 'asset') === 'marketing'
                                                                                    ? 'border-indigo-500 text-indigo-600'
                                                                                    : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
                                                                                }
                                                                            `}
                                                                        >
                                                                            <svg
                                                                                className={`
                                                                                    -ml-0.5 mr-2 h-5 w-5
                                                                                    ${(categoryTab[brand.id] || 'asset') === 'marketing' ? 'text-indigo-500' : 'text-gray-400 group-hover:text-gray-500'}
                                                                                `}
                                                                                fill="none"
                                                                                viewBox="0 0 24 24"
                                                                                strokeWidth="1.5"
                                                                                stroke="currentColor"
                                                                            >
                                                                                <path strokeLinecap="round" strokeLinejoin="round" d="M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75M15 10.5a3 3 0 11-6 0 3 3 0 016 0zm3 0h.008v.008H18V10.5zm-12 0h.008v.008H6V10.5z" />
                                                                            </svg>
                                                                            Marketing Asset
                                                                        </button>
                                                                    </nav>
                                                                </div>
                                                                {/* Categories List */}
                                                                <div className="divide-y divide-gray-200">
                                                                    {brand.categories
                                                                        .filter(category => category.asset_type === (categoryTab[brand.id] || 'asset'))
                                                                        .map((category) => (
                                                                            <div key={category.id} className="px-4 py-3 flex items-center">
                                                                                <div className="flex items-center flex-1 min-w-0">
                                                                                    {/* Category Icon */}
                                                                                    <div className="mr-3 flex-shrink-0">
                                                                                        {category.is_system || category.is_locked ? (
                                                                                            <CategoryIcon 
                                                                                                iconId={category.icon || 'folder'} 
                                                                                                className="h-5 w-5" 
                                                                                                color="text-gray-400"
                                                                                            />
                                                                                        ) : (
                                                                                            <CategoryIcon 
                                                                                                iconId={category.icon || 'plus-circle'} 
                                                                                                className="h-5 w-5" 
                                                                                                color="text-indigo-500"
                                                                                            />
                                                                                        )}
                                                                                    </div>
                                                                                    <div className="flex-1 min-w-0">
                                                                                        <p className="text-sm font-medium text-gray-900 truncate">
                                                                                            {category.name}
                                                                                        </p>
                                                                                    </div>
                                                                                </div>
                                                                            </div>
                                                                        ))}
                                                                    {brand.categories.filter(category => category.asset_type === (categoryTab[brand.id] || 'asset')).length === 0 && (
                                                                        <div className="px-4 py-8 text-center text-sm text-gray-500">
                                                                            No {(categoryTab[brand.id] || 'asset') === 'asset' ? 'Asset' : 'Marketing Asset'} categories yet.
                                                                        </div>
                                                                    )}
                                                                </div>
                                                            </div>
                                                        ) : (
                                                            <p className="text-sm text-gray-500">No categories yet.</p>
                                                        )}
                                                    </div>

                                                    {/* Users Section */}
                                                    <div>
                                                        <h3 className="text-base font-semibold leading-6 text-gray-900 mb-4">
                                                            Users
                                                        </h3>
                                                        
                                                        {/* Add User Form */}
                                                        <div className="mb-6 bg-white rounded-lg border border-gray-200 p-4">
                                                            <div className="flex items-center gap-3 mb-4">
                                                                <div className="flex-shrink-0">
                                                                    <svg className="h-6 w-6 text-gray-400" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                                                        <path strokeLinecap="round" strokeLinejoin="round" d="M18 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0ZM3 19.235v-.11a6.375 6.375 0 0 1 12.75 0v.109A12.318 12.318 0 0 1 9.375 21c-2.115 0-4.1-.56-5.375-1.765Z" />
                                                                    </svg>
                                                                </div>
                                                                <div className="flex-1">
                                                                    <h4 className="text-sm font-semibold text-gray-900">Add team members</h4>
                                                                    <p className="text-xs text-gray-500 mt-0.5">Invite new users or add existing company members</p>
                                                                </div>
                                                            </div>
                                                            
                                                            <UserInviteForm brandId={brand.id} defaultRole="member" />
                                                        </div>

                                                        {/* Recommended Users (Company users not on brand) */}
                                                        {brand.available_users && brand.available_users.length > 0 && (
                                                            <div className="mb-6">
                                                                <h4 className="text-sm font-semibold text-gray-900 mb-3">
                                                                    Team members previously added to projects
                                                                </h4>
                                                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                                                    {brand.available_users.map((user) => (
                                                                        <RecommendedUserCard 
                                                                            key={user.id} 
                                                                            user={user} 
                                                                            brandId={brand.id}
                                                                        />
                                                                    ))}
                                                                </div>
                                                            </div>
                                                        )}

                                                        {/* Pending Invitations */}
                                                        {brand.pending_invitations && brand.pending_invitations.length > 0 && (
                                                            <div className="mb-6">
                                                                <h4 className="text-sm font-semibold text-gray-900 mb-3">
                                                                    Pending Invitations
                                                                </h4>
                                                                <div className="bg-yellow-50 border border-yellow-200 rounded-lg divide-y divide-yellow-200">
                                                                    {brand.pending_invitations.map((invitation) => (
                                                                        <PendingInvitationCard 
                                                                            key={invitation.id} 
                                                                            invitation={invitation} 
                                                                            brandId={brand.id}
                                                                        />
                                                                    ))}
                                                                </div>
                                                            </div>
                                                        )}

                                                        {/* Existing Users */}
                                                        {brand.users && brand.users.length > 0 ? (
                                                            <div>
                                                                <h4 className="text-sm font-semibold text-gray-900 mb-3">
                                                                    Brand Members
                                                                </h4>
                                                                <div className="bg-white rounded-lg border border-gray-200 overflow-hidden">
                                                                    <ul className="divide-y divide-gray-200">
                                                                        {brand.users.map((user) => (
                                                                            <UserManagementCard 
                                                                                key={user.id} 
                                                                                user={user} 
                                                                                brandId={brand.id}
                                                                            />
                                                                        ))}
                                                                    </ul>
                                                                </div>
                                                            </div>
                                                        ) : (
                                                            <div className="text-center py-8 bg-gray-50 rounded-lg border border-gray-200">
                                                                <p className="text-sm text-gray-500">No users assigned to this brand yet.</p>
                                                            </div>
                                                        )}
                                                    </div>
                                                </div>
                                            </div>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                </div>
            </main>
            <ConfirmDialog
                open={deleteConfirm.open}
                onClose={() => setDeleteConfirm({ open: false, brandId: null, brandName: '' })}
                onConfirm={confirmDelete}
                title="Delete Brand"
                message={`Are you sure you want to delete "${deleteConfirm.brandName}"?`}
                variant="danger"
                confirmText="Delete"
            />
        </div>
    )
}

// User Invite Form Component
function UserInviteForm({ brandId, defaultRole = 'member' }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        role: defaultRole,
    })

    const handleSubmit = (e) => {
        e.preventDefault()
        post(`/app/brands/${brandId}/users/invite`, {
            preserveScroll: true,
            onSuccess: () => {
                reset()
            },
        })
    }

    return (
        <form onSubmit={handleSubmit} className="space-y-3">
            <div className="flex gap-2">
                <div className="flex-1">
                    <input
                        type="email"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                        placeholder="Enter an email"
                        className="block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                        required
                    />
                    {errors.email && (
                        <p className="mt-1 text-xs text-red-600">{errors.email}</p>
                    )}
                </div>
                <div className="flex-shrink-0">
                    <select
                        value={data.role}
                        onChange={(e) => setData('role', e.target.value)}
                        className="block rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                    >
                        <option value="member">Member</option>
                        <option value="admin">Admin</option>
                        <option value="brand_manager">Brand Manager</option>
                    </select>
                </div>
                <button
                    type="submit"
                    disabled={processing}
                    className="flex-shrink-0 rounded-md bg-indigo-600 px-4 py-1.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50"
                >
                    {processing ? 'Sending...' : 'Send invite'}
                </button>
            </div>
        </form>
    )
}

// Recommended User Card Component
function RecommendedUserCard({ user, brandId }) {
    const { post, processing } = useForm()
    const [role, setRole] = useState('member')

    const handleAdd = () => {
        post(`/app/brands/${brandId}/users/${user.id}/add`, {
            data: { role },
            preserveScroll: true,
        })
    }

    return (
        <div className="flex items-center gap-3 p-3 bg-white rounded-lg border border-gray-200 hover:border-indigo-300 transition-colors">
            <Avatar
                avatarUrl={user.avatar_url}
                firstName={user.first_name}
                lastName={user.last_name}
                email={user.email}
                size="sm"
            />
            <div className="flex-1 min-w-0">
                <p className="text-sm font-medium text-gray-900 truncate">
                    {user.name || user.email}
                </p>
                {user.name && (
                    <p className="text-xs text-gray-500 truncate">{user.email}</p>
                )}
            </div>
            <div className="flex items-center gap-2">
                <select
                    value={role}
                    onChange={(e) => setRole(e.target.value)}
                    className="text-xs rounded-md border-0 py-1 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600"
                    onClick={(e) => e.stopPropagation()}
                >
                    <option value="member">Member</option>
                    <option value="admin">Admin</option>
                    <option value="brand_manager">Brand Manager</option>
                </select>
                <button
                    type="button"
                    onClick={handleAdd}
                    disabled={processing}
                    className="flex-shrink-0 rounded-full bg-indigo-600 p-1.5 text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50"
                    title="Add to brand"
                >
                    <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" strokeWidth="2" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                </button>
            </div>
        </div>
    )
}

// Pending Invitation Card Component
function PendingInvitationCard({ invitation, brandId }) {
    const { post, processing } = useForm()

    const handleResend = () => {
        post(`/app/brands/${brandId}/invitations/${invitation.id}/resend`, {
            preserveScroll: true,
        })
    }

    const formatDate = (dateString) => {
        if (!dateString) return 'N/A'
        const date = new Date(dateString)
        return date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
    }

    return (
        <div className="p-3 flex items-center justify-between">
            <div className="flex-1 min-w-0">
                <div className="flex items-center gap-2">
                    <p className="text-sm font-medium text-gray-900">{invitation.email}</p>
                    {invitation.role && (
                        <span className="inline-flex items-center rounded-full bg-yellow-100 px-2 py-0.5 text-xs font-medium text-yellow-800">
                            {invitation.role}
                        </span>
                    )}
                </div>
                <div className="mt-1 text-xs text-gray-500">
                    {invitation.sent_at ? (
                        <>Sent: {formatDate(invitation.sent_at)}</>
                    ) : (
                        <>Created: {formatDate(invitation.created_at)}</>
                    )}
                </div>
            </div>
            <button
                type="button"
                onClick={handleResend}
                disabled={processing}
                className="ml-4 text-sm text-indigo-600 hover:text-indigo-800 font-medium disabled:opacity-50"
            >
                {processing ? 'Resending...' : 'Resend'}
            </button>
        </div>
    )
}

// User Management Card Component
function UserManagementCard({ user, brandId }) {
    const { delete: destroy, processing } = useForm()
    const [isEditing, setIsEditing] = useState(false)
    const [role, setRole] = useState(user.role || 'member')
    const [updatingRole, setUpdatingRole] = useState(false)
    const [showRemoveConfirm, setShowRemoveConfirm] = useState(false)

    const handleRoleUpdate = () => {
        setUpdatingRole(true)
        router.put(`/app/brands/${brandId}/users/${user.id}/role`, {
            role: role,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setIsEditing(false)
                setUpdatingRole(false)
            },
            onError: () => {
                setUpdatingRole(false)
            },
        })
    }

    const handleRemove = () => {
        setShowRemoveConfirm(true)
    }

    const confirmRemove = () => {
        destroy(`/app/brands/${brandId}/users/${user.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                setShowRemoveConfirm(false)
            },
        })
    }

    return (
        <li className="px-4 py-3">
            <div className="flex items-center gap-3">
                <Avatar
                    avatarUrl={user.avatar_url}
                    firstName={user.first_name}
                    lastName={user.last_name}
                    email={user.email}
                    size="md"
                />
                <div className="flex-1 min-w-0">
                    <p className="text-sm font-medium text-gray-900">
                        {user.name || user.email}
                    </p>
                    <p className="text-sm text-gray-500 truncate">{user.email}</p>
                </div>
                <div className="flex items-center gap-2">
                    {isEditing ? (
                        <>
                            <select
                                value={role}
                                onChange={(e) => setRole(e.target.value)}
                                className="text-xs rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600"
                            >
                                <option value="member">Member</option>
                                <option value="admin">Admin</option>
                                <option value="brand_manager">Brand Manager</option>
                            </select>
                            <button
                                type="button"
                                onClick={handleRoleUpdate}
                                disabled={updatingRole}
                                className="text-xs text-indigo-600 hover:text-indigo-800 font-medium disabled:opacity-50"
                            >
                                {updatingRole ? 'Saving...' : 'Save'}
                            </button>
                            <button
                                type="button"
                                onClick={() => {
                                    setIsEditing(false)
                                    setRole(user.role || 'member')
                                }}
                                className="text-xs text-gray-600 hover:text-gray-800 font-medium"
                            >
                                Cancel
                            </button>
                        </>
                    ) : (
                        <>
                            {user.role && (
                                <span className="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-800">
                                    {user.role}
                                </span>
                            )}
                            <button
                                type="button"
                                onClick={() => setIsEditing(true)}
                                className="text-xs text-indigo-600 hover:text-indigo-800 font-medium"
                            >
                                Edit Role
                            </button>
                            <button
                                type="button"
                                onClick={handleRemove}
                                disabled={processing}
                                className="text-xs text-red-600 hover:text-red-800 font-medium disabled:opacity-50"
                            >
                                Remove
                            </button>
                        </>
                    )}
                </div>
            </div>
            <ConfirmDialog
                open={showRemoveConfirm}
                onClose={() => setShowRemoveConfirm(false)}
                onConfirm={confirmRemove}
                title="Remove User"
                message={`Are you sure you want to remove ${user.name || user.email} from this brand?`}
                variant="warning"
                confirmText="Remove"
            />
        </li>
    )
}
