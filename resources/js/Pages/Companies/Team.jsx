import { Link, router, usePage, useForm } from '@inertiajs/react'
import { useState, useEffect, useCallback, useRef, useMemo } from 'react'
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

export default function Team({ tenant, brands = [], tenant_roles = [], invite_lock_company_role = false, current_user_count, max_users, user_limit_reached }) {
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
    const [linkedAgencies, setLinkedAgencies] = useState([])
    const [detachAgencyDialog, setDetachAgencyDialog] = useState({ open: false, link: null })
    const [detachAgencySubmitting, setDetachAgencySubmitting] = useState(false)
    const [pendingConvertUserId, setPendingConvertUserId] = useState(null)
    const [convertAgencyDialog, setConvertAgencyDialog] = useState({
        open: false,
        userId: null,
        userName: '',
        agencyTenantId: null,
        agencyName: '',
    })
    const [convertAgencySubmitting, setConvertAgencySubmitting] = useState(false)
    const [inviteFormKey, setInviteFormKey] = useState(0)

    const debouncedSearch = useDebounce(search, 300)

    const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''

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
        const params = new URLSearchParams({ page: pageToUse, per_page: '200' })
        if (debouncedSearch) params.set('search', debouncedSearch)
        if (filterBrand) params.set('brand_id', filterBrand)
        if (filterRole) params.set('role', filterRole)
        fetch(`/app/api/companies/users?${params}`, { credentials: 'same-origin' })
            .then((r) => r.json())
            .then((data) => {
                if (data.data) {
                    setUsers(data.data)
                    setMeta(data.meta || {})
                    setLinkedAgencies(Array.isArray(data.linked_agencies) ? data.linked_agencies : [])
                }
            })
            .catch(() => {
                setUsers([])
                setLinkedAgencies([])
            })
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

    const handleRemoveBrand = (userId, brandId, _brandName, options = {}) => {
        router.delete(`/app/brands/${brandId}/users/${userId}`, {
            data: { remove_from_company: Boolean(options.removeFromCompany) },
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

    const openInviteModal = () => {
        const first = brands[0]
        setData({
            email: '',
            role: 'member',
            brands: first ? [{ brand_id: first.id, role: 'viewer' }] : [],
        })
        setInviteFormKey((k) => k + 1)
        setShowInviteModal(true)
    }

    const displayCount = meta.total ?? current_user_count ?? 0
    const maxDisplay = max_users === Number.MAX_SAFE_INTEGER || max_users > 1000 ? '∞' : max_users

    const { agencySections, directUsers } = useMemo(() => {
        const direct = []
        const byAgency = new Map()
        for (const u of users) {
            if (!u.is_agency_managed) {
                direct.push(u)
                continue
            }
            const aid = u.agency_tenant_id ?? 'unknown'
            if (!byAgency.has(aid)) {
                byAgency.set(aid, {
                    agency_tenant_id: aid,
                    name: u.agency_tenant_name || 'Agency partner',
                    users: [],
                })
            }
            byAgency.get(aid).users.push(u)
        }
        const sections = Array.from(byAgency.values()).sort((a, b) =>
            (a.name || '').localeCompare(b.name || '', undefined, { sensitivity: 'base' })
        )
        return { agencySections: sections, directUsers: direct }
    }, [users])

    const hasAgencyUsers = useMemo(
        () => agencySections.some((s) => s.users.length > 0),
        [agencySections]
    )

    const findTenantAgencyLink = (agencyTenantId) =>
        linkedAgencies.find((l) => Number(l.agency_tenant?.id) === Number(agencyTenantId))

    const openDetachAgency = (agencyTenantId) => {
        const link = findTenantAgencyLink(agencyTenantId)
        if (!link) return
        setDetachAgencyDialog({ open: true, link })
    }

    const handleConvertToAgency = (userId, agencyTenantId) => {
        const u = users.find((x) => x.id === userId)
        const link = findTenantAgencyLink(agencyTenantId)
        const agencyName = link?.agency_tenant?.name ?? 'Agency'
        setConvertAgencyDialog({
            open: true,
            userId,
            userName: u?.name || u?.email || 'This person',
            agencyTenantId,
            agencyName,
        })
    }

    const confirmConvertToAgency = async () => {
        const { userId, agencyTenantId } = convertAgencyDialog
        if (!userId || agencyTenantId == null) {
            return
        }
        setConvertAgencySubmitting(true)
        setPendingConvertUserId(userId)
        try {
            const res = await fetch(`/app/api/companies/users/${userId}/agency-managed`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ agency_tenant_id: agencyTenantId }),
            })
            const data = await res.json().catch(() => ({}))
            if (!res.ok) {
                const msg =
                    data.errors?.agency_tenant_id?.[0] ||
                    data.errors?.user_id?.[0] ||
                    data.message ||
                    'Could not switch this member to agency-managed access.'
                window.alert(msg)
                return
            }
            setConvertAgencyDialog({
                open: false,
                userId: null,
                userName: '',
                agencyTenantId: null,
                agencyName: '',
            })
            fetchUsers()
        } catch {
            window.alert('Network error.')
        } finally {
            setConvertAgencySubmitting(false)
            setPendingConvertUserId(null)
        }
    }

    const confirmDetachAgency = async () => {
        const link = detachAgencyDialog.link
        if (!link?.id) {
            setDetachAgencyDialog({ open: false, link: null })
            return
        }
        setDetachAgencySubmitting(true)
        try {
            const res = await fetch(`/app/api/tenant/agencies/${link.id}`, {
                method: 'DELETE',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken(),
                },
            })
            if (!res.ok) {
                const data = await res.json().catch(() => ({}))
                window.alert(data.message || 'Could not remove agency partnership.')
                return
            }
            setDetachAgencyDialog({ open: false, link: null })
            fetchUsers()
        } catch {
            window.alert('Network error.')
        } finally {
            setDetachAgencySubmitting(false)
        }
    }

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

                {linkedAgencies.length > 0 && (
                    <div className="mb-4 rounded-lg border border-indigo-100 bg-indigo-50/80 px-4 py-3 text-sm text-indigo-950">
                        <p>
                            <span className="font-medium">Agency partnerships</span>
                            {' — '}
                            Agency-managed members are controlled through the agency partnership. Use{' '}
                            <Link
                                href="/app/companies/settings#agencies"
                                className="font-medium text-indigo-700 underline decoration-indigo-300 underline-offset-2 hover:text-indigo-900"
                            >
                                Company settings → Agencies
                            </Link>{' '}
                            to change agency access, or the Agency Dashboard to manage agency staff. Direct invites here
                            are for <span className="font-medium">Admin</span> or <span className="font-medium">Member</span>{' '}
                            only. Use <span className="font-medium">Switch to agency</span> when a direct member should
                            follow the agency link instead.
                        </p>
                    </div>
                )}

                <div className="mb-6 flex flex-wrap items-center justify-between gap-4">
                    <button
                        type="button"
                        onClick={openInviteModal}
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
                        <>
                            {directUsers.length > 0 && (
                                <div className={hasAgencyUsers ? 'border-b border-gray-200' : ''}>
                                    {hasAgencyUsers && (
                                        <div className="border-b border-gray-200 bg-gray-50 px-4 py-3">
                                            <h2 className="text-base font-semibold text-gray-900">Direct team</h2>
                                            <p className="text-xs text-gray-600">
                                                Members invited or added directly by your company ({directUsers.length}{' '}
                                                {directUsers.length === 1 ? 'person' : 'people'}). Company role is{' '}
                                                <span className="font-medium">Admin</span> or{' '}
                                                <span className="font-medium">Member</span> only.
                                            </p>
                                        </div>
                                    )}
                                    {directUsers.map((user) => (
                                        <UserRow
                                            key={user.id}
                                            user={user}
                                            brands={brands}
                                            tenant={tenant}
                                            authUserId={auth?.user?.id}
                                            onCompanyRoleChange={handleCompanyRoleChange}
                                            onBrandRoleChange={handleBrandRoleChange}
                                            onRemoveBrand={handleRemoveBrand}
                                            canRemoveFromCompany
                                            onDeleteFromCompany={handleDeleteFromCompany}
                                            updatingKeys={updatingKeys}
                                            linkedAgencies={linkedAgencies}
                                            onConvertToAgency={handleConvertToAgency}
                                            convertPending={pendingConvertUserId === user.id}
                                        />
                                    ))}
                                </div>
                            )}

                            {agencySections.map((section) => {
                                const link = findTenantAgencyLink(section.agency_tenant_id)
                                if (section.users.length === 0) {
                                    return null
                                }
                                return (
                                    <div key={section.agency_tenant_id} className="border-b border-gray-200 last:border-b-0">
                                        <div className="flex flex-col gap-2 border-b border-gray-200 bg-slate-50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                                            <div>
                                                <h2 className="text-base font-semibold text-gray-900">
                                                    {section.name}
                                                </h2>
                                                <p className="text-xs text-gray-600">
                                                    Agency-managed team ({section.users.length}{' '}
                                                    {section.users.length === 1 ? 'person' : 'people'}). Partnership roles
                                                    and brand scope are set in{' '}
                                                    <Link
                                                        href="/app/companies/settings#agencies"
                                                        className="font-medium text-indigo-700 underline decoration-indigo-300 underline-offset-2 hover:text-indigo-900"
                                                    >
                                                        Company settings → Agencies
                                                    </Link>
                                                    .
                                                </p>
                                            </div>
                                            {link && (
                                                <button
                                                    type="button"
                                                    onClick={() => openDetachAgency(section.agency_tenant_id)}
                                                    className="inline-flex shrink-0 items-center justify-center rounded-md border border-red-200 bg-white px-3 py-1.5 text-sm font-medium text-red-800 shadow-sm hover:bg-red-50"
                                                >
                                                    Remove agency from company
                                                </button>
                                            )}
                                        </div>
                                        {section.users.map((user) => (
                                            <UserRow
                                                key={user.id}
                                                user={user}
                                                brands={brands}
                                                tenant={tenant}
                                                authUserId={auth?.user?.id}
                                                onBrandRoleChange={handleBrandRoleChange}
                                                onRemoveBrand={handleRemoveBrand}
                                                canRemoveFromCompany
                                                onDeleteFromCompany={handleDeleteFromCompany}
                                                updatingKeys={updatingKeys}
                                                groupedUnderAgencySection
                                            />
                                        ))}
                                    </div>
                                )
                            })}
                        </>
                    )}
                </div>

                {/* Pagination */}
                {meta.last_page > 1 && (
                    <div className="mt-4 flex items-center justify-between">
                        <p className="text-sm text-gray-600">
                            Showing {(meta.current_page - 1) * (meta.per_page || 200) + 1}–
                            {Math.min(meta.current_page * (meta.per_page || 200), meta.total)} of {meta.total} users
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
                            <div className="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" onClick={() => { setShowInviteModal(false); reset() }} />
                            <div className="relative transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:p-6">
                                <div className="absolute right-0 top-0 hidden pr-4 pt-4 sm:block">
                                    <button type="button" onClick={() => { setShowInviteModal(false); reset() }} className="rounded-md bg-white text-gray-400 hover:text-gray-500">
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
                                        <select
                                            id="role"
                                            value={data.role}
                                            onChange={(e) => setData('role', e.target.value)}
                                            disabled={invite_lock_company_role}
                                            className="block w-full rounded-md border-0 py-1.5 px-3 text-sm text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 disabled:cursor-not-allowed disabled:bg-gray-50 disabled:text-gray-600"
                                        >
                                            {(tenant_roles || []).map((r) => (
                                                <option key={r.value} value={r.value}>
                                                    {r.label}
                                                </option>
                                            ))}
                                            {(!tenant_roles || tenant_roles.length === 0) && (
                                                <>
                                                    <option value="member">Member</option>
                                                    <option value="admin">Admin</option>
                                                </>
                                            )}
                                        </select>
                                        {errors.role && <p className="mt-1 text-sm text-red-600">{errors.role}</p>}
                                        {invite_lock_company_role && (
                                            <p className="mt-1.5 text-xs text-gray-500">
                                                Invites from agency staff on this client company are created as{' '}
                                                <span className="font-medium">Member</span> only. Client owners and company
                                                admins can also invite <span className="font-medium">Admin</span>. Agency
                                                partnership access is managed in Company settings → Agencies.
                                            </p>
                                        )}
                                    </div>
                                    <div className="mb-4">
                                        <BrandRoleSelector
                                            key={inviteFormKey}
                                            brands={brands}
                                            selectedBrands={data.brands}
                                            onChange={handleBrandAssignmentsChange}
                                            errors={errors}
                                            required
                                        />
                                    </div>
                                    <div className="mt-5 sm:mt-6 sm:grid sm:grid-flow-row-dense sm:grid-cols-2 sm:gap-3">
                                        <button type="submit" disabled={processing || user_limit_reached || !data.brands?.length} className="inline-flex w-full justify-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50 sm:col-start-2">
                                            {processing ? 'Sending...' : 'Send Invitation'}
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => {
                                                setShowInviteModal(false)
                                                reset()
                                            }}
                                            className="mt-3 inline-flex w-full justify-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 sm:col-start-1 sm:mt-0"
                                        >
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
            <AppFooter variant="settings" />

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

            <ConfirmDialog
                open={detachAgencyDialog.open && !!detachAgencyDialog.link}
                onClose={() => !detachAgencySubmitting && setDetachAgencyDialog({ open: false, link: null })}
                onConfirm={confirmDetachAgency}
                title="Remove agency from this company?"
                message={
                    detachAgencyDialog.link
                        ? `This removes the partnership with "${detachAgencyDialog.link.agency_tenant?.name || 'this agency'}" and revokes company access for everyone that agency added to ${tenant.name}. If that includes you, you will lose access here. Brand scopes can be adjusted later by linking the agency again from Company settings.`
                        : ''
                }
                confirmText="Remove agency"
                cancelText="Cancel"
                variant="danger"
                loading={detachAgencySubmitting}
            />

            <ConfirmDialog
                open={convertAgencyDialog.open}
                onClose={() =>
                    !convertAgencySubmitting &&
                    setConvertAgencyDialog({
                        open: false,
                        userId: null,
                        userName: '',
                        agencyTenantId: null,
                        agencyName: '',
                    })
                }
                onConfirm={confirmConvertToAgency}
                title="Switch to agency-managed access?"
                panelClassName="sm:max-w-2xl"
                message={
                    convertAgencyDialog.open ? (
                        <div className="space-y-4 text-left">
                            <p>
                                <span className="font-medium text-gray-800">{convertAgencyDialog.userName}</span> is
                                currently a <span className="font-medium text-gray-800">direct</span> team member:
                                their company role and per-brand access are managed here on the Team page.
                            </p>
                            <p>
                                You are about to move them to an{' '}
                                <span className="font-medium text-gray-800">agency association</span> under{' '}
                                <span className="font-medium text-gray-800">{convertAgencyDialog.agencyName}</span>.
                                After this change, they are managed like other staff on that agency link—not as a
                                standalone direct invite.
                            </p>
                            <div>
                                <p className="font-medium text-gray-800">What this means</p>
                                <ul className="mt-2 list-disc space-y-2 pl-5">
                                    <li>
                                        <span className="font-medium text-gray-700">Brand access from the workspace</span>
                                        — They open this client&apos;s brands from the agency workspace: the{' '}
                                        <span className="font-medium">top agency bar</span> and{' '}
                                        <span className="font-medium">brand / workspace dropdown</span>, the same way
                                        other agency users reach linked client companies.
                                    </li>
                                    <li>
                                        <span className="font-medium text-gray-700">Roles follow the agency link</span>
                                        — Company role and brand permissions come from{' '}
                                        <Link
                                            href="/app/companies/settings#agencies"
                                            className="font-medium text-indigo-600 underline decoration-indigo-200 underline-offset-2 hover:text-indigo-800"
                                        >
                                            Company settings → Agencies
                                        </Link>{' '}
                                        for that partnership, not from individual edits on this Team page.
                                    </li>
                                    <li>
                                        <span className="font-medium text-gray-700">Agency program context</span>
                                        — They align with your agency partnership rules (for example tier, incubation,
                                        or managed-client workflows) the same way as teammates added by the agency,
                                        rather than as a one-off direct member.
                                    </li>
                                </ul>
                            </div>
                            <p className="text-gray-600">
                                Direct-only changes you made here will no longer apply. Continue?
                            </p>
                        </div>
                    ) : (
                        ''
                    )
                }
                confirmText="Switch to agency access"
                cancelText="Cancel"
                variant="info"
                loading={convertAgencySubmitting}
            />
        </div>
    )
}
