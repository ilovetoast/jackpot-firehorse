import { useState, useCallback, useEffect } from 'react'
import { Link, usePage } from '@inertiajs/react'
import AppNav from '../../../Components/AppNav'
import AppHead from '../../../Components/AppHead'
import AppFooter from '../../../Components/AppFooter'
import {
    AcademicCapIcon,
    ArrowTopRightOnSquareIcon,
    DocumentTextIcon,
    FolderIcon,
    MinusIcon,
    PlusIcon,
} from '@heroicons/react/24/outline'

const PHASE = {
    closed: 0,
    fields: 1,
    values: 2,
}

const phaseLabel = (p) => {
    if (p === PHASE.fields) return 'Fields visible — click again for option values'
    if (p === PHASE.values) return 'Options visible — click again to collapse'
    return 'Click to show fields and visibility'
}

/** Start collapsed: expand a category to see fields, then again for option values. */
function buildInitialPhaseByKey(groups) {
    const initial = {}
    for (const g of groups || []) {
        for (const c of g.categories || []) {
            initial[`${g.key}-${c.id}`] = PHASE.closed
        }
    }
    return initial
}

/**
 * @param {object} props
 * @param {import('@inertiajs/core').PageProps} props
 */
function fieldDetailKey(gKey, categoryId, fieldId) {
    return `${gKey}-${categoryId}-f-${fieldId}`
}

export default function DefaultsReview({ groups, options_by_field_id, meta }) {
    const { auth } = usePage().props
    const [phaseByKey, setPhaseByKey] = useState(() => buildInitialPhaseByKey(groups))
    /** `true` = options list (or empty message) visible; default collapsed */
    const [fieldOptionsOpen, setFieldOptionsOpen] = useState({})

    const toggleFieldOptions = useCallback((key) => {
        setFieldOptionsOpen((prev) => ({
            ...prev,
            [key]: !prev[key],
        }))
    }, [])

    const cycle = useCallback((key) => {
        setPhaseByKey((prev) => {
            const cur = prev[key] ?? PHASE.closed
            return { ...prev, [key]: (cur + 1) % 3 }
        })
    }, [])

    useEffect(() => {
        setPhaseByKey((prev) => {
            const next = { ...prev }
            let changed = false
            for (const g of groups || []) {
                for (const c of g.categories || []) {
                    const k = `${g.key}-${c.id}`
                    if (next[k] === undefined) {
                        next[k] = PHASE.closed
                        changed = true
                    }
                }
            }
            return changed ? next : prev
        })
    }, [groups])

    return (
        <div className="min-h-full">
            <AppHead title="Onboarding: default categories and fields" suffix="Admin" />
            <AppNav brand={auth?.activeBrand} tenant={null} />
            <main className="bg-slate-50">
                <div className="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
                    <div className="mb-6">
                        <Link
                            href="/app/admin"
                            className="text-sm font-medium text-indigo-600 hover:text-indigo-500"
                        >
                            ← Admin
                        </Link>
                    </div>

                    <div className="mb-8 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <div className="flex items-center gap-2">
                                <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-indigo-100">
                                    <AcademicCapIcon className="h-6 w-6 text-indigo-700" />
                                </div>
                                <h1 className="text-2xl font-bold tracking-tight text-slate-900">
                                    Default categories &amp; fields
                                </h1>
                            </div>
                            <p className="mt-2 max-w-3xl text-sm text-slate-600">
                                Review what new accounts inherit: latest system category templates and
                                config-driven field visibility. Hidden catalog folders appear faded. Fields
                                turned on for the default experience are solid; &quot;available but
                                off&quot; for that folder (hidden in UI) are faded.
                            </p>
                        </div>
                        <a
                            href="/app/admin/system-categories"
                            className="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50"
                        >
                            System Categories
                            <ArrowTopRightOnSquareIcon className="h-4 w-4 text-slate-400" />
                        </a>
                    </div>

                    <div className="mb-6 rounded-xl border border-amber-100 bg-amber-50/60 px-4 py-3 text-sm text-amber-950/90">
                        <p className="font-medium">Read-only (v1)</p>
                        <p className="mt-1 text-amber-950/80">{meta?.editing}</p>
                    </div>
                    <p className="mb-6 text-xs text-slate-500">{meta?.source}</p>

                    <div className="space-y-8">
                        {(groups || []).map((g) => (
                            <section key={g.key} aria-label={g.label}>
                                <h2 className="sr-only">{g.label}</h2>
                                <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                                    <div className="flex items-center gap-2 border-b border-slate-200 bg-slate-50/90 px-3 py-2.5">
                                        <FolderIcon
                                            className="h-5 w-5 shrink-0 text-slate-500"
                                            aria-hidden
                                        />
                                        <span className="text-sm font-semibold tracking-tight text-slate-800">
                                            {g.label}
                                        </span>
                                    </div>
                                    <ul className="tree-root list-none divide-y divide-slate-100 py-1.5">
                                        {(g.categories || []).map((c) => {
                                            const k = `${g.key}-${c.id}`
                                            const phase = phaseByKey[k] ?? PHASE.closed
                                            const isCatHidden = c.is_hidden
                                            const notAuto = !c.auto_provision
                                            const isOpen = phase > PHASE.closed
                                            return (
                                                <li key={c.id} className="px-2">
                                                    <button
                                                        type="button"
                                                        onClick={() => cycle(k)}
                                                        className="group flex w-full items-start gap-1 rounded-lg py-1.5 pl-1 pr-1 text-left text-sm transition hover:bg-slate-50/90"
                                                    >
                                                        <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded border border-slate-200 bg-white text-slate-500 shadow-sm group-hover:border-slate-300">
                                                            {isOpen ? (
                                                                <MinusIcon className="h-3.5 w-3.5" />
                                                            ) : (
                                                                <PlusIcon className="h-3.5 w-3.5" />
                                                            )}
                                                        </span>
                                                        <FolderIcon
                                                            className="mt-1.5 h-4 w-4 shrink-0 text-amber-600/90"
                                                            aria-hidden
                                                        />
                                                        <div
                                                            className={`min-w-0 flex-1 pt-0.5 ${
                                                                isCatHidden || notAuto ? 'opacity-55' : ''
                                                            }`}
                                                        >
                                                            <div className="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                                                                <span className="font-medium text-slate-900">
                                                                    {c.name}
                                                                </span>
                                                                <code className="text-[11px] text-slate-500">
                                                                    {c.slug}
                                                                </code>
                                                                {isCatHidden ? (
                                                                    <span className="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-slate-600">
                                                                        Catalog hidden
                                                                    </span>
                                                                ) : null}
                                                                {notAuto ? (
                                                                    <span className="rounded bg-amber-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-800">
                                                                        Not auto-provisioned
                                                                    </span>
                                                                ) : null}
                                                            </div>
                                                            <p className="text-[11px] text-slate-500">
                                                                {phaseLabel(phase)}
                                                            </p>
                                                        </div>
                                                    </button>

                                                    {isOpen && (c.fields || []).length > 0 ? (
                                                        <ul
                                                            className="ml-[2.15rem] border-l border-slate-200 pl-3"
                                                            role="list"
                                                        >
                                                            {(c.fields || []).map((f) => {
                                                                const options =
                                                                    (options_by_field_id &&
                                                                        options_by_field_id[f.id]) ||
                                                                    []
                                                                const faded = f.is_hidden
                                                                const isSelect = ['select', 'multiselect'].includes(
                                                                    f.type
                                                                )
                                                                const fk = fieldDetailKey(g.key, c.id, f.id)
                                                                const hasOptionsDetail =
                                                                    phase >= PHASE.values && options.length > 0
                                                                const hasSelectEmptyMessage =
                                                                    phase >= PHASE.values && isSelect && !options.length
                                                                const hasCollapsibleDetail =
                                                                    hasOptionsDetail || hasSelectEmptyMessage
                                                                const isOptionsOpen =
                                                                    hasCollapsibleDetail && fieldOptionsOpen[fk] === true
                                                                const showOptions = hasOptionsDetail && isOptionsOpen
                                                                return (
                                                                    <li
                                                                        key={f.id}
                                                                        className="relative py-1.5 pl-0 before:absolute before:-left-3 before:top-[0.7rem] before:h-px before:w-2.5 before:bg-slate-200 first:pt-0.5"
                                                                    >
                                                                        <div
                                                                            className={`flex items-start gap-1 ${
                                                                                faded ? 'opacity-50' : ''
                                                                            }`}
                                                                        >
                                                                            {hasCollapsibleDetail ? (
                                                                                <button
                                                                                    type="button"
                                                                                    onClick={() => toggleFieldOptions(fk)}
                                                                                    className="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded border border-slate-200 bg-white text-slate-500 shadow-sm hover:border-slate-300"
                                                                                    aria-expanded={isOptionsOpen}
                                                                                    aria-label={
                                                                                        isOptionsOpen
                                                                                            ? `Hide options for ${f.label}`
                                                                                            : `Show options for ${f.label}`
                                                                                    }
                                                                                >
                                                                                    {isOptionsOpen ? (
                                                                                        <MinusIcon className="h-3 w-3" />
                                                                                    ) : (
                                                                                        <PlusIcon className="h-3 w-3" />
                                                                                    )}
                                                                                </button>
                                                                            ) : (
                                                                                <span
                                                                                    className="mt-0.5 w-6 shrink-0"
                                                                                    aria-hidden
                                                                                />
                                                                            )}
                                                                            <div className="min-w-0 flex-1">
                                                                                <div className="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                                                                                    <div className="flex min-w-0 items-baseline gap-1.5">
                                                                                        <DocumentTextIcon
                                                                                            className="h-3.5 w-3.5 shrink-0 text-slate-400"
                                                                                            aria-hidden
                                                                                        />
                                                                                        <span className="font-medium text-slate-800">
                                                                                            {f.label}
                                                                                        </span>
                                                                                    </div>
                                                                                    <code className="text-[11px] text-slate-500">
                                                                                        {f.key}
                                                                                    </code>
                                                                                    <span className="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-slate-600">
                                                                                        {f.type}
                                                                                    </span>
                                                                                    {f.is_primary ? (
                                                                                        <span className="text-[10px] font-semibold uppercase text-indigo-600">
                                                                                            Primary
                                                                                        </span>
                                                                                    ) : null}
                                                                                    {f.is_hidden ? (
                                                                                        <span className="text-[10px] text-slate-500">
                                                                                            (off for this folder)
                                                                                        </span>
                                                                                    ) : null}
                                                                                </div>
                                                                                {showOptions ? (
                                                                                    <ul
                                                                                        className="ml-1 mt-1.5 border-l border-slate-100 pl-2.5"
                                                                                        role="list"
                                                                                    >
                                                                                        {options.map((opt, oi) => (
                                                                                            <li
                                                                                                key={`${opt.value}-${oi}`}
                                                                                                className="py-0.5 text-[11px] text-slate-600"
                                                                                            >
                                                                                                <span>{opt.label}</span>
                                                                                                <code className="ml-1.5 font-mono text-slate-400">
                                                                                                    {opt.value}
                                                                                                </code>
                                                                                            </li>
                                                                                        ))}
                                                                                    </ul>
                                                                                ) : null}
                                                                                {hasSelectEmptyMessage && isOptionsOpen ? (
                                                                                    <p className="ml-5 mt-0.5 text-[11px] text-slate-400">
                                                                                        No system options listed for
                                                                                        this field.
                                                                                    </p>
                                                                                ) : null}
                                                                            </div>
                                                                        </div>
                                                                    </li>
                                                                )
                                                            })}
                                                        </ul>
                                                    ) : null}
                                                    {isOpen && (!c.fields || c.fields.length === 0) ? (
                                                        <p className="ml-12 border-l border-slate-200 pl-3 pb-2 text-sm text-slate-500">
                                                            No field rows resolved for this template.
                                                        </p>
                                                    ) : null}
                                                </li>
                                            )
                                        })}
                                    </ul>
                                </div>
                            </section>
                        ))}
                    </div>
                </div>
            </main>
            <AppFooter />
        </div>
    )
}
