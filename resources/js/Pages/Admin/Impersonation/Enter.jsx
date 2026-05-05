import axios from 'axios'
import { useEffect, useMemo, useState } from 'react'
import { Link, useForm, usePage } from '@inertiajs/react'
import AppHead from '../../../Components/AppHead'
import AppNav from '../../../Components/AppNav'
import AppFooter from '../../../Components/AppFooter'
import AdminShell from '../../../Components/Admin/AdminShell'
import AdminSupportSectionSidebar from '../../../Components/Admin/AdminSupportSectionSidebar'

/** Match PHP `ucfirst` for tenant role labels (e.g. agency_admin → Agency_admin). */
function phpUcfirst(str) {
    if (!str) {
        return null
    }
    return str.charAt(0).toUpperCase() + str.slice(1)
}

/**
 * Map {@see SiteAdminController::companyUsers} rows into the same shape as {@see SiteAdminController::allUsers}
 * so existing `selectUser` / brand logic keeps working.
 */
function normalizeCompanyUserRow(row, tenantMeta) {
    const tid = tenantMeta ? Number(tenantMeta.id) : null
    const tname = tenantMeta?.name ?? ''
    const tslug = tenantMeta?.slug ?? ''
    const roleLabel = phpUcfirst(row.tenant_role ?? 'member')
    const brands = (row.brand_assignments ?? []).map((b) => ({
        id: b.id,
        name: b.name,
        slug: '',
        tenant_id: tid,
        tenant_name: tname,
        is_default: Boolean(b.is_default),
        role: b.role,
    }))

    return {
        id: row.id,
        first_name: row.first_name,
        last_name: row.last_name,
        email: row.email,
        avatar_url: row.avatar_url,
        is_suspended: Boolean(row.is_suspended),
        companies: tid ? [{ id: tid, name: tname, slug: tslug, role: roleLabel }] : [],
        brands,
    }
}

function pickDefaultBrandForTenant(user, tenantId) {
    if (!user?.brands?.length || !tenantId) {
        return ''
    }
    const tid = Number(tenantId)
    const forTenant = user.brands.filter((b) => Number(b.tenant_id) === tid)
    const preferred = forTenant.find((b) => b.is_default) || forTenant[0]
    return preferred ? String(preferred.id) : ''
}

/** Company row from admin API uses ucfirst(role); tenant-wide admins may omit brand pivots. */
function isTenantWideAccess(roleLabel) {
    if (!roleLabel) {
        return false
    }
    const r = String(roleLabel)
        .toLowerCase()
        .replace(/\s+/g, '')
    return r === 'owner' || r === 'admin' || r === 'agencyadmin' || r === 'agency_admin'
}

export default function AdminImpersonationEnter({ can_start_full = false, company_options = [] }) {
    const { auth, flash } = usePage().props
    const [companyQuery, setCompanyQuery] = useState('')
    const [userListFilter, setUserListFilter] = useState('')
    const [loadingCompanyUsers, setLoadingCompanyUsers] = useState(false)
    const [companyUsers, setCompanyUsers] = useState([])
    const [companyUsersLoadError, setCompanyUsersLoadError] = useState(null)
    const [selectedUser, setSelectedUser] = useState(null)
    const [fetchedTenantBrands, setFetchedTenantBrands] = useState(null)

    const { data, setData, post, processing, errors, reset } = useForm({
        target_user_id: '',
        tenant_id: '',
        brand_id: '',
        mode: 'read_only',
        reason: '',
        ticket_id: '',
    })

    const filteredCompanies = useMemo(() => {
        const q = companyQuery.trim().toLowerCase()
        if (!q) {
            return company_options
        }
        return company_options.filter(
            (c) =>
                String(c.name || '')
                    .toLowerCase()
                    .includes(q) || String(c.slug || '').toLowerCase().includes(q)
        )
    }, [company_options, companyQuery])

    const selectedTenantMeta = useMemo(() => {
        if (!data.tenant_id) {
            return null
        }
        return company_options.find((c) => String(c.id) === String(data.tenant_id)) ?? null
    }, [company_options, data.tenant_id])

    useEffect(() => {
        if (!data.tenant_id) {
            setCompanyUsers([])
            setCompanyUsersLoadError(null)
            return
        }

        let cancelled = false
        setLoadingCompanyUsers(true)
        setCompanyUsersLoadError(null)

        axios
            .get(route('admin.api.companies.users', { tenant: data.tenant_id }))
            .then((res) => {
                if (cancelled) {
                    return
                }
                const rows = Array.isArray(res.data) ? res.data : []
                const meta = selectedTenantMeta ?? {
                    id: data.tenant_id,
                    name: 'Company',
                    slug: '',
                }
                setCompanyUsers(rows.map((row) => normalizeCompanyUserRow(row, meta)))
            })
            .catch(() => {
                if (!cancelled) {
                    setCompanyUsers([])
                    setCompanyUsersLoadError('Could not load users for this company.')
                }
            })
            .finally(() => {
                if (!cancelled) {
                    setLoadingCompanyUsers(false)
                }
            })

        return () => {
            cancelled = true
        }
    }, [data.tenant_id, selectedTenantMeta])

    const sortedCompanyUsers = useMemo(() => {
        return [...companyUsers].sort((a, b) => {
            const an = `${a.last_name || ''} ${a.first_name || ''} ${a.email || ''}`.toLowerCase().trim()
            const bn = `${b.last_name || ''} ${b.first_name || ''} ${b.email || ''}`.toLowerCase().trim()
            return an.localeCompare(bn)
        })
    }, [companyUsers])

    const filteredCompanyUsers = useMemo(() => {
        const q = userListFilter.trim().toLowerCase()
        if (!q) {
            return sortedCompanyUsers
        }
        return sortedCompanyUsers.filter((u) => {
            const blob = `${u.first_name || ''} ${u.last_name || ''} ${u.email || ''}`.toLowerCase()
            return blob.includes(q)
        })
    }, [sortedCompanyUsers, userListFilter])

    /** Keep the chosen user visible in the native select even when the filter hides them. */
    const selectOptions = useMemo(() => {
        if (!selectedUser) {
            return filteredCompanyUsers
        }
        if (filteredCompanyUsers.some((u) => u.id === selectedUser.id)) {
            return filteredCompanyUsers
        }
        return [selectedUser, ...filteredCompanyUsers]
    }, [filteredCompanyUsers, selectedUser])

    const brandsForTenant = useMemo(() => {
        if (!selectedUser?.brands?.length || !data.tenant_id) {
            return []
        }
        const tid = Number(data.tenant_id)
        return selectedUser.brands.filter((b) => Number(b.tenant_id) === tid)
    }, [selectedUser, data.tenant_id])

    const selectedCompany = useMemo(() => {
        if (!selectedUser?.companies?.length || !data.tenant_id) {
            return null
        }
        return selectedUser.companies.find((c) => String(c.id) === String(data.tenant_id)) ?? null
    }, [selectedUser, data.tenant_id])

    const brandOptions = useMemo(() => {
        if (fetchedTenantBrands?.length) {
            return fetchedTenantBrands.map((b) => ({ id: b.id, name: b.name, role: null }))
        }
        return brandsForTenant.map((b) => ({ id: b.id, name: b.name, role: b.role }))
    }, [fetchedTenantBrands, brandsForTenant])

    useEffect(() => {
        if (!selectedUser || !data.tenant_id) {
            setFetchedTenantBrands(null)
            return
        }
        const company = selectedUser.companies.find((c) => String(c.id) === String(data.tenant_id))
        if (!company || !isTenantWideAccess(company.role)) {
            setFetchedTenantBrands(null)
            return
        }

        let cancelled = false
        axios
            .get(route('admin.api.companies.details', { tenant: data.tenant_id }))
            .then((res) => {
                if (cancelled) {
                    return
                }
                const list = res.data?.brands ?? []
                setFetchedTenantBrands(Array.isArray(list) ? list : [])
            })
            .catch(() => {
                if (!cancelled) {
                    setFetchedTenantBrands([])
                }
            })

        return () => {
            cancelled = true
        }
    }, [selectedUser, data.tenant_id])

    useEffect(() => {
        if (!selectedUser || !data.tenant_id || !brandOptions.length) {
            return
        }
        const valid = brandOptions.some((b) => String(b.id) === String(data.brand_id))
        if (valid) {
            return
        }
        const def = fetchedTenantBrands?.find((b) => b.is_default) || fetchedTenantBrands?.[0]
        const fallback = def || brandOptions[0]
        if (fallback) {
            setData('brand_id', String(fallback.id))
        }
    }, [brandOptions, data.tenant_id, data.brand_id, fetchedTenantBrands])

    const onTenantPicked = (tenantId) => {
        setFetchedTenantBrands(null)
        setSelectedUser(null)
        setUserListFilter('')
        setCompanyUsers([])
        setCompanyUsersLoadError(null)
        setData('tenant_id', tenantId)
        setData('target_user_id', '')
        setData('brand_id', '')
    }

    const selectUser = (u) => {
        setSelectedUser(u)
        setFetchedTenantBrands(null)
        const brandId = pickDefaultBrandForTenant(u, data.tenant_id)
        setData('target_user_id', String(u.id))
        setData('brand_id', brandId)
    }

    const clearSelection = () => {
        setSelectedUser(null)
        setFetchedTenantBrands(null)
        setData('target_user_id', '')
        setData('brand_id', '')
    }

    const submit = (e) => {
        e.preventDefault()
        post(route('admin.impersonation.start'), {
            preserveScroll: true,
            onSuccess: () => reset('reason', 'ticket_id'),
        })
    }

    const inputCls =
        'mt-1 block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500'

    return (
        <div className="min-h-full">
            <AppHead title="Start support session" suffix="Admin" />
            <AppNav brand={auth?.activeBrand} tenant={null} />
            <main className="min-h-0">
                <AdminShell
                    centerKey="support"
                    breadcrumbs={[
                        { label: 'Admin', href: '/app/admin' },
                        { label: 'Support', href: '/app/admin/support' },
                        { label: 'Support access', href: '/app/admin/impersonation' },
                        { label: 'Start session' },
                    ]}
                    title="Start support session"
                    description="Use this only to troubleshoot a customer issue. All access is logged."
                    sidebar={<AdminSupportSectionSidebar />}
                >
                    {flash?.success ? (
                        <p className="mb-4 rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-900 ring-1 ring-emerald-200">{flash.success}</p>
                    ) : null}
                    {flash?.warning ? (
                        <p className="mb-4 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-900 ring-1 ring-amber-200">{flash.warning}</p>
                    ) : null}

                    <div className="mb-6 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">
                        <p className="font-medium">Internal use only</p>
                        <p className="mt-1">
                            Select the customer company, then find the user. Sessions are time-limited and audited (start, requests, end, force-end).
                        </p>
                    </div>

                    <form onSubmit={submit} className="max-w-2xl space-y-6">
                        <div>
                            <label htmlFor="company_filter" className="block text-sm font-medium text-slate-700">
                                Find company
                            </label>
                            <input
                                id="company_filter"
                                type="search"
                                className={inputCls}
                                placeholder="Filter by name or slug…"
                                value={companyQuery}
                                onChange={(e) => setCompanyQuery(e.target.value)}
                                autoComplete="off"
                            />
                        </div>
                        <div>
                            <label htmlFor="tenant_id" className="block text-sm font-medium text-slate-700">
                                Company <span className="text-red-600">*</span>
                            </label>
                            <select
                                id="tenant_id"
                                className={inputCls}
                                value={data.tenant_id}
                                onChange={(e) => onTenantPicked(e.target.value)}
                                required
                            >
                                <option value="">Select company…</option>
                                {filteredCompanies.map((c) => (
                                    <option key={c.id} value={String(c.id)}>
                                        {c.name} ({c.slug})
                                    </option>
                                ))}
                            </select>
                            {errors.tenant_id ? <p className="mt-1 text-xs text-red-600">{errors.tenant_id}</p> : null}
                        </div>

                        <div>
                            <label htmlFor="user_in_company" className="block text-sm font-medium text-slate-700">
                                User in this company <span className="text-red-600">*</span>
                            </label>
                            <p className="mt-0.5 text-xs text-slate-500">
                                Everyone with access to this company is listed. Use the filter to narrow the list.
                            </p>
                            <input
                                id="user_in_company_filter"
                                type="search"
                                className={inputCls}
                                placeholder={data.tenant_id ? 'Filter by name or email…' : 'Select a company first'}
                                value={userListFilter}
                                onChange={(e) => setUserListFilter(e.target.value)}
                                disabled={!data.tenant_id || loadingCompanyUsers}
                                autoComplete="off"
                            />
                            <select
                                id="user_in_company"
                                className={`${inputCls} mt-2`}
                                value={data.target_user_id}
                                disabled={!data.tenant_id || loadingCompanyUsers}
                                required
                                onChange={(e) => {
                                    const id = e.target.value
                                    if (!id) {
                                        clearSelection()
                                        return
                                    }
                                    const u = companyUsers.find((x) => String(x.id) === id)
                                    if (u) {
                                        selectUser(u)
                                    }
                                }}
                            >
                                <option value="">
                                    {loadingCompanyUsers ? 'Loading users…' : data.tenant_id ? 'Choose a user…' : 'Select a company first'}
                                </option>
                                {selectOptions.map((u) => {
                                    const name = [u.first_name, u.last_name].filter(Boolean).join(' ') || '—'
                                    const tag = u.is_suspended ? ' — suspended' : ''
                                    return (
                                        <option key={u.id} value={String(u.id)}>
                                            {name} ({u.email}){tag}
                                        </option>
                                    )
                                })}
                            </select>
                            {!data.tenant_id ? (
                                <p className="mt-1 text-xs text-slate-500">Choose a company to load its members.</p>
                            ) : null}
                            {companyUsersLoadError ? (
                                <p className="mt-1 text-xs text-red-600">{companyUsersLoadError}</p>
                            ) : null}
                            {!loadingCompanyUsers &&
                            data.tenant_id &&
                            !companyUsersLoadError &&
                            companyUsers.length === 0 ? (
                                <p className="mt-1 text-xs text-slate-500">No users found for this company.</p>
                            ) : null}
                            {!loadingCompanyUsers &&
                            data.tenant_id &&
                            companyUsers.length > 0 &&
                            filteredCompanyUsers.length === 0 &&
                            !selectedUser ? (
                                <p className="mt-1 text-xs text-slate-500">No users match this filter.</p>
                            ) : null}
                            {errors.target_user_id ? <p className="mt-1 text-xs text-red-600">{errors.target_user_id}</p> : null}
                        </div>

                        {selectedUser ? (
                            <div className="rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-sm">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <div>
                                        <p className="font-medium text-slate-900">
                                            {[selectedUser.first_name, selectedUser.last_name].filter(Boolean).join(' ') || 'User'}
                                        </p>
                                        <p className="text-slate-600">{selectedUser.email}</p>
                                    </div>
                                    <button type="button" className="text-sm font-medium text-indigo-700 hover:text-indigo-900" onClick={clearSelection}>
                                        Clear
                                    </button>
                                </div>
                                {!selectedUser.companies?.some((c) => String(c.id) === String(data.tenant_id)) ? (
                                    <p className="mt-2 text-amber-800">This user is not a member of the selected company.</p>
                                ) : null}
                            </div>
                        ) : null}

                        {selectedUser?.companies?.some((c) => String(c.id) === String(data.tenant_id)) ? (
                            <>
                                <div>
                                    <label htmlFor="brand_id" className="block text-sm font-medium text-slate-700">
                                        Brand / workspace context
                                    </label>
                                    <select
                                        id="brand_id"
                                        className={inputCls}
                                        value={data.brand_id}
                                        onChange={(e) => setData('brand_id', e.target.value)}
                                        required
                                        disabled={!data.tenant_id}
                                    >
                                        <option value="">Select brand…</option>
                                        {brandOptions.map((b) => (
                                            <option key={b.id} value={String(b.id)}>
                                                {b.name}
                                                {b.role ? ` (${b.role})` : ''}
                                            </option>
                                        ))}
                                    </select>
                                    {selectedCompany && !isTenantWideAccess(selectedCompany.role) && brandsForTenant.length === 0 && data.tenant_id ? (
                                        <p className="mt-1 text-xs text-amber-800">
                                            No brand assignments for this user in this company. Add them to a brand in Team settings, or pick another user.
                                        </p>
                                    ) : null}
                                    {selectedCompany && isTenantWideAccess(selectedCompany.role) && fetchedTenantBrands?.length === 0 && data.tenant_id ? (
                                        <p className="mt-1 text-xs text-amber-800">This company has no brands.</p>
                                    ) : null}
                                    {errors.brand_id ? <p className="mt-1 text-xs text-red-600">{errors.brand_id}</p> : null}
                                </div>

                                <div>
                                    <label htmlFor="mode" className="block text-sm font-medium text-slate-700">
                                        Session type
                                    </label>
                                    <select
                                        id="mode"
                                        className={inputCls}
                                        value={data.mode === 'full' && !can_start_full ? 'read_only' : data.mode}
                                        onChange={(e) => setData('mode', e.target.value)}
                                    >
                                        <option value="read_only">Read-only support session (site support and above)</option>
                                        {can_start_full ? <option value="full">Full admin session (site admin / owner only)</option> : null}
                                    </select>
                                    <p className="mt-1 text-xs text-slate-500">Assisted engineering session — coming later (not startable).</p>
                                    {!can_start_full ? (
                                        <p className="mt-1 text-xs text-slate-500">Full sessions require site administrator access.</p>
                                    ) : null}
                                    {errors.mode ? <p className="mt-1 text-xs text-red-600">{errors.mode}</p> : null}
                                </div>

                                <div>
                                    <label htmlFor="ticket_id" className="block text-sm font-medium text-slate-700">
                                        Ticket / case ID <span className="text-slate-500 font-normal">(recommended)</span>
                                    </label>
                                    <input
                                        id="ticket_id"
                                        type="text"
                                        className={inputCls}
                                        value={data.ticket_id}
                                        onChange={(e) => setData('ticket_id', e.target.value)}
                                        placeholder="e.g. JIRA-1234, SF-00055012"
                                        maxLength={128}
                                        autoComplete="off"
                                    />
                                    {errors.ticket_id ? <p className="mt-1 text-xs text-red-600">{errors.ticket_id}</p> : null}
                                    <p className="mt-1 text-xs text-slate-500">
                                        Stored on the session row and in the start audit event for enterprise support traceability.
                                    </p>
                                </div>

                                <div>
                                    <label htmlFor="reason" className="block text-sm font-medium text-slate-700">
                                        Reason <span className="text-red-600">*</span>
                                    </label>
                                    <textarea
                                        id="reason"
                                        rows={4}
                                        className={inputCls}
                                        value={data.reason}
                                        onChange={(e) => setData('reason', e.target.value)}
                                        placeholder="Required for audit (what you are doing, customer context, approval reference if applicable)."
                                        required
                                    />
                                    {errors.reason ? <p className="mt-1 text-xs text-red-600">{errors.reason}</p> : null}
                                </div>

                                {errors.impersonation ? <p className="text-sm text-red-600">{errors.impersonation}</p> : null}

                                <div className="flex flex-wrap gap-3">
                                    <button
                                        type="submit"
                                        disabled={
                                            processing ||
                                            !data.target_user_id ||
                                            !data.tenant_id ||
                                            !data.brand_id ||
                                            !data.reason.trim()
                                        }
                                        className="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        {processing ? 'Starting…' : 'Start session & open app'}
                                    </button>
                                    <Link
                                        href="/app/admin/impersonation"
                                        className="inline-flex items-center rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50"
                                    >
                                        Cancel
                                    </Link>
                                </div>
                            </>
                        ) : null}
                    </form>
                </AdminShell>
            </main>
            <AppFooter />
        </div>
    )
}
