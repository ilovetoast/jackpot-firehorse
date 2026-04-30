/**
 * Scrollable table region: horizontal overflow stays inside the card.
 * Use for admin lists; pair long cells with title= and row-level Details drawers.
 */
export function AdminTableScroll({ children, className = '' }) {
    return <div className={`overflow-x-auto ${className}`.trim()}>{children}</div>
}

/**
 * Card wrapper + optional header + table body slot.
 */
export function AdminDataTableCard({ title, description, toolbar, empty, children }) {
    return (
        <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm ring-1 ring-slate-200">
            {(title || description || toolbar) && (
                <div className="flex flex-col gap-3 border-b border-slate-200 px-4 py-4 sm:flex-row sm:items-start sm:justify-between sm:px-6">
                    <div className="min-w-0">
                        {title ? <h2 className="text-lg font-semibold text-slate-900">{title}</h2> : null}
                        {description ? <p className="mt-1 text-sm text-slate-500">{description}</p> : null}
                    </div>
                    {toolbar ? <div className="flex shrink-0 flex-wrap items-center gap-2">{toolbar}</div> : null}
                </div>
            )}
            <AdminTableScroll>
                <div className="inline-block min-w-full align-middle">{children}</div>
            </AdminTableScroll>
            {empty ? <p className="border-t border-slate-100 px-4 py-8 text-center text-sm text-slate-500 sm:px-6">{empty}</p> : null}
        </div>
    )
}

/** Truncated cell with ellipsis (single line). */
export function AdminTruncatedCell({ children, className = '', title }) {
    return (
        <span title={title} className={`block max-w-full truncate ${className}`.trim()}>
            {children}
        </span>
    )
}
