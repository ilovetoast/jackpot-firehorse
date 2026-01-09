import { Link, router, usePage, useForm } from '@inertiajs/react'
import { useState } from 'react'
import AppNav from '../../Components/AppNav'
import AppFooter from '../../Components/AppFooter'
import PlanLimitCallout from '../../Components/PlanLimitCallout'
import Avatar from '../../Components/Avatar'
import BrandRoleSelector from '../../Components/BrandRoleSelector'
import ConfirmDialog from '../../Components/ConfirmDialog'

export default function Team({ tenant, members, brands = [], current_user_count, max_users, user_limit_reached }) {
    const { auth } = usePage().props
    const [showInviteModal, setShowInviteModal] = useState(false)
    const [showOwnershipTransferModal, setShowOwnershipTransferModal] = useState(false)
    const [ownershipTransferTarget, setOwnershipTransferTarget] = useState(null)
    const [ownershipTransferSettingsLink, setOwnershipTransferSettingsLink] = useState(null)
    const [updatingRoles, setUpdatingRoles] = useState({})
    const [removeBrandConfirm, setRemoveBrandConfirm] = useState({ open: false, userId: null, brandId: null, brandName: '' })
    const [removeMemberConfirm, setRemoveMemberConfirm] = useState({ open: false, userId: null, userName: '' })
    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        role: 'member',
        brands: [],
    })

    const handleRemoveMember = (userId, userName) => {
        setRemoveMemberConfirm({ open: true, userId, userName })
    }

    const confirmRemoveMember = () => {
        if (removeMemberConfirm.userId) {
            router.delete(`/app/companies/${tenant.id}/team/${removeMemberConfirm.userId}`, {
                preserveScroll: true,
                onSuccess: () => {
                    setRemoveMemberConfirm({ open: false, userId: null, userName: '' })
                }
            })
        }
    }

    const handleInviteSubmit = (e) => {
        e.preventDefault()
        post(`/app/companies/${tenant.id}/team/invite`, {
            preserveScroll: true,
            onSuccess: () => {
                setShowInviteModal(false)
                reset()
            },
        })
    }

    const handleCloseInviteModal = () => {
        setShowInviteModal(false)
        reset()
    }

    const handleBrandAssignmentsChange = (brandAssignments) => {
        setData('brands', brandAssignments)
    }

    const handleTenantRoleChange = (userId, newRole) => {
        setUpdatingRoles(prev => ({ ...prev, [`tenant_${userId}`]: true }))
        router.put(`/app/companies/${tenant.id}/team/${userId}/role`, {
            role: newRole
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setUpdatingRoles(prev => ({ ...prev, [`tenant_${userId}`]: false }))
                router.reload({ preserveScroll: true })
            },
            onError: (errors) => {
                setUpdatingRoles(prev => ({ ...prev, [`tenant_${userId}`]: false }))
                
                // Check if this is an owner assignment attempt error
                if (errors?.error === 'cannot_assign_owner_role' || errors?.requires_ownership_transfer) {
                    const targetUser = members.find(m => m.id === userId)
                    setOwnershipTransferTarget({
                        id: userId,
                        name: targetUser?.name || errors?.target_user_name || 'User',
                        email: targetUser?.email || errors?.target_user_email || '',
                    })
                    setOwnershipTransferSettingsLink(errors?.settings_link || `/app/companies/settings#ownership-transfer`)
                    setShowOwnershipTransferModal(true)
                }
            }
        })
    }

    const handleInitiateOwnershipTransfer = () => {
        if (ownershipTransferTarget) {
            router.post(`/app/companies/${tenant.id}/ownership-transfer/initiate`, {
                new_owner_id: ownershipTransferTarget.id
            }, {
                preserveScroll: true,
                onSuccess: () => {
                    setShowOwnershipTransferModal(false)
                    setOwnershipTransferTarget(null)
                }
            })
        }
    }

    const handleBrandRoleChange = (userId, brandId, newRole) => {
        setUpdatingRoles(prev => ({ ...prev, [`brand_${userId}_${brandId}`]: true }))
        router.put(`/app/companies/${tenant.id}/team/${userId}/brands/${brandId}/role`, {
            role: newRole
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setUpdatingRoles(prev => ({ ...prev, [`brand_${userId}_${brandId}`]: false }))
                router.reload({ preserveScroll: true })
            },
            onError: () => {
                setUpdatingRoles(prev => ({ ...prev, [`brand_${userId}_${brandId}`]: false }))
            }
        })
    }

    const handleRemoveBrandAssignment = (userId, brandId, brandName) => {
        setRemoveBrandConfirm({ open: true, userId, brandId, brandName })
    }

    const confirmRemoveBrandAssignment = () => {
        if (removeBrandConfirm.userId && removeBrandConfirm.brandId) {
            router.delete(`/app/brands/${removeBrandConfirm.brandId}/users/${removeBrandConfirm.userId}`, {
                preserveScroll: true,
                onSuccess: () => {
                    setRemoveBrandConfirm({ open: false, userId: null, brandId: null, brandName: '' })
                    router.reload({ preserveScroll: true })
                }
            })
        }
    }

    const formatDate = (dateString) => {
        const date = new Date(dateString)
        return date.toLocaleDateString('en-US', { 
            year: 'numeric', 
            month: 'short', 
            day: 'numeric' 
        })
    }

    const getRoleColors = (role) => {
        const roleLower = role?.toLowerCase()
        // Convert 'owner' to 'admin' for brand roles (owner is only for tenant-level)
        const normalizedRole = roleLower === 'owner' ? 'admin' : roleLower
        const colors = {
            owner: 'bg-orange-100 text-orange-800 border-orange-200',
            admin: 'bg-purple-100 text-purple-800 border-purple-200',
            brand_manager: 'bg-blue-100 text-blue-800 border-blue-200',
            member: 'bg-gray-100 text-gray-800 border-gray-200',
        }
        return colors[normalizedRole] || colors.member
    }

    return (
        <div className="min-h-screen bg-gray-50">
            <AppNav />
            <main className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
                {/* Header */}
                <div className="mb-8">
                    <h1 className="text-3xl font-bold text-gray-900">Team Members</h1>
                    <p className="mt-2 text-sm text-gray-600">
                        Manage members and invitations for {tenant.name} ({current_user_count} of {max_users === Number.MAX_SAFE_INTEGER || max_users > 1000 ? '∞' : max_users} users)
                    </p>
                </div>

                {/* Invite Button */}
                <div className="mb-6 flex justify-end">
                    <button
                        type="button"
                        onClick={() => setShowInviteModal(true)}
                        disabled={user_limit_reached}
                        className="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                        <svg className="mr-2 h-4 w-4" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
                        </svg>
                        Invite Member
                    </button>
                </div>

                {/* Active Members Section */}
                <div className="mb-6">
                    <div className="flex items-center justify-between mb-4">
                        <div>
                            <h2 className="text-lg font-semibold text-gray-900">Active Members</h2>
                            <p className="mt-1 text-sm text-gray-500">People who have joined {tenant.name}</p>
                        </div>
                        <span className="text-sm text-gray-500">{current_user_count} of {max_users === Number.MAX_SAFE_INTEGER || max_users > 1000 ? '∞' : max_users} users</span>
                    </div>

                    {/* User Limit Reached Banner */}
                    {user_limit_reached && (
                        <PlanLimitCallout
                            title="User limit reached"
                            message={`Users limit reached (${current_user_count} of ${max_users === Number.MAX_SAFE_INTEGER || max_users > 1000 ? 'unlimited' : max_users}). Please upgrade your plan.`}
                        />
                    )}

                    {/* Members List */}
                    <div className="space-y-4">
                        {members.map((member) => {
                            const isOwner = member.role === 'Owner'
                            const canRemove = !isOwner && member.id !== auth.user?.id

                            return (
                                <div key={member.id} className="flex items-center justify-between rounded-lg border border-gray-200 bg-white p-4">
                                    <div className="flex items-center space-x-4">
                                        {/* Avatar */}
                                        <Avatar
                                            avatarUrl={member.avatar_url}
                                            firstName={member.first_name}
                                            lastName={member.last_name}
                                            email={member.email}
                                            size="md"
                                        />

                                        {/* Member Info */}
                                        <div className="flex-1">
                                            <div className="flex items-center space-x-2 flex-wrap gap-2">
                                                <span className="text-sm font-medium text-gray-900">
                                                    {member.first_name} {member.last_name}
                                                </span>
                                                {member.is_orphaned && (
                                                    <span className="inline-flex items-center rounded-md bg-yellow-50 px-2 py-1 text-xs font-medium text-yellow-800 ring-1 ring-inset ring-yellow-600/20">
                                                        ⚠️ Orphaned Record
                                                    </span>
                                                )}
                                                {!member.is_orphaned && (
                                                    <>
                                                        {/* Tenant Role Selector */}
                                                        <div className="relative inline-flex items-center">
                                                            <select
                                                                value={member.role_value || 'member'}
                                                                onChange={(e) => handleTenantRoleChange(member.id, e.target.value)}
                                                                disabled={isOwner || updatingRoles[`tenant_${member.id}`]}
                                                                className={`inline-flex items-center rounded-md border px-2.5 py-1 text-xs font-medium cursor-pointer transition-all focus:outline-none focus:ring-2 focus:ring-offset-1 focus:ring-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed appearance-none pr-7 ${getRoleColors(member.role_value)}`}
                                                            >
                                                                <option value="owner">👑 Owner</option>
                                                                <option value="admin">Admin</option>
                                                                <option value="brand_manager">Brand Manager</option>
                                                                <option value="member">Member</option>
                                                            </select>
                                                            <svg className="absolute right-1.5 h-3 w-3 pointer-events-none text-gray-600" fill="none" viewBox="0 0 24 24" strokeWidth="2" stroke="currentColor">
                                                                <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                                                            </svg>
                                                        </div>
                                                        {updatingRoles[`tenant_${member.id}`] && (
                                                            <span className="text-xs text-gray-500">Updating...</span>
                                                        )}
                                                    </>
                                                )}
                                            </div>
                                            <p className="mt-1 text-sm text-gray-500">{member.email}</p>
                                            {member.is_orphaned && (
                                                <p className="mt-1 text-xs text-yellow-700 bg-yellow-50 px-2 py-1 rounded">
                                                    This user is not a member of the company but has brand assignments. Remove the brand assignment to clean up.
                                                </p>
                                            )}
                                            
                                            {/* Brand Assignments - Displayed prominently with role selectors */}
                                            {member.brand_assignments && Array.isArray(member.brand_assignments) && member.brand_assignments.length > 0 && (
                                                <div className="mt-3">
                                                    <p className="text-xs font-medium text-gray-700 mb-2">Brand Roles:</p>
                                                    <div className="flex flex-col gap-2">
                                                        {member.brand_assignments.map((ba, index) => {
                                                            const isUpdating = updatingRoles[`brand_${member.id}_${ba.id}`]
                                                            
                                                            return (
                                                                <div key={`${ba.id}-${ba.pivot_id || index}`} className="flex items-center gap-1.5">
                                                                    <span className="text-xs font-medium text-gray-700">{ba.name}:</span>
                                                                    {member.is_orphaned ? (
                                                                        // For orphaned records, show role as text and allow removal
                                                                        <div className="flex items-center gap-2">
                                                                            <span className={`inline-flex items-center rounded-md border px-2.5 py-1 text-xs font-medium ${getRoleColors(ba.role)}`}>
                                                                                {ba.role || 'member'}
                                                                            </span>
                                                                            <button
                                                                                type="button"
                                                                                onClick={() => handleRemoveBrandAssignment(member.id, ba.id, ba.name)}
                                                                                className="text-red-600 hover:text-red-800 text-xs"
                                                                                title="Remove orphaned brand assignment"
                                                                            >
                                                                                Remove
                                                                            </button>
                                                                        </div>
                                                                    ) : (
                                                                        // For regular members, show role selector and remove button
                                                                        <>
                                                                            <div className="relative inline-flex items-center">
                                                                                <select
                                                                                    value={ba.role?.toLowerCase() || 'member'}
                                                                                    onChange={(e) => handleBrandRoleChange(member.id, ba.id, e.target.value)}
                                                                                    disabled={isUpdating}
                                                                                    className={`inline-flex items-center rounded-md border px-2.5 py-1 text-xs font-medium cursor-pointer transition-all focus:outline-none focus:ring-2 focus:ring-offset-1 focus:ring-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed appearance-none pr-7 ${getRoleColors(ba.role)}`}
                                                                                >
                                                                                    <option value="admin">Admin</option>
                                                                                    <option value="brand_manager">Brand Manager</option>
                                                                                    <option value="member">Member</option>
                                                                                </select>
                                                                                <svg className="absolute right-1.5 h-3 w-3 pointer-events-none text-gray-600" fill="none" viewBox="0 0 24 24" strokeWidth="2" stroke="currentColor">
                                                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                                                                                </svg>
                                                                            </div>
                                                                            {isUpdating && (
                                                                                <span className="text-xs text-gray-500">Updating...</span>
                                                                            )}
                                                                            <button
                                                                                type="button"
                                                                                onClick={() => handleRemoveBrandAssignment(member.id, ba.id, ba.name)}
                                                                                className="text-red-600 hover:text-red-800 text-xs font-medium ml-2"
                                                                                title="Remove brand access"
                                                                            >
                                                                                Remove
                                                                            </button>
                                                                        </>
                                                                    )}
                                                                </div>
                                                            )
                                                        })}
                                                    </div>
                                                </div>
                                            )}
                                            
                                            <p className="mt-2 text-xs text-gray-400">
                                                Joined {formatDate(member.joined_at)}
                                            </p>
                                        </div>
                                    </div>

                                    {/* Remove Button */}
                                    {canRemove && (
                                        <button
                                            type="button"
                                            onClick={() => handleRemoveMember(member.id, member.first_name && member.last_name ? `${member.first_name} ${member.last_name}` : member.email)}
                                            className="text-red-600 hover:text-red-800"
                                        >
                                            <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.12m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                                            </svg>
                                        </button>
                                    )}
                                </div>
                            )
                        })}
                    </div>
                </div>

                {/* Invite Member Modal */}
                {showInviteModal && (
                    <div className="fixed inset-0 z-50 overflow-y-auto">
                        <div className="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                            <div className="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" onClick={handleCloseInviteModal}></div>
                            <div className="relative transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:p-6">
                                <div className="absolute right-0 top-0 hidden pr-4 pt-4 sm:block">
                                    <button
                                        type="button"
                                        onClick={handleCloseInviteModal}
                                        className="rounded-md bg-white text-gray-400 hover:text-gray-500"
                                    >
                                        <span className="sr-only">Close</span>
                                        <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                    </button>
                                </div>
                                <form onSubmit={handleInviteSubmit}>
                                    <div>
                                        <h3 className="text-lg font-medium leading-6 text-gray-900 mb-4">
                                            Invite Team Member
                                        </h3>
                                        <div className="mb-4">
                                            <label htmlFor="email" className="block text-sm font-medium text-gray-700 mb-2">
                                                Email Address
                                            </label>
                                            <input
                                                type="email"
                                                id="email"
                                                value={data.email}
                                                onChange={(e) => setData('email', e.target.value)}
                                                className="block w-full rounded-md border-0 py-1.5 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                                                placeholder="colleague@example.com"
                                                required
                                            />
                                            {errors.email && (
                                                <p className="mt-1 text-sm text-red-600">{errors.email}</p>
                                            )}
                                        </div>
                                        <div className="mb-4">
                                            <label htmlFor="role" className="block text-sm font-medium text-gray-700 mb-2">
                                                Company Role (optional - will default to first brand role)
                                            </label>
                                            <select
                                                id="role"
                                                value={data.role}
                                                onChange={(e) => setData('role', e.target.value)}
                                                className="block w-full rounded-md border-0 py-1.5 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                                            >
                                                <option value="member">Member</option>
                                                <option value="admin">Admin</option>
                                                <option value="brand_manager">Brand Manager</option>
                                                <option value="owner">Owner</option>
                                            </select>
                                            <p className="mt-1 text-xs text-gray-500">
                                                Note: Owner role is for company-level only. Brand roles will default to Admin for owners.
                                            </p>
                                            {errors.role && (
                                                <p className="mt-1 text-sm text-red-600">{errors.role}</p>
                                            )}
                                        </div>
                                        <div className="mb-4">
                                            <BrandRoleSelector
                                                brands={brands}
                                                selectedBrands={data.brands}
                                                onChange={handleBrandAssignmentsChange}
                                                errors={errors}
                                                required={true}
                                            />
                                        </div>
                                        {user_limit_reached && (
                                            <div className="mb-4 rounded-md bg-yellow-50 p-4">
                                                <div className="flex">
                                                    <div className="flex-shrink-0">
                                                        <svg className="h-5 w-5 text-yellow-400" viewBox="0 0 20 20" fill="currentColor">
                                                            <path fillRule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clipRule="evenodd" />
                                                        </svg>
                                                    </div>
                                                    <div className="ml-3">
                                                        <h3 className="text-sm font-medium text-yellow-800">
                                                            User Limit Reached
                                                        </h3>
                                                        <div className="mt-2 text-sm text-yellow-700">
                                                            <p>You've reached your plan's user limit ({current_user_count} of {max_users === Number.MAX_SAFE_INTEGER || max_users > 1000 ? 'unlimited' : max_users}). Please upgrade your plan to invite more members.</p>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        )}
                                    </div>
                                    <div className="mt-5 sm:mt-6 sm:grid sm:grid-flow-row-dense sm:grid-cols-2 sm:gap-3">
                                        <button
                                            type="submit"
                                            disabled={processing || user_limit_reached || !data.brands || data.brands.length === 0}
                                            className="inline-flex w-full justify-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50 disabled:cursor-not-allowed sm:col-start-2"
                                        >
                                            {processing ? 'Sending...' : 'Send Invitation'}
                                        </button>
                                        <button
                                            type="button"
                                            onClick={handleCloseInviteModal}
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

                {/* Ownership Transfer Modal */}
                {showOwnershipTransferModal && ownershipTransferTarget && (
                    <div className="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
                        <div className="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                            <div className="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" onClick={() => setShowOwnershipTransferModal(false)}></div>
                            <div className="relative transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:p-6">
                                <div className="sm:flex sm:items-start">
                                    <div className="mx-auto flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-full bg-orange-100 sm:mx-0 sm:h-10 sm:w-10">
                                        <svg className="h-6 w-6 text-orange-600" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" />
                                        </svg>
                                    </div>
                                    <div className="mt-3 text-center sm:ml-4 sm:mt-0 sm:text-left">
                                        <h3 className="text-base font-semibold leading-6 text-gray-900" id="modal-title">
                                            Ownership Transfer Required
                                        </h3>
                                        <div className="mt-2">
                                            <p className="text-sm text-gray-500">
                                                Please use the ownership transfer process in the{' '}
                                                <Link 
                                                    href={ownershipTransferSettingsLink || `/app/companies/settings#ownership-transfer`}
                                                    className="text-indigo-600 hover:text-indigo-500 underline font-medium"
                                                    onClick={() => setShowOwnershipTransferModal(false)}
                                                >
                                                    Company settings
                                                </Link>
                                                .
                                            </p>
                                        </div>
                                        <div className="mt-4 rounded-md bg-blue-50 p-4">
                                            <h4 className="text-sm font-medium text-blue-900 mb-2">What happens next:</h4>
                                            <ol className="list-decimal list-inside space-y-1 text-sm text-blue-700">
                                                <li>You'll receive a confirmation email</li>
                                                <li>You must confirm the transfer via the email link</li>
                                                <li>{ownershipTransferTarget.name} will receive an acceptance email</li>
                                                <li>After they accept, ownership will be transferred</li>
                                            </ol>
                                        </div>
                                        <div className="mt-4">
                                            <p className="text-sm text-gray-600">
                                                <strong>Transfer ownership to:</strong> {ownershipTransferTarget.name} {ownershipTransferTarget.email && `(${ownershipTransferTarget.email})`}
                                            </p>
                                        </div>
                                    </div>
                                </div>
                                <div className="mt-5 sm:mt-4 sm:flex sm:flex-row-reverse sm:gap-3">
                                    <button
                                        type="button"
                                        onClick={handleInitiateOwnershipTransfer}
                                        className="inline-flex w-full justify-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 sm:ml-3 sm:w-auto"
                                    >
                                        Initiate Ownership Transfer
                                    </button>
                                    <Link
                                        href={ownershipTransferSettingsLink || `/app/companies/settings#ownership-transfer`}
                                        className="mt-3 inline-flex w-full justify-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 sm:mt-0 sm:w-auto"
                                        onClick={() => setShowOwnershipTransferModal(false)}
                                    >
                                        Go to Settings
                                    </Link>
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setShowOwnershipTransferModal(false)
                                            setOwnershipTransferTarget(null)
                                        }}
                                        className="mt-3 inline-flex w-full justify-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 sm:mt-0 sm:w-auto"
                                    >
                                        Cancel
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                )}
            </main>
            <AppFooter />
            
            {/* Remove Brand Assignment Confirmation Dialog */}
            <ConfirmDialog
                open={removeBrandConfirm.open}
                onClose={() => setRemoveBrandConfirm({ open: false, userId: null, brandId: null, brandName: '' })}
                onConfirm={confirmRemoveBrandAssignment}
                title="Remove Brand Access"
                message={(() => {
                    const member = members.find(m => m.id === removeBrandConfirm.userId)
                    const userName = member ? (member.first_name && member.last_name ? `${member.first_name} ${member.last_name}` : member.email) : 'this user'
                    return `Are you sure you want to remove ${userName}'s access to the "${removeBrandConfirm.brandName}" brand?`
                })()}
                confirmText="Remove Access"
                cancelText="Cancel"
                variant="danger"
            />
            
            {/* Remove Team Member Confirmation Dialog */}
            <ConfirmDialog
                open={removeMemberConfirm.open}
                onClose={() => setRemoveMemberConfirm({ open: false, userId: null, userName: '' })}
                onConfirm={confirmRemoveMember}
                title="Remove Team Member"
                message={`Are you sure you want to remove ${removeMemberConfirm.userName || 'this team member'} from the account? This action cannot be undone.`}
                confirmText="Remove Member"
                cancelText="Cancel"
                variant="danger"
            />
        </div>
    )
}
