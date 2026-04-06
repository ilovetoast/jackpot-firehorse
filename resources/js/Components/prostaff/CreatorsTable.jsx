import CreatorRow from './CreatorRow'

function SkeletonRows() {
    return (
        <>
            {Array.from({ length: 6 }).map((_, i) => (
                <tr key={i} className="border-b border-white/[0.06]">
                    <td colSpan={8} className="px-4 py-3">
                        <div className="h-4 w-full max-w-md animate-pulse rounded bg-white/10" />
                    </td>
                </tr>
            ))}
        </>
    )
}

/**
 * @param {{
 *   creators: Array<Record<string, unknown>>,
 *   loading: boolean,
 *   onRowClick: (userId: number) => void,
 *   emptyCta?: React.ReactNode,
 *   emptyReadOnlyHint?: string | null,
 * }} props
 */
export default function CreatorsTable({ creators, loading, onRowClick, emptyCta, emptyReadOnlyHint = null }) {
    if (!loading && creators.length === 0) {
        const emptyMessage =
            emptyReadOnlyHint || 'Add your first creator to start tracking performance'
        return (
            <div className="flex flex-col items-center justify-center rounded-2xl border border-dashed border-white/15 bg-white/[0.03] px-6 py-16 text-center backdrop-blur-md">
                <p className="max-w-md text-sm text-white/65">
                    {emptyMessage}
                </p>
                {emptyCta ? <div className="mt-6">{emptyCta}</div> : null}
            </div>
        )
    }

    return (
        <div className="overflow-hidden rounded-2xl border border-white/10 bg-white/[0.04] shadow-[inset_0_1px_0_0_rgba(255,255,255,0.06)] backdrop-blur-xl">
            <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-white/[0.06] text-left">
                    <thead>
                        <tr className="text-xs font-semibold uppercase tracking-wider text-white/45">
                            <th className="whitespace-nowrap px-4 py-3">Name</th>
                            <th className="whitespace-nowrap px-4 py-3">Tier</th>
                            <th className="whitespace-nowrap px-4 py-3">Target</th>
                            <th className="whitespace-nowrap px-4 py-3">Actual</th>
                            <th className="whitespace-nowrap px-4 py-3">%</th>
                            <th className="whitespace-nowrap px-4 py-3">Rank</th>
                            <th className="whitespace-nowrap px-4 py-3">Status</th>
                            <th className="whitespace-nowrap px-4 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-white/[0.04]">
                        {loading ? (
                            <SkeletonRows />
                        ) : (
                            creators.map((row) => (
                                <CreatorRow key={row.user_id} row={row} onRowClick={onRowClick} />
                            ))
                        )}
                    </tbody>
                </table>
            </div>
        </div>
    )
}
