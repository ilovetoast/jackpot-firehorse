/**
 * Minimized upload background tray — compact status widget (not a second uploader).
 * Collapsed: headline, count line, one thin aggregate bar, Expand + actions.
 * Expanded: optional file list (failed first, muted completed); capped with "Show all".
 */

import { useMemo } from 'react'
import { getSolidFillButtonForegroundHex } from '../utils/colorUtils'

const LIST_CAP_DEFAULT = 10

function cn(...parts) {
    return parts.filter(Boolean).join(' ')
}

/**
 * @typedef {'uploading'|'finalizing'|'processing_previews'|'ready'|'complete'|'complete_issues'|'failed'} TrayVisualPhase
 */

/**
 * @param {object} props
 * @param {string} props.title — Primary headline, e.g. "Uploading 7 assets"
 * @param {string|null} [props.countLine] — Secondary summary, e.g. "5 ready · 2 processing"
 * @param {TrayVisualPhase} [props.phase='uploading'] — Drives subtle accent / aria
 * @param {number|null} [props.aggregateProgress] — 0–100; null = indeterminate bar
 * @param {string} [props.brandPrimary='#6366f1']
 * @param {boolean} [props.listExpanded=false]
 * @param {() => void} [props.onToggleList]
 * @param {boolean} [props.listShowAll=false]
 * @param {() => void} [props.onToggleShowAll]
 * @param {number} [props.listCap=10]
 * @param {Array<{ id: string, name: string, rowKind: 'failed'|'active'|'complete', detail?: string|null, progress?: number|null }>} [props.fileRows=[]]
 * @param {string|null} [props.finalizeError]
 * @param {boolean} [props.isFinalizeSuccess]
 * @param {boolean} [props.showFinalizeButton]
 * @param {() => void} [props.onFinalize]
 * @param {boolean} [props.showDismiss]
 * @param {() => void} [props.onDismiss]
 * @param {() => void} [props.onExpandDialog]
 * @param {(clientId: string) => void} [props.onRetryFile]
 */
export function FloatingUploadProgressTray({
    title,
    countLine = null,
    phase = 'uploading',
    aggregateProgress = null,
    brandPrimary = '#6366f1',
    listExpanded = false,
    onToggleList,
    listShowAll = false,
    onToggleShowAll,
    listCap = LIST_CAP_DEFAULT,
    fileRows = [],
    finalizeError = null,
    isFinalizeSuccess = false,
    showFinalizeButton = false,
    onFinalize,
    showDismiss = false,
    onDismiss,
    onExpandDialog,
    onRetryFile,
}) {
    const totalFiles = fileRows.length
    const finalizeBtnFg = useMemo(() => getSolidFillButtonForegroundHex(brandPrimary), [brandPrimary])
    const visibleCap = listShowAll ? fileRows.length : Math.min(listCap, fileRows.length)
    const visibleRows = listExpanded ? fileRows.slice(0, visibleCap) : []
    const hasMoreRows = listExpanded && totalFiles > listCap && !listShowAll

    const phaseBorder =
        phase === 'failed' || phase === 'complete_issues'
            ? 'border-amber-200/90'
            : phase === 'complete'
              ? 'border-emerald-200/80'
              : 'border-gray-200/90'

    const indeterminate = aggregateProgress == null

    return (
        <div
            className={cn(
                'w-[min(100vw-1.5rem,19rem)] rounded-lg border bg-white/95 p-2.5 shadow-md backdrop-blur-sm',
                phaseBorder,
            )}
            role="status"
            aria-live="polite"
            aria-label={title}
        >
            <div className="flex items-start justify-between gap-2">
                <div className="min-w-0 flex-1">
                    <p className="text-[13px] font-semibold leading-snug text-gray-900">{title}</p>
                    {countLine ? (
                        <p className="mt-0.5 text-[11px] leading-snug text-gray-500">{countLine}</p>
                    ) : null}
                </div>
                {typeof onToggleList === 'function' && totalFiles > 0 ? (
                    <button
                        type="button"
                        onClick={onToggleList}
                        className="shrink-0 rounded px-1.5 py-0.5 text-[11px] font-medium text-gray-600 hover:bg-gray-100 hover:text-gray-900"
                        aria-expanded={listExpanded}
                    >
                        {listExpanded ? 'Hide list' : 'Details'}
                    </button>
                ) : null}
            </div>

            {/* Single aggregate bar — light track, brand tint fill (never heavy black per file) */}
            <div className="mt-2 h-1 w-full overflow-hidden rounded-full bg-gray-100">
                {indeterminate ? (
                    <div
                        className="h-full w-2/5 animate-pulse rounded-full"
                        style={{ backgroundColor: brandPrimary, opacity: 0.45 }}
                        title="In progress"
                    />
                ) : (
                    <div
                        className="h-full max-w-full rounded-full transition-[width] duration-300 ease-out"
                        style={{
                            width: `${Math.max(0, Math.min(100, aggregateProgress))}%`,
                            backgroundColor: brandPrimary,
                            opacity: 0.85,
                        }}
                    />
                )}
            </div>

            {finalizeError ? (
                <p className="mt-2 rounded border border-red-100 bg-red-50/90 px-2 py-1 text-[11px] leading-snug text-red-800">
                    {finalizeError}
                </p>
            ) : null}

            {isFinalizeSuccess ? (
                <p className="mt-2 text-[11px] font-medium text-emerald-700">All uploads complete.</p>
            ) : null}

            {listExpanded && visibleRows.length > 0 ? (
                <ul className="jp-upload-modal-scroll mt-2 max-h-52 space-y-1 overflow-y-auto overscroll-contain pr-0.5">
                    {visibleRows.map((row) => (
                        <li
                            key={row.id}
                            className={cn(
                                'rounded-md px-2 py-1.5 text-[11px] leading-snug',
                                row.rowKind === 'failed' &&
                                    row.planLimit &&
                                    'border-l-2 border-amber-300 bg-amber-50/95 text-amber-950',
                                row.rowKind === 'failed' &&
                                    !row.planLimit &&
                                    'border-l-2 border-red-400 bg-red-50/90 text-red-950',
                                row.rowKind === 'active' && 'bg-gray-50/80 text-gray-900',
                                row.rowKind === 'complete' && 'text-gray-400',
                            )}
                        >
                            <div className="flex items-start justify-between gap-2">
                                <span className="min-w-0 flex-1 truncate" title={row.name}>
                                    {row.rowKind === 'complete' ? (
                                        <span className="text-gray-400">✓ </span>
                                    ) : null}
                                    {row.name}
                                </span>
                                {row.rowKind === 'active' &&
                                row.progress != null &&
                                Number.isFinite(row.progress) ? (
                                    <span className="shrink-0 tabular-nums text-gray-500">
                                        {Math.round(row.progress)}%
                                    </span>
                                ) : null}
                            </div>
                            {row.rowKind === 'failed' && row.detail ? (
                                <p
                                    className={cn(
                                        'mt-0.5 line-clamp-3 text-[10px]',
                                        row.planLimit ? 'text-amber-900/90' : 'text-red-800/90',
                                    )}
                                >
                                    {row.detail}
                                </p>
                            ) : null}
                            {row.rowKind === 'failed' && row.planLimit ? (
                                row.canManageBilling ? (
                                    <a
                                        href={row.planLimit.upgrade_url}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="mt-1 inline-block text-[10px] font-semibold text-amber-800 underline decoration-amber-300 underline-offset-2 hover:text-amber-950"
                                    >
                                        View upgrade options →
                                    </a>
                                ) : (
                                    <p className="mt-1 text-[10px] leading-snug text-amber-900/85">
                                        Ask a workspace admin to upgrade for larger uploads.
                                    </p>
                                )
                            ) : null}
                            {row.rowKind === 'failed' && typeof onRetryFile === 'function' ? (
                                <button
                                    type="button"
                                    className="mt-1 text-[10px] font-semibold text-red-700 underline decoration-red-300 underline-offset-2 hover:text-red-900"
                                    onClick={() => onRetryFile(row.id)}
                                >
                                    Retry
                                </button>
                            ) : null}
                        </li>
                    ))}
                </ul>
            ) : null}

            {hasMoreRows && typeof onToggleShowAll === 'function' ? (
                <button
                    type="button"
                    onClick={onToggleShowAll}
                    className="mt-1.5 w-full text-center text-[11px] font-medium text-indigo-600 hover:text-indigo-800"
                >
                    Show all {totalFiles} files
                </button>
            ) : null}

            <div className="mt-2 flex flex-wrap items-center justify-between gap-x-2 gap-y-1.5 border-t border-gray-100/80 pt-2">
                {typeof onExpandDialog === 'function' ? (
                    <button
                        type="button"
                        onClick={onExpandDialog}
                        className="text-[11px] font-medium text-gray-600 hover:text-gray-900"
                    >
                        Expand
                    </button>
                ) : (
                    <span />
                )}
                <div className="flex flex-wrap items-center justify-end gap-1.5">
                    {showFinalizeButton && typeof onFinalize === 'function' ? (
                        <button
                            type="button"
                            onClick={onFinalize}
                            className="rounded-md px-2.5 py-1 text-[11px] font-semibold shadow-sm"
                            style={{ backgroundColor: brandPrimary, color: finalizeBtnFg }}
                        >
                            Finalize
                        </button>
                    ) : null}
                    {showDismiss && typeof onDismiss === 'function' ? (
                        <button
                            type="button"
                            onClick={onDismiss}
                            className="rounded-md border border-gray-200 bg-white px-2 py-1 text-[11px] font-medium text-gray-600 hover:bg-gray-50"
                            title="Dismiss tray"
                        >
                            Close
                        </button>
                    ) : null}
                </div>
            </div>
        </div>
    )
}
