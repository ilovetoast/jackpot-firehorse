import { router } from '@inertiajs/react'
import AdminAiCenterPage from '../../../Components/Admin/AdminAiCenterPage'
import { ChatBubbleLeftRightIcon, ExclamationTriangleIcon, SparklesIcon } from '@heroicons/react/24/outline'

/**
 * @param {object} props
 * @param {number} props.days
 * @param {Record<string, number>} props.totals
 * @param {number} props.total_cost_estimate
 * @param {Record<string, unknown>[]} props.recent
 * @param {Record<string, unknown>[]} props.no_match_sample
 * @param {Record<string, unknown>[]} props.failures
 * @param {{ key: string, count: number }[]} props.top_matched_actions
 * @param {{ question: string, count: number }[]} props.unanswered_patterns
 */
export default function HelpDiagnostics({
    days = 30,
    totals = {},
    total_cost_estimate = 0,
    recent = [],
    no_match_sample = [],
    failures = [],
    top_matched_actions = [],
    unanswered_patterns = [],
}) {
    const setDays = (d) => {
        router.get('/app/admin/ai/help-diagnostics', { days: d }, { preserveState: true, preserveScroll: true })
    }

    const statCards = [
        { label: 'Total asks', value: totals.asks ?? 0 },
        { label: 'AI answers', value: totals.ai_success ?? 0 },
        { label: 'No strong match', value: totals.no_strong_match ?? 0 },
        { label: 'AI call failed', value: totals.ai_failed ?? 0 },
        { label: 'Tenant AI off', value: totals.ai_disabled ?? 0 },
        { label: 'Feature off', value: totals.feature_disabled ?? 0 },
        { label: 'Helpful votes', value: totals.feedback_helpful ?? 0 },
        { label: 'Not helpful', value: totals.feedback_not_helpful ?? 0 },
    ]

    return (
        <AdminAiCenterPage
            breadcrumbs={[
                { label: 'Admin', href: '/app/admin' },
                { label: 'AI Control Center', href: '/app/admin/ai' },
                { label: 'Help AI' },
            ]}
            title="Help AI diagnostics"
            description="Questions users asked in the in-app help panel, match quality, AI failures, and feedback. Use this to improve config/help_actions.md topics — answers are grounded only in retrieved help actions."
            technicalNote={
                <div className="mt-2 flex flex-wrap items-center gap-3 text-xs text-slate-500">
                    <span>
                        Window:{' '}
                        <select
                            value={String(days)}
                            onChange={(e) => setDays(Number(e.target.value))}
                            className="rounded border border-slate-300 bg-white px-2 py-1 text-slate-800"
                        >
                            <option value="7">7 days</option>
                            <option value="30">30 days</option>
                            <option value="90">90 days</option>
                        </select>
                    </span>
                    <span>
                        Estimated LLM cost (successful AI rows):{' '}
                        <span className="font-mono font-medium text-slate-800">${Number(total_cost_estimate).toFixed(6)}</span>
                    </span>
                </div>
            }
        >
            <div className="mb-8 grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-4">
                {statCards.map((c) => (
                    <div key={c.label} className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                        <p className="text-xs font-medium uppercase tracking-wide text-slate-500">{c.label}</p>
                        <p className="mt-1 text-2xl font-semibold text-slate-900">{c.value}</p>
                    </div>
                ))}
            </div>

            <div className="mb-10 grid grid-cols-1 gap-8 lg:grid-cols-2">
                <section className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 className="flex items-center gap-2 text-lg font-semibold text-slate-900">
                        <SparklesIcon className="h-5 w-5 text-violet-600" aria-hidden />
                        Top matched action keys
                    </h2>
                    <p className="mt-1 text-sm text-slate-600">From retrieval before scoring — useful demand signal for docs.</p>
                    {top_matched_actions.length === 0 ? (
                        <p className="mt-4 text-sm text-slate-500">No data in this window.</p>
                    ) : (
                        <ul className="mt-4 divide-y divide-slate-100">
                            {top_matched_actions.map((row) => (
                                <li key={row.key} className="flex justify-between gap-2 py-2 text-sm">
                                    <code className="break-all text-slate-800">{row.key}</code>
                                    <span className="shrink-0 font-medium text-slate-600">{row.count}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <section className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 className="flex items-center gap-2 text-lg font-semibold text-slate-900">
                        <ChatBubbleLeftRightIcon className="h-5 w-5 text-slate-600" aria-hidden />
                        Repeated unanswered questions
                    </h2>
                    <p className="mt-1 text-sm text-slate-600">
                        Exact duplicate text among no strong match and AI failure rows — candidates for new help_actions entries.
                    </p>
                    {unanswered_patterns.length === 0 ? (
                        <p className="mt-4 text-sm text-slate-500">No repeats yet.</p>
                    ) : (
                        <ul className="mt-4 space-y-3">
                            {unanswered_patterns.map((row, i) => (
                                <li key={i} className="rounded-lg bg-slate-50 p-3 text-sm">
                                    <p className="font-medium text-slate-900">{row.count} repeats</p>
                                    <p className="mt-1 text-slate-700">{row.question}</p>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>

            <section className="mb-10 rounded-xl border border-amber-200 bg-amber-50/40 p-5 shadow-sm">
                <h2 className="flex items-center gap-2 text-lg font-semibold text-amber-950">
                    <ExclamationTriangleIcon className="h-5 w-5" aria-hidden />
                    No strong match (sample)
                </h2>
                <p className="mt-1 text-sm text-amber-950/80">Below threshold score — improve aliases/tags in help_actions config.</p>
                {no_match_sample.length === 0 ? (
                    <p className="mt-4 text-sm text-amber-900/70">None in this window.</p>
                ) : (
                    <ul className="mt-4 space-y-3">
                        {no_match_sample.map((row) => (
                            <li key={row.id} className="rounded-lg border border-amber-100 bg-white p-3 text-sm">
                                <p className="text-xs text-slate-500">
                                    {row.created_at} · {row.tenant_name ?? '—'} · score {row.best_score}
                                </p>
                                <p className="mt-1 text-slate-900">{row.question}</p>
                                {Array.isArray(row.matched_action_keys) && row.matched_action_keys.length > 0 && (
                                    <p className="mt-1 text-xs text-slate-600">
                                        Weak keys: {row.matched_action_keys.join(', ')}
                                    </p>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            <section className="mb-10 rounded-xl border border-red-100 bg-red-50/30 p-5 shadow-sm">
                <h2 className="text-lg font-semibold text-red-950">AI failures (recent)</h2>
                <p className="mt-1 text-sm text-red-950/80">Provider or parse errors after a strong match — user saw the primary topic fallback.</p>
                {failures.length === 0 ? (
                    <p className="mt-4 text-sm text-slate-600">None in this window.</p>
                ) : (
                    <ul className="mt-4 space-y-2">
                        {failures.map((row) => (
                            <li key={row.id} className="rounded-lg border border-red-100 bg-white p-3 text-sm">
                                <p className="text-xs text-slate-500">
                                    {row.created_at} · {row.tenant_name ?? '—'}
                                </p>
                                <p className="mt-1 text-slate-900">{row.question}</p>
                                {row.recommended_action_key && (
                                    <p className="mt-1 text-xs text-slate-600">Fallback key: {row.recommended_action_key}</p>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            <section className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 className="text-lg font-semibold text-slate-900">Recent asks</h2>
                <p className="mt-1 text-sm text-slate-600">Newest first (capped).</p>
                <div className="mt-4 overflow-x-auto">
                    <table className="min-w-full text-left text-sm">
                        <thead>
                            <tr className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                                <th className="py-2 pr-4">When</th>
                                <th className="py-2 pr-4">Tenant</th>
                                <th className="py-2 pr-4">Kind</th>
                                <th className="py-2 pr-4">Score</th>
                                <th className="py-2 pr-4">Feedback</th>
                                <th className="py-2">Question</th>
                            </tr>
                        </thead>
                        <tbody>
                            {recent.map((row) => (
                                <tr key={row.id} className="border-b border-slate-100 align-top">
                                    <td className="max-w-[8rem] py-2 pr-4 text-xs text-slate-500">{row.created_at}</td>
                                    <td className="py-2 pr-4 text-xs text-slate-700">{row.tenant?.name ?? '—'}</td>
                                    <td className="py-2 pr-4">
                                        <code className="text-xs text-violet-800">{row.response_kind}</code>
                                    </td>
                                    <td className="py-2 pr-4">{row.best_score}</td>
                                    <td className="py-2 pr-4 text-xs">{row.feedback_rating ?? '—'}</td>
                                    <td className="py-2 text-slate-800">{row.question}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    {recent.length === 0 && <p className="mt-4 text-sm text-slate-500">No rows in this window.</p>}
                </div>
            </section>
        </AdminAiCenterPage>
    )
}
