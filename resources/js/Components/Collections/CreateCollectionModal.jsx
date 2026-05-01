/**
 * Create collection — name, description, access preset (icon cards), optional isolated external guests.
 */
import { useState } from 'react'
import { createPortal } from 'react-dom'
import {
    XMarkIcon,
    UsersIcon,
    AdjustmentsHorizontalIcon,
    UserPlusIcon,
    LockClosedIcon,
    LinkIcon,
} from '@heroicons/react/24/outline'
import { Link } from '@inertiajs/react'

const ACCESS_PRESETS = [
    {
        value: 'all_brand',
        title: 'Everyone in the brand',
        description: 'All teammates with this brand can view the collection.',
        icon: UsersIcon,
    },
    {
        value: 'role_limited',
        title: 'By role',
        description: 'Choose which brand roles can open this collection (you can change this anytime).',
        icon: AdjustmentsHorizontalIcon,
    },
    {
        value: 'invite_only',
        title: 'Invite-only',
        description: 'Only people you add (brand teammates and/or isolated email guests).',
        icon: UserPlusIcon,
    },
]

/** Sensible default so a new “by role” collection is usable before first edit. */
const DEFAULT_ROLE_LIMITED_ROLES = ['admin', 'brand_manager', 'contributor', 'viewer']

const BRAND_ROLE_OPTIONS = [
    { id: 'admin', label: 'Brand admin' },
    { id: 'brand_manager', label: 'Brand manager' },
    { id: 'contributor', label: 'Contributor' },
    { id: 'viewer', label: 'Viewer' },
]

function SectionDivider({ children }) {
    return (
        <div className="relative py-3" role="presentation">
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

export default function CreateCollectionModal({
    open,
    onClose,
    onCreated,
    publicCollectionsEnabled = false,
    billingUpgradeUrl = '/app/billing',
}) {
    const [name, setName] = useState('')
    const [description, setDescription] = useState('')
    const [accessPreset, setAccessPreset] = useState('all_brand')
    const [allowedBrandRoles, setAllowedBrandRoles] = useState(() => [...DEFAULT_ROLE_LIMITED_ROLES])
    const [allowExternalGuests, setAllowExternalGuests] = useState(false)
    const [shareLinkEnabled, setShareLinkEnabled] = useState(false)
    const [sharePassword, setSharePassword] = useState('')
    const [sharePasswordConfirmation, setSharePasswordConfirmation] = useState('')
    const [shareDownloadsEnabled, setShareDownloadsEnabled] = useState(true)
    const [submitting, setSubmitting] = useState(false)
    const [error, setError] = useState(null)

    const showExternalChoice = accessPreset !== 'all_brand'

    const toggleRole = (roleId) => {
        setAllowedBrandRoles((prev) =>
            prev.includes(roleId) ? prev.filter((r) => r !== roleId) : [...prev, roleId]
        )
    }

    const handleSubmit = async (e) => {
        e.preventDefault()
        setError(null)
        if (!name.trim()) {
            setError('Name is required.')
            return
        }
        if (accessPreset === 'role_limited' && allowedBrandRoles.length === 0) {
            setError('Select at least one brand role, or pick a different access option.')
            return
        }
        if (publicCollectionsEnabled && shareLinkEnabled) {
            if (!sharePassword.trim()) {
                setError('Set a password for the share link.')
                return
            }
            if (sharePassword !== sharePasswordConfirmation) {
                setError('Password confirmation does not match.')
                return
            }
        }
        setSubmitting(true)
        try {
            const payload = {
                name: name.trim(),
                description: description.trim() || null,
                access_mode: accessPreset,
                allowed_brand_roles:
                    accessPreset === 'role_limited' ? [...allowedBrandRoles] : [],
                allows_external_guests: showExternalChoice && allowExternalGuests,
            }
            if (publicCollectionsEnabled && shareLinkEnabled) {
                payload.is_public = true
                payload.public_password = sharePassword
                payload.public_password_confirmation = sharePasswordConfirmation
                payload.public_downloads_enabled = shareDownloadsEnabled
            }
            const response = await window.axios.post('/app/collections', payload, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            })
            const data = response.data
            if (data?.collection?.id) {
                onCreated?.(data.collection)
                setName('')
                setDescription('')
                setAccessPreset('all_brand')
                setAllowedBrandRoles([...DEFAULT_ROLE_LIMITED_ROLES])
                setAllowExternalGuests(false)
                setShareLinkEnabled(false)
                setSharePassword('')
                setSharePasswordConfirmation('')
                setShareDownloadsEnabled(true)
                onClose()
            }
        } catch (err) {
            const msg =
                err.response?.data?.errors?.name?.[0] ??
                err.response?.data?.message ??
                'Failed to create collection.'
            setError(msg)
        } finally {
            setSubmitting(false)
        }
    }

    const handleClose = () => {
        if (!submitting) {
            setError(null)
            setName('')
            setDescription('')
            setAccessPreset('all_brand')
            setAllowedBrandRoles([...DEFAULT_ROLE_LIMITED_ROLES])
            setAllowExternalGuests(false)
            setShareLinkEnabled(false)
            setSharePassword('')
            setSharePasswordConfirmation('')
            setShareDownloadsEnabled(true)
            onClose()
        }
    }

    if (!open) return null

    /** Portal + z above AppNav (z-[40]) and AssetDrawer so backdrop covers full viewport. */
    const dialog = (
        <div className="fixed inset-0 z-[220] isolate overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
            <div className="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                <div
                    className="fixed inset-0 bg-gray-500/75 transition-opacity z-[220]"
                    aria-hidden="true"
                    onClick={handleClose}
                />
                <div className="relative z-[221] transform overflow-hidden rounded-xl bg-white text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-2xl max-h-[92vh] flex flex-col">
                    <div className="bg-white px-4 pb-4 pt-5 sm:p-6 sm:pb-4 overflow-y-auto flex-1 min-h-0">
                        <div className="flex items-center justify-between mb-4">
                            <h3 className="text-lg font-semibold text-gray-900" id="modal-title">Create collection</h3>
                            <button
                                type="button"
                                onClick={handleClose}
                                disabled={submitting}
                                className="rounded-md text-gray-400 hover:text-gray-600 focus:outline-none focus:ring-2 focus:ring-indigo-500 disabled:opacity-50"
                            >
                                <XMarkIcon className="h-5 w-5" />
                            </button>
                        </div>
                        <form onSubmit={handleSubmit}>
                            {error && (
                                <div className="mb-4 rounded-md bg-red-50 p-3 text-sm text-red-700">
                                    {error}
                                </div>
                            )}
                            <div className="space-y-5">
                                <div>
                                    <label htmlFor="collection-name" className="block text-sm font-medium text-gray-700">
                                        Name <span className="text-red-500">*</span>
                                    </label>
                                    <input
                                        id="collection-name"
                                        type="text"
                                        required
                                        value={name}
                                        onChange={(e) => setName(e.target.value)}
                                        className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 sm:text-sm"
                                        placeholder="e.g. Campaign assets"
                                        autoFocus
                                        disabled={submitting}
                                    />
                                </div>
                                <div>
                                    <label htmlFor="collection-description" className="block text-sm font-medium text-gray-700">
                                        Description <span className="text-gray-400">(optional)</span>
                                    </label>
                                    <textarea
                                        id="collection-description"
                                        rows={3}
                                        value={description}
                                        onChange={(e) => setDescription(e.target.value)}
                                        className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 sm:text-sm"
                                        placeholder="Brief description of this collection"
                                        disabled={submitting}
                                    />
                                </div>

                                <div>
                                    <span className="block text-sm font-medium text-gray-900">Who can view</span>
                                    <p className="mt-0.5 text-xs text-gray-500">
                                        For &quot;By role&quot;, pick allowed roles below. You can still refine access after creating.
                                    </p>
                                    <fieldset className="mt-3 space-y-2" disabled={submitting}>
                                        <legend className="sr-only">Access preset</legend>
                                        {ACCESS_PRESETS.map(({ value, title, description, icon: Icon }) => {
                                            const selected = accessPreset === value
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
                                                        name="create_collection_access"
                                                        value={value}
                                                        checked={selected}
                                                        onChange={() => {
                                                            setAccessPreset(value)
                                                            if (value === 'all_brand') {
                                                                setAllowExternalGuests(false)
                                                            }
                                                            if (value === 'role_limited') {
                                                                setAllowedBrandRoles([...DEFAULT_ROLE_LIMITED_ROLES])
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

                                {accessPreset === 'role_limited' && (
                                    <div className="rounded-lg border border-indigo-100 bg-indigo-50/40 p-4">
                                        <p className="text-xs font-semibold text-indigo-900">Brand roles allowed</p>
                                        <p className="mt-1 text-xs text-indigo-800/80">
                                            Teammates with any checked role can view this collection.
                                        </p>
                                        <div className="mt-3 space-y-2">
                                            {BRAND_ROLE_OPTIONS.map(({ id, label }) => (
                                                <label key={id} className="flex cursor-pointer items-center gap-2">
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

                                {showExternalChoice && (
                                    <>
                                        <SectionDivider>Isolated guests (optional)</SectionDivider>
                                        <button
                                            type="button"
                                            onClick={() => setAllowExternalGuests((v) => !v)}
                                            disabled={submitting}
                                            className={`flex w-full gap-3 rounded-xl border-2 p-4 text-left transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-500 focus-visible:ring-offset-2 ${
                                                allowExternalGuests
                                                    ? 'border-amber-300 bg-amber-50/50 shadow-sm'
                                                    : 'border-dashed border-gray-300 bg-gray-50/50'
                                            }`}
                                        >
                                            <span
                                                className={`flex h-11 w-11 shrink-0 items-center justify-center rounded-lg ${
                                                    allowExternalGuests ? 'bg-amber-200 text-amber-900' : 'bg-gray-200 text-gray-600'
                                                }`}
                                            >
                                                <LockClosedIcon className="h-6 w-6" aria-hidden="true" />
                                            </span>
                                            <span className="min-w-0 flex-1">
                                                <span className="flex flex-wrap items-center gap-2">
                                                    <span className="text-sm font-semibold text-gray-900">
                                                        Allow email invites to isolated workspace
                                                    </span>
                                                    <span
                                                        className={`rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide ${
                                                            allowExternalGuests
                                                                ? 'bg-amber-200 text-amber-950'
                                                                : 'bg-gray-200 text-gray-700'
                                                        }`}
                                                    >
                                                        {allowExternalGuests ? 'On' : 'Off'}
                                                    </span>
                                                </span>
                                                <span className="mt-1 block text-xs text-gray-600 leading-relaxed">
                                                    External people get collection-only access—not your full brand. Turn on if you may invite clients or
                                                    partners by email; you can always add brand teammates later from collection settings.
                                                </span>
                                            </span>
                                            <span
                                                className={`relative mt-0.5 inline-flex h-6 w-11 shrink-0 rounded-full border-2 border-transparent transition-colors ${
                                                    allowExternalGuests ? 'bg-amber-600' : 'bg-gray-300'
                                                }`}
                                                aria-hidden="true"
                                            >
                                                <span
                                                    className={`pointer-events-none inline-block h-5 w-5 translate-y-px rounded-full bg-white shadow transition ${
                                                        allowExternalGuests ? 'translate-x-5' : 'translate-x-1'
                                                    }`}
                                                />
                                            </span>
                                        </button>
                                    </>
                                )}

                                <SectionDivider>Share link</SectionDivider>
                                <div id="create-share-link-section" className={`rounded-xl border p-4 ${publicCollectionsEnabled ? 'border-gray-200 bg-gray-50/80' : 'border-gray-200 bg-gray-50 opacity-90'}`}>
                                    <div className="flex gap-3">
                                        <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                                            <LinkIcon className="h-5 w-5 text-gray-600" aria-hidden="true" />
                                        </span>
                                        <div className="min-w-0 flex-1">
                                            <h4 className="text-sm font-semibold text-gray-900">Password-protected share link</h4>
                                            <p className="mt-1 text-xs text-gray-600">
                                                Create a simple external page for this collection. Visitors must enter the password before they can view or download files.
                                            </p>
                                        </div>
                                    </div>
                                    {!publicCollectionsEnabled ? (
                                        <p className="mt-3 text-xs text-gray-600">
                                            Share links are available on higher plans.{' '}
                                            <Link href={billingUpgradeUrl} className="font-medium text-indigo-600 hover:text-indigo-500">
                                                Upgrade
                                            </Link>
                                            {' '}to send password-protected collection links to clients, partners, and outside teams.
                                        </p>
                                    ) : (
                                        <div className="mt-4 space-y-3">
                                            <label className="flex items-center justify-between gap-3">
                                                <span className="text-sm text-gray-800">Enable share link</span>
                                                <button
                                                    type="button"
                                                    role="switch"
                                                    aria-checked={shareLinkEnabled}
                                                    disabled={submitting}
                                                    onClick={() => setShareLinkEnabled((v) => !v)}
                                                    className={`relative inline-flex h-6 w-11 shrink-0 rounded-full border-2 border-transparent transition-colors focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 ${
                                                        shareLinkEnabled ? 'bg-indigo-600' : 'bg-gray-200'
                                                    }`}
                                                >
                                                    <span
                                                        className={`pointer-events-none inline-block h-5 w-5 rounded-full bg-white shadow transition ${
                                                            shareLinkEnabled ? 'translate-x-5' : 'translate-x-1'
                                                        }`}
                                                    />
                                                </button>
                                            </label>
                                            {shareLinkEnabled ? (
                                                <>
                                                    <div>
                                                        <label htmlFor="create-share-password" className="block text-xs font-medium text-gray-700">
                                                            Password <span className="text-red-500">*</span>
                                                        </label>
                                                        <input
                                                            id="create-share-password"
                                                            type="password"
                                                            autoComplete="new-password"
                                                            value={sharePassword}
                                                            onChange={(e) => setSharePassword(e.target.value)}
                                                            className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                                            disabled={submitting}
                                                        />
                                                    </div>
                                                    <div>
                                                        <label htmlFor="create-share-password2" className="block text-xs font-medium text-gray-700">
                                                            Confirm password <span className="text-red-500">*</span>
                                                        </label>
                                                        <input
                                                            id="create-share-password2"
                                                            type="password"
                                                            autoComplete="new-password"
                                                            value={sharePasswordConfirmation}
                                                            onChange={(e) => setSharePasswordConfirmation(e.target.value)}
                                                            className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                                            disabled={submitting}
                                                        />
                                                    </div>
                                                    <label className="flex items-center gap-2">
                                                        <input
                                                            type="checkbox"
                                                            checked={shareDownloadsEnabled}
                                                            onChange={() => setShareDownloadsEnabled((v) => !v)}
                                                            disabled={submitting}
                                                            className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                                        />
                                                        <span className="text-sm text-gray-800">Allow downloads</span>
                                                    </label>
                                                    <p className="text-xs text-gray-500">
                                                        People with the link and password can view this collection only. They will not get access to your library, dashboard, brand settings, or other collections.
                                                    </p>
                                                </>
                                            ) : null}
                                        </div>
                                    )}
                                </div>
                            </div>
                            <div className="mt-6 flex justify-end gap-3 border-t border-gray-100 pt-4">
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
                                    {submitting ? 'Creating…' : 'Create'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    )

    return typeof document !== 'undefined' ? createPortal(dialog, document.body) : null
}
