import { ArrowTopRightOnSquareIcon } from '@heroicons/react/24/outline'

function statusBadge(status) {
    const s = String(status || '').toLowerCase()
    const map = {
        behind: 'border-rose-400/40 bg-rose-500/15 text-rose-100',
        on_track: 'border-amber-400/35 bg-amber-500/12 text-amber-100',
        complete: 'border-emerald-400/40 bg-emerald-500/15 text-emerald-100',
    }
    const cls = map[s] || 'border-white/15 bg-white/10 text-white/70'
    const labels = {
        behind: '🔴 Behind Pace',
        on_track: '🟡 On Track',
        complete: '🟢 Complete',
    }
    const label = labels[s] || '—'
    return (
        <span className={`inline-flex rounded-full border px-2.5 py-0.5 text-xs font-medium ${cls}`}>{label}</span>
    )
}

function formatLastActivity(iso) {
    if (!iso) return '—'
    try {
        const d = new Date(iso)
        if (Number.isNaN(d.getTime())) return '—'
        return d.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
    } catch {
        return '—'
    }
}

/**
 * @param {{
 *   row: Record<string, unknown>,
 *   onRowClick: (userId: number) => void,
 *   onDamClick?: (userId: number) => void,
 * }} props
 */
export default function CreatorRow({ row, onRowClick, onDamClick }) {
    const userId = row.user_id != null ? Number(row.user_id) : NaN
    const pct = Number(row.completion_percentage)
    const rowClickable = Number.isFinite(userId) && userId > 0

    return (
        <tr
            className={`border-b border-white/[0.06] transition ${rowClickable ? 'cursor-pointer hover:bg-white/[0.04]' : 'cursor-default opacity-80'}`}
            onClick={() => {
                if (rowClickable) onRowClick(userId)
            }}
        >
            <td className="whitespace-nowrap px-4 py-3 text-sm font-medium text-white/95">{row.name}</td>
            <td className="whitespace-nowrap px-4 py-3 text-sm tabular-nums text-white/80">
                {row.target_uploads != null ? row.target_uploads : '—'}
            </td>
            <td className="whitespace-nowrap px-4 py-3 text-sm tabular-nums text-white/80">{row.actual_uploads}</td>
            <td className="whitespace-nowrap px-4 py-3 text-sm tabular-nums text-white/80">
                {Number.isFinite(pct) ? `${pct.toFixed(1)}%` : '—'}
            </td>
            <td className="whitespace-nowrap px-4 py-3 text-sm tabular-nums text-white/70">{row.rank}</td>
            <td className="whitespace-nowrap px-4 py-3">{statusBadge(row.status)}</td>
            <td className="whitespace-nowrap px-4 py-3 text-sm text-white/65">{formatLastActivity(row.last_upload_at)}</td>
            <td className="whitespace-nowrap px-4 py-3 text-right">
                <button
                    type="button"
                    onClick={(e) => {
                        e.stopPropagation()
                        if (!rowClickable) return
                        const openDam = onDamClick ?? onRowClick
                        openDam(userId)
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
