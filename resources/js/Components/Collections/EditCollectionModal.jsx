/**
 * Collection edit: Settings (name, description), Access & team (visibility, roles, teammates, guests),
 * Stats (composition + download links from this collection).
 */
import { useState, useEffect, useCallback, useRef } from 'react'
import { createPortal } from 'react-dom'
import {
    XMarkIcon,
    UsersIcon,
    AdjustmentsHorizontalIcon,
    UserPlusIcon,
    BuildingOffice2Icon,
    LockClosedIcon,
    EnvelopeIcon,
    GlobeAltIcon,
    ChartBarIcon,
    Cog6ToothIcon,
    UserGroupIcon,
    SparklesIcon,
} from '@heroicons/react/24/outline'
import { Link } from '@inertiajs/react'
import Avatar from '../Avatar'

const PUBLIC_TOOLTIP = 'Viewable via a shareable link. Collections do not grant access to assets outside this view.'

const BRAND_ROLE_OPTIONS = [
    { id: 'admin', label: 'Brand admin' },
    { id: 'brand_manager', label: 'Brand manager' },
    { id: 'contributor', label: 'Contributor' },
    { id: 'viewer', label: 'Viewer' },
]

const ACCESS_MODE_OPTIONS = [
    {
        value: 'all_brand',
        title: 'Everyone in the brand',
        description: 'Anyone with access to this brand can open the collection.',
        icon: UsersIcon,
    },
    {
        value: 'role_limited',
        title: 'Selected roles',
        description: 'Only teammates whose brand role you allow, plus optional named people below.',
        icon: AdjustmentsHorizontalIcon,
    },
    {
        value: 'invite_only',
        title: 'Invite-only',
        description: 'Only people you add explicitly (brand teammates and/or isolated guests).',
        icon: UserPlusIcon,
    },
]

function deriveAccessMode(collection) {
    if (collection?.access_mode) {
        return collection.access_mode
    }
    if (collection?.visibility === 'brand') {
        return 'all_brand'
    }
    if (collection?.visibility === 'restricted') {
        return 'role_limited'
    }
    return 'invite_only'
}

function SectionDivider({ children }) {
    return (
        <div className="relative py-4" role="presentation">
            <div className="absolute inset-0 flex items-center" aria-hidden="true">
                <div className="w-full border-t border-gray-200" />
            </div>
            <div className="relative flex justify-center">
                <span className="bg-white px-3 text-xs font-semibold uppercase tracking-wider text-gray-400">
                    {children}
                </span>
            </div>
        </div>
    )
}

export default function EditCollectionModal({
    open,
    collection = null,
    publicCollectionsEnabled = false,
    onClose,
    onSaved,
}) {
    const [name, setName] = useState('')
    const [description, setDescription] = useState('')
    const [accessMode, setAccessMode] = useState('all_brand')
    const [allowedBrandRoles, setAllowedBrandRoles] = useState([])
    const [allowsExternalGuests, setAllowsExternalGuests] = useState(false)
    const [isPublic, setIsPublic] = useState(false)
    const [submitting, setSubmitting] = useState(false)
    const [error, setError] = useState(null)

    const [brandUsers, setBrandUsers] = useState([])
    const [internalMembers, setInternalMembers] = useState([])
    const [internalLoading, setInternalLoading] = useState(false)
    const [pickUserId, setPickUserId] = useState('')
    const [internalInviteSubmitting, setInternalInviteSubmitting] = useState(false)
    const [internalError, setInternalError] = useState(null)

    const [accessGrants, setAccessGrants] = useState([])
    const [accessPending, setAccessPending] = useState([])
    const [accessLoading, setAccessLoading] = useState(false)
    const [inviteEmail, setInviteEmail] = useState('')
    const [inviteSubmitting, setInviteSubmitting] = useState(false)
    const [inviteError, setInviteError] = useState(null)

    const [activeTab, setActiveTab] = useState('settings')
    const [stats, setStats] = useState({ loading: false, error: null, data: null })
    const statsFetchedRef = useRef(false)
    const [externalAccessCountsHydrated, setExternalAccessCountsHydrated] = useState(false)
    const [campaignData, setCampaignData] = useState({ loading: false, data: null })
    const campaignFetchedRef = useRef(false)

    const showRolePickers = accessMode === 'role_limited' || accessMode === 'invite_only'
    const showInternalSection = accessMode === 'role_limited' || accessMode === 'invite_only'
    const showExternalSection = showRolePickers && allowsExternalGuests

    const loadInternalData = useCallback(() => {
        if (!collection?.id) return
        setInternalLoading(true)
        setInternalError(null)
        fetch(`/app/collections/${collection.id}/internal-invite-data`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        })
            .then((r) => (r.ok ? r.json() : { brand_users: [], members: [] }))
            .then((data) => {
                setBrandUsers(data.brand_users ?? [])
                setInternalMembers(data.members ?? [])
            })
            .catch(() => {
                setBrandUsers([])
                setInternalMembers([])
                setInternalError('Could not load teammates.')
            })
            .finally(() => setInternalLoading(false))
    }, [collection?.id])

    const loadExternalAccess = useCallback(() => {
        if (!collection?.id || !allowsExternalGuests || !showRolePickers) return
        setAccessLoading(true)
        fetch(`/app/collections/${collection.id}/access-invites`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        })
            .then((r) => r.json())
            .then((data) => {
                setAccessGrants(data.grants ?? [])
                setAccessPending(data.pending ?? [])
            })
            .catch(() => {
                setAccessGrants([])
                setAccessPending([])
            })
            .finally(() => {
                setAccessLoading(false)
                setExternalAccessCountsHydrated(true)
            })
    }, [collection?.id, allowsExternalGuests, showRolePickers])

    // Hydrate only when the dialog opens or the collection id changes — not when Inertia replaces
    // `selected_collection` with a new object reference (same id), or role toggles snap back.
    useEffect(() => {
        if (!open || !collection?.id) {
            return
        }
        setName(collection.name ?? '')
        setDescription(collection.description ?? '')
        setAccessMode(deriveAccessMode(collection))
        setAllowedBrandRoles(
            Array.isArray(collection.allowed_brand_roles) ? [...collection.allowed_brand_roles] : []
        )
        setAllowsExternalGuests(!!collection.allows_external_guests)
        setIsPublic(!!collection.is_public)
        setError(null)
        setInviteError(null)
        setInternalError(null)
        setInviteEmail('')
        setPickUserId('')
        setActiveTab('settings')
        setStats({ loading: false, error: null, data: null })
        statsFetchedRef.current = false
        setExternalAccessCountsHydrated(false)
        setCampaignData({ loading: false, data: null })
        campaignFetchedRef.current = false
        // eslint-disable-next-line react-hooks/exhaustive-deps -- intentionally omit `collection` reference; see comment above.
    }, [open, collection?.id])

    useEffect(() => {
        if (!open || !collection?.id || activeTab !== 'stats') return
        if (statsFetchedRef.current || stats.loading || stats.data) return
        statsFetchedRef.current = true
        setStats((prev) => ({ ...prev, loading: true, error: null }))
        fetch(`/app/collections/${collection.id}/stats`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        })
            .then((r) => {
                if (!r.ok) throw new Error('unavailable')
                return r.json()
            })
            .then((data) => setStats({ loading: false, error: null, data }))
            .catch(() => setStats({ loading: false, error: true, data: null }))
    }, [open, collection?.id, activeTab, stats.loading, stats.data])

    useEffect(() => {
        if (!open || !collection?.id || activeTab !== 'campaign') return
        if (campaignFetchedRef.current || campaignData.loading || campaignData.data) return
        campaignFetchedRef.current = true
        setCampaignData((prev) => ({ ...prev, loading: true }))
        fetch(`/app/collections/${collection.id}/campaign`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        })
            .then((r) => (r.ok ? r.json() : null))
            .then((data) => setCampaignData({ loading: false, data: data?.campaign_identity ?? null }))
            .catch(() => setCampaignData({ loading: false, data: null }))
    }, [open, collection?.id, activeTab, campaignData.loading, campaignData.data])

    useEffect(() => {
        if (!open || !collection?.id || activeTab !== 'access' || !showInternalSection) return
        loadInternalData()
    }, [open, collection?.id, activeTab, showInternalSection, loadInternalData])

    useEffect(() => {
        if (!open || !collection?.id || activeTab !== 'access' || !showExternalSection) {
            if (!showExternalSection) {
                setAccessGrants([])
                setAccessPending([])
                setExternalAccessCountsHydrated(false)
            }
            return
        }
        loadExternalAccess()
    }, [open, collection?.id, activeTab, showExternalSection, loadExternalAccess])

    const toggleRole = (roleId) => {
        setAllowedBrandRoles((prev) =>
            prev.includes(roleId) ? prev.filter((r) => r !== roleId) : [...prev, roleId]
        )
    }

    const handleSubmit = async (e) => {
        e.preventDefault()
        setError(null)
        if (!collection?.id) return
        const trimmedName = name.trim()
        if (!trimmedName) {
            setError('Name is required.')
            return
        }
        if (accessMode === 'role_limited' && allowedBrandRoles.length === 0) {
            setError('Select at least one brand role, or choose another access option.')
            return
        }
        setSubmitting(true)
        try {
            const rolesPayload =
                showRolePickers && allowedBrandRoles.length > 0 ? allowedBrandRoles : []
            const body = {
                name: trimmedName,
                description: description.trim() || null,
                access_mode: accessMode,
                allowed_brand_roles: rolesPayload,
                allows_external_guests: accessMode !== 'all_brand' && allowsExternalGuests,
            }
            if (publicCollectionsEnabled) {
                body.is_public = accessMode === 'all_brand' ? isPublic : false
            }
            const response = await fetch(`/app/collections/${collection.id}`, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
                credentials: 'same-origin',
                body: JSON.stringify(body),
            })
            const data = await response.json().catch(() => ({}))
            if (response.ok && data?.collection) {
                onSaved?.(data.collection)
                onClose()
                return
            }
            const msg =
                data?.errors?.access_mode?.[0] ??
                data?.errors?.name?.[0] ??
                data?.message ??
                'Failed to update collection.'
            setError(msg)
        } catch (err) {
            setError(err?.message ?? 'Failed to update collection.')
        } finally {
            setSubmitting(false)
        }
    }

    const handleClose = () => {
        if (!submitting) {
            setError(null)
            onClose()
        }
    }

    const handleInviteExternal = async (e) => {
        e.preventDefault()
        if (!collection?.id || !inviteEmail.trim()) return
        setInviteError(null)
        setInviteSubmitting(true)
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || ''
        try {
            const form = new FormData()
            form.append('email', inviteEmail.trim())
            form.append('_token', csrf)
            const r = await fetch(`/app/collections/${collection.id}/access-invite`, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
                credentials: 'same-origin',
                body: form,
            })
            const data = await r.json().catch(() => ({}))
            if (r.ok) {
                const emailSent = inviteEmail.trim()
                setInviteEmail('')
                setAccessPending((prev) => [
                    ...prev,
                    { id: data?.id, email: emailSent, sent_at: new Date().toISOString() },
                ])
                return
            }
            setInviteError(data?.errors?.email?.[0] ?? data?.message ?? 'Failed to send invite.')
        } catch (err) {
            setInviteError(err?.message ?? 'Failed to send invite.')
        } finally {
            setInviteSubmitting(false)
        }
    }

    const handleRevokeExternal = async (grantId) => {
        if (!collection?.id) return
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || ''
        try {
            const r = await fetch(`/app/collections/${collection.id}/grants/${grantId}`, {
                method: 'DELETE',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
                credentials: 'same-origin',
            })
            if (r.ok) {
                setAccessGrants((prev) => prev.filter((g) => g.id !== grantId))
            }
        } catch (_) {}
    }

    const handleAddInternalMember = async () => {
        if (!collection?.id || !pickUserId) return
        setInternalError(null)
        setInternalInviteSubmitting(true)
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || ''
        try {
            const form = new FormData()
            form.append('user_id', String(pickUserId))
            form.append('_token', csrf)
            const r = await fetch(`/app/collections/${collection.id}/invite`, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
                credentials: 'same-origin',
                body: form,
            })
            const data = await r.json().catch(() => ({}))
            if (r.ok && data?.member) {
                setPickUserId('')
                loadInternalData()
                return
            }
            setInternalError(data?.errors?.user_id?.[0] ?? data?.message ?? 'Could not add teammate.')
        } catch (err) {
            setInternalError(err?.message ?? 'Could not add teammate.')
        } finally {
            setInternalInviteSubmitting(false)
        }
    }

    const handleRemoveInternalMember = async (memberId) => {
        if (!collection?.id) return
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || ''
        try {
            const r = await fetch(`/app/collections/${collection.id}/members/${memberId}`, {
                method: 'DELETE',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
                credentials: 'same-origin',
            })
            if (r.ok) {
                setInternalMembers((prev) => prev.filter((m) => m.id !== memberId))
            }
        } catch (_) {}
    }

    const memberUserIds = new Set(internalMembers.map((m) => m.user_id).filter(Boolean))
    const addableUsers = brandUsers.filter((u) => !memberUserIds.has(u.id))

    const serverExternalTotal =
        (collection?.external_guest_grants_count ?? 0) + (collection?.external_guest_invites_count ?? 0)
    const liveExternalTotal = accessGrants.length + accessPending.length
    const showAccessTabExternalBadge =
        externalAccessCountsHydrated && showExternalSection ? liveExternalTotal > 0 : serverExternalTotal > 0

    if (!open) return null

    /** Portal + z above AppNav (z-[40]) and AssetDrawer so backdrop covers full viewport. */
    const dialog = (
        <div
            className="fixed inset-0 z-[220] isolate overflow-y-auto"
            aria-labelledby="edit-modal-title"
            role="dialog"
            aria-modal="true"
        >
            <div className="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                <div
                    className="fixed inset-0 bg-gray-500/75 transition-opacity z-[220]"
                    aria-hidden="true"
                    onClick={handleClose}
                />
                <div className="relative z-[221] transform overflow-hidden rounded-xl bg-white text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-2xl max-h-[92vh] flex flex-col">
                    <div className="bg-white px-4 pb-4 pt-5 sm:p-6 sm:pb-4 overflow-y-auto flex-1 min-h-0">
                        <div className="flex items-center justify-between mb-2">
                            <h3 className="text-lg font-semibold text-gray-900" id="edit-modal-title">Edit collection</h3>
                            <button
                                type="button"
                                onClick={handleClose}
                                disabled={submitting}
                                className="rounded-md text-gray-400 hover:text-gray-600 focus:outline-none focus:ring-2 focus:ring-indigo-500 disabled:opacity-50"
                            >
                                <XMarkIcon className="h-5 w-5" />
                            </button>
                        </div>
                        <nav className="-mx-1 mb-4 flex border-b border-gray-200" aria-label="Collection dialog tabs">
                            <button
                                type="button"
                                onClick={() => setActiveTab('settings')}
                                className={`border-b-2 py-2.5 px-3 text-sm font-medium sm:px-4 ${
                                    activeTab === 'settings'
                                        ? 'border-indigo-600 text-indigo-600'
                                        : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
                                }`}
                            >
                                <span className="flex items-center gap-2">
                                    <Cog6ToothIcon className="h-4 w-4 shrink-0" />
                                    Settings
                                </span>
                            </button>
                            <button
                                type="button"
                                onClick={() => setActiveTab('access')}
                                aria-label={
                                    showAccessTabExternalBadge
                                        ? 'Access and team, external guests have access or pending invites'
                                        : 'Access and team'
                                }
                                className={`border-b-2 py-2.5 pl-3 pr-4 text-sm font-medium sm:pl-4 sm:pr-5 ${
                                    activeTab === 'access'
                                        ? 'border-indigo-600 text-indigo-600'
                                        : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
                                }`}
                            >
                                <span className="relative inline-flex items-center gap-2">
                                    <UserGroupIcon className="h-4 w-4 shrink-0" />
                                    Access & team
                                    {showAccessTabExternalBadge ? (
                                        <span className="absolute -right-2 -top-1 flex h-2.5 w-2.5 rounded-full bg-amber-500 ring-2 ring-white" />
                                    ) : null}
                                </span>
                            </button>
                            <button
                                type="button"
                                onClick={() => setActiveTab('stats')}
                                className={`border-b-2 py-2.5 px-3 text-sm font-medium sm:px-4 ${
                                    activeTab === 'stats'
                                        ? 'border-indigo-600 text-indigo-600'
                                        : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
                                }`}
                            >
                                <span className="flex items-center gap-2">
                                    <ChartBarIcon className="h-4 w-4 shrink-0" />
                                    Stats
                                </span>
                            </button>
                            <button
                                type="button"
                                onClick={() => setActiveTab('campaign')}
                                className={`border-b-2 py-2.5 px-3 text-sm font-medium sm:px-4 ${
                                    activeTab === 'campaign'
                                        ? 'border-indigo-600 text-indigo-600'
                                        : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
                                }`}
                            >
                                <span className="flex items-center gap-2">
                                    <SparklesIcon className="h-4 w-4 shrink-0" />
                                    Campaign
                                </span>
                            </button>
                        </nav>
                        <form onSubmit={handleSubmit}>
                            {error && (activeTab === 'settings' || activeTab === 'access') && (
                                <div className="mb-4 rounded-md bg-red-50 p-3 text-sm text-red-700">
                                    {error}
                                </div>
                            )}
                            {activeTab === 'settings' && (
                            <div className="space-y-5">
                                <div>
                                    <label htmlFor="edit-collection-name" className="block text-sm font-medium text-gray-700">
                                        Name <span className="text-red-500">*</span>
                                    </label>
                                    <input
                                        id="edit-collection-name"
                                        type="text"
                                        required
                                        value={name}
                                        onChange={(e) => setName(e.target.value)}
                                        className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 sm:text-sm"
                                        placeholder="Collection name"
                                        disabled={submitting}
                                    />
                                </div>
                                <div>
                                    <label htmlFor="edit-collection-description" className="block text-sm font-medium text-gray-700">
                                        Description <span className="text-gray-400">(optional)</span>
                                    </label>
                                    <textarea
                                        id="edit-collection-description"
                                        rows={3}
                                        value={description}
                                        onChange={(e) => setDescription(e.target.value)}
                                        className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 sm:text-sm"
                                        placeholder="Brief description"
                                        disabled={submitting}
                                    />
                                </div>
                            </div>
                            )}
                            {activeTab === 'access' && (
                            <div className="space-y-5">
                                <div>
                                    <span className="block text-sm font-medium text-gray-900">Who can view this collection</span>
                                    <p className="mt-0.5 text-xs text-gray-500">
                                        Pick a baseline, then add teammates or isolated guests below. Save when you are done.
                                    </p>
                                    <fieldset className="mt-3 space-y-2" disabled={submitting}>
                                        <legend className="sr-only">Access mode</legend>
                                        {ACCESS_MODE_OPTIONS.map(({ value, title, description, icon: Icon }) => {
                                            const selected = accessMode === value
                                            return (
                                                <label
                                                    key={value}
                                                    className={`flex cursor-pointer gap-3 rounded-lg border-2 p-3 text-left transition-colors ${
                                                        selected
                                                            ? 'border-indigo-600 bg-indigo-50/60 ring-1 ring-indigo-600/20'
                                                            : 'border-gray-200 bg-white hover:border-gray-300 hover:bg-gray-50/80'
                                                    }`}
                                                >
                                                    <input
                                                        type="radio"
                                                        name="collection_access_mode"
                                                        value={value}
                                                        checked={selected}
                                                        onChange={() => {
                                                            setAccessMode(value)
                                                            if (value === 'all_brand') {
                                                                setIsPublic(false)
                                                            }
                                                        }}
                                                        className="mt-1 h-4 w-4 shrink-0 border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                                    />
                                                    <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                                                        <Icon className={`h-5 w-5 ${selected ? 'text-indigo-600' : 'text-gray-500'}`} aria-hidden="true" />
                                                    </span>
                                                    <span className="min-w-0 flex-1">
                                                        <span className="block text-sm font-medium text-gray-900">{title}</span>
                                                        <span className="mt-0.5 block text-xs text-gray-600">{description}</span>
                                                    </span>
                                                </label>
                                            )
                                        })}
                                    </fieldset>
                                </div>

                                {showRolePickers && (
                                    <div className="rounded-lg border border-indigo-100 bg-indigo-50/40 p-4">
                                        <p className="text-xs font-semibold text-indigo-900">Brand roles allowed</p>
                                        <p className="mt-1 text-xs text-indigo-800/80">
                                            {accessMode === 'invite_only'
                                                ? 'Optional shortcut: also allow anyone with these roles without a separate invite.'
                                                : 'Teammates with any checked role can view. Use “Brand workspace” below to add one-off exceptions.'}
                                        </p>
                                        <div className="mt-3 space-y-2">
                                            {BRAND_ROLE_OPTIONS.map(({ id, label }) => (
                                                <label key={id} className="flex items-center gap-2">
                                                    <input
                                                        type="checkbox"
                                                        checked={allowedBrandRoles.includes(id)}
                                                        onChange={() => toggleRole(id)}
                                                        disabled={submitting}
                                                        className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                                    />
                                                    <span className="text-sm text-gray-800">{label}</span>
                                                </label>
                                            ))}
                                        </div>
                                    </div>
                                )}

                                {publicCollectionsEnabled && accessMode === 'all_brand' && (
                                    <div className="flex items-center gap-3 rounded-lg border border-gray-200 bg-gray-50/80 px-3 py-2.5">
                                        <span className="flex h-9 w-9 items-center justify-center rounded-md bg-white shadow-sm ring-1 ring-gray-200">
                                            <GlobeAltIcon className="h-5 w-5 text-gray-600" aria-hidden="true" />
                                        </span>
                                        <div className="min-w-0 flex-1">
                                            <span className="text-sm font-medium text-gray-900">Public link</span>
                                            <p className="text-xs text-gray-500">{PUBLIC_TOOLTIP}</p>
                                        </div>
                                        <button
                                            type="button"
                                            role="switch"
                                            aria-checked={isPublic}
                                            disabled={submitting}
                                            onClick={() => setIsPublic((v) => !v)}
                                            className={`relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed ${
                                                isPublic ? 'bg-indigo-600' : 'bg-gray-200'
                                            }`}
                                        >
                                            <span
                                                className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out ${
                                                    isPublic ? 'translate-x-5' : 'translate-x-1'
                                                }`}
                                            />
                                        </button>
                                    </div>
                                )}

                                {showInternalSection && (
                                    <>
                                        <SectionDivider>Brand workspace</SectionDivider>
                                        <div className="rounded-xl border border-slate-200 bg-gradient-to-b from-slate-50/90 to-white shadow-sm">
                                            <div className="flex gap-3 border-b border-slate-200/80 px-4 py-3">
                                                <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-indigo-100 text-indigo-700">
                                                    <BuildingOffice2Icon className="h-5 w-5" aria-hidden="true" />
                                                </span>
                                                <div className="min-w-0 flex-1">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <h4 className="text-sm font-semibold text-slate-900">Teammates on this brand</h4>
                                                        <span className="rounded-full bg-indigo-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-indigo-800">
                                                            Full brand access
                                                        </span>
                                                    </div>
                                                    <p className="mt-1 text-xs text-slate-600">
                                                        They keep their normal brand login and permissions. This list is only for who may open this collection.
                                                    </p>
                                                </div>
                                            </div>
                                            <div className="p-4">
                                                {internalError && <p className="mb-2 text-sm text-red-600">{internalError}</p>}
                                                <div className="flex flex-wrap gap-2">
                                                    <select
                                                        value={pickUserId}
                                                        onChange={(e) => setPickUserId(e.target.value)}
                                                        disabled={internalInviteSubmitting || internalLoading}
                                                        className="min-w-[12rem] flex-1 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                                                        aria-label="Select brand teammate to add"
                                                    >
                                                        <option value="">Select teammate…</option>
                                                        {addableUsers.map((u) => (
                                                            <option key={u.id} value={u.id}>
                                                                {[u.first_name, u.last_name].filter(Boolean).join(' ') || u.email}
                                                                {u.brand_role ? ` (${u.brand_role})` : ''}
                                                            </option>
                                                        ))}
                                                    </select>
                                                    <button
                                                        type="button"
                                                        onClick={handleAddInternalMember}
                                                        disabled={internalInviteSubmitting || !pickUserId}
                                                        className="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
                                                    >
                                                        {internalInviteSubmitting ? 'Adding…' : 'Add to collection'}
                                                    </button>
                                                </div>
                                                {internalLoading ? (
                                                    <p className="mt-3 text-sm text-slate-500">Loading…</p>
                                                ) : internalMembers.length > 0 ? (
                                                    <ul className="mt-3 divide-y divide-slate-100 rounded-md border border-slate-200 bg-white text-sm">
                                                        {internalMembers.map((m) => (
                                                            <li key={m.id} className="flex items-center justify-between gap-2 px-3 py-2.5">
                                                                <div className="flex items-center gap-2 min-w-0">
                                                                    {m.user ? (
                                                                        <>
                                                                            <Avatar
                                                                                avatarUrl={m.user.avatar_url}
                                                                                firstName={m.user.first_name}
                                                                                lastName={m.user.last_name}
                                                                                email={m.user.email}
                                                                                size="sm"
                                                                            />
                                                                            <span className="truncate text-slate-800">
                                                                                {m.user.name || m.user.email}
                                                                                {!m.accepted_at ? (
                                                                                    <span className="text-amber-700 text-xs ml-1">(pending)</span>
                                                                                ) : null}
                                                                            </span>
                                                                        </>
                                                                    ) : (
                                                                        <span className="text-slate-500">User #{m.user_id}</span>
                                                                    )}
                                                                </div>
                                                                <button
                                                                    type="button"
                                                                    onClick={() => handleRemoveInternalMember(m.id)}
                                                                    className="text-red-600 hover:text-red-800 text-xs font-medium flex-shrink-0"
                                                                >
                                                                    Remove
                                                                </button>
                                                            </li>
                                                        ))}
                                                    </ul>
                                                ) : (
                                                    <p className="mt-3 text-sm text-slate-500">No extra teammates added yet.</p>
                                                )}
                                            </div>
                                        </div>
                                    </>
                                )}

                                {accessMode !== 'all_brand' && (
                                    <>
                                        <SectionDivider>Isolated guest access</SectionDivider>
                                        <div
                                            className={`rounded-xl border-2 transition-colors ${
                                                allowsExternalGuests
                                                    ? 'border-amber-300 bg-amber-50/50 shadow-sm'
                                                    : 'border-dashed border-gray-300 bg-gray-50/50'
                                            }`}
                                        >
                                            <button
                                                type="button"
                                                onClick={() => setAllowsExternalGuests((v) => !v)}
                                                disabled={submitting}
                                                className="flex w-full gap-3 p-4 text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-500 focus-visible:ring-offset-2 rounded-xl"
                                            >
                                                <span
                                                    className={`flex h-11 w-11 shrink-0 items-center justify-center rounded-lg ${
                                                        allowsExternalGuests ? 'bg-amber-200 text-amber-900' : 'bg-gray-200 text-gray-600'
                                                    }`}
                                                >
                                                    <LockClosedIcon className="h-6 w-6" aria-hidden="true" />
                                                </span>
                                                <span className="min-w-0 flex-1">
                                                    <span className="flex flex-wrap items-center gap-2">
                                                        <span className="text-sm font-semibold text-gray-900">
                                                            Separate workspace for people outside the brand
                                                        </span>
                                                        <span
                                                            className={`rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide ${
                                                                allowsExternalGuests
                                                                    ? 'bg-amber-200 text-amber-950'
                                                                    : 'bg-gray-200 text-gray-700'
                                                            }`}
                                                        >
                                                            {allowsExternalGuests ? 'On' : 'Off'}
                                                        </span>
                                                    </span>
                                                    <span className="mt-1 block text-xs text-gray-600 leading-relaxed">
                                                        Invite by email. Guests sign in to a minimal, collection-only experience: they see just this
                                                        collection (view), not your library, dashboard, or other brands. Revoke access anytime.
                                                    </span>
                                                </span>
                                                <span
                                                    className={`relative mt-0.5 inline-flex h-6 w-11 shrink-0 rounded-full border-2 border-transparent transition-colors ${
                                                        allowsExternalGuests ? 'bg-amber-600' : 'bg-gray-300'
                                                    }`}
                                                    aria-hidden="true"
                                                >
                                                    <span
                                                        className={`pointer-events-none inline-block h-5 w-5 translate-y-px rounded-full bg-white shadow transition ${
                                                            allowsExternalGuests ? 'translate-x-5' : 'translate-x-1'
                                                        }`}
                                                    />
                                                </span>
                                            </button>

                                            {allowsExternalGuests && (
                                                <div className="border-t border-amber-200/80 px-4 pb-4 pt-3">
                                                    <div className="mb-3 flex items-start gap-2 rounded-md bg-white/80 p-2 ring-1 ring-amber-100">
                                                        <EnvelopeIcon className="h-5 w-5 shrink-0 text-amber-800 mt-0.5" aria-hidden="true" />
                                                        <p className="text-xs text-amber-950/90">
                                                            <strong className="font-semibold">Email path only.</strong> External guests are never added to
                                                            your brand roster—they only receive access to this collection in an isolated workspace.
                                                        </p>
                                                    </div>
                                                    <div className="flex gap-2" role="group" aria-label="Invite external guest by email">
                                                        <input
                                                            type="email"
                                                            value={inviteEmail}
                                                            onChange={(e) => setInviteEmail(e.target.value)}
                                                            onKeyDown={(e) => e.key === 'Enter' && (e.preventDefault(), handleInviteExternal(e))}
                                                            placeholder="Guest email address"
                                                            disabled={inviteSubmitting}
                                                            className="flex-1 rounded-md border border-amber-200/80 bg-white px-3 py-2 text-sm focus:border-amber-500 focus:ring-amber-500"
                                                        />
                                                        <button
                                                            type="button"
                                                            onClick={handleInviteExternal}
                                                            disabled={inviteSubmitting || !inviteEmail.trim()}
                                                            className="rounded-md bg-amber-800 px-3 py-2 text-sm font-medium text-white hover:bg-amber-900 disabled:opacity-50"
                                                        >
                                                            {inviteSubmitting ? 'Sending…' : 'Send invite'}
                                                        </button>
                                                    </div>
                                                    {inviteError && <p className="mt-2 text-sm text-red-600">{inviteError}</p>}
                                                    {accessLoading ? (
                                                        <p className="mt-3 text-sm text-amber-900/70">Loading…</p>
                                                    ) : (
                                                        <>
                                                            {accessGrants.length > 0 && (
                                                                <div className="mt-3">
                                                                    <p className="text-xs font-semibold text-amber-950 uppercase tracking-wide mb-1">
                                                                        Active guests
                                                                    </p>
                                                                    <ul className="text-sm space-y-1">
                                                                        {accessGrants.map((g) => (
                                                                            <li
                                                                                key={g.id}
                                                                                className="flex items-center justify-between gap-2 rounded-md bg-white/90 px-2 py-1.5 ring-1 ring-amber-100"
                                                                            >
                                                                                <div className="flex items-center gap-2 min-w-0">
                                                                                    <Avatar
                                                                                        avatarUrl={g.user?.avatar_url}
                                                                                        firstName={g.user?.first_name}
                                                                                        lastName={g.user?.last_name}
                                                                                        email={g.user?.email}
                                                                                        size="sm"
                                                                                    />
                                                                                    <span className="truncate text-gray-800">
                                                                                        {g.user?.name || g.user?.email || '—'}
                                                                                    </span>
                                                                                </div>
                                                                                <button
                                                                                    type="button"
                                                                                    onClick={() => handleRevokeExternal(g.id)}
                                                                                    className="text-red-700 hover:text-red-900 text-xs font-medium flex-shrink-0"
                                                                                >
                                                                                    Revoke
                                                                                </button>
                                                                            </li>
                                                                        ))}
                                                                    </ul>
                                                                </div>
                                                            )}
                                                            {accessPending.length > 0 && (
                                                                <div className="mt-2">
                                                                    <p className="text-xs font-semibold text-amber-950 uppercase tracking-wide mb-1">
                                                                        Pending invites
                                                                    </p>
                                                                    <ul className="text-sm text-amber-950/80">
                                                                        {accessPending.map((p) => (
                                                                            <li key={p.id || p.email}>{p.email}</li>
                                                                        ))}
                                                                    </ul>
                                                                </div>
                                                            )}
                                                        </>
                                                    )}
                                                </div>
                                            )}
                                        </div>
                                    </>
                                )}
                            </div>
                            )}
                            {(activeTab === 'settings' || activeTab === 'access') && (
                            <div className="mt-6 flex justify-end gap-3 pt-4 border-t border-gray-100">
                                <button
                                    type="button"
                                    onClick={handleClose}
                                    disabled={submitting}
                                    className="rounded-md bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 disabled:opacity-50"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    disabled={submitting || !name.trim()}
                                    className="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50"
                                >
                                    {submitting ? 'Saving…' : 'Save'}
                                </button>
                            </div>
                            )}
                        </form>
                        {activeTab === 'stats' && (
                            <div className="space-y-4 pb-1">
                                {stats.loading && <p className="text-sm text-gray-500">Loading stats…</p>}
                                {stats.error && (
                                    <p className="text-sm text-red-600">Stats unavailable. You may not have permission to view them.</p>
                                )}
                                {stats.data && !stats.loading && !stats.error && (
                                    <>
                                        <p className="text-xs text-gray-500">
                                            Download counts include shareable ZIP links created from this collection with assets
                                            still in the bucket. Public “download collection” ZIPs are not counted here.
                                        </p>
                                        <div className="rounded-lg border border-gray-200 bg-gray-50 p-3">
                                            <p className="text-xs font-medium uppercase tracking-wide text-gray-500">Content</p>
                                            <dl className="mt-2 grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                                                <dt className="text-gray-600">Visible assets</dt>
                                                <dd className="font-medium text-gray-900 tabular-nums">
                                                    {stats.data.assets_visible_count ?? 0}
                                                </dd>
                                                <dt className="text-gray-600">Public link</dt>
                                                <dd className="font-medium text-gray-900">
                                                    {stats.data.is_public ? 'On' : 'Off'}
                                                </dd>
                                            </dl>
                                        </div>
                                        <div className="rounded-lg border border-gray-200 bg-gray-50 p-3">
                                            <p className="text-xs font-medium uppercase tracking-wide text-gray-500">People</p>
                                            <dl className="mt-2 grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                                                <dt className="text-gray-600">Teammates (accepted)</dt>
                                                <dd className="font-medium text-gray-900 tabular-nums">
                                                    {stats.data.internal_members?.accepted ?? 0}
                                                </dd>
                                                <dt className="text-gray-600">Teammates (pending)</dt>
                                                <dd className="font-medium text-gray-900 tabular-nums">
                                                    {stats.data.internal_members?.pending ?? 0}
                                                </dd>
                                                <dt className="text-gray-600">External access (active)</dt>
                                                <dd className="font-medium text-gray-900 tabular-nums">
                                                    {stats.data.external_access?.active_grants ?? 0}
                                                </dd>
                                                <dt className="text-gray-600">External invites (pending)</dt>
                                                <dd className="font-medium text-gray-900 tabular-nums">
                                                    {stats.data.external_access?.pending_invites ?? 0}
                                                </dd>
                                            </dl>
                                        </div>
                                        <div className="rounded-lg border border-gray-200 bg-gray-50 p-3">
                                            <p className="text-xs font-medium uppercase tracking-wide text-gray-500">Downloads</p>
                                            <dl className="mt-2 grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                                                <dt className="text-gray-600">Download groups from here</dt>
                                                <dd className="font-medium text-gray-900 tabular-nums">
                                                    {stats.data.downloads_from_collection?.download_groups_created ?? 0}
                                                </dd>
                                                <dt className="text-gray-600">Recorded link opens</dt>
                                                <dd className="font-medium text-gray-900 tabular-nums">
                                                    {stats.data.downloads_from_collection?.link_opens_recorded ?? 0}
                                                </dd>
                                            </dl>
                                        </div>
                                    </>
                                )}
                                <div className="mt-6 flex justify-end gap-3 border-t border-gray-100 pt-4">
                                    <button
                                        type="button"
                                        onClick={handleClose}
                                        className="rounded-md bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
                                    >
                                        Close
                                    </button>
                                </div>
                            </div>
                        )}
                        {activeTab === 'campaign' && (
                            <div className="space-y-4 pb-1">
                                {campaignData.loading && <p className="text-sm text-gray-500">Loading campaign identity…</p>}
                                {!campaignData.loading && !campaignData.data && (
                                    <div className="rounded-lg border border-dashed border-gray-300 bg-gray-50/50 p-6 text-center">
                                        <SparklesIcon className="mx-auto h-10 w-10 text-gray-300" />
                                        <h4 className="mt-3 text-sm font-semibold text-gray-900">No campaign identity yet</h4>
                                        <p className="mt-1 text-sm text-gray-500">
                                            Add a campaign identity to enable campaign-specific brand scoring for assets in this collection.
                                        </p>
                                        {collection?.id && (
                                            <Link
                                                href={`/app/collections/${collection.id}/campaign`}
                                                className="mt-4 inline-flex items-center gap-2 rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                                            >
                                                <SparklesIcon className="h-4 w-4" />
                                                Set up campaign identity
                                            </Link>
                                        )}
                                    </div>
                                )}
                                {!campaignData.loading && campaignData.data && (
                                    <>
                                        <div className="rounded-lg border border-gray-200 bg-gray-50 p-4">
                                            <div className="flex items-center justify-between">
                                                <div>
                                                    <p className="text-sm font-semibold text-gray-900">
                                                        {campaignData.data.campaign_name || 'Untitled campaign'}
                                                    </p>
                                                    {campaignData.data.campaign_goal && (
                                                        <p className="mt-0.5 text-xs text-gray-500 line-clamp-2">{campaignData.data.campaign_goal}</p>
                                                    )}
                                                </div>
                                                <span className={`rounded-full px-2.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide ${
                                                    campaignData.data.campaign_status === 'active'
                                                        ? 'bg-green-100 text-green-800'
                                                        : campaignData.data.campaign_status === 'completed'
                                                        ? 'bg-blue-100 text-blue-800'
                                                        : campaignData.data.campaign_status === 'archived'
                                                        ? 'bg-gray-200 text-gray-600'
                                                        : 'bg-amber-100 text-amber-800'
                                                }`}>
                                                    {campaignData.data.campaign_status || 'draft'}
                                                </span>
                                            </div>
                                        </div>
                                        <div className="grid grid-cols-2 gap-3">
                                            <div className="rounded-lg border border-gray-200 bg-white p-3">
                                                <p className="text-xs font-medium uppercase tracking-wide text-gray-500">Readiness</p>
                                                <p className={`mt-1 text-sm font-semibold capitalize ${
                                                    campaignData.data.readiness_status === 'ready'
                                                        ? 'text-green-700'
                                                        : campaignData.data.readiness_status === 'partial'
                                                        ? 'text-amber-700'
                                                        : 'text-gray-500'
                                                }`}>
                                                    {campaignData.data.readiness_status || 'incomplete'}
                                                </p>
                                            </div>
                                            <div className="rounded-lg border border-gray-200 bg-white p-3">
                                                <p className="text-xs font-medium uppercase tracking-wide text-gray-500">Scoring</p>
                                                <p className={`mt-1 text-sm font-semibold ${
                                                    campaignData.data.scoring_enabled ? 'text-green-700' : 'text-gray-500'
                                                }`}>
                                                    {campaignData.data.scoring_enabled ? 'Enabled' : 'Disabled'}
                                                </p>
                                            </div>
                                        </div>
                                        {campaignData.data.reference_count != null && (
                                            <div className="rounded-lg border border-gray-200 bg-white p-3">
                                                <p className="text-xs font-medium uppercase tracking-wide text-gray-500">Campaign references</p>
                                                <p className="mt-1 text-sm font-semibold text-gray-900 tabular-nums">
                                                    {campaignData.data.reference_count} reference{campaignData.data.reference_count !== 1 ? 's' : ''}
                                                </p>
                                            </div>
                                        )}
                                        {collection?.id && (
                                            <Link
                                                href={`/app/collections/${collection.id}/campaign`}
                                                className="inline-flex items-center gap-2 rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                                            >
                                                Edit campaign identity
                                            </Link>
                                        )}
                                    </>
                                )}
                                <div className="mt-6 flex justify-end gap-3 border-t border-gray-100 pt-4">
                                    <button
                                        type="button"
                                        onClick={handleClose}
                                        className="rounded-md bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
                                    >
                                        Close
                                    </button>
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </div>
    )

    return typeof document !== 'undefined' ? createPortal(dialog, document.body) : null
}
