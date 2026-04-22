import { useState, useEffect, useRef } from 'react'
import { Link, router, useForm, usePage } from '@inertiajs/react'
import AppNav from '../../Components/AppNav'
import AppFooter from '../../Components/AppFooter'
import BrandRoleSelector from '../../Components/BrandRoleSelector'
import {
    BuildingOffice2Icon as BuildingOfficeIcon,
    ChartBarIcon,
    UsersIcon,
    TagIcon,
    ClockIcon,
    ArrowLeftIcon,
    CheckCircleIcon,
    ExclamationTriangleIcon,
    XCircleIcon,
    UserPlusIcon,
    PencilIcon,
    SparklesIcon,
    CpuChipIcon,
    InformationCircleIcon,
    TrashIcon,
    RectangleStackIcon,
    CubeIcon,
    XMarkIcon,
    ChartPieIcon,
} from '@heroicons/react/24/outline'

function defaultCreatorExpiresLocal() {
    const d = new Date()
    d.setMonth(d.getMonth() + 6)
    d.setSeconds(0, 0)
    d.setMinutes(d.getMinutes() - d.getTimezoneOffset())
    return d.toISOString().slice(0, 16)
}

export default function AdminCompanyView({ 
    company, 
    monthlyData, 
    currentCosts, 
    currentIncome, 
    profitability,
    recentActivity,
    users,
    brands = [],
    all_brands = [],
    stats,
    aiUsage = null,
    linked_agencies = [],
}) {
    const { flash, errors: pageErrors } = usePage().props

    const [showAddUserForm, setShowAddUserForm] = useState(false)
    const [availableUsers, setAvailableUsers] = useState([])
    const [loadingUsers, setLoadingUsers] = useState(false)
    const [userSearchQuery, setUserSearchQuery] = useState('')
    const searchTimeoutRef = useRef(null)

    const { data, setData, post, processing, errors, reset } = useForm({
        user_id: null,
        role: 'member',
        brands: [],
    })
    
    // Plan change state
    const [planChangeConfirm, setPlanChangeConfirm] = useState({ 
        open: false, 
        oldPlan: company.plan_name || 'free',
        newPlan: company.plan_name || 'free',
        billingStatus: company.billing_status || 'comped',
        expirationMonths: company.billing_status_expires_at ? Math.ceil((new Date(company.billing_status_expires_at) - new Date()) / (1000 * 60 * 60 * 24 * 30)) : 6,
        equivalentPlanValue: null,
    })

    const incubation = company.incubation
    const [extendDays, setExtendDays] = useState(14)
    const [extendReason, setExtendReason] = useState('')
    const [extendStatus, setExtendStatus] = useState(null)
    const [extendSubmitting, setExtendSubmitting] = useState(false)

    const [aiCostModalOpen, setAiCostModalOpen] = useState(false)

    const [creatorExpires, setCreatorExpires] = useState('')
    const [creatorStatus, setCreatorStatus] = useState('active')
    const [creatorSeats, setCreatorSeats] = useState('')
    const [creatorBusy, setCreatorBusy] = useState(false)
    /** Add-on modules: pick (Creator, Space, …) then module-specific step */
    const [addonsModal, setAddonsModal] = useState({ open: false, step: 'pick' })

    const closeAddonsModal = () => setAddonsModal({ open: false, step: 'pick' })

    useEffect(() => {
        const cm = company.creator_module
        if (cm?.expires_at) {
            const d = new Date(cm.expires_at)
            if (!Number.isNaN(d.getTime())) {
                d.setMinutes(d.getMinutes() - d.getTimezoneOffset())
                setCreatorExpires(d.toISOString().slice(0, 16))
            } else {
                setCreatorExpires(defaultCreatorExpiresLocal())
            }
        } else {
            setCreatorExpires(defaultCreatorExpiresLocal())
        }
        setCreatorStatus(cm?.status === 'trial' ? 'trial' : 'active')
        setCreatorSeats(cm?.seats_limit != null ? String(cm.seats_limit) : '')
    }, [company.id, company.creator_module?.expires_at, company.creator_module?.seats_limit, company.creator_module?.status])

    const submitCreatorModule = (e) => {
        e.preventDefault()
        if (!company?.id) return
        const seatsTrim = creatorSeats.trim()
        let seats_limit = null
        if (seatsTrim !== '') {
            const n = parseInt(seatsTrim, 10)
            if (!Number.isFinite(n) || n < 1) {
                return
            }
            seats_limit = n
        }
        setCreatorBusy(true)
        const payload = {
            expires_at: creatorExpires,
            status: creatorStatus,
            seats_limit,
        }
        router.put(route('admin.companies.creator-module', company.id), payload, {
            preserveScroll: true,
            onFinish: () => setCreatorBusy(false),
            onSuccess: () => closeAddonsModal(),
        })
    }

    const revokeCreatorModule = () => {
        if (!company?.id) return
        if (!window.confirm('Revoke Creator module access? Memberships and stats stay; uploads and management are blocked until re-granted.')) return
        setCreatorBusy(true)
        router.put(route('admin.companies.creator-module', company.id), { action: 'revoke' }, {
            preserveScroll: true,
            onFinish: () => setCreatorBusy(false),
            onSuccess: () => closeAddonsModal(),
        })
    }

    const submitIncubationExtend = (e) => {
        e.preventDefault()
        if (!company?.id || !incubation) return
        const max = incubation.max_support_extension_days || 14
        const days = Math.min(Math.max(1, parseInt(extendDays, 10) || 1), max)
        setExtendSubmitting(true)
        setExtendStatus(null)
        fetch(`/app/admin/api/companies/${company.id}/incubation/extend`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ extend_days: days, reason: extendReason || null }),
        })
            .then(async (res) => {
                const data = await res.json().catch(() => ({}))
                if (!res.ok) {
                    setExtendStatus({ type: 'error', message: data.message || 'Request failed' })
                    return
                }
                setExtendStatus({ type: 'success', message: data.message || 'Deadline extended.' })
                setExtendReason('')
                router.reload({ only: ['company'] })
            })
            .catch(() => {
                setExtendStatus({ type: 'error', message: 'Network error' })
            })
            .finally(() => setExtendSubmitting(false))
    }

    // Load users from API when form is opened
    useEffect(() => {
        if (!showAddUserForm) {
            setAvailableUsers([])
            setUserSearchQuery('')
            return
        }

        // Load initial users when form opens
        setLoadingUsers(true)
        const params = new URLSearchParams({
            exclude_tenant_id: company.id,
        })
        fetch(`/app/admin/api/users/selector?${params}`)
            .then(res => res.json())
            .then(data => {
                setAvailableUsers(data || [])
                setLoadingUsers(false)
            })
            .catch(err => {
                console.error('Failed to load users:', err)
                setLoadingUsers(false)
            })
    }, [showAddUserForm, company.id])

    // Load users from API when search query changes (debounced)
    useEffect(() => {
        if (!showAddUserForm || userSearchQuery.length < 2) {
            return
        }

        if (searchTimeoutRef.current) {
            clearTimeout(searchTimeoutRef.current)
        }

        searchTimeoutRef.current = setTimeout(() => {
            setLoadingUsers(true)
            const params = new URLSearchParams({
                search: userSearchQuery,
                exclude_tenant_id: company.id,
            })
            fetch(`/app/admin/api/users/selector?${params}`)
                .then(res => res.json())
                .then(data => {
                    setAvailableUsers(data || [])
                    setLoadingUsers(false)
                })
                .catch(err => {
                    console.error('Failed to load users:', err)
                    setLoadingUsers(false)
                })
        }, 300)

        return () => {
            if (searchTimeoutRef.current) {
                clearTimeout(searchTimeoutRef.current)
            }
        }
    }, [userSearchQuery, showAddUserForm, company.id])

    const formatRoleLabel = (role) => {
        if (!role || typeof role !== 'string') return '—'
        return role.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())
    }

    const confirmRemoveAgencyLink = (link) => {
        const name = link.agency_tenant?.name || 'this agency'
        if (!window.confirm(`Remove agency link to "${name}"? Agency-managed users will lose access to this company.`)) {
            return
        }
        router.delete(`/app/admin/companies/${company.id}/agency-links/${link.id}`, { preserveScroll: true })
    }

    const confirmRemoveAgencyUser = (user) => {
        if (!window.confirm(`Remove ${user.email} from this company? They will lose access to brands here.`)) {
            return
        }
        router.delete(`/app/admin/companies/${company.id}/users/${user.id}`, { preserveScroll: true })
    }

    const handleUserSelect = (user) => {
        setData('user_id', user.id)
    }

    const handleBrandsChange = (brandAssignments) => {
        setData('brands', brandAssignments)
    }

    const handleSubmit = (e) => {
        e.preventDefault()
        post(`/app/admin/companies/${company.id}/add-user`, {
            preserveScroll: true,
            onSuccess: () => {
                reset()
                setShowAddUserForm(false)
                setUserSearchQuery('')
                setAvailableUsers([])
            },
        })
    }
    // Format currency
    const formatCurrency = (amount) => {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: 'USD',
            minimumFractionDigits: 2,
        }).format(amount || 0)
    }

    // Format date
    const formatDate = (dateString) => {
        if (!dateString) return 'N/A'
        try {
            const date = new Date(dateString)
            return date.toLocaleString('en-US', {
                month: 'short',
                day: 'numeric',
                year: 'numeric',
                hour: 'numeric',
                minute: '2-digit',
            })
        } catch (e) {
            return dateString
        }
    }
    
    // Helper to capitalize first letter
    const ucfirst = (str) => {
        if (!str) return ''
        return str.charAt(0).toUpperCase() + str.slice(1)
    }

    // Get profitability badge config
    const getProfitabilityBadge = () => {
        if (!profitability) return null
        
        const configs = {
            profitable: {
                label: profitability.label,
                className: 'bg-green-100 text-green-800',
                icon: CheckCircleIcon,
            },
            break_even: {
                label: profitability.label,
                className: 'bg-yellow-100 text-yellow-800',
                icon: ExclamationTriangleIcon,
            },
            losing: {
                label: profitability.label,
                className: 'bg-red-100 text-red-800',
                icon: XCircleIcon,
            },
            unknown: {
                label: profitability.label,
                className: 'bg-gray-100 text-gray-800',
                icon: ClockIcon,
            },
            no_data: {
                label: profitability.label,
                className: 'bg-gray-100 text-gray-800',
                icon: ClockIcon,
            },
        }

        return configs[profitability.rating] || configs.unknown
    }

    // Calculate chart dimensions
    const chartHeight = 200
    const chartWidth = 600
    const maxValue = Math.max(
        ...monthlyData.map(d => Math.max(d.income, d.total_cost)),
        1 // Minimum 1 to avoid division by zero
    )

    const profitabilityBadge = getProfitabilityBadge()
    const ProfitabilityIcon = profitabilityBadge?.icon

    return (
        <div className="min-h-full">
            <AppNav brand={null} tenant={null} />
            <main className="bg-gray-50">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
                    {/* Header */}
                    <div className="mb-8">
                        <Link
                            href="/app/admin"
                            className="text-sm font-medium text-gray-500 hover:text-gray-700 mb-4 inline-flex items-center gap-1"
                        >
                            <ArrowLeftIcon className="h-4 w-4" />
                            Back to Admin Dashboard
                        </Link>
                        <div className="flex items-center justify-between">
                            <div>
                                <h1 className="text-3xl font-bold tracking-tight text-gray-900">{company.name}</h1>
                                <p className="mt-2 text-sm text-gray-700">
                                    Company details, financials, and activity overview
                                </p>
                            </div>
                            {profitabilityBadge && ProfitabilityIcon && (
                                <span className={`inline-flex items-center rounded-full px-4 py-2 text-sm font-medium ${profitabilityBadge.className}`}>
                                    <ProfitabilityIcon className="h-5 w-5 mr-2" />
                                    {profitabilityBadge.label}
                                </span>
                            )}
                        </div>
                    </div>

                    {flash?.success && (
                        <div className="mb-4 rounded-md bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-800">
                            {flash.success}
                        </div>
                    )}
                    {flash?.error && (
                        <div className="mb-4 rounded-md bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-800">
                            {flash.error}
                        </div>
                    )}

                    {/* Company Details Card */}
                    <div className="mb-8">
                        <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                            <div className="p-6">
                                <h2 className="text-lg font-semibold text-gray-900 mb-4">Company Information</h2>
                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                    <div>
                                        <dt className="text-sm font-medium text-gray-500">Plan</dt>
                                        <dd className="mt-1 text-sm text-gray-900">{company.plan}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-sm font-medium text-gray-500">Owner</dt>
                                        <dd className="mt-1 text-sm text-gray-900">
                                            {company.owner ? (
                                                <>
                                                    {company.owner.name}
                                                    <span className="text-gray-500 ml-1">({company.owner.email})</span>
                                                </>
                                            ) : (
                                                'No owner assigned'
                                            )}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-sm font-medium text-gray-500">Created</dt>
                                        <dd className="mt-1 text-sm text-gray-900">{formatDate(company.created_at)}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-sm font-medium text-gray-500">Stripe Status</dt>
                                        <dd className="mt-1">
                                            {company.stripe_connected ? (
                                                <span className={`inline-flex items-center rounded-full px-2 py-1 text-xs font-medium ${
                                                    company.subscription_status === 'active' ? 'bg-green-100 text-green-800' :
                                                    company.subscription_status === 'trialing' ? 'bg-blue-100 text-blue-800' :
                                                    company.subscription_status === 'past_due' ? 'bg-orange-100 text-orange-800' :
                                                    'bg-gray-100 text-gray-800'
                                                }`}>
                                                    {company.subscription_status || 'Connected'}
                                                </span>
                                            ) : (
                                                <span className="inline-flex items-center rounded-full px-2 py-1 text-xs font-medium bg-gray-100 text-gray-800">
                                                    Not Connected
                                                </span>
                                            )}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-sm font-medium text-gray-500">Total Users</dt>
                                        <dd className="mt-1 text-sm text-gray-900">{stats.total_users}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-sm font-medium text-gray-500">Total Brands</dt>
                                        <dd className="mt-1 text-sm text-gray-900">{stats.total_brands}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-sm font-medium text-gray-500">Total Assets</dt>
                                        <dd className="mt-1 text-sm text-gray-900">{stats.total_assets}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-sm font-medium text-gray-500">Storage Used</dt>
                                        <dd className="mt-1 text-sm text-gray-900">{stats.total_storage_gb} GB</dd>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {incubation && (
                        <div className="mb-8">
                            <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                                <div className="p-6">
                                    <h2 className="text-lg font-semibold text-gray-900 mb-2 flex items-center gap-2">
                                        <ClockIcon className="h-5 w-5 text-gray-400" />
                                        Incubation
                                    </h2>
                                    <p className="text-sm text-gray-600 mb-4">
                                        Client workspace stewarded by{' '}
                                        <span className="font-medium text-gray-800">
                                            {incubation.incubated_by?.name || 'an agency'}
                                        </span>
                                        . Target plan (pre-transfer limits):{' '}
                                        <span className="font-mono text-gray-800">
                                            {incubation.incubation_target_plan_key || '—'}
                                        </span>
                                        .
                                    </p>
                                    <dl className="grid grid-cols-1 gap-3 sm:grid-cols-2 text-sm mb-4">
                                        <div>
                                            <dt className="text-gray-500">Incubated at</dt>
                                            <dd className="text-gray-900">{formatDate(incubation.incubated_at)}</dd>
                                        </div>
                                        <div>
                                            <dt className="text-gray-500">Transfer deadline</dt>
                                            <dd className="text-gray-900">{formatDate(incubation.incubation_expires_at)}</dd>
                                        </div>
                                        <div>
                                            <dt className="text-gray-500">Hard lock (expired window)</dt>
                                            <dd>
                                                {incubation.incubation_locked ? (
                                                    <span className="inline-flex rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-900">
                                                        Locked — uploads/downloads blocked
                                                    </span>
                                                ) : (
                                                    <span className="text-gray-700">No</span>
                                                )}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt className="text-gray-500">Max extension per grant (agency tier)</dt>
                                            <dd className="text-gray-900">
                                                {incubation.max_support_extension_days ?? '—'} days
                                            </dd>
                                        </div>
                                    </dl>
                                    <form onSubmit={submitIncubationExtend} className="rounded-lg border border-gray-200 bg-gray-50/80 p-4">
                                            <p className="text-sm font-medium text-gray-800 mb-3">Push transfer deadline</p>
                                            <div className="flex flex-wrap items-end gap-3">
                                                <div>
                                                    <label htmlFor="extend-days" className="block text-xs text-gray-500 mb-1">
                                                        Additional days (max {incubation.max_support_extension_days ?? 14})
                                                    </label>
                                                    <input
                                                        id="extend-days"
                                                        type="number"
                                                        min={1}
                                                        max={incubation.max_support_extension_days || 14}
                                                        value={extendDays}
                                                        onChange={(e) => setExtendDays(e.target.value)}
                                                        className="block w-28 rounded-md border border-gray-300 px-2 py-1.5 text-sm"
                                                    />
                                                </div>
                                                <div className="min-w-[200px] flex-1">
                                                    <label htmlFor="extend-reason" className="block text-xs text-gray-500 mb-1">
                                                        Reason (optional)
                                                    </label>
                                                    <input
                                                        id="extend-reason"
                                                        type="text"
                                                        value={extendReason}
                                                        onChange={(e) => setExtendReason(e.target.value)}
                                                        className="block w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm"
                                                        placeholder="e.g. Customer billing delayed"
                                                    />
                                                </div>
                                                <button
                                                    type="submit"
                                                    disabled={extendSubmitting}
                                                    className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
                                                >
                                                    {extendSubmitting ? 'Saving…' : 'Extend deadline'}
                                                </button>
                                            </div>
                                            {extendStatus && (
                                                <p
                                                    className={`mt-3 text-sm ${
                                                        extendStatus.type === 'success' ? 'text-green-700' : 'text-red-700'
                                                    }`}
                                                >
                                                    {extendStatus.message}
                                                </p>
                                            )}
                                        </form>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Linked agency partners (client company ↔ agency) */}
                    <div className="mb-8">
                        <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                            <div className="p-6">
                                <h2 className="text-lg font-semibold text-gray-900 mb-2 flex items-center gap-2">
                                    <BuildingOfficeIcon className="h-5 w-5 text-gray-400" />
                                    Agency partners
                                </h2>
                                <p className="text-sm text-gray-600 mb-4">
                                    Agencies linked to this company, their scope, and users provisioned by each agency.
                                </p>
                                {linked_agencies.length === 0 ? (
                                    <p className="text-sm text-gray-500">No agency is linked to this company.</p>
                                ) : (
                                    <div className="space-y-6">
                                        {linked_agencies.map((link) => (
                                            <div
                                                key={link.id}
                                                className="rounded-lg border border-gray-200 bg-gray-50/80 p-4"
                                            >
                                                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                                    <div>
                                                        <div className="flex flex-wrap items-center gap-2">
                                                            {link.agency_tenant?.id ? (
                                                                <Link
                                                                    href={`/app/admin/agencies/${link.agency_tenant.id}`}
                                                                    className="text-base font-semibold text-indigo-700 hover:text-indigo-900"
                                                                >
                                                                    {link.agency_tenant.name || 'Agency'}
                                                                </Link>
                                                            ) : (
                                                                <span className="text-base font-semibold text-gray-900">
                                                                    {link.agency_tenant?.name || 'Agency'}
                                                                </span>
                                                            )}
                                                            <span className="inline-flex rounded-md bg-white px-2 py-0.5 text-xs font-medium text-gray-700 ring-1 ring-gray-200">
                                                                {formatRoleLabel(link.role)}
                                                            </span>
                                                        </div>
                                                        {link.brand_assignments?.length > 0 && (
                                                            <ul className="mt-2 text-sm text-gray-700">
                                                                {link.brand_assignments.map((ba) => (
                                                                    <li key={`${link.id}-${ba.brand_id}`}>
                                                                        <span className="font-medium">{ba.brand_name || `Brand #${ba.brand_id}`}</span>
                                                                        {' — '}
                                                                        <span className="text-gray-600">{formatRoleLabel(ba.role)}</span>
                                                                    </li>
                                                                ))}
                                                            </ul>
                                                        )}
                                                    </div>
                                                    <button
                                                        type="button"
                                                        onClick={() => confirmRemoveAgencyLink(link)}
                                                        className="inline-flex shrink-0 items-center gap-1 rounded-md border border-red-200 bg-white px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-50"
                                                    >
                                                        <TrashIcon className="h-4 w-4" />
                                                        Remove agency link
                                                    </button>
                                                </div>

                                                <div className="mt-4 overflow-x-auto">
                                                    <table className="min-w-full divide-y divide-gray-200 text-sm">
                                                        <thead>
                                                            <tr className="text-left text-xs font-medium uppercase tracking-wide text-gray-500">
                                                                <th className="py-2 pr-4">User</th>
                                                                <th className="py-2 pr-4">Company role</th>
                                                                <th className="py-2 pr-4">Brand access</th>
                                                                <th className="py-2 text-right">Actions</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody className="divide-y divide-gray-100 bg-white">
                                                            {link.managed_users?.length ? (
                                                                link.managed_users.map((u) => (
                                                                    <tr key={u.id}>
                                                                        <td className="py-2 pr-4">
                                                                            <div className="font-medium text-gray-900">{u.name}</div>
                                                                            <div className="text-xs text-gray-500">{u.email}</div>
                                                                        </td>
                                                                        <td className="py-2 pr-4 text-gray-800">
                                                                            {formatRoleLabel(u.tenant_role)}
                                                                        </td>
                                                                        <td className="py-2 pr-4 text-gray-700">
                                                                            {u.brand_access?.length ? (
                                                                                <ul className="space-y-0.5">
                                                                                    {u.brand_access.map((b, i) => (
                                                                                        <li key={i}>
                                                                                            {b.brand_name}{' '}
                                                                                            <span className="text-gray-500">
                                                                                                ({formatRoleLabel(b.role)})
                                                                                            </span>
                                                                                        </li>
                                                                                    ))}
                                                                                </ul>
                                                                            ) : (
                                                                                <span className="text-gray-400">—</span>
                                                                            )}
                                                                        </td>
                                                                        <td className="py-2 text-right">
                                                                            <button
                                                                                type="button"
                                                                                onClick={() => confirmRemoveAgencyUser(u)}
                                                                                className="text-sm font-medium text-red-600 hover:text-red-800"
                                                                            >
                                                                                Remove from company
                                                                            </button>
                                                                        </td>
                                                                    </tr>
                                                                ))
                                                            ) : (
                                                                <tr>
                                                                    <td colSpan={4} className="py-3 text-gray-500">
                                                                        No agency-managed users on this link.
                                                                    </td>
                                                                </tr>
                                                            )}
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>

                    {/* Plan Management Section */}
                    {company.plan_management && (
                        <div className="mb-8">
                            <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                                <div className="p-6">
                                    <div className="flex flex-wrap items-center justify-between gap-2 mb-4">
                                        <h2 className="text-lg font-semibold text-gray-900">Plan Management</h2>
                                        <div className="flex flex-wrap items-center gap-2">
                                            {company.creator_module && (
                                                <button
                                                    type="button"
                                                    onClick={() => setAddonsModal({ open: true, step: 'pick' })}
                                                    className="inline-flex items-center px-3 py-2 text-sm font-medium text-violet-700 bg-violet-50 hover:bg-violet-100 rounded-md ring-1 ring-violet-200/80"
                                                >
                                                    <RectangleStackIcon className="h-4 w-4 mr-2" />
                                                    Add-on modules
                                                </button>
                                            )}
                                            {company.can_manage_plan && !company.plan_management.is_externally_managed && (
                                                <button
                                                    type="button"
                                                    onClick={() => {
                                                        setPlanChangeConfirm({
                                                            open: true,
                                                            oldPlan: company.plan_name || 'free',
                                                            newPlan: company.plan_name || 'free',
                                                            billingStatus: company.billing_status || 'comped',
                                                            expirationMonths: company.billing_status_expires_at ? Math.ceil((new Date(company.billing_status_expires_at) - new Date()) / (1000 * 60 * 60 * 24 * 30)) : 6,
                                                            equivalentPlanValue: null,
                                                        })
                                                    }}
                                                    className="inline-flex items-center px-3 py-2 text-sm font-medium text-indigo-600 bg-indigo-50 hover:bg-indigo-100 rounded-md"
                                                >
                                                    <PencilIcon className="h-4 w-4 mr-2" />
                                                    Change Plan
                                                </button>
                                            )}
                                        </div>
                                    </div>
                                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                        <div>
                                            <dt className="text-sm font-medium text-gray-500">Current Plan</dt>
                                            <dd className="mt-1">
                                                <span className={`inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ${
                                                    company.plan_name === 'free' ? 'bg-gray-100 text-gray-800' :
                                                    company.plan_name === 'starter' ? 'bg-green-100 text-green-800' :
                                                    company.plan_name === 'pro' ? 'bg-blue-100 text-blue-800' :
                                                    company.plan_name === 'premium' ? 'bg-indigo-100 text-indigo-800' :
                                                    company.plan_name === 'enterprise' ? 'bg-purple-100 text-purple-800' :
                                                    'bg-gray-100 text-gray-800'
                                                }`}>
                                                    {company.billing_status && (
                                                        <span className="mr-1 font-semibold">{company.billing_status === 'comped' ? 'Comped' : company.billing_status === 'trial' ? 'Trial' : ''}:</span>
                                                    )}
                                                    {company.plan}
                                                </span>
                                            </dd>
                                        </div>
                                        <div>
                                            <dt className="text-sm font-medium text-gray-500">Billing Status</dt>
                                            <dd className="mt-1 text-sm text-gray-900">
                                                {company.billing_status ? (
                                                    <span className={`inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ${
                                                        company.billing_status === 'comped' ? 'bg-blue-100 text-blue-800' :
                                                        company.billing_status === 'trial' ? 'bg-yellow-100 text-yellow-800' :
                                                        'bg-green-100 text-green-800'
                                                    }`}>
                                                        {company.billing_status === 'comped' ? 'Comped (Complimentary)' :
                                                         company.billing_status === 'trial' ? 'Trial' :
                                                         'Paid'}
                                                    </span>
                                                ) : (
                                                    company.stripe_connected ? 'Paid (Stripe)' : 'Free'
                                                )}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt className="text-sm font-medium text-gray-500">Expiration Date</dt>
                                            <dd className="mt-1 text-sm text-gray-900">
                                                {company.billing_status_expires_at ? (
                                                    <>
                                                        {formatDate(company.billing_status_expires_at)}
                                                        {new Date(company.billing_status_expires_at) < new Date() && (
                                                            <span className="ml-2 inline-flex items-center rounded-md px-2 py-1 text-xs font-medium bg-red-100 text-red-800">
                                                                Expired
                                                            </span>
                                                        )}
                                                        {new Date(company.billing_status_expires_at) >= new Date() && new Date(company.billing_status_expires_at) <= new Date(Date.now() + 30 * 24 * 60 * 60 * 1000) && (
                                                            <span className="ml-2 inline-flex items-center rounded-md px-2 py-1 text-xs font-medium bg-yellow-100 text-yellow-800">
                                                                Expiring Soon
                                                            </span>
                                                        )}
                                                    </>
                                                ) : (
                                                    <span className="text-gray-400">No expiration</span>
                                                )}
                                            </dd>
                                        </div>
                                        {company.plan_change_info && (
                                            <>
                                                <div>
                                                    <dt className="text-sm font-medium text-gray-500">Last Changed</dt>
                                                    <dd className="mt-1 text-sm text-gray-900">
                                                        {company.plan_change_info.changed_at ? formatDate(company.plan_change_info.changed_at) : 'N/A'}
                                                    </dd>
                                                </div>
                                                <div>
                                                    <dt className="text-sm font-medium text-gray-500">Changed By</dt>
                                                    <dd className="mt-1 text-sm text-gray-900">
                                                        {company.plan_change_info.changed_by?.name || 'System Administrator'}
                                                        {company.plan_change_info.changed_by?.email && (
                                                            <span className="text-gray-500 ml-1">({company.plan_change_info.changed_by.email})</span>
                                                        )}
                                                    </dd>
                                                </div>
                                                <div>
                                                    <dt className="text-sm font-medium text-gray-500">Previous Plan</dt>
                                                    <dd className="mt-1 text-sm text-gray-900">
                                                        {company.plan_change_info.old_plan ? ucfirst(company.plan_change_info.old_plan) : 'N/A'}
                                                    </dd>
                                                </div>
                                            </>
                                        )}
                                        <div>
                                            <dt className="text-sm font-medium text-gray-500">Management Source</dt>
                                            <dd className="mt-1 text-sm text-gray-900">
                                                {company.plan_management.source === 'stripe' ? 'Stripe' :
                                                 company.plan_management.source === 'shopify' ? 'Shopify' :
                                                 company.plan_management.source === 'manual' ? 'Manual (Admin)' :
                                                 'Unknown'}
                                            </dd>
                                        </div>
                                        {company.plan_name === 'enterprise' && (
                                            <div className="col-span-full">
                                                <dt className="text-sm font-medium text-gray-500 mb-2">Infrastructure Tier</dt>
                                                <dd className="mt-1">
                                                    <form
                                                        onSubmit={(e) => {
                                                            e.preventDefault()
                                                            const form = e.target
                                                            const tier = form.infrastructure_tier?.value
                                                            if (tier) {
                                                                router.put(route('admin.companies.update-infrastructure-tier', company.id), {
                                                                    infrastructure_tier: tier,
                                                                }, { preserveScroll: true })
                                                            }
                                                        }}
                                                        className="flex items-center gap-4"
                                                    >
                                                        <select
                                                            name="infrastructure_tier"
                                                            defaultValue={company.infrastructure_tier || 'shared'}
                                                            onChange={(e) => {
                                                                router.put(route('admin.companies.update-infrastructure-tier', company.id), {
                                                                    infrastructure_tier: e.target.value,
                                                                }, { preserveScroll: true })
                                                            }}
                                                            className="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                                        >
                                                            <option value="shared">Shared</option>
                                                            <option value="dedicated">Dedicated</option>
                                                        </select>
                                                        <span className="text-xs text-gray-500">
                                                            Dedicated = per-tenant S3 bucket. Sales provisions via this control.
                                                        </span>
                                                    </form>
                                                </dd>
                                            </div>
                                        )}
                                        {company.creator_module && (
                                            <div className="col-span-full border-t border-gray-200 pt-5 mt-2">
                                                <h3 className="text-sm font-semibold text-gray-900">
                                                    Add-on modules (summary)
                                                </h3>
                                                <p className="text-xs text-gray-500 mt-1 mb-4 max-w-2xl">
                                                    Grant or extend modules via the &quot;Add-on modules&quot; button above. Creator covers prostaff uploads and dashboards; Space is planned for storage add-ons.
                                                </p>
                                                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4 text-sm">
                                                    <div>
                                                        <dt className="text-gray-500">Entitled now</dt>
                                                        <dd className="mt-0.5 font-medium text-gray-900">
                                                            {company.creator_module.enabled ? (
                                                                <span className="text-green-700">Yes</span>
                                                            ) : (
                                                                <span className="text-gray-600">No</span>
                                                            )}
                                                        </dd>
                                                    </div>
                                                    <div>
                                                        <dt className="text-gray-500">Row status</dt>
                                                        <dd className="mt-0.5 font-medium text-gray-900">
                                                            {company.creator_module.status
                                                                ? company.creator_module.status
                                                                : '—'}
                                                        </dd>
                                                    </div>
                                                    <div>
                                                        <dt className="text-gray-500">Module expires</dt>
                                                        <dd className="mt-0.5 font-medium text-gray-900">
                                                            {company.creator_module.expires_at
                                                                ? formatDate(company.creator_module.expires_at)
                                                                : '—'}
                                                        </dd>
                                                    </div>
                                                    <div>
                                                        <dt className="text-gray-500">Seats limit</dt>
                                                        <dd className="mt-0.5 font-medium text-gray-900">
                                                            {company.creator_module.seats_limit != null
                                                                ? company.creator_module.seats_limit
                                                                : '—'}
                                                        </dd>
                                                    </div>
                                                </div>
                                            </div>
                                        )}
                                        {company.plan_management.is_externally_managed && (
                                            <div className="col-span-full">
                                                <div className="rounded-md bg-yellow-50 border border-yellow-200 p-3">
                                                    <p className="text-sm text-yellow-800">
                                                        <ExclamationTriangleIcon className="h-5 w-5 inline mr-2" />
                                                        This plan is managed externally and cannot be adjusted from this interface.
                                                    </p>
                                                </div>
                                            </div>
                                        )}
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Financial Overview */}
                    <div className="mb-8">
                        <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                            <div className="p-6">
                                <h2 className="text-lg font-semibold text-gray-900 mb-4">Financial Overview</h2>
                                
                                {/* Current Month Summary */}
                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-3 mb-6">
                                    <div className="rounded-lg bg-green-50 p-4">
                                        <dt className="text-sm font-medium text-green-800">Monthly Income</dt>
                                        <dd className="mt-1 text-2xl font-semibold text-green-900">
                                            {formatCurrency(currentIncome?.total_income || 0)}
                                        </dd>
                                    </div>
                                    <div className="rounded-lg bg-red-50 p-4">
                                        <dt className="text-sm font-medium text-red-800">Monthly Costs</dt>
                                        <dd className="mt-1 text-2xl font-semibold text-red-900">
                                            {formatCurrency(
                                                (currentCosts?.storage?.monthly_cost || 0) + 
                                                (currentCosts?.ai_agents?.total_cost || 0)
                                            )}
                                        </dd>
                                        <div className="mt-2 text-xs text-red-700">
                                            Storage: {formatCurrency(currentCosts?.storage?.monthly_cost || 0)}
                                            {currentCosts?.ai_agents?.total_cost > 0 && (
                                                <> • AI: {formatCurrency(currentCosts?.ai_agents?.total_cost || 0)}</>
                                            )}
                                        </div>
                                    </div>
                                    <div className={`rounded-lg p-4 ${
                                        profitability?.profit && profitability.profit > 0 
                                            ? 'bg-green-50' 
                                            : profitability?.profit && profitability.profit < 0
                                            ? 'bg-red-50'
                                            : 'bg-gray-50'
                                    }`}>
                                        <dt className={`text-sm font-medium ${
                                            profitability?.profit && profitability.profit > 0 
                                                ? 'text-green-800' 
                                                : profitability?.profit && profitability.profit < 0
                                                ? 'text-red-800'
                                                : 'text-gray-800'
                                        }`}>
                                            Net Profit
                                        </dt>
                                        <dd className={`mt-1 text-2xl font-semibold ${
                                            profitability?.profit && profitability.profit > 0 
                                                ? 'text-green-900' 
                                                : profitability?.profit && profitability.profit < 0
                                                ? 'text-red-900'
                                                : 'text-gray-900'
                                        }`}>
                                            {formatCurrency(profitability?.profit || 0)}
                                        </dd>
                                        {profitability?.margin_percent !== undefined && (
                                            <div className="mt-2 text-xs text-gray-600">
                                                Margin: {profitability.margin_percent}%
                                            </div>
                                        )}
                                    </div>
                                </div>

                                {/* 6-Month Chart */}
                                <div>
                                    <h3 className="text-sm font-semibold text-gray-900 mb-4">Last 6 Months</h3>
                                    <div className="overflow-x-auto">
                                        <div className="inline-block min-w-full">
                                            <div className="relative" style={{ height: `${chartHeight}px`, width: `${Math.max(chartWidth, monthlyData.length * 100)}px` }}>
                                                {/* Chart Bars */}
                                                <div className="absolute inset-0 flex items-end justify-between gap-2 px-4">
                                                    {monthlyData.map((data, index) => (
                                                        <div key={index} className="flex-1 flex flex-col items-center gap-1">
                                                            {/* Income Bar */}
                                                            <div className="w-full relative" style={{ height: `${chartHeight - 40}px` }}>
                                                                {data.income > 0 && (
                                                                    <div
                                                                        className="w-full bg-green-500 rounded-t absolute bottom-0 transition-all hover:bg-green-600"
                                                                        style={{
                                                                            height: `${(data.income / maxValue) * (chartHeight - 40)}px`,
                                                                        }}
                                                                        title={`Income: ${formatCurrency(data.income)}`}
                                                                    />
                                                                )}
                                                                {/* Cost Bar */}
                                                                {data.total_cost > 0 && (
                                                                    <div
                                                                        className="w-full bg-red-500 rounded-t absolute bottom-0 transition-all hover:bg-red-600 opacity-75"
                                                                        style={{
                                                                            height: `${(data.total_cost / maxValue) * (chartHeight - 40)}px`,
                                                                        }}
                                                                        title={`Costs: ${formatCurrency(data.total_cost)}`}
                                                                    />
                                                                )}
                                                            </div>
                                                            <div className="text-xs text-gray-600 text-center">
                                                                {data.month}
                                                            </div>
                                                        </div>
                                                    ))}
                                                </div>
                                                {/* Y-axis labels */}
                                                <div className="absolute left-0 top-0 bottom-10 flex flex-col justify-between text-xs text-gray-500">
                                                    <span>{formatCurrency(maxValue)}</span>
                                                    <span>{formatCurrency(maxValue / 2)}</span>
                                                    <span>$0</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    {/* Legend */}
                                    <div className="flex items-center justify-center gap-4 mt-4">
                                        <div className="flex items-center gap-2">
                                            <div className="h-3 w-3 bg-green-500 rounded"></div>
                                            <span className="text-xs text-gray-600">Income</span>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <div className="h-3 w-3 bg-red-500 rounded"></div>
                                            <span className="text-xs text-gray-600">Costs</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Activity, Users, Brands Teasers */}
                    <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                        {/* Recent Activity */}
                        <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                            <div className="p-6">
                                <div className="flex items-center justify-between mb-4">
                                    <h2 className="text-lg font-semibold text-gray-900 flex items-center gap-2">
                                        <ClockIcon className="h-5 w-5 text-gray-400" />
                                        Recent Activity
                                    </h2>
                                    <Link
                                        href={`/app/admin/activity-logs?tenant_id=${company.id}`}
                                        className="text-sm text-indigo-600 hover:text-indigo-900"
                                    >
                                        View All
                                    </Link>
                                </div>
                                {recentActivity && recentActivity.length > 0 ? (
                                    <div className="space-y-3">
                                        {recentActivity.slice(0, 5).map((activity) => (
                                            <div key={activity.id} className="text-sm">
                                                <p className="text-gray-900">{activity.description || activity.type}</p>
                                                <p className="text-xs text-gray-500 mt-1">{formatDate(activity.created_at)}</p>
                                            </div>
                                        ))}
                                    </div>
                                ) : (
                                    <p className="text-sm text-gray-500">No recent activity</p>
                                )}
                            </div>
                        </div>

                        {/* Users */}
                        <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                            <div className="p-6">
                                {/* TODO: Add a way to manage all users associated with this company and their roles with the company and the brand */}
                                <div className="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                                    <span className="font-medium">TODO:</span> Add a way to manage all users associated with this company and their roles with the company and the brand.
                                </div>
                                <div className="flex items-center justify-between mb-4">
                                    <h2 className="text-lg font-semibold text-gray-900 flex items-center gap-2">
                                        <UsersIcon className="h-5 w-5 text-gray-400" />
                                        Users
                                    </h2>
                                    <div className="flex items-center gap-2">
                                        <button
                                            onClick={() => setShowAddUserForm(!showAddUserForm)}
                                            className="text-sm text-indigo-600 hover:text-indigo-900 flex items-center gap-1"
                                        >
                                            <UserPlusIcon className="h-4 w-4" />
                                            Add User
                                        </button>
                                        <Link
                                            href="/app/admin/organization?tab=users"
                                            className="text-sm text-indigo-600 hover:text-indigo-900"
                                        >
                                            View All ({stats.total_users})
                                        </Link>
                                    </div>
                                </div>

                                {/* Add User Form */}
                                {showAddUserForm && (
                                    <div className="mb-4 p-4 bg-gray-50 rounded-lg border border-gray-200">
                                        <form onSubmit={handleSubmit} className="space-y-4">
                                            <div>
                                                <label className="block text-sm font-medium leading-6 text-gray-900 mb-2">
                                                    Select User
                                                </label>
                                                <input
                                                    type="text"
                                                    value={userSearchQuery}
                                                    onChange={(e) => setUserSearchQuery(e.target.value)}
                                                    placeholder="Search for a user by name or email..."
                                                    className="block w-full rounded-md border-0 py-2 pl-3 pr-10 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6 mb-2"
                                                />
                                                {loadingUsers && (
                                                    <p className="text-xs text-gray-500">Searching...</p>
                                                )}
                                                {!loadingUsers && availableUsers.length > 0 && (
                                                    <div className="mt-2 max-h-60 overflow-auto rounded-md bg-white py-1 text-base shadow-lg ring-1 ring-black ring-opacity-5 focus:outline-none sm:text-sm border border-gray-200">
                                                        {availableUsers.map((user) => {
                                                            const isSelected = data.user_id === user.id
                                                            return (
                                                                <div
                                                                    key={user.id}
                                                                    onClick={() => handleUserSelect(user)}
                                                                    className={`relative cursor-default select-none py-2 pl-3 pr-9 ${
                                                                        isSelected ? 'bg-indigo-50' : 'text-gray-900 hover:bg-gray-50'
                                                                    }`}
                                                                >
                                                                    <div className="flex items-center">
                                                                        <div className="flex h-8 w-8 items-center justify-center rounded-full bg-indigo-600 text-sm font-medium text-white flex-shrink-0">
                                                                            {user.first_name && user.last_name
                                                                                ? `${user.first_name.charAt(0)}${user.last_name.charAt(0)}`.toUpperCase()
                                                                                : (user.first_name ? user.first_name.charAt(0) : user.email.charAt(0)).toUpperCase()}
                                                                        </div>
                                                                        <div className="ml-3">
                                                                            <span className={`block truncate ${isSelected ? 'font-semibold' : 'font-normal'}`}>
                                                                                {user.first_name && user.last_name
                                                                                    ? `${user.first_name} ${user.last_name}`
                                                                                    : user.first_name || user.email}
                                                                            </span>
                                                                            <span className="block truncate text-xs text-gray-500">{user.email}</span>
                                                                        </div>
                                                                    </div>
                                                                    {isSelected && (
                                                                        <span className="absolute inset-y-0 right-0 flex items-center pr-4 text-indigo-600">
                                                                            <svg className="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                                                                                <path fillRule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clipRule="evenodd" />
                                                                            </svg>
                                                                        </span>
                                                                    )}
                                                                </div>
                                                            )
                                                        })}
                                                    </div>
                                                )}
                                                {!loadingUsers && userSearchQuery.length >= 2 && availableUsers.length === 0 && (
                                                    <p className="text-xs text-gray-500 mt-2">No users found</p>
                                                )}
                                                {data.user_id && (
                                                    <p className="text-xs text-indigo-600 mt-2">
                                                        Selected: {availableUsers.find(u => u.id === data.user_id)?.email || 'User selected'}
                                                    </p>
                                                )}
                                                {errors.user_id && (
                                                    <p className="mt-1 text-sm text-red-600">{errors.user_id}</p>
                                                )}
                                            </div>
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
                                                    {company.plan_name && ['pro', 'premium', 'enterprise'].includes(company.plan_name.toLowerCase()) && (
                                                        <option value="brand_manager">Brand Manager</option>
                                                    )}
                                                    <option value="owner">Owner</option>
                                                </select>
                                                {errors.role && (
                                                    <p className="mt-1 text-sm text-red-600">{errors.role}</p>
                                                )}
                                            </div>

                                            {/* Brand Assignments */}
                                            {all_brands && all_brands.length > 0 && (
                                                <BrandRoleSelector
                                                    brands={all_brands}
                                                    selectedBrands={data.brands}
                                                    onChange={handleBrandsChange}
                                                    errors={errors}
                                                    required={true}
                                                />
                                            )}

                                            <div className="flex items-center gap-2 pt-2">
                                                <button
                                                    type="submit"
                                                    disabled={processing}
                                                    className="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50"
                                                >
                                                    {processing ? 'Adding...' : 'Add User'}
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => {
                                                        setShowAddUserForm(false)
                                                        reset()
                                                        setUserSearchQuery('')
                                                        setAvailableUsers([])
                                                    }}
                                                    className="rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
                                                >
                                                    Cancel
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                )}

                                {users && users.length > 0 ? (
                                    <div className="space-y-3">
                                        {users.map((user) => (
                                            <div key={user.id} className="text-sm">
                                                <p className="text-gray-900 font-medium">{user.name || user.email}</p>
                                                <div className="flex items-center gap-2 mt-1">
                                                    <span className="text-xs text-gray-500">{user.email}</span>
                                                    {user.last_login_at && (
                                                        <span className="text-xs text-gray-400">
                                                            Last login{' '}
                                                            {new Date(user.last_login_at).toLocaleString(undefined, {
                                                                dateStyle: 'medium',
                                                                timeStyle: 'short',
                                                            })}
                                                        </span>
                                                    )}
                                                    {user.is_owner && (
                                                        <span className="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-indigo-100 text-indigo-800">
                                                            Owner
                                                        </span>
                                                    )}
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                ) : (
                                    <p className="text-sm text-gray-500">No users</p>
                                )}
                            </div>
                        </div>

                        {/* Brands */}
                        <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                            <div className="p-6">
                                <div className="flex items-center justify-between mb-4">
                                    <h2 className="text-lg font-semibold text-gray-900 flex items-center gap-2">
                                        <TagIcon className="h-5 w-5 text-gray-400" />
                                        Brands
                                    </h2>
                                    <div className="flex items-center gap-2">
                                        <Link
                                            href={`/app/admin/companies/${company.id}?tab=brands`}
                                            className="text-sm text-indigo-600 hover:text-indigo-900"
                                        >
                                            View All
                                        </Link>
                                        <span className="text-sm text-gray-500">{stats.total_brands} total</span>
                                    </div>
                                </div>
                                {brands && brands.length > 0 ? (
                                    <div className="space-y-3">
                                        {brands.map((brand) => (
                                            <div key={brand.id} className="text-sm">
                                                <p className="text-gray-900 font-medium">
                                                    {brand.name}
                                                    {brand.is_default && (
                                                        <span className="ml-2 inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-indigo-100 text-indigo-800">
                                                            Default
                                                        </span>
                                                    )}
                                                </p>
                                            </div>
                                        ))}
                                    </div>
                                ) : (
                                    <p className="text-sm text-gray-500">No brands</p>
                                )}
                            </div>
                        </div>
                    </div>

                    {/* AI Billing Estimates */}
                    <div className="mt-8">
                        <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                            <div className="p-6">
                                <div className="flex flex-wrap items-center justify-between gap-3 mb-4">
                                    <h2 className="text-lg font-semibold text-gray-900 flex items-center gap-2">
                                        <SparklesIcon className="h-5 w-5 text-purple-500" />
                                        AI Usage & Billing Estimates
                                    </h2>
                                    {aiUsage?.cost_detail && (
                                        <button
                                            type="button"
                                            onClick={() => setAiCostModalOpen(true)}
                                            className="inline-flex items-center gap-1.5 rounded-md border border-indigo-200 bg-indigo-50 px-3 py-1.5 text-sm font-medium text-indigo-800 hover:bg-indigo-100"
                                        >
                                            <ChartPieIcon className="h-4 w-4" aria-hidden />
                                            Cost breakdown / line items
                                        </button>
                                    )}
                                </div>

                                {aiUsage && aiUsage.status === 'success' ? (
                                    <>
                                        {/* Current Month Overview */}
                                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4 mb-6">
                                            <div className="rounded-lg bg-purple-50 p-4">
                                                <dt className="text-sm font-medium text-purple-800">Plan credits used</dt>
                                                <dd className="mt-1 text-2xl font-semibold text-purple-900">
                                                    {Math.round(aiUsage.current_usage?.credits_used ?? 0).toLocaleString()}
                                                </dd>
                                                <div className="mt-1 text-xs text-purple-700">
                                                    Weighted credits this month (caps / limits)
                                                </div>
                                                <div className="mt-2 border-t border-purple-200/80 pt-2 text-[11px] text-purple-800/90">
                                                    Agent workflow runs logged:{' '}
                                                    <span className="font-semibold">
                                                        {aiUsage.current_usage?.total_runs ?? 0}
                                                    </span>
                                                </div>
                                            </div>
                                            <div className="rounded-lg bg-green-50 p-4">
                                                <dt className="text-sm font-medium text-green-800">Cost to date (est.)</dt>
                                                <dd className="mt-1 text-2xl font-semibold text-green-900">
                                                    {formatCurrency(aiUsage.current_usage?.cost_to_date || 0)}
                                                </dd>
                                                <div className="mt-1 text-xs text-green-700">
                                                    Agent runs + metered usage (see breakdown)
                                                </div>
                                            </div>
                                            <div className="rounded-lg bg-blue-50 p-4">
                                                <dt className="text-sm font-medium text-blue-800">Projected monthly</dt>
                                                <dd className="mt-1 text-2xl font-semibold text-blue-900">
                                                    {formatCurrency(aiUsage.projections?.monthly_cost || 0)}
                                                </dd>
                                                <div className="mt-1 text-xs text-blue-700">
                                                    ~{Math.round(aiUsage.projections?.monthly_credits ?? 0).toLocaleString()}{' '}
                                                    credits (linear extrapolation)
                                                </div>
                                            </div>
                                            <div className={`rounded-lg p-4 ${
                                                aiUsage.projections?.usage_percentage > 80 
                                                    ? 'bg-red-50' 
                                                    : aiUsage.projections?.usage_percentage > 60
                                                    ? 'bg-yellow-50'
                                                    : 'bg-gray-50'
                                            }`}>
                                                <dt className={`text-sm font-medium ${
                                                    aiUsage.projections?.usage_percentage > 80 
                                                        ? 'text-red-800' 
                                                        : aiUsage.projections?.usage_percentage > 60
                                                        ? 'text-yellow-800'
                                                        : 'text-gray-800'
                                                }`}>
                                                    Cap Usage
                                                </dt>
                                                <dd className={`mt-1 text-2xl font-semibold ${
                                                    aiUsage.projections?.usage_percentage > 80 
                                                        ? 'text-red-900' 
                                                        : aiUsage.projections?.usage_percentage > 60
                                                        ? 'text-yellow-900'
                                                        : 'text-gray-900'
                                                }`}>
                                                    {Math.round(aiUsage.projections?.usage_percentage || 0)}%
                                                </dd>
                                                <div className={`mt-1 text-xs ${
                                                    aiUsage.projections?.usage_percentage > 80 
                                                        ? 'text-red-700' 
                                                        : aiUsage.projections?.usage_percentage > 60
                                                        ? 'text-yellow-700'
                                                        : 'text-gray-700'
                                                }`}>
                                                    of monthly cap
                                                </div>
                                            </div>
                                        </div>

                                        {aiUsage.cost_detail?.methodology && (
                                            <p className="mb-6 rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-xs leading-relaxed text-gray-700">
                                                <span className="font-semibold text-gray-800">About these numbers: </span>
                                                {aiUsage.cost_detail.methodology}
                                            </p>
                                        )}

                                        {/* Feature Breakdown */}
                                        {aiUsage.current_usage?.features && Object.keys(aiUsage.current_usage.features).length > 0 && (
                                            <div className="mb-6">
                                                <h3 className="text-sm font-semibold text-gray-900 mb-3">Usage by Feature</h3>
                                                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                                    {Object.entries(aiUsage.current_usage.features).map(([feature, usage]) => (
                                                        <div key={feature} className="flex items-center justify-between p-3 bg-gray-50 rounded-md">
                                                            <div className="flex items-center gap-2">
                                                                <CpuChipIcon className="h-4 w-4 text-gray-500" />
                                                                <span className="text-sm font-medium text-gray-900 capitalize">
                                                                    {feature}
                                                                </span>
                                                            </div>
                                                            <div className="text-sm text-gray-600">
                                                                {usage} calls
                                                                {aiUsage.caps?.[feature] > 0 && (
                                                                    <span className="text-xs text-gray-500 ml-1">
                                                                        / {aiUsage.caps[feature]} cap
                                                                    </span>
                                                                )}
                                                            </div>
                                                        </div>
                                                    ))}
                                                </div>
                                            </div>
                                        )}

                                        {/* Monthly Caps */}
                                        <div className="border-t border-gray-200 pt-4">
                                            <h3 className="text-sm font-semibold text-gray-900 mb-3">Monthly Caps & Limits</h3>
                                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                                <div className="flex items-center justify-between p-3 bg-blue-50 rounded-md">
                                                    <span className="text-sm font-medium text-blue-900">Tagging Cap</span>
                                                    <span className="text-sm text-blue-700">
                                                        {aiUsage.caps?.tagging > 0 ? `${aiUsage.caps.tagging} calls/month` : 'Unlimited'}
                                                    </span>
                                                </div>
                                                <div className="flex items-center justify-between p-3 bg-indigo-50 rounded-md">
                                                    <span className="text-sm font-medium text-indigo-900">Suggestions Cap</span>
                                                    <span className="text-sm text-indigo-700">
                                                        {aiUsage.caps?.suggestions > 0 ? `${aiUsage.caps.suggestions} calls/month` : 'Unlimited'}
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    </>
                                ) : aiUsage && aiUsage.status === 'error' ? (
                                    <div className="rounded-md bg-red-50 p-4">
                                        <div className="flex">
                                            <div className="flex-shrink-0">
                                                <ExclamationTriangleIcon className="h-5 w-5 text-red-400" />
                                            </div>
                                            <div className="ml-3">
                                                <p className="text-sm text-red-800">
                                                    {aiUsage.message || 'Failed to load AI usage data'}
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                ) : (
                                    <div className="rounded-md bg-gray-50 p-4">
                                        <div className="flex">
                                            <div className="flex-shrink-0">
                                                <InformationCircleIcon className="h-5 w-5 text-gray-400" />
                                            </div>
                                            <div className="ml-3">
                                                <p className="text-sm text-gray-600">
                                                    AI usage data not available
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>

                    {/* Remaining TODO Section */}
                    <div className="mt-6 text-xs text-gray-500 bg-gray-50 rounded-lg p-4">
                        <p className="font-semibold mb-2">Future Enhancements:</p>
                        <ul className="list-disc list-inside space-y-1">
                            <li>AI cost optimization recommendations from usage patterns</li>
                            <li>Usage alerts when spend or credits cross thresholds</li>
                            <li>Export AI cost / usage reports (CSV)</li>
                        </ul>
                    </div>
                </div>
            </main>

            {aiCostModalOpen && aiUsage?.status === 'success' && aiUsage?.cost_detail && (
                <div className="fixed inset-0 z-50 overflow-y-auto">
                    <div className="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                        <div
                            className="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity"
                            role="presentation"
                            onClick={() => setAiCostModalOpen(false)}
                        />
                        <div
                            className="relative z-10 max-h-[90vh] w-full max-w-4xl transform overflow-hidden rounded-lg bg-white text-left shadow-xl transition-all"
                            role="dialog"
                            aria-modal="true"
                            aria-labelledby="ai-cost-modal-title"
                        >
                            <div className="flex items-start justify-between border-b border-gray-200 px-5 py-4">
                                <div>
                                    <h3 id="ai-cost-modal-title" className="text-lg font-semibold text-gray-900">
                                        AI spend detail — {company.name}
                                    </h3>
                                    <p className="mt-1 text-xs text-gray-500">
                                        {aiUsage.cost_detail.period_start && aiUsage.cost_detail.period_end
                                            ? `${new Date(aiUsage.cost_detail.period_start).toLocaleDateString()} → ${new Date(aiUsage.cost_detail.period_end).toLocaleDateString()}`
                                            : 'Current calendar month'}
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    className="rounded-md p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600"
                                    onClick={() => setAiCostModalOpen(false)}
                                >
                                    <XMarkIcon className="h-6 w-6" aria-hidden />
                                    <span className="sr-only">Close</span>
                                </button>
                            </div>
                            <div className="max-h-[calc(90vh-5rem)] overflow-y-auto px-5 py-4 space-y-8">
                                <section>
                                    <h4 className="text-sm font-semibold text-gray-900">Summary</h4>
                                    <dl className="mt-2 grid grid-cols-1 gap-3 sm:grid-cols-3">
                                        <div className="rounded-md border border-gray-100 bg-gray-50 p-3">
                                            <dt className="text-xs text-gray-500">Combined (shown on overview)</dt>
                                            <dd className="text-lg font-semibold text-gray-900">
                                                {formatCurrency(aiUsage.cost_detail.combined_total_usd ?? 0)}
                                            </dd>
                                        </div>
                                        <div className="rounded-md border border-gray-100 bg-gray-50 p-3">
                                            <dt className="text-xs text-gray-500">Agent / workflow runs</dt>
                                            <dd className="text-lg font-semibold text-gray-900">
                                                {formatCurrency(aiUsage.cost_detail.agent_runs_total_usd ?? 0)}
                                            </dd>
                                            <dd className="text-xs text-gray-600">
                                                {aiUsage.cost_detail.agent_run_count ?? 0} runs
                                            </dd>
                                        </div>
                                        <div className="rounded-md border border-gray-100 bg-gray-50 p-3">
                                            <dt className="text-xs text-gray-500">Metered (ai_usage ledger)</dt>
                                            <dd className="text-lg font-semibold text-gray-900">
                                                {formatCurrency(aiUsage.cost_detail.metered_total_usd ?? 0)}
                                            </dd>
                                        </div>
                                    </dl>
                                    <p className="mt-3 text-xs leading-relaxed text-gray-600">{aiUsage.cost_detail.methodology}</p>
                                </section>

                                {Array.isArray(aiUsage.cost_detail.metered_by_feature) &&
                                    aiUsage.cost_detail.metered_by_feature.length > 0 && (
                                        <section>
                                            <h4 className="text-sm font-semibold text-gray-900">Metered usage by feature</h4>
                                            <div className="mt-2 overflow-x-auto rounded-md border border-gray-200">
                                                <table className="min-w-full divide-y divide-gray-200 text-sm">
                                                    <thead className="bg-gray-50">
                                                        <tr>
                                                            <th className="px-3 py-2 text-left font-medium text-gray-700">Feature</th>
                                                            <th className="px-3 py-2 text-right font-medium text-gray-700">Calls</th>
                                                            <th className="px-3 py-2 text-right font-medium text-gray-700">Est. cost</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody className="divide-y divide-gray-100 bg-white">
                                                        {aiUsage.cost_detail.metered_by_feature.map((row) => (
                                                            <tr key={row.feature}>
                                                                <td className="px-3 py-2 text-gray-900">{row.feature}</td>
                                                                <td className="px-3 py-2 text-right tabular-nums text-gray-700">
                                                                    {row.calls}
                                                                </td>
                                                                <td className="px-3 py-2 text-right tabular-nums text-gray-900">
                                                                    {formatCurrency(row.cost_usd)}
                                                                </td>
                                                            </tr>
                                                        ))}
                                                    </tbody>
                                                </table>
                                            </div>
                                        </section>
                                    )}

                                {Array.isArray(aiUsage.cost_detail.by_agent) && aiUsage.cost_detail.by_agent.length > 0 && (
                                    <section>
                                        <h4 className="text-sm font-semibold text-gray-900">Spend by agent name</h4>
                                        <div className="mt-2 overflow-x-auto rounded-md border border-gray-200">
                                            <table className="min-w-full divide-y divide-gray-200 text-sm">
                                                <thead className="bg-gray-50">
                                                    <tr>
                                                        <th className="px-3 py-2 text-left font-medium text-gray-700">Agent</th>
                                                        <th className="px-3 py-2 text-right font-medium text-gray-700">Runs</th>
                                                        <th className="px-3 py-2 text-right font-medium text-gray-700">Est. cost</th>
                                                    </tr>
                                                </thead>
                                                <tbody className="divide-y divide-gray-100 bg-white">
                                                    {aiUsage.cost_detail.by_agent.map((row) => (
                                                        <tr key={row.label}>
                                                            <td className="px-3 py-2 text-gray-900">{row.label}</td>
                                                            <td className="px-3 py-2 text-right tabular-nums text-gray-700">
                                                                {row.run_count}
                                                            </td>
                                                            <td className="px-3 py-2 text-right tabular-nums text-gray-900">
                                                                {formatCurrency(row.cost_usd)}
                                                            </td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </div>
                                    </section>
                                )}

                                {Array.isArray(aiUsage.cost_detail.by_model) && aiUsage.cost_detail.by_model.length > 0 && (
                                    <section>
                                        <h4 className="text-sm font-semibold text-gray-900">Spend by model</h4>
                                        <div className="mt-2 overflow-x-auto rounded-md border border-gray-200">
                                            <table className="min-w-full divide-y divide-gray-200 text-sm">
                                                <thead className="bg-gray-50">
                                                    <tr>
                                                        <th className="px-3 py-2 text-left font-medium text-gray-700">Model</th>
                                                        <th className="px-3 py-2 text-right font-medium text-gray-700">Runs</th>
                                                        <th className="px-3 py-2 text-right font-medium text-gray-700">Est. cost</th>
                                                    </tr>
                                                </thead>
                                                <tbody className="divide-y divide-gray-100 bg-white">
                                                    {aiUsage.cost_detail.by_model.map((row) => (
                                                        <tr key={row.label}>
                                                            <td className="px-3 py-2 font-mono text-xs text-gray-900">{row.label}</td>
                                                            <td className="px-3 py-2 text-right tabular-nums text-gray-700">
                                                                {row.run_count}
                                                            </td>
                                                            <td className="px-3 py-2 text-right tabular-nums text-gray-900">
                                                                {formatCurrency(row.cost_usd)}
                                                            </td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </div>
                                    </section>
                                )}

                                {Array.isArray(aiUsage.cost_detail.by_task_type) && aiUsage.cost_detail.by_task_type.length > 0 && (
                                    <section>
                                        <h4 className="text-sm font-semibold text-gray-900">Spend by task type</h4>
                                        <div className="mt-2 overflow-x-auto rounded-md border border-gray-200">
                                            <table className="min-w-full divide-y divide-gray-200 text-sm">
                                                <thead className="bg-gray-50">
                                                    <tr>
                                                        <th className="px-3 py-2 text-left font-medium text-gray-700">Task</th>
                                                        <th className="px-3 py-2 text-right font-medium text-gray-700">Runs</th>
                                                        <th className="px-3 py-2 text-right font-medium text-gray-700">Est. cost</th>
                                                    </tr>
                                                </thead>
                                                <tbody className="divide-y divide-gray-100 bg-white">
                                                    {aiUsage.cost_detail.by_task_type.map((row) => (
                                                        <tr key={row.label}>
                                                            <td className="px-3 py-2 text-gray-900">{row.label}</td>
                                                            <td className="px-3 py-2 text-right tabular-nums text-gray-700">
                                                                {row.run_count}
                                                            </td>
                                                            <td className="px-3 py-2 text-right tabular-nums text-gray-900">
                                                                {formatCurrency(row.cost_usd)}
                                                            </td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </div>
                                    </section>
                                )}

                                {Array.isArray(aiUsage.cost_detail.by_day) && aiUsage.cost_detail.by_day.length > 0 && (
                                    <section>
                                        <h4 className="text-sm font-semibold text-gray-900">Spend by day (agent runs)</h4>
                                        <div className="mt-2 overflow-x-auto rounded-md border border-gray-200">
                                            <table className="min-w-full divide-y divide-gray-200 text-sm">
                                                <thead className="bg-gray-50">
                                                    <tr>
                                                        <th className="px-3 py-2 text-left font-medium text-gray-700">Date</th>
                                                        <th className="px-3 py-2 text-right font-medium text-gray-700">Runs</th>
                                                        <th className="px-3 py-2 text-right font-medium text-gray-700">Est. cost</th>
                                                    </tr>
                                                </thead>
                                                <tbody className="divide-y divide-gray-100 bg-white">
                                                    {aiUsage.cost_detail.by_day.map((row) => (
                                                        <tr key={row.day}>
                                                            <td className="px-3 py-2 text-gray-900">{row.day}</td>
                                                            <td className="px-3 py-2 text-right tabular-nums text-gray-700">
                                                                {row.run_count}
                                                            </td>
                                                            <td className="px-3 py-2 text-right tabular-nums text-gray-900">
                                                                {formatCurrency(row.cost_usd)}
                                                            </td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </div>
                                    </section>
                                )}

                                {Array.isArray(aiUsage.cost_detail.recent_runs) && aiUsage.cost_detail.recent_runs.length > 0 && (
                                    <section>
                                        <h4 className="text-sm font-semibold text-gray-900">
                                            Recent agent runs (line items, newest first)
                                        </h4>
                                        <p className="mt-1 text-xs text-gray-500">Showing up to 100 rows for this month.</p>
                                        <div className="mt-2 max-h-72 overflow-auto rounded-md border border-gray-200">
                                            <table className="min-w-full divide-y divide-gray-200 text-xs">
                                                <thead className="sticky top-0 bg-gray-50">
                                                    <tr>
                                                        <th className="px-2 py-2 text-left font-medium text-gray-700">When</th>
                                                        <th className="px-2 py-2 text-left font-medium text-gray-700">Agent</th>
                                                        <th className="px-2 py-2 text-left font-medium text-gray-700">Task</th>
                                                        <th className="px-2 py-2 text-left font-medium text-gray-700">Model</th>
                                                        <th className="px-2 py-2 text-right font-medium text-gray-700">Cost</th>
                                                        <th className="px-2 py-2 text-center font-medium text-gray-700">Status</th>
                                                        <th className="px-2 py-2 text-right font-medium text-gray-700">Tok in/out</th>
                                                    </tr>
                                                </thead>
                                                <tbody className="divide-y divide-gray-100 bg-white">
                                                    {aiUsage.cost_detail.recent_runs.map((r) => (
                                                        <tr key={r.id}>
                                                            <td className="whitespace-nowrap px-2 py-1.5 text-gray-700">
                                                                {formatDate(r.started_at)}
                                                            </td>
                                                            <td className="max-w-[10rem] truncate px-2 py-1.5 text-gray-900">
                                                                {r.agent_name || '—'}
                                                            </td>
                                                            <td className="max-w-[8rem] truncate px-2 py-1.5 text-gray-700">
                                                                {r.task_type || '—'}
                                                            </td>
                                                            <td className="max-w-[10rem] truncate font-mono px-2 py-1.5 text-gray-600">
                                                                {r.model_used || '—'}
                                                            </td>
                                                            <td className="whitespace-nowrap px-2 py-1.5 text-right font-medium text-gray-900">
                                                                {formatCurrency(r.estimated_cost_usd)}
                                                            </td>
                                                            <td className="whitespace-nowrap px-2 py-1.5 text-center text-gray-600">
                                                                {r.status}
                                                            </td>
                                                            <td className="whitespace-nowrap px-2 py-1.5 text-right text-gray-500">
                                                                {r.tokens_in}/{r.tokens_out}
                                                            </td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </div>
                                    </section>
                                )}
                            </div>
                            <div className="border-t border-gray-200 bg-gray-50 px-5 py-3 text-right">
                                <button
                                    type="button"
                                    className="rounded-md bg-white px-3 py-1.5 text-sm font-medium text-gray-700 ring-1 ring-gray-300 hover:bg-gray-100"
                                    onClick={() => setAiCostModalOpen(false)}
                                >
                                    Close
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            )}

            <AppFooter />

            {/* Add-on modules: pick module, then configure (Creator today; Space placeholder) */}
            {addonsModal.open && company.creator_module && (
                <div className="fixed inset-0 z-50 overflow-y-auto">
                    <div className="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                        <div
                            className="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity"
                            onClick={closeAddonsModal}
                            aria-hidden="true"
                        />
                        <div
                            className={`relative transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:p-6 ${
                                addonsModal.step === 'creator' ? 'sm:max-w-xl' : 'sm:max-w-lg'
                            }`}
                            role="dialog"
                            aria-modal="true"
                            aria-labelledby="addons-modal-title"
                        >
                            <div className="absolute right-0 top-0 pr-4 pt-4">
                                <button
                                    type="button"
                                    onClick={closeAddonsModal}
                                    className="rounded-md bg-white text-gray-400 hover:text-gray-600 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                >
                                    <span className="sr-only">Close</span>
                                    <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>

                            {addonsModal.step === 'pick' ? (
                                <>
                                    <div className="sm:flex sm:items-start">
                                        <div className="mx-auto flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-violet-100 sm:mx-0 sm:h-10 sm:w-10">
                                            <RectangleStackIcon className="h-6 w-6 text-violet-600" aria-hidden="true" />
                                        </div>
                                        <div className="mt-3 text-center sm:ml-4 sm:mt-0 sm:text-left flex-1 pr-8">
                                            <h3 id="addons-modal-title" className="text-lg font-semibold text-gray-900">
                                                Add-on modules
                                            </h3>
                                            <p className="mt-2 text-sm text-gray-500">
                                                Choose a module to configure for {company.name}.
                                            </p>
                                        </div>
                                    </div>
                                    <ul className="mt-6 space-y-3">
                                        <li>
                                            <button
                                                type="button"
                                                onClick={() => setAddonsModal({ open: true, step: 'creator' })}
                                                className="flex w-full items-start gap-4 rounded-lg border border-gray-200 p-4 text-left transition hover:border-violet-300 hover:bg-violet-50/50"
                                            >
                                                <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-indigo-100">
                                                    <SparklesIcon className="h-5 w-5 text-indigo-600" />
                                                </span>
                                                <span className="min-w-0 flex-1">
                                                    <span className="block text-sm font-semibold text-gray-900">Creator (Prostaff)</span>
                                                    <span className="mt-0.5 block text-xs text-gray-500">
                                                        Prostaff uploads, assignments, and dashboards. Set expiry, trial/active, and optional seats cap.
                                                    </span>
                                                </span>
                                                <span className="shrink-0 text-sm font-medium text-violet-700">Configure</span>
                                            </button>
                                        </li>
                                        <li>
                                            <div className="flex w-full items-start gap-4 rounded-lg border border-dashed border-gray-200 bg-gray-50/80 p-4 text-left opacity-80">
                                                <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-gray-200">
                                                    <CubeIcon className="h-5 w-5 text-gray-600" />
                                                </span>
                                                <span className="min-w-0 flex-1">
                                                    <span className="block text-sm font-semibold text-gray-700">Space</span>
                                                    <span className="mt-0.5 block text-xs text-gray-500">
                                                        Storage and asset add-ons (planned). Not available yet.
                                                    </span>
                                                </span>
                                                <span className="shrink-0 text-sm font-medium text-gray-400">Coming soon</span>
                                            </div>
                                        </li>
                                    </ul>
                                </>
                            ) : (
                                <>
                                    <button
                                        type="button"
                                        onClick={() => setAddonsModal({ open: true, step: 'pick' })}
                                        className="mb-4 inline-flex items-center text-sm font-medium text-gray-600 hover:text-gray-900"
                                    >
                                        <ArrowLeftIcon className="mr-1.5 h-4 w-4" />
                                        Back to modules
                                    </button>
                                    <h3 id="addons-modal-title" className="text-lg font-semibold text-gray-900 pr-8">
                                        Creator (Prostaff) module
                                    </h3>
                                    <p className="mt-2 text-xs text-gray-500">
                                        Grants prostaff uploads, assignments, and dashboards until the expiry date. Revoking keeps memberships and stats; uploads and management are blocked until re-granted.
                                    </p>
                                    <form onSubmit={submitCreatorModule} className="mt-5 space-y-4">
                                        <div>
                                            <label htmlFor="creator-expires" className="block text-sm font-medium text-gray-700">
                                                Access until (local)
                                            </label>
                                            <input
                                                id="creator-expires"
                                                type="datetime-local"
                                                value={creatorExpires}
                                                onChange={(e) => setCreatorExpires(e.target.value)}
                                                className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                            />
                                            {pageErrors?.expires_at && (
                                                <p className="mt-1 text-sm text-red-600">{pageErrors.expires_at}</p>
                                            )}
                                        </div>
                                        <div>
                                            <label htmlFor="creator-status" className="block text-sm font-medium text-gray-700">
                                                Module status
                                            </label>
                                            <select
                                                id="creator-status"
                                                value={creatorStatus}
                                                onChange={(e) => setCreatorStatus(e.target.value)}
                                                className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                            >
                                                <option value="active">Active</option>
                                                <option value="trial">Trial</option>
                                            </select>
                                            {pageErrors?.status && (
                                                <p className="mt-1 text-sm text-red-600">{pageErrors.status}</p>
                                            )}
                                        </div>
                                        <div>
                                            <label htmlFor="creator-seats" className="block text-sm font-medium text-gray-700">
                                                Seats cap (optional)
                                            </label>
                                            <input
                                                id="creator-seats"
                                                type="number"
                                                min={1}
                                                max={50000}
                                                placeholder="Unlimited if empty"
                                                value={creatorSeats}
                                                onChange={(e) => setCreatorSeats(e.target.value)}
                                                className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                            />
                                            {pageErrors?.seats_limit && (
                                                <p className="mt-1 text-sm text-red-600">{pageErrors.seats_limit}</p>
                                            )}
                                        </div>
                                        <div className="flex flex-wrap gap-2 pt-2">
                                            <button
                                                type="submit"
                                                disabled={creatorBusy}
                                                className="inline-flex justify-center rounded-md border border-transparent bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-50"
                                            >
                                                {creatorBusy ? 'Saving…' : 'Save / extend module'}
                                            </button>
                                            <button
                                                type="button"
                                                disabled={creatorBusy}
                                                onClick={revokeCreatorModule}
                                                className="inline-flex justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-50"
                                            >
                                                Revoke access
                                            </button>
                                        </div>
                                    </form>
                                </>
                            )}
                        </div>
                    </div>
                </div>
            )}
            
            {/* Plan Change Dialog */}
            {planChangeConfirm.open && (
                <div className="fixed inset-0 z-50 overflow-y-auto">
                    <div className="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                        {/* Backdrop */}
                        <div
                            className="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity"
                            onClick={() => setPlanChangeConfirm({ ...planChangeConfirm, open: false })}
                        />

                        {/* Modal */}
                        <div className="relative transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:p-6">
                            <div className="sm:flex sm:items-start">
                                <div className="mx-auto flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-full bg-amber-100 sm:mx-0 sm:h-10 sm:w-10">
                                    <ExclamationTriangleIcon className="h-6 w-6 text-amber-600" aria-hidden="true" />
                                </div>
                                <div className="mt-3 text-center sm:ml-4 sm:mt-0 sm:text-left w-full">
                                    <h3 className="text-base font-semibold leading-6 text-gray-900">
                                        Change Company Plan
                                    </h3>
                                    <div className="mt-2">
                                        <p className="text-sm text-gray-500">
                                            Change {company.name}'s plan from <strong>{planChangeConfirm.oldPlan}</strong> to <strong>{planChangeConfirm.newPlan}</strong>
                                        </p>
                                        <div className="mt-4 space-y-4">
                                                {/* Plan Selector — always visible */}
                                                <div>
                                                    <label htmlFor="new_plan" className="block text-sm font-medium text-gray-700">
                                                        New Plan
                                                    </label>
                                                    <select
                                                        id="new_plan"
                                                        value={planChangeConfirm.newPlan}
                                                        onChange={(e) => setPlanChangeConfirm({ ...planChangeConfirm, newPlan: e.target.value })}
                                                        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                                    >
                                                        <option value="free">Free</option>
                                                        <option value="starter">Starter</option>
                                                        <option value="pro">Pro</option>
                                                        <option value="premium">Premium</option>
                                                        <option value="enterprise">Enterprise</option>
                                                    </select>
                                                </div>

                                                {planChangeConfirm.newPlan !== 'free' && (
                                                    <>
                                                        {/* Billing Status */}
                                                        <div>
                                                            <label htmlFor="billing_status" className="block text-sm font-medium text-gray-700">
                                                                Billing Status
                                                            </label>
                                                            <select
                                                                id="billing_status"
                                                                value={planChangeConfirm.billingStatus || 'comped'}
                                                                onChange={(e) => setPlanChangeConfirm({ ...planChangeConfirm, billingStatus: e.target.value, equivalentPlanValue: e.target.value !== 'comped' ? null : planChangeConfirm.equivalentPlanValue })}
                                                                className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                                            >
                                                                <option value="comped">Comped (Free/Complimentary)</option>
                                                                <option value="trial">Trial</option>
                                                            </select>
                                                            <p className="mt-1 text-xs text-gray-500">Internal accounting status - not visible to customers</p>
                                                        </div>

                                                        {/* Expiration Months */}
                                                        <div>
                                                            <label htmlFor="expiration_months" className="block text-sm font-medium text-gray-700">
                                                                Expiration (Months) <span className="text-red-500">*</span>
                                                            </label>
                                                            <input
                                                                type="number"
                                                                id="expiration_months"
                                                                min="1"
                                                                max="36"
                                                                value={planChangeConfirm.expirationMonths || ''}
                                                                onChange={(e) => setPlanChangeConfirm({ ...planChangeConfirm, expirationMonths: parseInt(e.target.value) || '' })}
                                                                className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                                                required
                                                            />
                                                            <p className="mt-1 text-xs text-gray-500">Plan will auto-downgrade to Free after expiration if not upgraded (1-36 months)</p>
                                                        </div>

                                                        {/* Equivalent Plan Value (only for comped) */}
                                                        {planChangeConfirm.billingStatus === 'comped' && (
                                                            <div>
                                                                <label htmlFor="equivalent_plan_value" className="block text-sm font-medium text-gray-700">
                                                                    Equivalent Plan Value (Optional)
                                                                </label>
                                                                <div className="mt-1">
                                                                    <div className="relative rounded-md shadow-sm">
                                                                        <div className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                                                                            <span className="text-gray-500 sm:text-sm">$</span>
                                                                        </div>
                                                                        <input
                                                                            type="number"
                                                                            id="equivalent_plan_value"
                                                                            min="0"
                                                                            step="0.01"
                                                                            value={planChangeConfirm.equivalentPlanValue || ''}
                                                                            onChange={(e) => setPlanChangeConfirm({ ...planChangeConfirm, equivalentPlanValue: e.target.value ? parseFloat(e.target.value) : null })}
                                                                            className="block w-full rounded-md border-gray-300 pl-7 focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                                                            placeholder="0.00"
                                                                        />
                                                                    </div>
                                                                </div>
                                                                <p className="mt-1 text-xs text-gray-500">Sales insight only - NOT counted as revenue (for internal tracking)</p>
                                                            </div>
                                                        )}
                                                    </>
                                                )}
                                            </div>
                                    </div>
                                </div>
                            </div>

                            {/* Actions */}
                            <div className="mt-5 sm:mt-4 sm:flex sm:flex-row-reverse">
                                <button
                                    type="button"
                                    onClick={() => {
                                        const payload = {
                                            plan: planChangeConfirm.newPlan,
                                        }
                                        
                                        if (planChangeConfirm.newPlan !== 'free' && planChangeConfirm.billingStatus && planChangeConfirm.expirationMonths) {
                                            payload.billing_status = planChangeConfirm.billingStatus
                                            payload.expiration_months = planChangeConfirm.expirationMonths
                                            if (planChangeConfirm.billingStatus === 'comped' && planChangeConfirm.equivalentPlanValue) {
                                                payload.equivalent_plan_value = planChangeConfirm.equivalentPlanValue
                                            }
                                        }
                                        
                                        router.put(`/app/admin/companies/${company.id}/plan`, payload, {
                                            preserveScroll: true,
                                            onSuccess: () => {
                                                setPlanChangeConfirm({ ...planChangeConfirm, open: false })
                                                router.reload({ only: ['company'] })
                                            },
                                        })
                                    }}
                                    disabled={planChangeConfirm.newPlan !== 'free' && (!planChangeConfirm.expirationMonths || planChangeConfirm.expirationMonths < 1 || planChangeConfirm.expirationMonths > 36)}
                                    className="inline-flex w-full justify-center rounded-md bg-amber-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-amber-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-600 sm:ml-3 sm:w-auto disabled:opacity-50 disabled:cursor-not-allowed"
                                >
                                    Change Plan
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setPlanChangeConfirm({ ...planChangeConfirm, open: false })}
                                    className="mt-3 inline-flex w-full justify-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 sm:mt-0 sm:w-auto"
                                >
                                    Cancel
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            )}
        </div>
    )
}
