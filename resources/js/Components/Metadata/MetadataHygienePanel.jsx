import { useCallback, useEffect, useState } from 'react'

/**
 * Phase 5.3 — admin metadata hygiene panel.
 *
 * Lazy-loaded inside the folder schema submenu (FolderSchemaHelp.jsx).
 * Surfaces three operations against a single (tenant, field):
 *
 *   1. Alias list  (alias → canonical)
 *      - Add alias (admin types both values)
 *      - Remove alias
 *
 *   2. Duplicate candidates (hash-bucket + plural/singular pairs)
 *      - "Suggested" merges; clicking pre-fills the merge form.
 *      - Visually subtle — admins decide whether to act.
 *
 *   3. Merge action
 *      - Inputs: from / to. The service validates the combination.
 *      - On success, displays the row count + alias-recorded flag.
 *      - Clears the duplicate suggestion that fed the form.
 *
 * Strict guarantees:
 *   - All requests are non-destructive (server-side: alias rows + merge rewrites
 *     `value_json` only).
 *   - Admin-only — the parent panel already gates rendering; the server
 *     re-checks the permission on every request.
 *   - Errors are surfaced inline (no toast spam), and the panel never
 *     auto-merges or auto-deletes on the client.
 *
 * Out of scope (deferred):
 *   - AI suggestions
 *   - Merge undo (the audit table is in place; UI is a future phase)
 *   - Background re-scoring jobs
 */
export default function MetadataHygienePanel({ field, onChanged }) {
    const fieldId = field?.id
    const [aliases, setAliases] = useState([])
    const [candidates, setCandidates] = useState([])
    const [loadingAliases, setLoadingAliases] = useState(false)
    const [loadingCandidates, setLoadingCandidates] = useState(false)
    const [error, setError] = useState(null)
    const [aliasInput, setAliasInput] = useState({ alias: '', canonical: '' })
    const [mergeInput, setMergeInput] = useState({ from: '', to: '' })
    const [busy, setBusy] = useState(false)
    const [lastResult, setLastResult] = useState(null)

    const loadAll = useCallback(async () => {
        if (!fieldId) return
        setError(null)
        setLoadingAliases(true)
        setLoadingCandidates(true)
        try {
            const [aliasRes, dupRes] = await Promise.all([
                fetch(
                    `/app/api/tenant/metadata/fields/${fieldId}/hygiene/aliases`,
                    {
                        credentials: 'same-origin',
                        headers: { Accept: 'application/json' },
                    }
                ),
                fetch(
                    `/app/api/tenant/metadata/fields/${fieldId}/hygiene/duplicates`,
                    {
                        credentials: 'same-origin',
                        headers: { Accept: 'application/json' },
                    }
                ),
            ])
            if (aliasRes.ok) {
                const data = await aliasRes.json()
                setAliases(Array.isArray(data?.aliases) ? data.aliases : [])
            }
            if (dupRes.ok) {
                const data = await dupRes.json()
                setCandidates(Array.isArray(data?.candidates) ? data.candidates : [])
            }
        } catch {
            setError('Could not load metadata hygiene data.')
        } finally {
            setLoadingAliases(false)
            setLoadingCandidates(false)
        }
    }, [fieldId])

    useEffect(() => {
        void loadAll()
    }, [loadAll])

    const csrf = useCallback(() => {
        const meta = document.querySelector('meta[name="csrf-token"]')
        return meta ? meta.getAttribute('content') : ''
    }, [])

    const onAddAlias = async () => {
        const alias = aliasInput.alias.trim()
        const canonical = aliasInput.canonical.trim()
        if (!alias || !canonical || busy) return
        setBusy(true)
        setError(null)
        try {
            const res = await fetch(
                `/app/api/tenant/metadata/fields/${fieldId}/hygiene/aliases`,
                {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrf(),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ alias, canonical }),
                }
            )
            if (!res.ok) {
                let msg = 'Could not save alias.'
                try {
                    const j = await res.json()
                    msg = j?.message || msg
                } catch {}
                setError(msg)
            } else {
                setAliasInput({ alias: '', canonical: '' })
                void loadAll()
                onChanged?.()
            }
        } finally {
            setBusy(false)
        }
    }

    const onRemoveAlias = async (aliasId) => {
        if (busy) return
        setBusy(true)
        try {
            const res = await fetch(
                `/app/api/tenant/metadata/fields/${fieldId}/hygiene/aliases/${aliasId}`,
                {
                    method: 'DELETE',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrf(),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                }
            )
            if (res.ok || res.status === 204) {
                void loadAll()
                onChanged?.()
            }
        } finally {
            setBusy(false)
        }
    }

    const onMerge = async () => {
        const from = mergeInput.from.trim()
        const to = mergeInput.to.trim()
        if (!from || !to || busy) return
        setBusy(true)
        setError(null)
        setLastResult(null)
        try {
            const res = await fetch(
                `/app/api/tenant/metadata/fields/${fieldId}/hygiene/merge`,
                {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrf(),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ from, to }),
                }
            )
            if (!res.ok) {
                let msg = 'Merge failed.'
                try {
                    const j = await res.json()
                    msg = j?.message || msg
                } catch {}
                setError(msg)
            } else {
                const data = await res.json()
                setLastResult(data)
                setMergeInput({ from: '', to: '' })
                void loadAll()
                onChanged?.()
            }
        } finally {
            setBusy(false)
        }
    }

    const useCandidateForMerge = (canonical, alias) => {
        setMergeInput({ from: alias, to: canonical })
        setError(null)
    }

    if (!fieldId) return null

    return (
        <div
            className="space-y-3 border-t border-slate-100 px-3 py-3"
            aria-label="Metadata hygiene"
        >
            <div className="flex items-center justify-between">
                <p className="text-[11px] font-semibold uppercase tracking-wider text-slate-700">
                    Metadata hygiene
                </p>
                <button
                    type="button"
                    onClick={loadAll}
                    className="text-[10px] text-slate-500 underline-offset-2 hover:underline"
                >
                    Refresh
                </button>
            </div>
            {error ? (
                <p
                    role="alert"
                    className="rounded-md border border-rose-200 bg-rose-50 px-2.5 py-1.5 text-[11px] text-rose-700"
                >
                    {error}
                </p>
            ) : null}

            {/* Aliases */}
            <Section title="Aliases" hint="alias → canonical">
                {loadingAliases && aliases.length === 0 ? (
                    <p className="text-[10.5px] italic text-slate-400">Loading aliases…</p>
                ) : aliases.length === 0 ? (
                    <p className="text-[10.5px] italic text-slate-400">No aliases yet.</p>
                ) : (
                    <ul className="space-y-0.5">
                        {aliases.map((row) => (
                            <li
                                key={row.id}
                                className="flex items-center gap-2 truncate rounded px-1.5 py-0.5 text-[10.5px] text-slate-700"
                            >
                                <span className="truncate font-medium">{row.alias_value}</span>
                                <span className="text-slate-400">→</span>
                                <span className="truncate">{row.canonical_value}</span>
                                <span className="ml-auto rounded bg-slate-100 px-1.5 py-0.5 text-[9px] uppercase tracking-wide text-slate-500">
                                    {row.source}
                                </span>
                                <button
                                    type="button"
                                    onClick={() => onRemoveAlias(row.id)}
                                    disabled={busy}
                                    className="text-[10px] text-rose-500 underline-offset-2 hover:underline disabled:opacity-60"
                                    aria-label={`Remove alias ${row.alias_value}`}
                                >
                                    Remove
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
                <div className="mt-1 flex flex-wrap items-center gap-1.5">
                    <input
                        type="text"
                        value={aliasInput.alias}
                        onChange={(e) =>
                            setAliasInput((s) => ({ ...s, alias: e.target.value }))
                        }
                        placeholder="Alias (e.g. outdoors)"
                        className="h-7 w-32 rounded-md border border-slate-200 bg-white px-2 text-[11px]"
                    />
                    <span aria-hidden className="text-slate-400">→</span>
                    <input
                        type="text"
                        value={aliasInput.canonical}
                        onChange={(e) =>
                            setAliasInput((s) => ({ ...s, canonical: e.target.value }))
                        }
                        placeholder="Canonical (e.g. outdoor)"
                        className="h-7 w-32 rounded-md border border-slate-200 bg-white px-2 text-[11px]"
                    />
                    <button
                        type="button"
                        onClick={onAddAlias}
                        disabled={busy || !aliasInput.alias || !aliasInput.canonical}
                        className="h-7 rounded-md bg-slate-900 px-2.5 text-[11px] font-medium text-white disabled:opacity-50"
                    >
                        Add
                    </button>
                </div>
            </Section>

            {/* Duplicate candidates */}
            <Section title="Possible duplicates" hint="merge into one canonical value">
                {loadingCandidates && candidates.length === 0 ? (
                    <p className="text-[10.5px] italic text-slate-400">Scanning…</p>
                ) : candidates.length === 0 ? (
                    <p className="text-[10.5px] italic text-slate-400">No suggestions.</p>
                ) : (
                    <ul className="space-y-1">
                        {candidates.map((g, i) => (
                            <li
                                key={`${g.hash}-${i}`}
                                className="rounded-md border border-amber-100 bg-amber-50 px-2 py-1.5 text-[10.5px] text-slate-700"
                            >
                                <p>
                                    <span className="font-medium">{g.canonical_hint}</span>
                                    <span className="ml-1 text-[9px] uppercase tracking-wide text-amber-700">
                                        {g.reason === 'plural_singular_pair'
                                            ? 'plural/singular'
                                            : 'normalization'}
                                    </span>
                                </p>
                                <ul className="mt-0.5 flex flex-wrap gap-1">
                                    {g.values.map((v) => (
                                        <li key={v}>
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    useCandidateForMerge(g.canonical_hint, v)
                                                }
                                                className="rounded-md border border-slate-200 bg-white px-1.5 py-0.5 hover:bg-slate-50"
                                                aria-label={`Use "${v}" as merge source for "${g.canonical_hint}"`}
                                                disabled={v === g.canonical_hint}
                                                title={
                                                    v === g.canonical_hint
                                                        ? 'Suggested canonical'
                                                        : `Pre-fill merge: ${v} → ${g.canonical_hint}`
                                                }
                                            >
                                                {v}
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            </li>
                        ))}
                    </ul>
                )}
            </Section>

            {/* Merge action */}
            <Section title="Merge values" hint="rewrites asset metadata, non-destructive">
                <div className="flex flex-wrap items-center gap-1.5">
                    <input
                        type="text"
                        value={mergeInput.from}
                        onChange={(e) =>
                            setMergeInput((s) => ({ ...s, from: e.target.value }))
                        }
                        placeholder="From"
                        className="h-7 w-28 rounded-md border border-slate-200 bg-white px-2 text-[11px]"
                    />
                    <span aria-hidden className="text-slate-400">→</span>
                    <input
                        type="text"
                        value={mergeInput.to}
                        onChange={(e) =>
                            setMergeInput((s) => ({ ...s, to: e.target.value }))
                        }
                        placeholder="To"
                        className="h-7 w-28 rounded-md border border-slate-200 bg-white px-2 text-[11px]"
                    />
                    <button
                        type="button"
                        onClick={onMerge}
                        disabled={busy || !mergeInput.from || !mergeInput.to}
                        className="h-7 rounded-md bg-amber-600 px-2.5 text-[11px] font-medium text-white disabled:opacity-50"
                    >
                        Merge
                    </button>
                </div>
                {lastResult ? (
                    <p className="mt-1 text-[10.5px] text-emerald-700">
                        Merged. Updated {lastResult.rows_updated} row
                        {lastResult.rows_updated === 1 ? '' : 's'}
                        {lastResult.alias_recorded ? ' · alias recorded' : ''}
                        {lastResult.bounded_by_cap
                            ? ' · cap reached, run again to continue'
                            : ''}
                        .
                    </p>
                ) : null}
            </Section>
        </div>
    )
}

function Section({ title, hint, children }) {
    return (
        <div>
            <p className="text-[10px] uppercase tracking-wide text-slate-500">
                {title}
                {hint ? (
                    <span className="ml-1 text-[9px] italic text-slate-400">
                        {hint}
                    </span>
                ) : null}
            </p>
            <div className="mt-1">{children}</div>
        </div>
    )
}
