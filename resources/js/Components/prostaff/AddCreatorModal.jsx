import { useCallback, useEffect, useRef, useState } from 'react'
import { Link } from '@inertiajs/react'

const PERIOD_TYPES = [
    { value: 'month', label: 'Month' },
    { value: 'quarter', label: 'Quarter' },
    { value: 'year', label: 'Year' },
]

/**
 * Email-first flow: match workspace members or invite new user; assigns prostaff on accept when inviting.
 *
 * @param {{
 *   open: boolean,
 *   onClose: () => void,
 *   brandId: number,
 *   existingCreatorEmails?: string[],
 *   onSuccess: () => void,
 * }} props
 */
export default function AddCreatorModal({ open, onClose, brandId, existingCreatorEmails = [], onSuccess }) {
    const [email, setEmail] = useState('')
    const [matches, setMatches] = useState([])
    const [searchLoading, setSearchLoading] = useState(false)
    const [searchForbidden, setSearchForbidden] = useState(false)
    const [targetUploads, setTargetUploads] = useState('')
    const [periodType, setPeriodType] = useState('month')
    const [busy, setBusy] = useState(false)
    const [error, setError] = useState(null)
    const searchTimer = useRef(null)

    const normalizedExisting = (existingCreatorEmails || []).map((e) => String(e || '').toLowerCase())

    const resetForms = useCallback(() => {
        setEmail('')
        setMatches([])
        setTargetUploads('')
        setPeriodType('month')
        setError(null)
        setSearchForbidden(false)
    }, [])

    useEffect(() => {
        if (!open) resetForms()
    }, [open, resetForms])

    useEffect(() => {
        if (!open) return undefined
        if (searchTimer.current) clearTimeout(searchTimer.current)
        const q = email.trim()
        if (q.length < 2) {
            setMatches([])
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
                    setMatches([])
                    return
                }
                setSearchForbidden(false)
                if (!res.ok) {
                    setMatches([])
                    return
                }
                const json = await res.json()
                const rows = Array.isArray(json.data) ? json.data : []
                const em = q.toLowerCase()
                setMatches(rows.filter((u) => (u.email && String(u.email).toLowerCase().includes(em)) || (u.name && String(u.name).toLowerCase().includes(em))))
            } catch {
                setMatches([])
            } finally {
                setSearchLoading(false)
            }
        }, 320)
        return () => {
            if (searchTimer.current) clearTimeout(searchTimer.current)
        }
    }, [email, open])

    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''

    const emailLooksValid = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim())

    const pickMatch = (row) => {
        if (row?.email) setEmail(row.email)
    }

    const submit = async (e) => {
        e.preventDefault()
        setError(null)
        const em = email.trim()
        if (!emailLooksValid) {
            setError('Enter a valid email address.')
            return
        }
        if (normalizedExisting.includes(em.toLowerCase())) {
            setError('This person is already a creator for this brand.')
            return
        }
        const body = {
            email: em,
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

    if (!open) return null

    const showInviteHint = emailLooksValid && matches.length === 0 && !searchLoading && email.trim().length >= 3

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

                <p className="mt-2 text-xs text-white/45">
                    Enter an email. We&apos;ll match workspace members or send an invite. New users become creators when they
                    accept.
                </p>

                {error ? (
                    <p className="mt-4 rounded-lg border border-rose-400/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-100">
                        {error}
                    </p>
                ) : null}

                <form onSubmit={submit} className="mt-5 space-y-4">
                    <div>
                        <label className="block text-xs font-medium uppercase tracking-wide text-white/50">Email</label>
                        <input
                            type="email"
                            autoComplete="off"
                            value={email}
                            onChange={(e) => setEmail(e.target.value)}
                            placeholder="name@company.com"
                            className="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white placeholder:text-white/35 focus:border-white/25 focus:outline-none focus:ring-1 focus:ring-white/20"
                        />
                        {searchForbidden ? (
                            <p className="mt-2 text-xs text-amber-200/90">
                                Directory search needs team access. You can still submit the email to send an invite if
                                they&apos;re new.{' '}
                                <Link href={route('companies.team')} className="underline hover:text-white">
                                    Team
                                </Link>
                            </p>
                        ) : null}
                        {searchLoading ? <p className="mt-2 text-xs text-white/45">Looking up workspace…</p> : null}
                        {matches.length > 0 ? (
                            <ul className="mt-2 max-h-36 overflow-auto rounded-xl border border-white/10 bg-black/25 ring-1 ring-white/5">
                                {matches.map((u) => {
                                    const taken = normalizedExisting.includes(String(u.email || '').toLowerCase())
                                    return (
                                        <li key={u.id}>
                                            <button
                                                type="button"
                                                disabled={taken}
                                                onClick={() => pickMatch(u)}
                                                className={`flex w-full flex-col items-start px-3 py-2 text-left text-sm transition hover:bg-white/10 ${
                                                    taken ? 'cursor-not-allowed opacity-45' : ''
                                                }`}
                                            >
                                                <span className="font-medium text-white">{u.name}</span>
                                                <span className="text-xs text-white/50">{u.email}</span>
                                                {taken ? (
                                                    <span className="text-xs text-amber-200/80">Already a creator</span>
                                                ) : null}
                                            </button>
                                        </li>
                                    )
                                })}
                            </ul>
                        ) : null}
                        {showInviteHint ? (
                            <div className="mt-3 rounded-xl border border-violet-400/25 bg-violet-500/10 px-3 py-2 text-xs text-violet-100/90">
                                <span className="font-semibold text-violet-100">Invite new user</span>
                                <span className="block text-white/55">
                                    No workspace match — we&apos;ll email an invitation and add them as a creator when they
                                    join.
                                </span>
                            </div>
                        ) : null}
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
                            <label className="block text-xs font-medium uppercase tracking-wide text-white/50">Period</label>
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
                            {busy ? 'Working…' : 'Continue'}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    )
}
