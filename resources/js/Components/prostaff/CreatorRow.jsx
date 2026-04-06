import { ArrowTopRightOnSquareIcon } from '@heroicons/react/24/outline'

function statusBadge(status) {
    const s = String(status || '').toLowerCase()
    const map = {
        behind: 'border-rose-400/40 bg-rose-500/15 text-rose-100',
        on_track: 'border-amber-400/35 bg-amber-500/12 text-amber-100',
        complete: 'border-emerald-400/40 bg-emerald-500/15 text-emerald-100',
    }
    const cls = map[s] || 'border-white/15 bg-white/10 text-white/70'
    const label = s === 'on_track' ? 'On track' : s ? s.replace(/_/g, ' ') : '—'
    return (
        <span
            className={`inline-flex rounded-full border px-2.5 py-0.5 text-xs font-medium capitalize ${cls}`}
        >
            {label}
        </span>
    )
}

/**
 * @param {{
 *   row: Record<string, unknown>,
 *   onRowClick: (userId: number) => void,
 * }} props
 */
export default function CreatorRow({ row, onRowClick }) {
    const userId = row.user_id
    const pct = Number(row.completion_percentage)
    const tier = row.tier != null && row.tier !== '' ? String(row.tier) : '—'

    return (
        <tr
            className="cursor-pointer border-b border-white/[0.06] transition hover:bg-white/[0.04]"
            onClick={() => onRowClick(userId)}
        >
            <td className="whitespace-nowrap px-4 py-3 text-sm font-medium text-white/95">{row.name}</td>
            <td className="whitespace-nowrap px-4 py-3 text-sm text-white/60">{tier}</td>
            <td className="whitespace-nowrap px-4 py-3 text-sm tabular-nums text-white/80">
                {row.target_uploads != null ? row.target_uploads : '—'}
            </td>
            <td className="whitespace-nowrap px-4 py-3 text-sm tabular-nums text-white/80">{row.actual_uploads}</td>
            <td className="whitespace-nowrap px-4 py-3 text-sm tabular-nums text-white/80">
                {Number.isFinite(pct) ? `${pct.toFixed(1)}%` : '—'}
            </td>
            <td className="whitespace-nowrap px-4 py-3 text-sm tabular-nums text-white/70">{row.rank}</td>
            <td className="whitespace-nowrap px-4 py-3">{statusBadge(row.status)}</td>
            <td className="whitespace-nowrap px-4 py-3 text-right">
                <button
                    type="button"
                    onClick={(e) => {
                        e.stopPropagation()
                        onRowClick(userId)
                    }}
                    className="inline-flex items-center gap-1 rounded-lg border border-white/15 bg-white/[0.06] px-2.5 py-1.5 text-xs font-medium text-white/85 backdrop-blur-sm transition hover:border-white/25 hover:bg-white/10"
                >
                    DAM
                    <ArrowTopRightOnSquareIcon className="h-3.5 w-3.5 opacity-70" />
                </button>
            </td>
        </tr>
    )
}
