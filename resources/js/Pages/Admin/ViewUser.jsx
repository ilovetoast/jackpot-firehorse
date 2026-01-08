import { useState } from 'react'
import { Link, router, usePage } from '@inertiajs/react'
import AppNav from '../../Components/AppNav'
import AppFooter from '../../Components/AppFooter'
import Avatar from '../../Components/Avatar'
import ActivityFeed from '../../Components/ActivityFeed'
import { ArrowLeftIcon, CheckCircleIcon, XCircleIcon } from '@heroicons/react/24/outline'

export default function ViewUser({ user, companies, brand_assignments, activities }) {
    const { auth } = usePage().props

    const handleSuspend = () => {
        if (confirm(`Are you sure you want to SUSPEND ${user.first_name} ${user.last_name}'s account? This will block them from accessing any pages. They will receive a notification email.`)) {
            router.post(`/app/admin/users/${user.id}/suspend`, {
                preserveScroll: true,
                onSuccess: () => {
                    router.reload({ only: ['user'] })
                },
            })
        }
    }

    const handleUnsuspend = () => {
        if (confirm(`Are you sure you want to UNSUSPEND ${user.first_name} ${user.last_name}'s account? They will regain access to the platform.`)) {
            router.post(`/app/admin/users/${user.id}/unsuspend`, {
                preserveScroll: true,
                onSuccess: () => {
                    router.reload({ only: ['user'] })
                },
            })
        }
    }

    const handleCancelFromCompany = (companyId, companyName) => {
        if (confirm(`Are you sure you want to CANCEL ${user.first_name} ${user.last_name}'s account from ${companyName}? This will remove them from the company but keep their account active.`)) {
            router.post(`/app/admin/companies/${companyId}/users/${user.id}/cancel`, {
                preserveScroll: true,
                onSuccess: () => {
                    router.reload({ only: ['user'] })
                },
            })
        }
    }

    const handleDeleteAccount = () => {
        const userName = `${user.first_name || ''} ${user.last_name || ''}`.trim() || user.email || 'this user'
        
        if (confirm(`WARNING: Are you sure you want to PERMANENTLY DELETE ${userName}'s account? This action cannot be undone.`)) {
            if (confirm(`Final confirmation: This will permanently delete the account. Continue?`)) {
                if (!companies || companies.length === 0) {
                    alert('Cannot delete account: User is not associated with any company.')
                    return
                }
                
                const companyId = companies[0].id
                const userId = user.id
                
                if (!companyId || !userId) {
                    alert('Error: Missing company or user ID. Please refresh the page and try again.')
                    console.error('Missing IDs:', { companyId, userId, companies, user })
                    return
                }
                
                const deleteUrl = `/app/admin/companies/${companyId}/users/${userId}/delete`
                
                console.log('Deleting account:', { companyId, userId, deleteUrl, companies })
                
                router.post(deleteUrl, {}, {
                    preserveScroll: false,
                    onSuccess: (page) => {
                        console.log('Delete successful, redirecting...', page)
                        router.visit('/app/admin', {
                            data: { activeTab: 'users' },
                            preserveState: false,
                        })
                    },
                    onError: (errors) => {
                        console.error('Delete account error:', errors)
                        let errorMessage = 'Failed to delete account. '
                        if (errors.user) {
                            errorMessage += errors.user
                        } else if (errors.error) {
                            errorMessage += errors.error
                        } else if (typeof errors === 'string') {
                            errorMessage += errors
                        } else {
                            errorMessage += 'Please check the console for details.'
                        }
                        alert(errorMessage)
                    },
                    onFinish: () => {
                        console.log('Delete request finished')
                    },
                })
            }
        }
    }

    return (
        <div className="min-h-full">
            <AppNav brand={auth.activeBrand} tenant={null} />
            <main className="bg-gray-50">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
                    {/* Back Button */}
                    <div className="mb-6">
                        <Link
                            href="/app/admin"
                            className="inline-flex items-center text-sm font-medium text-gray-500 hover:text-gray-700"
                        >
                            <ArrowLeftIcon className="h-4 w-4 mr-1" />
                            Back to Admin
                        </Link>
                    </div>

                    {/* Header */}
                    <div className="mb-6">
                        <div className="flex items-center justify-between">
                            <div className="flex items-center gap-4">
                                <Avatar
                                    avatarUrl={user.avatar_url}
                                    firstName={user.first_name}
                                    lastName={user.last_name}
                                    email={user.email}
                                    size="xl"
                                />
                                <div>
                                    <h1 className="text-3xl font-bold tracking-tight text-gray-900">
                                        {user.first_name} {user.last_name}
                                    </h1>
                                    <p className="mt-1 text-sm text-gray-500">{user.email}</p>
                                    {user.is_suspended && (
                                        <span className="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium bg-red-100 text-red-800 mt-2">
                                            <XCircleIcon className="h-3 w-3 mr-1" />
                                            Suspended
                                        </span>
                                    )}
                                </div>
                            </div>
                        </div>
                    </div>

                    <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                        {/* Left Column - User Profile */}
                        <div className="lg:col-span-2 space-y-6">
                            {/* Profile Information */}
                            <div className="bg-white shadow-sm ring-1 ring-gray-200 rounded-lg overflow-hidden">
                                <div className="px-6 py-4 border-b border-gray-200">
                                    <h2 className="text-lg font-semibold text-gray-900">Profile Information</h2>
                                </div>
                                <div className="px-6 py-4">
                                    <dl className="grid grid-cols-1 gap-6 sm:grid-cols-2">
                                        <div>
                                            <dt className="text-sm font-medium text-gray-500">First Name</dt>
                                            <dd className="mt-1 text-sm text-gray-900">{user.first_name || 'N/A'}</dd>
                                        </div>
                                        <div>
                                            <dt className="text-sm font-medium text-gray-500">Last Name</dt>
                                            <dd className="mt-1 text-sm text-gray-900">{user.last_name || 'N/A'}</dd>
                                        </div>
                                        <div>
                                            <dt className="text-sm font-medium text-gray-500">Email</dt>
                                            <dd className="mt-1 text-sm text-gray-900">{user.email}</dd>
                                        </div>
                                        <div>
                                            <dt className="text-sm font-medium text-gray-500">Account Created</dt>
                                            <dd className="mt-1 text-sm text-gray-900">{user.created_at || 'N/A'}</dd>
                                        </div>
                                        {user.country && (
                                            <div>
                                                <dt className="text-sm font-medium text-gray-500">Country</dt>
                                                <dd className="mt-1 text-sm text-gray-900">{user.country}</dd>
                                            </div>
                                        )}
                                        {user.timezone && (
                                            <div>
                                                <dt className="text-sm font-medium text-gray-500">Timezone</dt>
                                                <dd className="mt-1 text-sm text-gray-900">{user.timezone}</dd>
                                            </div>
                                        )}
                                    </dl>
                                </div>
                            </div>

                            {/* Companies */}
                            {companies && companies.length > 0 && (
                                <div className="bg-white shadow-sm ring-1 ring-gray-200 rounded-lg overflow-hidden">
                                    <div className="px-6 py-4 border-b border-gray-200">
                                        <h2 className="text-lg font-semibold text-gray-900">Companies</h2>
                                    </div>
                                    <div className="px-6 py-4">
                                        <ul className="space-y-3">
                                            {companies.map((company) => (
                                                <li key={company.id} className="flex items-center justify-between py-2 border-b border-gray-100 last:border-0">
                                                    <div>
                                                        <span className="text-sm font-medium text-gray-900">{company.name}</span>
                                                        {company.role && (
                                                            <span className="ml-2 inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium bg-gray-100 text-gray-800 capitalize">
                                                                {company.role}
                                                            </span>
                                                        )}
                                                    </div>
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                </div>
                            )}

                            {/* Brand Assignments */}
                            {brand_assignments && brand_assignments.length > 0 && (
                                <div className="bg-white shadow-sm ring-1 ring-gray-200 rounded-lg overflow-hidden">
                                    <div className="px-6 py-4 border-b border-gray-200">
                                        <h2 className="text-lg font-semibold text-gray-900">Brand Assignments</h2>
                                    </div>
                                    <div className="px-6 py-4">
                                        <ul className="space-y-3">
                                            {brand_assignments.map((brand) => (
                                                <li key={brand.id} className="flex items-center justify-between py-2 border-b border-gray-100 last:border-0">
                                                    <div>
                                                        <span className="text-sm font-medium text-gray-900">{brand.name}</span>
                                                        {brand.tenant_name && (
                                                            <span className="ml-2 text-xs text-gray-500">({brand.tenant_name})</span>
                                                        )}
                                                    </div>
                                                    {brand.role && (
                                                        <span className="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium bg-indigo-100 text-indigo-800 capitalize">
                                                            {brand.role}
                                                        </span>
                                                    )}
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                </div>
                            )}

                            {/* Site Roles */}
                            {user.site_roles && user.site_roles.length > 0 && (
                                <div className="bg-white shadow-sm ring-1 ring-gray-200 rounded-lg overflow-hidden">
                                    <div className="px-6 py-4 border-b border-gray-200">
                                        <h2 className="text-lg font-semibold text-gray-900">Site Roles</h2>
                                    </div>
                                    <div className="px-6 py-4">
                                        <div className="flex flex-wrap gap-2">
                                            {user.site_roles.map((role) => (
                                                <span key={role} className="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium bg-yellow-100 text-yellow-800">
                                                    {role === 'site_owner' && '👑'}
                                                    {role.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase())}
                                                </span>
                                            ))}
                                        </div>
                                    </div>
                                </div>
                            )}
                        </div>

                        {/* Right Column - Activity Feed & Actions */}
                        <div className="lg:col-span-1 space-y-6">
                            {/* Actions */}
                            <div className="bg-white shadow-sm ring-1 ring-gray-200 rounded-lg overflow-hidden">
                                <div className="px-6 py-4 border-b border-gray-200">
                                    <h2 className="text-lg font-semibold text-gray-900">Actions</h2>
                                </div>
                                <div className="px-6 py-4 space-y-3">
                                    {user.is_suspended ? (
                                        <button
                                            type="button"
                                            onClick={handleUnsuspend}
                                            className="w-full rounded-md bg-green-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-green-500"
                                        >
                                            Unsuspend Account
                                        </button>
                                    ) : (
                                        <button
                                            type="button"
                                            onClick={handleSuspend}
                                            className="w-full rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-500"
                                        >
                                            Suspend Account
                                        </button>
                                    )}
                                    
                                    {companies && companies.length > 0 && (
                                        <>
                                            {companies.map((company) => (
                                                <button
                                                    key={company.id}
                                                    type="button"
                                                    onClick={() => handleCancelFromCompany(company.id, company.name)}
                                                    className="w-full rounded-md bg-orange-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-orange-500"
                                                >
                                                    Cancel from {company.name}
                                                </button>
                                            ))}
                                        </>
                                    )}
                                    
                                    <button
                                        type="button"
                                        onClick={handleDeleteAccount}
                                        className="w-full rounded-md bg-red-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-600"
                                    >
                                        Delete Account
                                    </button>
                                </div>
                            </div>

                            {/* Activity Feed */}
                            <ActivityFeed
                                activities={activities || []}
                                currentUser={auth.user}
                                primaryColor="#6366f1"
                                secondaryColor="#8b5cf6"
                                accentColor="#ec4899"
                            />
                        </div>
                    </div>
                </div>
            </main>
            <AppFooter />
        </div>
    )
}
