/**
 * Manager-only: pending brand invites that will become creators on accept.
 *
 * @param {{ invites: Array<{ invitation_id: number, email: string, sent_at?: string|null, target_uploads?: number|null, period_type?: string }> }} props
 */
export default function PendingCreatorInvitesTable({ invites }) {
    if (!invites?.length) return null

    const formatSent = (iso) => {
        if (!iso) return '—'
        try {
            const d = new Date(iso)
            if (Number.isNaN(d.getTime())) return '—'
            return d.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
        } catch {
            return '—'
        }
    }

    return (
        <div className="overflow-hidden rounded-2xl border border-violet-400/20 bg-violet-500/[0.08] shadow-[inset_0_1px_0_0_rgba(255,255,255,0.06)] backdrop-blur-xl">
            <div className="border-b border-white/10 px-4 py-3 sm:px-5">
                <h2 className="text-sm font-semibold text-white">Pending invitations</h2>
                <p className="mt-0.5 text-xs text-white/50">
                    These people have been invited and will become creators when they accept.
                </p>
            </div>
            <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-white/[0.06] text-left">
                    <thead>
                        <tr className="text-xs font-semibold uppercase tracking-wider text-white/45">
                            <th className="whitespace-nowrap px-4 py-3">Email</th>
                            <th className="whitespace-nowrap px-4 py-3">Target</th>
                            <th className="whitespace-nowrap px-4 py-3">Period</th>
                            <th className="whitespace-nowrap px-4 py-3">Sent</th>
                            <th className="whitespace-nowrap px-4 py-3">Status</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-white/[0.04]">
                        {invites.map((inv) => (
                            <tr key={inv.invitation_id} className="border-b border-white/[0.06]">
                                <td className="whitespace-nowrap px-4 py-3 text-sm font-medium text-white/95">{inv.email}</td>
                                <td className="whitespace-nowrap px-4 py-3 text-sm tabular-nums text-white/75">
                                    {inv.target_uploads != null ? inv.target_uploads : '—'}
                                </td>
                                <td className="whitespace-nowrap px-4 py-3 text-sm text-white/65 capitalize">{inv.period_type || '—'}</td>
                                <td className="whitespace-nowrap px-4 py-3 text-sm text-white/60">{formatSent(inv.sent_at)}</td>
                                <td className="whitespace-nowrap px-4 py-3">
                                    <span className="inline-flex rounded-full border border-violet-400/35 bg-violet-500/20 px-2.5 py-0.5 text-xs font-medium text-violet-100">
                                        Awaiting accept
                                    </span>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    )
}
