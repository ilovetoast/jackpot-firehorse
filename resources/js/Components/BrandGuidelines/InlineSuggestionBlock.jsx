/**
 * Inline suggestion block — shows contextual recommendations next to fields.
 * Used when research/suggestions exist for the current step.
 */
export default function InlineSuggestionBlock({
    title,
    items = [],
    onApply,
    onDismiss,
    dismissed,
    compact = false,
}) {
    if (!items?.length) return null

    const displayItems = Array.isArray(items) ? items : [items]
    const label = title || 'Recommended'

    return (
        <div className={`rounded-lg border border-indigo-500/30 bg-indigo-500/10 p-3 ${compact ? 'mt-2' : 'mt-3 mb-2'}`}>
            <p className="text-xs font-medium text-indigo-200 mb-1">{label}</p>
            <div className="flex flex-wrap items-center gap-2">
                {displayItems.map((item, i) => {
                    const str = typeof item === 'string' ? item : item?.label || item?.value || String(item)
                    if (dismissed?.includes(str)) return null
                    return (
                        <span
                            key={i}
                            className="inline-flex items-center gap-1 px-2 py-1 rounded-md bg-white/10 text-sm text-white/90"
                        >
                            {str}
                            {onApply && (
                                <button
                                    type="button"
                                    onClick={() => onApply(str)}
                                    className="text-indigo-300 hover:text-indigo-200 text-xs"
                                >
                                    Use
                                </button>
                            )}
                        </span>
                    )
                })}
                {onDismiss && (
                    <button
                        type="button"
                        onClick={onDismiss}
                        className="text-xs text-white/50 hover:text-white/70"
                    >
                        Dismiss
                    </button>
                )}
            </div>
        </div>
    )
}
