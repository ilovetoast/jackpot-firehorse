/**
 * Insights: archived vs active footprint + illustrative S3 list-price comparison (storage tier only).
 */
export default function StorageInsightPanel({ storage_insight: si, formatStorage }) {
    if (!si?.pricing) {
        return null
    }

    const p = si.pricing
    const m = si.implied_list_storage_tier_usd_month ?? {}
    const ratioPct =
        p.ia_vs_standard_ratio != null ? (Number(p.ia_vs_standard_ratio) * 100).toFixed(1) : null
    const discount = p.standard_ia_discount_percent_storage_tier

    return (
        <div className="rounded-lg border border-gray-200 bg-gray-50/80 p-4 text-sm text-gray-700">
            <h3 className="text-sm font-semibold text-gray-900">Storage classes (illustrative)</h3>
            <dl className="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2">
                <div>
                    <dt className="text-xs font-medium uppercase tracking-wide text-gray-500">Archived (this brand)</dt>
                    <dd className="mt-0.5 font-semibold text-gray-900">{formatStorage(si.archived_mb ?? 0)}</dd>
                </div>
                <div>
                    <dt className="text-xs font-medium uppercase tracking-wide text-gray-500">
                        Active visible files (all types)
                    </dt>
                    <dd className="mt-0.5 font-semibold text-gray-900">{formatStorage(si.active_visible_mb ?? 0)}</dd>
                </div>
            </dl>
            <p className="mt-2 text-[11px] text-gray-500">
                The main Storage figure on Insights counts catalog-ready published library assets. Active visible here is
                broader: every visible, non-archived file for this brand (includes executions and other visible types).
            </p>
            <p className="mt-3 text-xs leading-relaxed text-gray-600">
                Assumption: active library bytes behave like{' '}
                <span className="font-medium">S3 Standard</span> (~{p.currency}${p.standard_usd_per_gb_month}/GB-mo list)
                vs archived bytes on <span className="font-medium">{p.archive_storage_class ?? 'STANDARD_IA'}</span> (~
                {p.currency}${p.standard_ia_usd_per_gb_month}/GB-mo list). Archived storage is about{' '}
                {ratioPct != null ? (
                    <span className="font-medium">{ratioPct}%</span>
                ) : (
                    <span className="font-medium">—</span>
                )}{' '}
                of the list <em>storage-tier</em> rate of Standard
                {discount != null ? (
                    <>
                        {' '}
                        (~{discount}% lower than Standard for that line item)
                    </>
                ) : null}
                . Per MB-month (same assumption): Standard ~{p.currency}
                {p.standard_usd_per_mb_month}, {p.archive_storage_class ?? 'Standard-IA'} ~{p.currency}
                {p.standard_ia_usd_per_mb_month}.
            </p>
            <p className="mt-2 text-xs leading-relaxed text-gray-600">
                Order-of-magnitude list <strong>storage</strong> cost if those bytes were billed at list rates (not your
                invoice): archived @ IA ~{p.currency}
                {(m.archived_at_standard_ia_rate ?? 0).toFixed(2)}/mo; same archived bytes @ Standard ~{p.currency}
                {(m.archived_if_still_standard_rate ?? 0).toFixed(2)}/mo; active visible @ Standard ~{p.currency}
                {(m.active_visible_at_standard_rate ?? 0).toFixed(2)}/mo.
            </p>
            {p.disclaimer ? <p className="mt-2 text-[11px] text-gray-500">{p.disclaimer}</p> : null}
        </div>
    )
}
