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
 *   onDamClick?: (userId: number) => void,
 *   emptyCta?: React.ReactNode,
 *   emptyReadOnlyHint?: string | null,
 *   emptySuppressed?: boolean,
 * }} props
 */
export default function CreatorsTable({
    creators,
    loading,
    onRowClick,
    onDamClick,
    emptyCta,
    emptyReadOnlyHint = null,
    emptySuppressed = false,
}) {
    const readOnlyBody =
        emptyReadOnlyHint ||
        'Start tracking performance by adding your first creator. Performance data will appear here once creators join and upload.'

    if (!loading && creators.length === 0 && emptySuppressed) {
        return (
            <p className="rounded-2xl border border-white/[0.08] bg-white/[0.03] px-6 py-10 text-center text-sm text-white/50">
                Active creators will appear in this table after people accept their invitations.
            </p>
        )
    }

    if (!loading && creators.length === 0) {
        return (
            <div className="overflow-hidden rounded-2xl border border-white/10 bg-white/[0.04] px-6 py-16 text-center shadow-[inset_0_1px_0_0_rgba(255,255,255,0.06)] backdrop-blur-xl sm:px-10">
                <h2 className="text-lg font-semibold tracking-tight text-white">No creators yet</h2>
                <p className="mx-auto mt-3 max-w-md text-sm leading-relaxed text-white/55">
                    {emptyCta ? (
                        <>
                            Start tracking performance by adding your first creator. Invite teammates or workspace members
                            to show up here with targets and uploads.
                        </>
                    ) : (
                        readOnlyBody
                    )}
                </p>
                {emptyCta ? <div className="mt-8 flex justify-center">{emptyCta}</div> : null}
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
                            <th className="whitespace-nowrap px-4 py-3">Target</th>
                            <th className="whitespace-nowrap px-4 py-3">Actual</th>
                            <th className="whitespace-nowrap px-4 py-3">Completion %</th>
                            <th className="whitespace-nowrap px-4 py-3">Rank</th>
                            <th className="whitespace-nowrap px-4 py-3">Status</th>
                            <th className="whitespace-nowrap px-4 py-3">Last activity</th>
                            <th className="whitespace-nowrap px-4 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-white/[0.04]">
                        {loading ? (
                            <SkeletonRows />
                        ) : (
                            creators.map((row) => (
                                <CreatorRow key={row.user_id} row={row} onRowClick={onRowClick} onDamClick={onDamClick} />
                            ))
                        )}
                    </tbody>
                </table>
            </div>
        </div>
    )
}
