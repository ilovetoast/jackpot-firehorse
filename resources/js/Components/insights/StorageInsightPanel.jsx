/**
 * Insights: archived vs active visible byte footprint for this brand (no vendor pricing copy).
 */
export default function StorageInsightPanel({ storage_insight: si, formatStorage }) {
    if (!si) {
        return null
    }

    return (
        <div className="rounded-xl border border-slate-200/90 bg-slate-50/50 p-4 text-sm text-slate-700">
            <h3 className="text-sm font-semibold text-slate-900">Storage breakdown</h3>
            <dl className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2 sm:gap-4">
                <div>
                    <dt className="text-[11px] font-medium uppercase tracking-wide text-slate-500">Archived (this brand)</dt>
                    <dd className="mt-0.5 font-semibold tabular-nums text-slate-900">{formatStorage(si.archived_mb ?? 0)}</dd>
                </div>
                <div>
                    <dt className="text-[11px] font-medium uppercase tracking-wide text-slate-500">
                        Active visible files (all types)
                    </dt>
                    <dd className="mt-0.5 font-semibold tabular-nums text-slate-900">{formatStorage(si.active_visible_mb ?? 0)}</dd>
                </div>
            </dl>
            <p className="mt-3 text-xs leading-relaxed text-slate-500">
                The main <span className="text-slate-600">Storage</span> total above counts catalog-ready published library
                assets. <span className="text-slate-600">Active visible</span> here includes every visible, non-archived
                file for this brand (including executions and other visible types).
            </p>
        </div>
    )
}
