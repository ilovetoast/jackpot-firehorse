import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link, router } from '@inertiajs/react'
import { ArrowRightIcon, CheckIcon, SparklesIcon } from '@heroicons/react/24/outline'
import { parseProstaffDashboardResponse } from '../../utils/parseProstaffDashboardResponse'

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
}

const CREATOR_MODULE_CALLOUTS = [
    'Per-creator targets, completion, and performance at a glance',
    'Structured approvals so creator uploads stay on-brand',
    'Jump from a creator to their assets in the library in one click',
]

/**
 * Brand Settings → Creators tab
 *
 * @param {{
 *   brandId: number,
 *   brandUsers: Array<{ id: number, name?: string, email?: string }>,
 *   creatorModule: { enabled?: boolean, approver_user_ids?: number[], has_approvers?: boolean },
 *   brandColor?: string,
 *   iconAccentColor?: string,
 * }} props
 */
export default function BrandCreatorsSettingsPanel({
    brandId,
    brandUsers = [],
    creatorModule = {},
    brandColor = '#6366f1',
    iconAccentColor,
}) {
    const accent = iconAccentColor || brandColor
    const enabled = creatorModule.enabled === true
    const initialApprovers = useMemo(
        () => (Array.isArray(creatorModule.approver_user_ids) ? creatorModule.approver_user_ids.map(Number) : []),
        [creatorModule.approver_user_ids]
    )
    const [selected, setSelected] = useState(() => new Set(initialApprovers))
    const [savingApprovers, setSavingApprovers] = useState(false)
    const [approverError, setApproverError] = useState(null)
    const [rows, setRows] = useState([])
    const [rowsLoading, setRowsLoading] = useState(false)

    useEffect(() => {
        setSelected(new Set(initialApprovers))
    }, [initialApprovers])

    const loadRows = useCallback(async () => {
        if (!enabled) return
        setRowsLoading(true)
        try {
            const res = await fetch(route('api.brands.prostaff.dashboard', { brand: brandId }), {
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            })
            const data = await res.json().catch(() => ({}))
            const { active } = parseProstaffDashboardResponse(data)
            setRows(active)
        } catch {
            setRows([])
        } finally {
            setRowsLoading(false)
        }
    }, [brandId, enabled])

    useEffect(() => {
        loadRows()
    }, [loadRows])

    const toggleApprover = (id) => {
        setSelected((prev) => {
            const next = new Set(prev)
            if (next.has(id)) next.delete(id)
            else next.add(id)
            return next
        })
    }

    const saveApprovers = async () => {
        setApproverError(null)
        if (selected.size < 1) {
            setApproverError('Select at least one approver.')
            return
        }
        setSavingApprovers(true)
        try {
            const res = await fetch(route('api.brands.prostaff.approvers', { brand: brandId }), {
                method: 'PUT',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrf(),
                },
                body: JSON.stringify({ user_ids: Array.from(selected) }),
            })
            const data = await res.json().catch(() => ({}))
            if (!res.ok) {
                setApproverError(
                    data?.message ||
                        (data?.errors && Object.values(data.errors).flat().join(' ')) ||
                        'Could not save approvers.'
                )
                return
            }
            router.reload({ preserveScroll: true })
        } catch {
            setApproverError('Network error.')
        } finally {
            setSavingApprovers(false)
        }
    }

    if (!enabled) {
        const settingsUrl =
            typeof route === 'function'
                ? `${route('companies.settings')}#creator-module`
                : '/app/companies/settings#creator-module'

        return (
            <div id="creators" className="scroll-mt-8">
                {/*
                  TODO: Replace the placeholder screenshot below with a real product image
                  (e.g. creator profile + dashboard) once marketing/design exports are available.
                */}
                <section
                    className="overflow-hidden rounded-xl border border-gray-200 bg-white p-4 shadow-sm sm:p-5"
                    aria-labelledby="creators-module-promo-heading"
                >
                    <div className="flex flex-col gap-5 lg:flex-row lg:items-stretch lg:gap-6">
                        <div className="relative shrink-0 lg:w-[min(100%,280px)] xl:w-[300px]">
                            <div
                                className="flex aspect-[4/3] w-full flex-col items-center justify-center gap-2 rounded-lg border border-dashed border-slate-300 bg-slate-50 px-4 text-center ring-1 ring-inset ring-slate-100"
                                aria-hidden
                            >
                                <div
                                    className="h-2 w-16 rounded-full bg-slate-200/90"
                                    style={{ boxShadow: `0 0 0 1px ${brandColor}22` }}
                                />
                                <div className="h-20 w-full max-w-[200px] rounded-md bg-gradient-to-br from-slate-200/80 to-slate-100" />
                                <p className="text-[11px] font-medium uppercase tracking-wide text-slate-400">
                                    Product screenshot
                                </p>
                                <p className="text-xs text-slate-500">Placeholder</p>
                            </div>
                        </div>

                        <div className="flex min-w-0 flex-1 flex-col gap-4">
                            <div className="min-w-0">
                                <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Creators</p>
                                <h2
                                    id="creators-module-promo-heading"
                                    className="mt-1 text-base font-semibold text-gray-900 sm:text-lg"
                                >
                                    Unlock creator analytics for this brand
                                </h2>
                                <p className="mt-1.5 text-sm leading-relaxed text-gray-600">
                                    Enable the Creator module in company settings to assign approvers, invite creators, and
                                    track performance—then finish setup here for this brand.
                                </p>
                                <ul className="mt-4 space-y-2.5">
                                    {CREATOR_MODULE_CALLOUTS.map((line) => (
                                        <li key={line} className="flex gap-2.5 text-sm text-gray-700">
                                            <span
                                                className="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-slate-100 text-slate-600 ring-1 ring-inset ring-slate-200/80"
                                                style={{ color: accent }}
                                            >
                                                <CheckIcon className="h-3 w-3" aria-hidden />
                                            </span>
                                            <span className="leading-snug">{line}</span>
                                        </li>
                                    ))}
                                </ul>
                            </div>

                            <div className="mt-auto flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                                <Link
                                    href={settingsUrl}
                                    className="inline-flex items-center justify-center gap-2 rounded-lg bg-violet-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-violet-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-violet-600"
                                >
                                    <SparklesIcon className="h-5 w-5 shrink-0" aria-hidden />
                                    Unlock Creator Module
                                    <ArrowRightIcon className="h-4 w-4 shrink-0" aria-hidden />
                                </Link>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        )
    }

    return (
        <div id="creators" className="scroll-mt-8 space-y-8">
            <div className="rounded-2xl border border-gray-200/90 bg-white/80 p-6 shadow-sm ring-1 ring-gray-200/60 backdrop-blur-md sm:p-8">
                <h2 className="text-xl font-semibold text-gray-900">Creators</h2>
                <p className="mt-2 max-w-2xl text-sm text-gray-600">
                    Assign approvers before adding creators. Approvers must be active members of this brand.
                </p>

                <div className="mt-6 rounded-xl border border-violet-100 bg-violet-50/50 p-5 backdrop-blur-sm">
                    <h3 className="text-sm font-semibold text-gray-900">Assigned approvers (required)</h3>
                    <p className="mt-1 text-xs text-gray-600">Select who can approve creator submissions for this brand.</p>
                    {approverError ? (
                        <p className="mt-3 text-sm text-red-600">{approverError}</p>
                    ) : null}
                    <ul className="mt-4 max-h-56 space-y-2 overflow-y-auto rounded-lg border border-gray-200/80 bg-white/90 p-3">
                        {brandUsers.map((u) => (
                            <li key={u.id}>
                                <label className="flex cursor-pointer items-center gap-3 rounded-lg px-2 py-1.5 hover:bg-gray-50">
                                    <input
                                        type="checkbox"
                                        className="h-4 w-4 rounded border-gray-300 text-violet-600"
                                        checked={selected.has(u.id)}
                                        onChange={() => toggleApprover(u.id)}
                                    />
                                    <span className="text-sm text-gray-900">{u.name || u.email}</span>
                                    <span className="text-xs text-gray-500">{u.email}</span>
                                </label>
                            </li>
                        ))}
                    </ul>
                    <button
                        type="button"
                        onClick={saveApprovers}
                        disabled={savingApprovers}
                        className="mt-4 inline-flex rounded-xl bg-violet-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-violet-500 disabled:opacity-50"
                    >
                        {savingApprovers ? 'Saving…' : 'Save approvers'}
                    </button>
                </div>
            </div>

            <div className="rounded-2xl border border-gray-200/90 bg-white/80 p-6 shadow-sm ring-1 ring-gray-200/60 backdrop-blur-md sm:p-8">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <h3 className="text-lg font-semibold text-gray-900">Creator list</h3>
                    <Link
                        href={route('brands.creators', { brand: brandId })}
                        className="inline-flex justify-center rounded-xl border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-800 shadow-sm transition hover:bg-gray-50"
                    >
                        Open creator dashboard
                    </Link>
                </div>
                {rowsLoading ? (
                    <p className="mt-4 text-sm text-gray-500">Loading creators…</p>
                ) : rows.length === 0 ? (
                    <p className="mt-4 text-sm text-gray-600">No creators yet.</p>
                ) : (
                    <div className="mt-4 overflow-x-auto rounded-xl border border-gray-200/80">
                        <table className="min-w-full text-left text-sm">
                            <thead className="border-b border-gray-200 bg-gray-50/80 text-xs font-semibold uppercase tracking-wide text-gray-500">
                                <tr>
                                    <th className="px-4 py-3">Name</th>
                                    <th className="px-4 py-3">Target</th>
                                    <th className="px-4 py-3">Period</th>
                                    <th className="px-4 py-3">Status</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {rows.map((r) => (
                                    <tr key={r.user_id}>
                                        <td className="px-4 py-3 font-medium text-gray-900">{r.name}</td>
                                        <td className="px-4 py-3 text-gray-700">
                                            {r.target_uploads != null ? r.target_uploads : '—'}
                                        </td>
                                        <td className="px-4 py-3 text-gray-600">{r.period_type || '—'}</td>
                                        <td className="px-4 py-3 text-gray-700 capitalize">{r.status || '—'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </div>
    )
}
