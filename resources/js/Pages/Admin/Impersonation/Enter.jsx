import axios from 'axios'
import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link, useForm, usePage } from '@inertiajs/react'
import AppHead from '../../../Components/AppHead'
import AppNav from '../../../Components/AppNav'
import AppFooter from '../../../Components/AppFooter'
import AdminShell from '../../../Components/Admin/AdminShell'
import AdminSupportSectionSidebar from '../../../Components/Admin/AdminSupportSectionSidebar'

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
    const [search, setSearch] = useState('')
    const [debouncedSearch, setDebouncedSearch] = useState('')
    const [loadingUsers, setLoadingUsers] = useState(false)
    const [userHits, setUserHits] = useState([])
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

    useEffect(() => {
        const t = setTimeout(() => setDebouncedSearch(search.trim()), 350)
        return () => clearTimeout(t)
    }, [search])

    const loadUsers = useCallback(async () => {
        if (!data.tenant_id || debouncedSearch.length < 2) {
            setUserHits([])
            return
        }
        setLoadingUsers(true)
        try {
            const res = await axios.get(route('admin.api.users'), {
                params: { search: debouncedSearch, per_page: 25, tenant_id: data.tenant_id },
            })
            setUserHits(res.data?.data ?? res.data ?? [])
        } catch {
            setUserHits([])
        } finally {
            setLoadingUsers(false)
        }
    }, [debouncedSearch, data.tenant_id])

    useEffect(() => {
        loadUsers()
    }, [loadUsers])

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
        setSearch('')
        setDebouncedSearch('')
        setUserHits([])
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
                            <label className="block text-sm font-medium text-slate-700">Find user in this company</label>
                            <input
                                type="search"
                                className={inputCls}
                                placeholder={data.tenant_id ? 'Name or email (min. 2 characters)' : 'Select a company first'}
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                disabled={!data.tenant_id}
                                autoComplete="off"
                            />
                            {!data.tenant_id ? (
                                <p className="mt-1 text-xs text-slate-500">Choose a company before searching users.</p>
                            ) : null}
                            {data.tenant_id && debouncedSearch.length > 0 && debouncedSearch.length < 2 ? (
                                <p className="mt-1 text-xs text-slate-500">Type at least two characters to search.</p>
                            ) : null}
                            {loadingUsers ? <p className="mt-2 text-xs text-slate-500">Searching…</p> : null}
                            {!loadingUsers && data.tenant_id && debouncedSearch.length >= 2 && userHits.length === 0 ? (
                                <p className="mt-2 text-xs text-slate-500">No users matched in this company.</p>
                            ) : null}
                            {userHits.length > 0 ? (
                                <ul className="mt-2 max-h-56 overflow-auto rounded-md border border-slate-200 bg-white text-sm shadow-sm">
                                    {userHits.map((u) => (
                                        <li key={u.id}>
                                            <button
                                                type="button"
                                                className="flex w-full flex-col items-start gap-0.5 px-3 py-2 text-left hover:bg-slate-50"
                                                onClick={() => selectUser(u)}
                                            >
                                                <span className="font-medium text-slate-900">
                                                    {[u.first_name, u.last_name].filter(Boolean).join(' ') || '—'}
                                                </span>
                                                <span className="text-xs text-slate-600">{u.email}</span>
                                                {u.is_suspended ? (
                                                    <span className="text-xs font-medium text-red-700">Suspended</span>
                                                ) : null}
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            ) : null}
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
                                {errors.target_user_id ? <p className="text-sm text-red-600">{errors.target_user_id}</p> : null}

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
