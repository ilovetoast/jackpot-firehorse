import { Link, router, usePage, useForm } from '@inertiajs/react'
import { useState, useEffect, useCallback, useRef } from 'react'
import AppNav from '../../Components/AppNav'
import AppHead from '../../Components/AppHead'
import AppFooter from '../../Components/AppFooter'
import CompanyTabs from '../../Components/Company/CompanyTabs'
import UserRow from '../../Components/Company/UserRow'
import PlanLimitCallout from '../../Components/PlanLimitCallout'
import BrandRoleSelector from '../../Components/BrandRoleSelector'
import ConfirmDialog from '../../Components/ConfirmDialog'

function useDebounce(value, delay) {
    const [debouncedValue, setDebouncedValue] = useState(value)
    useEffect(() => {
        const t = setTimeout(() => setDebouncedValue(value), delay)
        return () => clearTimeout(t)
    }, [value, delay])
    return debouncedValue
}

export default function Team({ tenant, brands = [], tenant_roles = [], current_user_count, max_users, user_limit_reached }) {
    const { auth } = usePage().props

    const [users, setUsers] = useState([])
    const [loading, setLoading] = useState(true)
    const [meta, setMeta] = useState({ current_page: 1, last_page: 1, total: 0 })
    const [page, setPage] = useState(1)
    const [search, setSearch] = useState('')
    const [filterBrand, setFilterBrand] = useState('')
    const [filterRole, setFilterRole] = useState('')
    const [updatingKeys, setUpdatingKeys] = useState({})
    const [deleteFromCompanyConfirm, setDeleteFromCompanyConfirm] = useState({ open: false, userId: null, userName: '' })
    const [showInviteModal, setShowInviteModal] = useState(false)
    const [showOwnershipTransferModal, setShowOwnershipTransferModal] = useState(false)
    const [ownershipTransferTarget, setOwnershipTransferTarget] = useState(null)
    const [ownershipTransferSettingsLink, setOwnershipTransferSettingsLink] = useState(null)

    const debouncedSearch = useDebounce(search, 300)

    const prevFiltersRef = useRef([debouncedSearch, filterBrand, filterRole])

    const fetchUsers = useCallback(() => {
        setLoading(true)
        const filtersChanged =
            prevFiltersRef.current[0] !== debouncedSearch ||
            prevFiltersRef.current[1] !== filterBrand ||
            prevFiltersRef.current[2] !== filterRole
        if (filtersChanged) {
            prevFiltersRef.current = [debouncedSearch, filterBrand, filterRole]
        }
        const pageToUse = filtersChanged ? 1 : page
        const params = new URLSearchParams({ page: pageToUse })
        if (debouncedSearch) params.set('search', debouncedSearch)
        if (filterBrand) params.set('brand_id', filterBrand)
        if (filterRole) params.set('role', filterRole)
        fetch(`/app/api/companies/users?${params}`, { credentials: 'same-origin' })
            .then((r) => r.json())
            .then((data) => {
                if (data.data) {
                    setUsers(data.data)
                    setMeta(data.meta || {})
                }
            })
            .catch(() => setUsers([]))
            .finally(() => setLoading(false))
    }, [page, debouncedSearch, filterBrand, filterRole])

    useEffect(() => {
        setPage(1)
    }, [debouncedSearch, filterBrand, filterRole])

    useEffect(() => {
        fetchUsers()
    }, [page, debouncedSearch, filterBrand, filterRole])

    const handleCompanyRoleChange = (userId, newRole) => {
        setUpdatingKeys((prev) => ({ ...prev, [`tenant_${userId}`]: true }))
        router.put(`/app/companies/${tenant.id}/team/${userId}/role`, { role: newRole }, {
            preserveScroll: true,
            onSuccess: () => {
                setUpdatingKeys((prev) => ({ ...prev, [`tenant_${userId}`]: false }))
                fetchUsers()
            },
            onError: (errors) => {
                setUpdatingKeys((prev) => ({ ...prev, [`tenant_${userId}`]: false }))
                if (errors?.error === 'cannot_assign_owner_role' || errors?.requires_ownership_transfer) {
                    const targetUser = users.find((u) => u.id === userId)
                    setOwnershipTransferTarget({
                        id: userId,
                        name: targetUser?.name || errors?.target_user_name || 'User',
                        email: targetUser?.email || errors?.target_user_email || '',
                    })
                    setOwnershipTransferSettingsLink(errors?.settings_link || '/app/companies/settings#ownership-transfer')
                    setShowOwnershipTransferModal(true)
                }
            },
        })
    }

    const handleBrandRoleChange = (userId, brandId, newRole) => {
        setUpdatingKeys((prev) => ({ ...prev, [`brand_${userId}_${brandId}`]: true }))
        router.put(`/app/companies/${tenant.id}/team/${userId}/brands/${brandId}/role`, { role: newRole }, {
            preserveScroll: true,
            onSuccess: () => {
                setUpdatingKeys((prev) => ({ ...prev, [`brand_${userId}_${brandId}`]: false }))
                fetchUsers()
            },
            onError: () => {
                setUpdatingKeys((prev) => ({ ...prev, [`brand_${userId}_${brandId}`]: false }))
            },
        })
    }

    const handleRemoveBrand = (userId, brandId, brandName) => {
        router.delete(`/app/brands/${brandId}/users/${userId}`, {
            preserveScroll: true,
            onSuccess: () => fetchUsers(),
        })
    }

    const handleDeleteFromCompany = (userId, userName) => {
        setDeleteFromCompanyConfirm({ open: true, userId, userName })
    }

    const confirmDeleteFromCompany = () => {
        if (deleteFromCompanyConfirm.userId) {
            router.delete(`/app/companies/${tenant.id}/team/${deleteFromCompanyConfirm.userId}/delete-from-company`, {
                preserveScroll: true,
                onSuccess: () => {
                    setDeleteFromCompanyConfirm({ open: false, userId: null, userName: '' })
                    fetchUsers()
                },
            })
        }
    }

    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        role: 'member',
        brands: [],
    })

    const handleInviteSubmit = (e) => {
        e.preventDefault()
        post(`/app/companies/${tenant.id}/team/invite`, {
            preserveScroll: true,
            onSuccess: () => {
                setShowInviteModal(false)
                reset()
                fetchUsers()
            },
        })
    }

    const handleBrandAssignmentsChange = (brandAssignments) => {
        setData('brands', brandAssignments)
    }

    const displayCount = meta.total ?? current_user_count ?? 0
    const maxDisplay = max_users === Number.MAX_SAFE_INTEGER || max_users > 1000 ? '∞' : max_users

    return (
        <div className="min-h-screen flex flex-col bg-gray-50">
            <AppHead title="Team" />
            <AppNav />
            <main className="flex-1 mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8 w-full">
                <div className="mb-6">
                    <h1 className="text-3xl font-bold text-gray-900">Team Members</h1>
                    <p className="mt-2 text-sm text-gray-600">
                        Manage members and invitations for {tenant.name} ({displayCount} of {maxDisplay} users)
                    </p>
                </div>

                <CompanyTabs />

                <div className="mb-6 flex flex-wrap items-center justify-between gap-4">
                    <button
                        type="button"
                        onClick={() => setShowInviteModal(true)}
                        disabled={user_limit_reached}
                        className="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                        <svg className="mr-2 h-4 w-4" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
                        </svg>
                        Invite Member
                    </button>

                    {/* Search + Filters */}
                    <div className="flex flex-wrap items-center gap-3">
                        <input
                            type="search"
                            placeholder="Search users..."
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            className="rounded-md border-gray-300 text-sm py-1.5 px-3 w-48 focus:ring-indigo-500 focus:border-indigo-500"
                        />
                        <select
                            value={filterBrand}
                            onChange={(e) => setFilterBrand(e.target.value)}
                            className="rounded-md border-gray-300 text-sm py-1.5"
                        >
                            <option value="">All brands</option>
                            {(brands || []).map((b) => (
                                <option key={b.id} value={b.id}>{b.name}</option>
                            ))}
                        </select>
                        <select
                            value={filterRole}
                            onChange={(e) => setFilterRole(e.target.value)}
                            className="rounded-md border-gray-300 text-sm py-1.5"
                        >
                            <option value="">All roles</option>
                            <option value="owner">Owner</option>
                            <option value="admin">Admin</option>
                            <option value="member">Member</option>
                        </select>
                    </div>
                </div>

                {user_limit_reached && (
                    <PlanLimitCallout
                        title="User limit reached"
                        message={`Users limit reached (${current_user_count} of ${maxDisplay}). Please upgrade your plan.`}
                    />
                )}

                <div className="rounded-lg border border-gray-200 bg-white overflow-hidden">
                    {loading ? (
                        <div className="py-12 text-center text-gray-500">Loading...</div>
                    ) : users.length === 0 ? (
                        <div className="py-12 text-center text-gray-500">No users found.</div>
                    ) : (
                        users.map((user) => (
                            <UserRow
                                key={user.id}
                                user={user}
                                brands={brands}
                                tenant={tenant}
                                authUserId={auth?.user?.id}
                                onCompanyRoleChange={handleCompanyRoleChange}
                                onBrandRoleChange={handleBrandRoleChange}
                                onRemoveBrand={handleRemoveBrand}
                                onDeleteFromCompany={handleDeleteFromCompany}
                                updatingKeys={updatingKeys}
                            />
                        ))
                    )}
                </div>

                {/* Pagination */}
                {meta.last_page > 1 && (
                    <div className="mt-4 flex items-center justify-between">
                        <p className="text-sm text-gray-600">
                            Showing {(meta.current_page - 1) * 25 + 1}–{Math.min(meta.current_page * 25, meta.total)} of {meta.total} users
                        </p>
                        <div className="flex gap-2">
                            <button
                                type="button"
                                onClick={() => setPage((p) => Math.max(1, p - 1))}
                                disabled={meta.current_page <= 1}
                                className="rounded-md border border-gray-300 px-3 py-1 text-sm disabled:opacity-50 disabled:cursor-not-allowed hover:bg-gray-50"
                            >
                                Prev
                            </button>
                            <button
                                type="button"
                                onClick={() => setPage((p) => Math.min(meta.last_page, p + 1))}
                                disabled={meta.current_page >= meta.last_page}
                                className="rounded-md border border-gray-300 px-3 py-1 text-sm disabled:opacity-50 disabled:cursor-not-allowed hover:bg-gray-50"
                            >
                                Next
                            </button>
                        </div>
                    </div>
                )}

                {/* Invite Modal */}
                {showInviteModal && (
                    <div className="fixed inset-0 z-50 overflow-y-auto">
                        <div className="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                            <div className="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" onClick={() => setShowInviteModal(false)} />
                            <div className="relative transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:p-6">
                                <div className="absolute right-0 top-0 hidden pr-4 pt-4 sm:block">
                                    <button type="button" onClick={() => setShowInviteModal(false)} className="rounded-md bg-white text-gray-400 hover:text-gray-500">
                                        <span className="sr-only">Close</span>
                                        <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                                    </button>
                                </div>
                                <form onSubmit={handleInviteSubmit}>
                                    <h3 className="text-lg font-medium leading-6 text-gray-900 mb-4">Invite Team Member</h3>
                                    <div className="mb-4">
                                        <label htmlFor="email" className="block text-sm font-medium text-gray-700 mb-2">Email Address</label>
                                        <input
                                            type="email"
                                            id="email"
                                            value={data.email}
                                            onChange={(e) => setData('email', e.target.value)}
                                            className="block w-full rounded-md border-0 py-1.5 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm"
                                            placeholder="colleague@example.com"
                                            required
                                        />
                                        {errors.email && <p className="mt-1 text-sm text-red-600">{errors.email}</p>}
                                    </div>
                                    <div className="mb-4">
                                        <label htmlFor="role" className="block text-sm font-medium text-gray-700 mb-2">Company Role</label>
                                        <select id="role" value={data.role} onChange={(e) => setData('role', e.target.value)} className="block w-full rounded-md border-0 py-1.5 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm">
                                            {(tenant_roles || []).map((r) => <option key={r.value} value={r.value}>{r.label}</option>)}
                                            {(!tenant_roles || tenant_roles.length === 0) && (
                                                <>
                                                    <option value="member">Member</option>
                                                    <option value="admin">Admin</option>
                                                </>
                                            )}
                                        </select>
                                    </div>
                                    <div className="mb-4">
                                        <BrandRoleSelector brands={brands} selectedBrands={data.brands} onChange={handleBrandAssignmentsChange} errors={errors} required />
                                    </div>
                                    <div className="mt-5 sm:mt-6 sm:grid sm:grid-flow-row-dense sm:grid-cols-2 sm:gap-3">
                                        <button type="submit" disabled={processing || user_limit_reached || !data.brands?.length} className="inline-flex w-full justify-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50 sm:col-start-2">
                                            {processing ? 'Sending...' : 'Send Invitation'}
                                        </button>
                                        <button type="button" onClick={() => setShowInviteModal(false)} className="mt-3 inline-flex w-full justify-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 sm:col-start-1 sm:mt-0">
                                            Cancel
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                )}

                {/* Ownership Transfer Modal (when assigning owner role) */}
                {showOwnershipTransferModal && ownershipTransferTarget && (
                    <div className="fixed inset-0 z-50 overflow-y-auto">
                        <div className="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                            <div className="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" onClick={() => setShowOwnershipTransferModal(false)} />
                            <div className="relative transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:p-6">
                                <h3 className="text-base font-semibold leading-6 text-gray-900">Ownership Transfer Required</h3>
                                <p className="mt-2 text-sm text-gray-500">
                                    Please use the ownership transfer process in <Link href={ownershipTransferSettingsLink || '/app/companies/settings#ownership-transfer'} className="text-indigo-600 hover:text-indigo-500 underline">Company settings</Link>.
                                </p>
                                <div className="mt-4">
                                    <button type="button" onClick={() => setShowOwnershipTransferModal(false)} className="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-500">
                                        OK
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                )}
            </main>
            <AppFooter />

            <ConfirmDialog
                open={deleteFromCompanyConfirm.open}
                onClose={() => setDeleteFromCompanyConfirm({ open: false, userId: null, userName: '' })}
                onConfirm={confirmDeleteFromCompany}
                title="Delete from company"
                message={`This will remove ${deleteFromCompanyConfirm.userName || 'this user'} from the company and revoke all access. This cannot be undone.`}
                confirmText="Delete from company"
                cancelText="Cancel"
                variant="danger"
            />
        </div>
    )
}
