import { useMemo, useState } from 'react'
import {
    ChevronDownIcon,
    ChevronRightIcon,
    NoSymbolIcon,
    ShieldExclamationIcon,
} from '@heroicons/react/24/outline'
import { CheckCircleIcon, ClockIcon } from '@heroicons/react/24/solid'
import { getRegisteredTypesForHelp, getDamFileTypes } from '../../utils/damFileTypes'

/**
 * "Supported file types" section for the help panel.
 *
 * Pulls the per-type summary from the server-shared registry payload
 * (FileTypeService::getUploadRegistryForFrontend → types_for_help).
 * Adding a new type to config/file_types.php automatically surfaces it
 * here — no edits to the help panel required.
 *
 * Three groups in the UI:
 *   1. Allowed       — status === 'enabled' && enabled === true
 *   2. Coming soon   — status === 'coming_soon' (registered, not yet processable)
 *   3. Blocked       — security policy: executable, server_script, archive, web
 *      (sourced from blocked_groups on the same registry payload, NOT from `types`)
 *
 * Each "Allowed" row shows: name, extensions, max size if set, badges for
 * preview / AI analysis support so users can tell at a glance what we'll
 * do with the file (just store it vs. process + AI-tag).
 */
function bytesToHumanLabel(bytes) {
    if (!Number.isFinite(bytes) || bytes <= 0) return null
    const mb = bytes / (1024 * 1024)
    if (mb >= 1024) return `${(mb / 1024).toFixed(1)} GB`
    if (mb >= 10) return `${Math.round(mb)} MB`
    return `${mb.toFixed(1)} MB`
}

function ExtensionPills({ extensions }) {
    if (!Array.isArray(extensions) || extensions.length === 0) return null
    return (
        <div className="mt-1.5 flex flex-wrap gap-1">
            {extensions.map((ext) => (
                <code
                    key={ext}
                    className="inline-flex items-center rounded-md bg-slate-100 px-1.5 py-0.5 font-mono text-[10px] font-medium uppercase text-slate-700 dark:bg-slate-800 dark:text-slate-200"
                >
                    .{ext}
                </code>
            ))}
        </div>
    )
}

function CapabilityChips({ capabilities }) {
    const chips = []
    if (capabilities?.preview) chips.push({ label: 'Preview', tone: 'sky' })
    if (capabilities?.ai_analysis) chips.push({ label: 'AI', tone: 'violet' })
    if (capabilities?.download_only) chips.push({ label: 'Download only', tone: 'amber' })
    if (chips.length === 0) return null
    const toneClass = (t) =>
        ({
            sky: 'bg-sky-50 text-sky-800 ring-sky-200',
            violet: 'bg-violet-50 text-violet-800 ring-violet-200',
            amber: 'bg-amber-50 text-amber-800 ring-amber-200',
        })[t] || 'bg-slate-100 text-slate-700 ring-slate-200'
    return (
        <div className="mt-1 flex flex-wrap gap-1">
            {chips.map((c) => (
                <span
                    key={c.label}
                    className={`inline-flex items-center rounded-md px-1.5 py-0.5 text-[10px] font-medium ring-1 ring-inset ${toneClass(c.tone)}`}
                >
                    {c.label}
                </span>
            ))}
        </div>
    )
}

function CodecDetails({ details }) {
    if (!details || typeof details !== 'object') return null
    const entries = Object.entries(details)
    if (entries.length === 0) return null
    return (
        <ul className="mt-1.5 space-y-0.5 text-[11px] leading-snug text-slate-500">
            {entries.map(([ext, meta]) => {
                const browser = meta?.browser_playback === 'transcoded' ? 'auto-converted' : 'native'
                return (
                    <li key={ext} className="flex items-baseline gap-1.5">
                        <code className="rounded bg-slate-100 px-1 py-px font-mono text-[10px] uppercase text-slate-700 dark:bg-slate-800 dark:text-slate-200">
                            .{ext}
                        </code>
                        <span className="text-[10px] uppercase tracking-wide text-slate-400">{browser}</span>
                        {meta?.note ? <span className="text-slate-500">{meta.note}</span> : null}
                    </li>
                )
            })}
        </ul>
    )
}

function AllowedRow({ type }) {
    const sizeLabel = bytesToHumanLabel(type.max_size_bytes)
    return (
        <li className="rounded-lg border border-slate-200 bg-white px-3 py-2.5 shadow-sm">
            <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                    <p className="truncate text-sm font-semibold text-slate-900">{type.name}</p>
                    {type.description ? (
                        <p className="mt-0.5 text-[11px] leading-snug text-slate-500">{type.description}</p>
                    ) : null}
                </div>
                <span className="inline-flex shrink-0 items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-emerald-700 ring-1 ring-inset ring-emerald-200">
                    <CheckCircleIcon className="h-3 w-3" aria-hidden />
                    Allowed
                </span>
            </div>
            <ExtensionPills extensions={type.extensions} />
            <CodecDetails details={type.codec_details} />
            <div className="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-slate-500">
                {sizeLabel ? <span>Max {sizeLabel} per file</span> : <span>Plan-based size limits</span>}
            </div>
            <CapabilityChips capabilities={type.capabilities} />
        </li>
    )
}

function ComingSoonRow({ type }) {
    return (
        <li className="rounded-lg border border-amber-200 bg-amber-50/60 px-3 py-2.5 shadow-sm">
            <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                    <p className="truncate text-sm font-semibold text-amber-900">{type.name}</p>
                    {type.disabled_message ? (
                        <p className="mt-0.5 text-[11px] leading-snug text-amber-800">{type.disabled_message}</p>
                    ) : type.description ? (
                        <p className="mt-0.5 text-[11px] leading-snug text-amber-800">{type.description}</p>
                    ) : null}
                </div>
                <span className="inline-flex shrink-0 items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-800 ring-1 ring-inset ring-amber-200">
                    <ClockIcon className="h-3 w-3" aria-hidden />
                    Coming soon
                </span>
            </div>
            <ExtensionPills extensions={type.extensions} />
        </li>
    )
}

function BlockedRow({ groupKey, group }) {
    const friendlyName = useMemo(() => {
        const map = {
            executable: 'Executables',
            server_script: 'Server scripts',
            archive: 'Archives',
            web: 'Web pages & scripts',
        }
        return map[groupKey] || groupKey
    }, [groupKey])

    return (
        <li className="rounded-lg border border-rose-200 bg-rose-50/60 px-3 py-2.5 shadow-sm">
            <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                    <p className="truncate text-sm font-semibold text-rose-900">{friendlyName}</p>
                    {group.message ? (
                        <p className="mt-0.5 text-[11px] leading-snug text-rose-800">{group.message}</p>
                    ) : null}
                </div>
                <span className="inline-flex shrink-0 items-center gap-1 rounded-full bg-rose-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-rose-800 ring-1 ring-inset ring-rose-200">
                    <NoSymbolIcon className="h-3 w-3" aria-hidden />
                    Blocked
                </span>
            </div>
            <ExtensionPills extensions={(group.extensions || []).slice(0, 12)} />
            {Array.isArray(group.extensions) && group.extensions.length > 12 ? (
                <p className="mt-1 text-[10px] text-rose-700/80">
                    +{group.extensions.length - 12} more extensions in this category
                </p>
            ) : null}
        </li>
    )
}

export default function HelpSupportedFileTypes({ defaultOpen = false }) {
    const [open, setOpen] = useState(defaultOpen)

    const types = useMemo(() => getRegisteredTypesForHelp(), [])
    const cfg = useMemo(() => getDamFileTypes(), [])
    const allowed = types.filter((t) => t.enabled && t.status === 'enabled')
    const comingSoon = types.filter((t) => t.status === 'coming_soon')
    const blockedGroups = cfg.blocked_groups || {}
    const blockedKeys = Object.keys(blockedGroups)

    if (allowed.length === 0 && comingSoon.length === 0 && blockedKeys.length === 0) {
        return null
    }

    return (
        <section className="space-y-3">
            <button
                type="button"
                onClick={() => setOpen((o) => !o)}
                className="flex w-full items-center justify-between gap-2 border-b border-slate-200 pb-1.5 text-left"
                aria-expanded={open}
            >
                <div className="min-w-0">
                    <h2 className="text-[11px] font-semibold uppercase tracking-wider text-slate-500">
                        Supported file types
                    </h2>
                    <p className="mt-1 text-[11px] leading-snug text-slate-500">
                        What you can upload, what's coming, and what we never accept.
                    </p>
                </div>
                <span className="shrink-0 text-slate-400">
                    {open ? (
                        <ChevronDownIcon className="h-4 w-4" aria-hidden />
                    ) : (
                        <ChevronRightIcon className="h-4 w-4" aria-hidden />
                    )}
                </span>
            </button>

            {open && (
                <div className="space-y-4">
                    {allowed.length > 0 && (
                        <div>
                            <p className="mb-1.5 text-[11px] font-semibold uppercase tracking-wider text-emerald-700">
                                Allowed ({allowed.length})
                            </p>
                            <ul className="m-0 grid list-none grid-cols-1 gap-2 p-0">
                                {allowed.map((t) => (
                                    <AllowedRow key={t.key} type={t} />
                                ))}
                            </ul>
                        </div>
                    )}

                    {comingSoon.length > 0 && (
                        <div>
                            <p className="mb-1.5 text-[11px] font-semibold uppercase tracking-wider text-amber-700">
                                Coming soon ({comingSoon.length})
                            </p>
                            <ul className="m-0 grid list-none grid-cols-1 gap-2 p-0">
                                {comingSoon.map((t) => (
                                    <ComingSoonRow key={t.key} type={t} />
                                ))}
                            </ul>
                        </div>
                    )}

                    {blockedKeys.length > 0 && (
                        <div>
                            <p className="mb-1.5 flex items-center gap-1 text-[11px] font-semibold uppercase tracking-wider text-rose-700">
                                <ShieldExclamationIcon className="h-3 w-3" aria-hidden />
                                Always blocked ({blockedKeys.length})
                            </p>
                            <ul className="m-0 grid list-none grid-cols-1 gap-2 p-0">
                                {blockedKeys.map((k) => (
                                    <BlockedRow key={k} groupKey={k} group={blockedGroups[k]} />
                                ))}
                            </ul>
                            <p className="mt-2 text-[10px] leading-snug text-slate-500">
                                Executables, server scripts, archives, and HTML pages are blocked at every step
                                (browser picker, drag-and-drop, server preflight, and storage finalize) for the
                                safety of every workspace.
                            </p>
                        </div>
                    )}
                </div>
            )}
        </section>
    )
}
