import { useState, useCallback } from 'react'
import { Link, usePage } from '@inertiajs/react'
import AppNav from '../../../Components/AppNav'
import AppHead from '../../../Components/AppHead'
import AppFooter from '../../../Components/AppFooter'
import {
    AcademicCapIcon,
    ChevronRightIcon,
    ChevronDownIcon,
    ArrowTopRightOnSquareIcon,
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

/**
 * @param {object} props
 * @param {import('@inertiajs/core').PageProps} props
 */
export default function DefaultsReview({ groups, options_by_field_id, meta }) {
    const { auth } = usePage().props
    const [phaseByKey, setPhaseByKey] = useState({})

    const cycle = useCallback((key) => {
        setPhaseByKey((prev) => {
            const cur = prev[key] ?? PHASE.closed
            return { ...prev, [key]: (cur + 1) % 3 }
        })
    }, [])

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
                    <p className="mb-8 text-xs text-slate-500">{meta?.source}</p>

                    <div className="space-y-10">
                        {(groups || []).map((g) => (
                            <section key={g.key}>
                                <h2 className="text-lg font-semibold text-slate-900">{g.label}</h2>
                                <ul className="mt-3 space-y-2">
                                    {(g.categories || []).map((c) => {
                                        const k = `${g.key}-${c.id}`
                                        const phase = phaseByKey[k] ?? PHASE.closed
                                        const isCatHidden = c.is_hidden
                                        const notAuto = !c.auto_provision
                                        return (
                                            <li
                                                key={c.id}
                                                className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm"
                                            >
                                                <button
                                                    type="button"
                                                    onClick={() => cycle(k)}
                                                    className="flex w-full items-start gap-3 px-4 py-3 text-left transition hover:bg-slate-50/80"
                                                >
                                                    {phase > PHASE.closed ? (
                                                        <ChevronDownIcon className="mt-0.5 h-5 w-5 shrink-0 text-slate-400" />
                                                    ) : (
                                                        <ChevronRightIcon className="mt-0.5 h-5 w-5 shrink-0 text-slate-400" />
                                                    )}
                                                    <div
                                                        className={`min-w-0 flex-1 ${
                                                            isCatHidden || notAuto ? 'opacity-55' : ''
                                                        }`}
                                                    >
                                                        <div className="flex flex-wrap items-center gap-2">
                                                            <span className="font-medium text-slate-900">
                                                                {c.name}
                                                            </span>
                                                            <span className="font-mono text-xs text-slate-500">
                                                                {c.slug}
                                                            </span>
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
                                                        <p className="mt-1 text-xs text-slate-500">
                                                            {phaseLabel(phase)}
                                                        </p>
                                                    </div>
                                                </button>

                                                {phase >= PHASE.fields && (c.fields || []).length > 0 ? (
                                                    <div className="border-t border-slate-100 bg-slate-50/50 px-4 py-3">
                                                        <ul className="space-y-2">
                                                            {(c.fields || []).map((f) => {
                                                                const options =
                                                                    (options_by_field_id &&
                                                                        options_by_field_id[f.id]) ||
                                                                    []
                                                                const showOptions =
                                                                    phase >= PHASE.values &&
                                                                    options.length > 0
                                                                const faded = f.is_hidden
                                                                return (
                                                                    <li
                                                                        key={f.id}
                                                                        className="rounded-lg border border-slate-200/80 bg-white px-3 py-2"
                                                                    >
                                                                        <div
                                                                            className={`flex flex-wrap items-baseline gap-2 ${
                                                                                faded
                                                                                    ? 'opacity-50'
                                                                                    : ''
                                                                            }`}
                                                                        >
                                                                            <span className="font-medium text-slate-800">
                                                                                {f.label}
                                                                            </span>
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
                                                                            <ul className="mt-2 flex max-h-40 flex-wrap gap-1.5 overflow-y-auto text-[11px] text-slate-600">
                                                                                {options.map((opt, oi) => (
                                                                                    <li
                                                                                        key={`${opt.value}-${oi}`}
                                                                                        className="rounded border border-slate-100 bg-slate-50 px-2 py-0.5"
                                                                                    >
                                                                                        <span className="font-medium">
                                                                                            {opt.label}
                                                                                        </span>
                                                                                        <span className="ml-1 font-mono text-slate-400">
                                                                                            {opt.value}
                                                                                        </span>
                                                                                    </li>
                                                                                ))}
                                                                            </ul>
                                                                        ) : null}
                                                                        {phase >= PHASE.values &&
                                                                        ['select', 'multiselect'].includes(
                                                                            f.type
                                                                        ) &&
                                                                        !options.length ? (
                                                                            <p className="mt-1 text-[11px] text-slate-400">
                                                                                No system options
                                                                                listed for this
                                                                                field.
                                                                            </p>
                                                                        ) : null}
                                                                    </li>
                                                                )
                                                            })}
                                                        </ul>
                                                    </div>
                                                ) : null}
                                                {phase >= PHASE.fields &&
                                                (!c.fields || c.fields.length === 0) ? (
                                                    <div className="border-t border-slate-100 px-4 py-3 text-sm text-slate-500">
                                                        No field rows resolved for this template.
                                                    </div>
                                                ) : null}
                                            </li>
                                        )
                                    })}
                                </ul>
                            </section>
                        ))}
                    </div>
                </div>
            </main>
            <AppFooter />
        </div>
    )
}
