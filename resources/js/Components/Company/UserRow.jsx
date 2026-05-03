import { useState, useEffect } from 'react'
import { Link, router } from '@inertiajs/react'
import Avatar from '../Avatar'
import {
    ChevronDownIcon,
    ChevronRightIcon,
    PlusIcon,
    TrashIcon,
} from '@heroicons/react/24/outline'
import ConfirmDialog from '../ConfirmDialog'

const ROLE_COLORS = {
    owner: 'bg-orange-100 text-orange-800 border-orange-200',
    admin: 'bg-violet-200/80 text-violet-950 border-violet-300',
    member: 'bg-gray-100 text-gray-800 border-gray-200',
    agency_admin: 'bg-violet-100 text-violet-900 border-violet-200',
    agency_partner: 'bg-violet-50 text-violet-900 border-violet-200',
    brand_manager: 'bg-violet-100 text-violet-900 border-violet-200',
    contributor: 'bg-slate-200 text-slate-800 border-slate-300',
    viewer: 'bg-gray-100 text-gray-800 border-gray-200',
}

const COMPANY_ROLE_LABEL = {
    owner: 'Owner',
    admin: 'Admin',
    member: 'Member',
    agency_admin: 'Agency manager',
    agency_partner: 'Agency partner',
}

const BRAND_ROLES = [
    { value: 'viewer', label: 'Viewer' },
    { value: 'contributor', label: 'Contributor' },
    { value: 'brand_manager', label: 'Brand Manager' },
    { value: 'admin', label: 'Admin' },
]

export default function UserRow({
    user,
    brands = [],
    tenant,
    authUserId,
    onCompanyRoleChange,
    onBrandRoleChange,
    onRemoveBrand,
    /** When true, offer "also remove from company" on last-brand removal (Company Team page always passes true). */
    canRemoveFromCompany = false,
    onDeleteFromCompany,
    updatingKeys = {},
    groupedUnderAgencySection = false,
    manageAgencyAccessHref = '/app/companies/settings#agencies',
    linkedAgencies = [],
    onConvertToAgency,
    convertPending = false,
}) {
    const [expanded, setExpanded] = useState(false)
    const [addBrandOpen, setAddBrandOpen] = useState(false)
    const [addBrandBrandId, setAddBrandBrandId] = useState('')
    const [addBrandRole, setAddBrandRole] = useState('viewer')
    const [addBrandSubmitting, setAddBrandSubmitting] = useState(false)
    const [removeBrandConfirm, setRemoveBrandConfirm] = useState(null)
    const [removeAlsoFromCompany, setRemoveAlsoFromCompany] = useState(false)
    const [deleteConfirm, setDeleteConfirm] = useState(false)

    const isOwner = user.company_role === 'owner'
    const isAgencyManaged = user.is_agency_managed === true
    const canModify = !isOwner && user.id !== authUserId && !isAgencyManaged
    const userBrandIds = (user.brand_roles || []).map((br) => br.brand_id)
    const availableBrands = brands.filter((b) => !userBrandIds.includes(b.id))

    const getRoleColor = (role) => {
        const r = (role || 'viewer').toLowerCase()
        return ROLE_COLORS[r] || ROLE_COLORS.viewer
    }

    const handleAddBrandAccess = async () => {
        if (!addBrandBrandId) return
        setAddBrandSubmitting(true)
        try {
            const res = await fetch(`/app/api/companies/users/${user.id}/brands`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '', Accept: 'application/json' },
                body: JSON.stringify({ brand_id: parseInt(addBrandBrandId, 10), role: addBrandRole }),
            })
            const data = await res.json()
            if (data.success) {
                setAddBrandOpen(false)
                setAddBrandBrandId('')
                setAddBrandRole('viewer')
                router.reload({ preserveScroll: true })
            } else {
                console.error(data.error || 'Failed to add brand access')
            }
        } finally {
            setAddBrandSubmitting(false)
        }
    }

    const handleRemoveBrand = (brandId, brandName) => {
        setRemoveBrandConfirm({ brandId, brandName })
    }

    useEffect(() => {
        if (!removeBrandConfirm) {
            return
        }
        const otherBrands = (user.brand_roles || []).filter((br) => br.brand_id !== removeBrandConfirm.brandId).length
        setRemoveAlsoFromCompany(otherBrands === 0 && canRemoveFromCompany)
    }, [removeBrandConfirm, user.brand_roles, canRemoveFromCompany])

    const confirmRemoveBrand = () => {
        if (removeBrandConfirm && onRemoveBrand) {
            const otherBrands = (user.brand_roles || []).filter((br) => br.brand_id !== removeBrandConfirm.brandId).length
            const removeFromCo = otherBrands === 0 && removeAlsoFromCompany && canRemoveFromCompany
            onRemoveBrand(user.id, removeBrandConfirm.brandId, removeBrandConfirm.brandName, { removeFromCompany: removeFromCo })
            setRemoveBrandConfirm(null)
        }
    }

    const handleDeleteFromCompany = () => {
        setDeleteConfirm(true)
    }

    const confirmDelete = () => {
        if (onDeleteFromCompany) {
            onDeleteFromCompany(user.id, user.name || user.email)
            setDeleteConfirm(false)
        }
    }

    return (
        <div className="border-b border-gray-200 last:border-b-0">
            {/* Header row */}
            <div
                className="flex cursor-pointer items-center gap-3 px-5 py-4 sm:gap-4 sm:px-6 sm:py-5 hover:bg-gray-50/50"
                onClick={() => setExpanded((e) => !e)}
            >
                <button type="button" className="p-0.5 text-gray-400 hover:text-gray-600" aria-label={expanded ? 'Collapse' : 'Expand'}>
                    {expanded ? <ChevronDownIcon className="h-4 w-4" /> : <ChevronRightIcon className="h-4 w-4" />}
                </button>
                <Avatar avatarUrl={user.avatar_url} firstName={user.first_name} lastName={user.last_name} email={user.email} size="sm" />
                <div className="flex-1 min-w-0">
                    <span className="font-medium text-gray-900">{user.name || user.email}</span>
                    {isAgencyManaged && !groupedUnderAgencySection && (
                        <span
                            className="ml-2 inline-flex items-center rounded-full border border-violet-200 bg-violet-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-violet-900"
                            title={user.agency_tenant_name ? `Managed via agency: ${user.agency_tenant_name}` : 'Managed via agency link'}
                        >
                            Agency{user.agency_tenant_name ? ` · ${user.agency_tenant_name}` : ''}
                        </span>
                    )}
                    <span className="text-gray-500 text-sm ml-2">{user.email}</span>
                </div>
                {onCompanyRoleChange && !isOwner && !isAgencyManaged ? (
                    <select
                        value={user.company_role || 'member'}
                        onChange={(e) => {
                            e.stopPropagation()
                            onCompanyRoleChange(user.id, e.target.value)
                        }}
                        onClick={(e) => e.stopPropagation()}
                        disabled={updatingKeys[`tenant_${user.id}`]}
                        className={`inline-flex shrink-0 cursor-pointer items-center rounded-md border px-2.5 py-1 text-xs font-medium focus:outline-none focus:ring-violet-500 disabled:opacity-50 ${getRoleColor(user.company_role)}`}
                    >
                        <option value="admin">Admin</option>
                        <option value="member">Member</option>
                    </select>
                ) : (
                    <span
                        className={`inline-flex shrink-0 items-center rounded-md border px-2.5 py-1 text-xs font-medium ${getRoleColor(user.company_role)}`}
                    >
                        {COMPANY_ROLE_LABEL[user.company_role] ||
                            (user.company_role === 'owner' ? 'Owner' : user.company_role === 'admin' ? 'Admin' : 'Member')}
                    </span>
                )}
                {isAgencyManaged && (
                    <a
                        href={manageAgencyAccessHref}
                        className="shrink-0 text-xs font-medium text-violet-600 hover:text-violet-800 underline decoration-violet-200 underline-offset-2"
                    >
                        Manage agency access
                    </a>
                )}
                {updatingKeys[`tenant_${user.id}`] && (
                    <span className="text-xs text-gray-500">Updating...</span>
                )}
                {canModify && linkedAgencies.length > 0 && typeof onConvertToAgency === 'function' && (
                    <div onClick={(e) => e.stopPropagation()}>
                        <label className="sr-only" htmlFor={`agency-convert-${user.id}`}>
                            Switch member to agency-managed access
                        </label>
                        <select
                            id={`agency-convert-${user.id}`}
                            disabled={convertPending}
                            defaultValue=""
                            onChange={(e) => {
                                const v = e.target.value
                                if (v) {
                                    onConvertToAgency(user.id, parseInt(v, 10))
                                    e.target.value = ''
                                }
                            }}
                            className="max-w-[11rem] rounded-md border border-violet-200 bg-violet-50/80 py-1 pl-2 pr-7 text-xs font-medium text-violet-900 focus:border-violet-400 focus:outline-none focus:ring-1 focus:ring-violet-400 disabled:opacity-50"
                            title="Use the agency link’s roles and brand access for this person"
                        >
                            <option value="">Switch to agency…</option>
                            {linkedAgencies.map((l) => {
                                const aid = l.agency_tenant?.id ?? l.agency_tenant_id
                                const aname = l.agency_tenant?.name ?? 'Agency'
                                return (
                                    <option key={aid} value={aid}>
                                        {aname}
                                    </option>
                                )
                            })}
                        </select>
                    </div>
                )}
                {canModify && (
                    <button
                        type="button"
                        onClick={(e) => { e.stopPropagation(); handleDeleteFromCompany() }}
                        className="p-1.5 rounded text-red-600 hover:bg-red-50"
                        title="Delete from company"
                    >
                        <TrashIcon className="h-4 w-4" />
                    </button>
                )}
            </div>

            {/* Expanded: brand roles */}
            {expanded && (
                <div className="border-t border-gray-100 bg-gray-50/80 px-5 pb-5 pt-3 sm:px-6">
                    <div className="space-y-2 pl-9 sm:pl-10">
                        {isAgencyManaged && (
                            <p className="text-xs text-gray-600 pb-1">
                                Roles for this person are managed by the agency link. To change access, use Company Settings → Agencies or remove the agency.
                            </p>
                        )}
                        {(user.brand_roles || []).map((br) => {
                            const key = `brand_${user.id}_${br.brand_id}`
                            const isUpdating = updatingKeys[key]
                            return (
                                <div key={br.brand_id} className="flex items-center gap-3 py-1.5">
                                    <span className="text-sm font-medium text-gray-700 w-40 truncate">{br.brand_name}</span>
                                    <select
                                        value={br.role}
                                        onChange={(e) => onBrandRoleChange?.(user.id, br.brand_id, e.target.value)}
                                        disabled={isUpdating || isAgencyManaged}
                                        className={`rounded-md border px-2 py-1 text-xs font-medium ${getRoleColor(br.role)} focus:ring-violet-500 disabled:opacity-50`}
                                        onClick={(e) => e.stopPropagation()}
                                        title={isAgencyManaged ? 'Managed via agency link' : undefined}
                                    >
                                        {BRAND_ROLES.map((r) => (
                                            <option key={r.value} value={r.value}>{r.label}</option>
                                        ))}
                                    </select>
                                    {isUpdating && <span className="text-xs text-gray-500">Updating...</span>}
                                    {canModify && (
                                        <button
                                            type="button"
                                            onClick={(e) => { e.stopPropagation(); handleRemoveBrand(br.brand_id, br.brand_name) }}
                                            className="text-xs text-red-600 hover:text-red-800 disabled:cursor-not-allowed disabled:opacity-40"
                                            disabled={isAgencyManaged}
                                            title={isAgencyManaged ? 'Managed via agency link' : undefined}
                                        >
                                            Remove
                                        </button>
                                    )}
                                </div>
                            )
                        })}
                        {canModify && !isAgencyManaged && (
                            <div className="pt-2">
                                {!addBrandOpen ? (
                                    <button
                                        type="button"
                                        onClick={(e) => { e.stopPropagation(); setAddBrandOpen(true) }}
                                        className="inline-flex items-center gap-1 text-sm text-violet-600 hover:text-violet-800 font-medium"
                                    >
                                        <PlusIcon className="h-4 w-4" />
                                        Add Brand Access
                                    </button>
                                ) : (
                                    <div className="flex flex-wrap items-center gap-2" onClick={(e) => e.stopPropagation()}>
                                        <select
                                            value={addBrandBrandId}
                                            onChange={(e) => setAddBrandBrandId(e.target.value)}
                                            className="rounded-md border-gray-300 text-sm py-1.5"
                                        >
                                            <option value="">Select brand...</option>
                                            {availableBrands.map((b) => (
                                                <option key={b.id} value={b.id}>{b.name}</option>
                                            ))}
                                        </select>
                                        <select
                                            value={addBrandRole}
                                            onChange={(e) => setAddBrandRole(e.target.value)}
                                            className="rounded-md border-gray-300 text-sm py-1.5"
                                        >
                                            {BRAND_ROLES.map((r) => (
                                                <option key={r.value} value={r.value}>{r.label}</option>
                                            ))}
                                        </select>
                                        <button
                                            type="button"
                                            onClick={handleAddBrandAccess}
                                            disabled={addBrandSubmitting || !addBrandBrandId}
                                            className="rounded-md bg-violet-600 px-2 py-1 text-xs text-white hover:bg-violet-500 disabled:opacity-50"
                                        >
                                            {addBrandSubmitting ? 'Adding...' : 'Add'}
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => { setAddBrandOpen(false); setAddBrandBrandId(''); setAddBrandRole('viewer') }}
                                            className="text-sm text-gray-500 hover:text-gray-700"
                                        >
                                            Cancel
                                        </button>
                                    </div>
                                )}
                            </div>
                        )}
                    </div>
                </div>
            )}

            <ConfirmDialog
                open={!!removeBrandConfirm}
                onClose={() => setRemoveBrandConfirm(null)}
                onConfirm={confirmRemoveBrand}
                title="Remove Brand Access"
                panelClassName="sm:max-w-lg"
                message={
                    removeBrandConfirm ? (
                        <div className="space-y-3 text-left">
                            <p>
                                Remove {user.name || user.email}&apos;s access to &quot;{removeBrandConfirm.brandName}&quot;?
                            </p>
                            {(user.brand_roles || []).filter((br) => br.brand_id !== removeBrandConfirm.brandId).length === 0 ? (
                                <>
                                    <p className="text-gray-600">
                                        This is their only brand in the company. If you remove them only from the brand, they remain a company member with no brand access until someone reassigns them.
                                    </p>
                                    {canRemoveFromCompany ? (
                                        <label className="flex items-start gap-2 cursor-pointer">
                                            <input
                                                type="checkbox"
                                                className="mt-1 rounded border-gray-300 text-red-600 focus:ring-red-600"
                                                checked={removeAlsoFromCompany}
                                                onChange={(e) => setRemoveAlsoFromCompany(e.target.checked)}
                                            />
                                            <span className="text-gray-700">Also remove them from the company (recommended)</span>
                                        </label>
                                    ) : (
                                        <p className="text-amber-800 text-xs bg-amber-50 border border-amber-100 rounded-md p-2">
                                            You don&apos;t have permission to remove company members. A company admin can remove them under Company → Team if they should not stay in the company.
                                        </p>
                                    )}
                                </>
                            ) : null}
                        </div>
                    ) : (
                        ''
                    )
                }
                confirmText="Remove"
                cancelText="Cancel"
                variant="danger"
            />
            <ConfirmDialog
                open={deleteConfirm}
                onClose={() => setDeleteConfirm(false)}
                onConfirm={confirmDelete}
                title="Delete from company"
                message={`Remove ${user.name || user.email} from the company and revoke all access? This cannot be undone.`}
                confirmText="Delete from company"
                cancelText="Cancel"
                variant="danger"
            />
        </div>
    )
}
