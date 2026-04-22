/**
 * Studio composition → Kling i2v: shared helpers and cost breakdown for admin AI UIs.
 */

export function formatUsd6(value) {
    const n = Number(value)
    return Number.isFinite(n) ? n.toFixed(6) : '0.000000'
}

export function isStudioAnimationRun(run) {
    if (!run) {
        return false
    }
    return (
        run.task_type === 'studio_composition_animation' ||
        run?.metadata?.generative_audit?.audit_kind === 'studio_composition_animation'
    )
}

/**
 * Run-detail block: est. Kling COGS, length add-on, credits retail implied USD, disclaimer.
 * Use with JSON from GET /app/admin/ai/runs/{id} (metadata.cost_estimate).
 */
export function StudioRunCostBreakdown({ runDetails }) {
    if (!isStudioAnimationRun(runDetails)) {
        return <p className="text-sm text-gray-900">${formatUsd6(runDetails.estimated_cost)}</p>
    }

    return (
        <div className="space-y-2">
            <p className="text-sm text-gray-900">
                <span className="font-semibold">${formatUsd6(runDetails.estimated_cost)}</span> est. provider COGS
                (total; includes length add-on when output exceeds base window)
            </p>
            {runDetails.metadata?.cost_estimate?.components && (
                <ul className="list-inside list-disc text-xs text-gray-600 space-y-0.5">
                    <li>
                        Base COGS: $
                        {Number(runDetails.metadata.cost_estimate.components.base_cogs_usd ?? 0).toFixed(4)}
                    </li>
                    <li>
                        Length: {runDetails.metadata.cost_estimate.components.output_duration_seconds ?? '—'}s output
                        (first {runDetails.metadata.cost_estimate.components.base_covers_seconds ?? '—'}
                        s in base); +$
                        {Number(
                            runDetails.metadata.cost_estimate.components.per_extra_second_cogs_usd ?? 0,
                        ).toFixed(4)}
                        /extra s × {runDetails.metadata.cost_estimate.components.extra_seconds ?? 0} extra s
                    </li>
                    <li>
                        Credits charged: {runDetails.metadata.cost_estimate.components.credits_charged ?? '—'}
                    </li>
                </ul>
            )}
            {runDetails.metadata?.cost_estimate?.credits_retail_list_usd != null && (
                <p className="text-xs text-gray-700">
                    Implied retail (credits × list $/credit): ~$
                    {Number(runDetails.metadata.cost_estimate.credits_retail_list_usd).toFixed(4)}{' '}
                    <span className="text-gray-500">(STUDIO_ANIMATION_LIST_USD_PER_CREDIT; not COGS)</span>
                </p>
            )}
            <p className="text-xs text-amber-900 bg-amber-50 border border-amber-100 rounded-md px-2 py-1.5">
                {runDetails.metadata?.cost_estimate?.disclaimer ||
                    'This is an internal estimate, not a Kling invoice line.'}
            </p>
        </div>
    )
}

export function StudioRunTokensNotApplicable() {
    return (
        <p className="text-sm text-gray-600">
            Not applicable — Kling image-to-video is not billed as LLM prompt/completion tokens. Plan credits and
            COGS estimate are used instead.
        </p>
    )
}
