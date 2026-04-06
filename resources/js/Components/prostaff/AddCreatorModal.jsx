import { useCallback, useEffect, useRef, useState } from 'react'
import { Link } from '@inertiajs/react'

const PERIOD_TYPES = [
    { value: 'month', label: 'Month' },
    { value: 'quarter', label: 'Quarter' },
    { value: 'year', label: 'Year' },
]

function tabBtn(active, onClick, children) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={`flex-1 rounded-lg px-3 py-2 text-sm font-medium transition ${
                active
                    ? 'bg-white/15 text-white shadow-inner ring-1 ring-white/20'
                    : 'text-white/55 hover:bg-white/5 hover:text-white/80'
            }`}
        >
            {children}
        </button>
    )
}

/**
 * @param {{
 *   open: boolean,
 *   onClose: () => void,
 *   brandId: number,
 *   existingCreatorUserIds: number[],
 *   onSuccess: () => void,
 * }} props
 */
export default function AddCreatorModal({ open, onClose, brandId, existingCreatorUserIds, onSuccess }) {
    const [tab, setTab] = useState('existing')
    const [search, setSearch] = useState('')
    const [searchResults, setSearchResults] = useState([])
    const [searchLoading, setSearchLoading] = useState(false)
    const [searchForbidden, setSearchForbidden] = useState(false)
    const [selectedUser, setSelectedUser] = useState(null)
    const [targetUploads, setTargetUploads] = useState('')
    const [periodType, setPeriodType] = useState('month')
    const [manualUserId, setManualUserId] = useState('')
    const [inviteFirst, setInviteFirst] = useState('')
    const [inviteLast, setInviteLast] = useState('')
    const [inviteEmail, setInviteEmail] = useState('')
    const [busy, setBusy] = useState(false)
    const [error, setError] = useState(null)
    const searchTimer = useRef(null)

    const resetForms = useCallback(() => {
        setSearch('')
        setSearchResults([])
        setSelectedUser(null)
        setTargetUploads('')
        setPeriodType('month')
        setManualUserId('')
        setInviteFirst('')
        setInviteLast('')
        setInviteEmail('')
        setError(null)
        setSearchForbidden(false)
    }, [])

    useEffect(() => {
        if (!open) {
            resetForms()
            setTab('existing')
        }
    }, [open, resetForms])

    useEffect(() => {
        if (!open || tab !== 'existing') return undefined
        if (searchTimer.current) clearTimeout(searchTimer.current)
        const q = search.trim()
        if (q.length < 2) {
            setSearchResults([])
            setSearchLoading(false)
            return undefined
        }
        setSearchLoading(true)
        searchTimer.current = setTimeout(async () => {
            try {
                const params = new URLSearchParams({ search: q, per_page: '30' })
                const res = await fetch(`/app/api/companies/users?${params}`, {
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                })
                if (res.status === 403) {
                    setSearchForbidden(true)
                    setSearchResults([])
                    return
                }
                setSearchForbidden(false)
                if (!res.ok) {
                    setSearchResults([])
                    return
                }
                const json = await res.json()
                const rows = Array.isArray(json.data) ? json.data : []
                setSearchResults(rows)
            } catch {
                setSearchResults([])
            } finally {
                setSearchLoading(false)
            }
        }, 320)
        return () => {
            if (searchTimer.current) clearTimeout(searchTimer.current)
        }
    }, [search, open, tab])

    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''

    const submitExisting = async (e) => {
        e.preventDefault()
        setError(null)
        const uid = selectedUser?.id ?? (manualUserId.trim() ? parseInt(manualUserId, 10) : NaN)
        if (!Number.isFinite(uid) || uid < 1) {
            setError('Select a user or enter a valid user ID.')
            return
        }
        const body = {
            user_id: uid,
            period_type: periodType,
        }
        if (targetUploads.trim() !== '') {
            const t = parseInt(targetUploads, 10)
            if (!Number.isFinite(t) || t < 0) {
                setError('Target uploads must be a non-negative number.')
                return
            }
            body.target_uploads = t
        }
        setBusy(true)
        try {
            const res = await fetch(route('api.brands.prostaff.members.store', { brand: brandId }), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrf(),
                },
                body: JSON.stringify(body),
            })
            const data = await res.json().catch(() => ({}))
            if (res.status === 403 && data?.error === 'creator_module_inactive') {
                setError(data?.message || 'Creator module is not active.')
                return
            }
            if (!res.ok) {
                const msg =
                    data?.message ||
                    (data?.errors && Object.values(data.errors).flat().join(' ')) ||
                    data?.error ||
                    'Could not add creator.'
                setError(typeof msg === 'string' ? msg : 'Could not add creator.')
                return
            }
            onSuccess()
            onClose()
        } catch {
            setError('Network error.')
        } finally {
            setBusy(false)
        }
    }

    const submitInvite = async (e) => {
        e.preventDefault()
        setError(null)
        if (!inviteEmail.trim()) {
            setError('Email is required.')
            return
        }
        setBusy(true)
        try {
            const fd = new FormData()
            fd.append('_token', csrf())
            fd.append('email', inviteEmail.trim())
            fd.append('role', 'contributor')
            const res = await fetch(`/app/brands/${brandId}/users/invite`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: fd,
            })
            if (res.status === 403) {
                setError('You may not have permission to invite users to this brand. Try Company → Team, or ask an admin.')
                return
            }
            if (res.status === 422) {
                const data = await res.json().catch(() => ({}))
                const msg =
                    data?.message ||
                    (data?.errors && Object.values(data.errors).flat().join(' ')) ||
                    'Invitation could not be sent.'
                setError(typeof msg === 'string' ? msg : 'Invitation could not be sent.')
                return
            }
            if (!res.ok && res.status !== 302) {
                setError('Invitation could not be sent.')
                return
            }
            onSuccess()
            onClose()
        } catch {
            setError('Network error.')
        } finally {
            setBusy(false)
        }
    }

    if (!open) return null

    return (
        <div className="fixed inset-0 z-[200] flex items-end justify-center p-4 sm:items-center">
            <button
                type="button"
                className="absolute inset-0 bg-black/70 backdrop-blur-sm"
                aria-label="Close"
                onClick={onClose}
            />
            <div
                className="relative w-full max-w-lg overflow-hidden rounded-2xl border border-white/10 bg-[#12141a]/95 p-6 shadow-2xl backdrop-blur-2xl"
                role="dialog"
                aria-modal="true"
                aria-labelledby="add-creator-title"
            >
                <div className="flex items-start justify-between gap-4">
                    <h2 id="add-creator-title" className="text-lg font-semibold text-white">
                        Add creator
                    </h2>
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-lg p-1 text-white/50 transition hover:bg-white/10 hover:text-white"
                    >
                        <span className="sr-only">Close</span>
                        <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div className="mt-4 flex gap-1 rounded-xl bg-black/30 p-1 ring-1 ring-white/10">
                    {tabBtn(tab === 'existing', () => setTab('existing'), 'Existing user')}
                    {tabBtn(tab === 'invite', () => setTab('invite'), 'Invite new user')}
                </div>

                {error ? (
                    <p className="mt-4 rounded-lg border border-rose-400/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-100">
                        {error}
                    </p>
                ) : null}

                {tab === 'existing' ? (
                    <form onSubmit={submitExisting} className="mt-5 space-y-4">
                        <div>
                            <label className="block text-xs font-medium uppercase tracking-wide text-white/50">
                                Search workspace members
                            </label>
                            <input
                                type="search"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Name or email…"
                                className="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white placeholder:text-white/35 focus:border-white/25 focus:outline-none focus:ring-1 focus:ring-white/20"
                            />
                            {searchForbidden ? (
                                <p className="mt-2 text-xs text-amber-200/90">
                                    Directory search needs team management access. Enter a user ID below, or add them from{' '}
                                    <Link href={route('companies.team')} className="underline hover:text-white">
                                        Team
                                    </Link>
                                    .
                                </p>
                            ) : null}
                            {searchLoading ? (
                                <p className="mt-2 text-xs text-white/45">Searching…</p>
                            ) : null}
                            {searchResults.length > 0 ? (
                                <ul className="mt-2 max-h-40 overflow-auto rounded-xl border border-white/10 bg-black/20">
                                    {searchResults.map((u) => {
                                        const isCreator = existingCreatorUserIds.includes(u.id)
                                        return (
                                            <li key={u.id}>
                                                <button
                                                    type="button"
                                                    disabled={isCreator}
                                                    onClick={() => {
                                                        setSelectedUser(u)
                                                        setManualUserId(String(u.id))
                                                    }}
                                                    className={`flex w-full flex-col items-start px-3 py-2 text-left text-sm transition hover:bg-white/10 ${
                                                        selectedUser?.id === u.id ? 'bg-white/10' : ''
                                                    } ${isCreator ? 'cursor-not-allowed opacity-45' : ''}`}
                                                >
                                                    <span className="font-medium text-white">{u.name}</span>
                                                    <span className="text-xs text-white/50">{u.email}</span>
                                                    {isCreator ? (
                                                        <span className="text-xs text-amber-200/80">Already a creator</span>
                                                    ) : null}
                                                </button>
                                            </li>
                                        )
                                    })}
                                </ul>
                            ) : null}
                        </div>
                        <div>
                            <label className="block text-xs font-medium uppercase tracking-wide text-white/50">
                                User ID (fallback)
                            </label>
                            <input
                                type="number"
                                min={1}
                                value={manualUserId}
                                onChange={(e) => {
                                    setManualUserId(e.target.value)
                                    setSelectedUser(null)
                                }}
                                className="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white placeholder:text-white/35 focus:border-white/25 focus:outline-none focus:ring-1 focus:ring-white/20"
                                placeholder="Numeric ID"
                            />
                        </div>
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div>
                                <label className="block text-xs font-medium uppercase tracking-wide text-white/50">
                                    Target uploads
                                </label>
                                <input
                                    type="number"
                                    min={0}
                                    value={targetUploads}
                                    onChange={(e) => setTargetUploads(e.target.value)}
                                    className="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white focus:border-white/25 focus:outline-none focus:ring-1 focus:ring-white/20"
                                    placeholder="Optional"
                                />
                            </div>
                            <div>
                                <label className="block text-xs font-medium uppercase tracking-wide text-white/50">
                                    Period
                                </label>
                                <select
                                    value={periodType}
                                    onChange={(e) => setPeriodType(e.target.value)}
                                    className="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white focus:border-white/25 focus:outline-none focus:ring-1 focus:ring-white/20"
                                >
                                    {PERIOD_TYPES.map((p) => (
                                        <option key={p.value} value={p.value} className="bg-gray-900">
                                            {p.label}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        </div>
                        <p className="text-xs text-white/40">
                            Optional tier field is reserved for a future API update; membership tier is not sent yet.
                        </p>
                        <div className="flex justify-end gap-2 pt-2">
                            <button
                                type="button"
                                onClick={onClose}
                                className="rounded-xl border border-white/15 px-4 py-2.5 text-sm font-medium text-white/80 transition hover:bg-white/5"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                disabled={busy}
                                className="rounded-xl bg-white/90 px-4 py-2.5 text-sm font-semibold text-gray-900 transition hover:bg-white disabled:opacity-50"
                            >
                                {busy ? 'Saving…' : 'Add creator'}
                            </button>
                        </div>
                    </form>
                ) : (
                    <form onSubmit={submitInvite} className="mt-5 space-y-4">
                        <p className="text-xs text-white/50">
                            Sends a brand invitation as <span className="text-white/70">contributor</span>. After they
                            join, add them as a creator from the &quot;Existing user&quot; tab. Name fields are for your
                            notes only (not stored server-side yet).
                        </p>
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div>
                                <label className="block text-xs font-medium uppercase tracking-wide text-white/50">
                                    First name
                                </label>
                                <input
                                    value={inviteFirst}
                                    onChange={(e) => setInviteFirst(e.target.value)}
                                    className="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white focus:border-white/25 focus:outline-none focus:ring-1 focus:ring-white/20"
                                />
                            </div>
                            <div>
                                <label className="block text-xs font-medium uppercase tracking-wide text-white/50">
                                    Last name
                                </label>
                                <input
                                    value={inviteLast}
                                    onChange={(e) => setInviteLast(e.target.value)}
                                    className="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white focus:border-white/25 focus:outline-none focus:ring-1 focus:ring-white/20"
                                />
                            </div>
                        </div>
                        <div>
                            <label className="block text-xs font-medium uppercase tracking-wide text-white/50">
                                Email
                            </label>
                            <input
                                type="email"
                                required
                                value={inviteEmail}
                                onChange={(e) => setInviteEmail(e.target.value)}
                                className="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white focus:border-white/25 focus:outline-none focus:ring-1 focus:ring-white/20"
                            />
                        </div>
                        <div className="flex justify-end gap-2 pt-2">
                            <button
                                type="button"
                                onClick={onClose}
                                className="rounded-xl border border-white/15 px-4 py-2.5 text-sm font-medium text-white/80 transition hover:bg-white/5"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                disabled={busy}
                                className="rounded-xl bg-white/90 px-4 py-2.5 text-sm font-semibold text-gray-900 transition hover:bg-white disabled:opacity-50"
                            >
                                {busy ? 'Sending…' : 'Send invite'}
                            </button>
                        </div>
                    </form>
                )}
            </div>
        </div>
    )
}
