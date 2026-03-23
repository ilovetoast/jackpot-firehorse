import { Link } from '@inertiajs/react'
import { useState } from 'react'
import axios from 'axios'
import JsonView from '@uiw/react-json-view'
import AppHead from '../../../Components/AppHead'
import AppNav from '../../../Components/AppNav'
import AppFooter from '../../../Components/AppFooter'
import { ArrowPathIcon } from '@heroicons/react/24/outline'

function Section({ title, children }) {
    return (
        <section className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <h2 className="text-sm font-semibold uppercase tracking-wide text-slate-500 mb-3">{title}</h2>
            {children}
        </section>
    )
}

function kv(label, value) {
    return (
        <div className="grid grid-cols-1 sm:grid-cols-3 gap-1 text-sm py-1 border-b border-slate-100 last:border-0">
            <span className="text-slate-500">{label}</span>
            <span className="sm:col-span-2 text-slate-900 break-all">{value ?? '—'}</span>
        </div>
    )
}

function alignmentStateLabel(alignmentState, level) {
    const a = (alignmentState || '').toLowerCase()
    if (a === 'on_brand') return 'On brand'
    if (a === 'partial_alignment') return 'Partial alignment'
    if (a === 'off_brand') return 'Diverges from guidelines'
    if (a === 'insufficient_evidence') return 'Not enough data'
    const l = (level || '').toLowerCase()
    if (l === 'low') return 'Diverges from guidelines'
    if (l === 'medium') return 'Somewhat on brand'
    if (l === 'high') return 'On brand'
    return level || '—'
}

function formatConf(c) {
    return typeof c === 'number' && !Number.isNaN(c) ? c.toFixed(2) : String(c ?? '—')
}

function PathRow({ status, title, text }) {
    const icon =
        status === 'ok' ? (
            <span className="text-emerald-600" aria-hidden>
                ✓
            </span>
        ) : status === 'warn' ? (
            <span className="text-amber-600" aria-hidden>
                ⚠
            </span>
        ) : (
            <span className="text-slate-400" aria-hidden>
                ✖
            </span>
        )
    return (
        <div className="flex gap-3 text-sm py-1.5 border-b border-slate-100 last:border-0">
            <span className="w-5 shrink-0 pt-0.5 font-mono">{icon}</span>
            <div>
                <div className="font-medium text-slate-800">{title}</div>
                <div className="text-slate-600 text-xs mt-0.5">{text}</div>
            </div>
        </div>
    )
}

export default function BrandIntelligenceShow({
    auth,
    asset,
    metadata,
    score,
    dna_warnings: dnaWarnings,
    engine_version: engineVersion,
    scoring_path: scoringPath,
    ai_explanation: aiExplanation,
    reference_top_matches: referenceTopMatches,
}) {
    const [simResult, setSimResult] = useState(null)
    const [simError, setSimError] = useState(null)
    const [simLoading, setSimLoading] = useState(false)

    const breakdown = score?.breakdown_json ?? {}
    const refs = breakdown.reference_similarity ?? {}
    const signals = breakdown.signals ?? {}
    const recs = breakdown.recommendations ?? []
    const ebiSignals = breakdown.signal_breakdown ?? {}
    const tierUsage = breakdown.reference_tier_usage ?? {}

    const runSimulate = async () => {
        setSimError(null)
        setSimLoading(true)
        try {
            const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
            const res = await axios.post(`/app/admin/brand-intelligence/assets/${asset.id}/simulate`, null, {
                headers: {
                    'X-CSRF-TOKEN': token,
                    Accept: 'application/json',
                },
            })
            setSimResult({
                payload: res.data?.payload ?? null,
                delta: res.data?.delta ?? null,
            })
        } catch (e) {
            setSimError(e.response?.data?.message || e.message || 'Simulation failed')
            setSimResult(null)
        } finally {
            setSimLoading(false)
        }
    }

    const simPayload = simResult?.payload
    const simDelta = simResult?.delta

    return (
        <div className="min-h-full">
            <AppHead title={`EBI: ${asset.name}`} suffix="Admin" />
            <AppNav brand={auth?.activeBrand} tenant={null} />
            <main className="bg-slate-50">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
                    <div className="mb-6">
                        <Link href="/app/admin/brand-intelligence" className="text-sm font-medium text-slate-500 hover:text-slate-700">
                            ← Brand Intelligence
                        </Link>
                        <div className="mt-3 flex flex-wrap items-center gap-3">
                            <h1 className="text-2xl font-bold text-slate-900">Brand Intelligence — Asset</h1>
                            {engineVersion && (
                                <span
                                    className="inline-flex items-center rounded-full bg-slate-800 px-3 py-1 text-xs font-mono text-white"
                                    title="Code engine version (compare with stored row below when debugging regressions)"
                                >
                                    Engine: {engineVersion}
                                </span>
                            )}
                        </div>
                        <p className="mt-2 text-sm text-slate-600 font-mono">{asset.id}</p>
                        <p className="text-sm text-slate-800">{asset.name}</p>
                        {asset.deleted_at && (
                            <span className="mt-2 inline-block rounded bg-amber-100 text-amber-900 text-xs px-2 py-1">Deleted</span>
                        )}
                    </div>

                    {dnaWarnings?.length > 0 && (
                        <div className="mb-6 rounded-lg border border-amber-200 bg-amber-50 p-4">
                            <h3 className="text-sm font-semibold text-amber-900 mb-2">DNA warnings</h3>
                            <ul className="list-disc list-inside text-sm text-amber-900 space-y-1">
                                {dnaWarnings.map((w, i) => (
                                    <li key={i}>{w}</li>
                                ))}
                            </ul>
                        </div>
                    )}

                    {scoringPath && (
                        <div className="mb-6">
                            <Section title="Scoring path">
                                <p className="text-xs text-slate-500 mb-3">How this score was produced (aligned with engine gates).</p>
                                <PathRow {...scoringPath.signals} />
                                <PathRow {...scoringPath.reference_similarity} />
                                <PathRow {...scoringPath.ai} />
                            </Section>
                        </div>
                    )}

                    {!score && (
                        <p className="text-sm text-slate-600 mb-6">No stored Brand Intelligence score for this asset.</p>
                    )}

                    {score ? (
                        <div className="space-y-6 mb-6">
                            <Section title="A. Summary">
                                {kv(
                                    'Alignment state',
                                    `${alignmentStateLabel(breakdown.alignment_state, score.level)} (${breakdown.alignment_state ?? '—'})`
                                )}
                                {kv('Level (legacy)', `${alignmentStateLabel(breakdown.alignment_state, score.level)} (${score.level})`)}
                                {kv('Signal count', breakdown.signal_count != null ? String(breakdown.signal_count) : '—')}
                                {kv('Confidence', formatConf(score.confidence))}
                                {kv('Stored engine version', score.engine_version ?? '—')}
                                {score.engine_version && engineVersion && score.engine_version !== engineVersion && (
                                    <p className="text-xs text-amber-800 bg-amber-50 border border-amber-100 rounded px-2 py-1.5 mt-2">
                                        Stored row differs from current code engine — re-score to refresh.
                                    </p>
                                )}
                                {kv('Overall score (raw)', score.overall_score)}
                                {kv('AI used (stored)', String(score.ai_used))}
                                {kv('Updated', score.updated_at ? new Date(score.updated_at).toLocaleString() : '—')}
                            </Section>

                            <Section title="B. Signals">
                                {kv('EBI gate: has_logo', String(ebiSignals.has_logo ?? '—'))}
                                {kv('EBI gate: has_brand_colors', String(ebiSignals.has_brand_colors ?? '—'))}
                                {kv('EBI gate: has_typography', String(ebiSignals.has_typography ?? '—'))}
                                {kv('EBI gate: has_reference_similarity', String(ebiSignals.has_reference_similarity ?? '—'))}
                                {kv('has_text', String(signals.has_text ?? '—'))}
                                {kv('has_typography', String(signals.has_typography ?? '—'))}
                                {kv('has_visual', String(signals.has_visual ?? '—'))}
                            </Section>

                            <Section title="C. Reference similarity">
                                {referenceTopMatches?.length > 0 && (
                                    <div className="mb-4">
                                        <p className="text-xs font-medium text-slate-600 mb-2">Top matches (by embedding cosine)</p>
                                        <div className="flex flex-wrap gap-3">
                                            {referenceTopMatches.map((m) => (
                                                <div key={m.asset_id} className="text-center w-[88px]">
                                                    {m.thumbnail_url ? (
                                                        <img
                                                            src={m.thumbnail_url}
                                                            alt=""
                                                            className="w-20 h-20 object-cover rounded border border-slate-200 mx-auto"
                                                        />
                                                    ) : (
                                                        <div className="w-20 h-20 rounded border border-dashed border-slate-200 bg-slate-50 mx-auto text-[10px] text-slate-400 flex items-center justify-center p-1">
                                                            No thumb
                                                        </div>
                                                    )}
                                                    <div className="text-[10px] text-slate-500 mt-1 font-mono">{m.score_int}%</div>
                                                    <div className="text-[10px] text-slate-400 font-mono">cos {m.cosine}</div>
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                )}
                                {kv('used', String(refs.used ?? '—'))}
                                {kv('score', refs.score != null ? String(refs.score) : '—')}
                                {kv('confidence', refs.confidence != null ? String(refs.confidence) : '—')}
                                {kv('normalized', refs.normalized != null ? String(refs.normalized) : '—')}
                                {kv('reference_count', refs.reference_count != null ? String(refs.reference_count) : '—')}
                                {kv('style_only', String(refs.style_only ?? '—'))}
                                {kv('weighted', String(refs.weighted ?? '—'))}
                                {kv('tier usage (system)', tierUsage.system != null ? String(tierUsage.system) : '—')}
                                {kv('tier usage (promoted)', tierUsage.promoted != null ? String(tierUsage.promoted) : '—')}
                                {kv('tier usage (guideline)', tierUsage.guideline != null ? String(tierUsage.guideline) : '—')}
                            </Section>

                            <Section title="D. AI insight (stored)">
                                {aiExplanation && (
                                    <div className="mb-4 rounded-md bg-slate-50 border border-slate-200 px-3 py-2">
                                        <p className="text-xs font-semibold text-slate-700 mb-1">{aiExplanation.heading}</p>
                                        {aiExplanation.lines?.length ? (
                                            <ul className="list-disc list-inside text-sm text-slate-800 space-y-0.5">
                                                {aiExplanation.lines.map((line, i) => (
                                                    <li key={i}>{line}</li>
                                                ))}
                                            </ul>
                                        ) : (
                                            <p className="text-sm text-slate-500">—</p>
                                        )}
                                    </div>
                                )}
                                {breakdown.ai_insight?.text ? (
                                    <pre className="text-sm text-slate-800 whitespace-pre-wrap">{breakdown.ai_insight.text}</pre>
                                ) : (
                                    <p className="text-sm text-slate-500">None</p>
                                )}
                                {breakdown.ai_insight?.confidence != null && (
                                    <p className="text-xs text-slate-500 mt-1">
                                        Model confidence: {breakdown.ai_insight.confidence}
                                    </p>
                                )}
                            </Section>

                            <Section title="E. Recommendations">
                                {recs.length === 0 ? (
                                    <p className="text-sm text-slate-500">None</p>
                                ) : (
                                    <ul className="list-disc list-inside text-sm text-slate-800 space-y-1">
                                        {recs.map((r, i) => (
                                            <li key={i}>{r}</li>
                                        ))}
                                    </ul>
                                )}
                            </Section>

                            {(breakdown.overall_summary ||
                                breakdown.creative_analysis ||
                                breakdown.copy_alignment ||
                                breakdown.ebi_ai_trace) && (
                                <Section title="F. Creative intelligence (parallel)">
                                    {kv('overall_summary', breakdown.overall_summary ?? '—')}
                                    {kv('visual_alignment (wrapper)', JSON.stringify(breakdown.visual_alignment ?? null))}
                                    {kv('visual_alignment_ai', JSON.stringify(breakdown.visual_alignment_ai ?? null))}
                                    {kv('copy_alignment', JSON.stringify(breakdown.copy_alignment ?? null))}
                                    {kv('context_analysis', JSON.stringify(breakdown.context_analysis ?? null))}
                                    {kv('dimension_weights', JSON.stringify(breakdown.dimension_weights ?? null))}
                                    {kv('brand_copy_conflict', String(breakdown.brand_copy_conflict ?? false))}
                                    {kv('ebi_ai_trace', JSON.stringify(breakdown.ebi_ai_trace ?? null))}
                                    <details className="mt-2">
                                        <summary className="cursor-pointer text-xs font-medium text-slate-600">
                                            creative_analysis (full)
                                        </summary>
                                        <div className="mt-2 text-xs overflow-auto max-h-64">
                                            <JsonView value={breakdown.creative_analysis ?? {}} collapsed={1} />
                                        </div>
                                    </details>
                                </Section>
                            )}

                            <details className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                                <summary className="cursor-pointer text-sm font-semibold text-slate-700">
                                    G. Raw breakdown JSON
                                </summary>
                                <div className="mt-3 text-xs overflow-auto max-h-[32rem]">
                                    <JsonView value={breakdown} collapsed={2} />
                                </div>
                            </details>

                            <details className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                                <summary className="cursor-pointer text-sm font-semibold text-slate-700">
                                    Asset metadata (JSON)
                                </summary>
                                <div className="mt-3 text-xs overflow-auto max-h-[32rem]">
                                    <JsonView value={metadata || {}} collapsed={2} />
                                </div>
                            </details>
                        </div>
                    ) : null}

                    {!score && (
                        <details className="mb-6 rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                            <summary className="cursor-pointer text-sm font-semibold text-slate-700">
                                Asset metadata (JSON)
                            </summary>
                            <div className="mt-3 text-xs overflow-auto max-h-[32rem]">
                                <JsonView value={metadata || {}} collapsed={2} />
                            </div>
                        </details>
                    )}

                    <div className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                        <h2 className="text-sm font-semibold uppercase tracking-wide text-slate-500 mb-3">Simulation (no save)</h2>
                        <p className="text-sm text-slate-600 mb-3">
                            Runs <code className="text-xs bg-slate-100 px-1 rounded">BrandIntelligenceEngine::scoreAsset($dryRun)</code> in-process.
                            Does not write to the database; skips AI usage logging.
                        </p>
                        <button
                            type="button"
                            onClick={runSimulate}
                            disabled={simLoading}
                            className="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                        >
                            <ArrowPathIcon className={`h-4 w-4 ${simLoading ? 'animate-spin' : ''}`} />
                            {simLoading ? 'Running…' : 'Re-run scoring (no save)'}
                        </button>
                        {simError && <p className="mt-3 text-sm text-red-600">{simError}</p>}
                        {simPayload && (
                            <>
                                {simDelta ? (
                                    <div className="mt-4 rounded-lg border border-emerald-200 bg-emerald-50/80 p-4">
                                        <h3 className="text-sm font-semibold text-emerald-900 mb-3">Delta (stored vs simulated)</h3>
                                        <div className="grid sm:grid-cols-2 gap-4 text-sm">
                                            <div className="rounded border border-emerald-100 bg-white p-3">
                                                <div className="text-xs font-medium text-slate-500 uppercase tracking-wide mb-2">Current (stored)</div>
                                                <div className="space-y-1 text-slate-800">
                                                    <div>
                                                        <span className="text-slate-500">Score label: </span>
                                                        {alignmentStateLabel(simDelta.current?.alignment_state, simDelta.current?.level)} (
                                                        {formatConf(simDelta.current?.confidence)})
                                                    </div>
                                                    <div className="text-xs text-slate-600 font-mono">
                                                        level={simDelta.current?.level} · conf={formatConf(simDelta.current?.confidence)}
                                                    </div>
                                                </div>
                                            </div>
                                            <div className="rounded border border-emerald-200 bg-white p-3">
                                                <div className="text-xs font-medium text-slate-500 uppercase tracking-wide mb-2">Simulated</div>
                                                <div className="space-y-1 text-slate-800">
                                                    <div>
                                                        <span className="text-slate-500">Score label: </span>
                                                        {alignmentStateLabel(simDelta.simulated?.alignment_state, simDelta.simulated?.level)} (
                                                        {formatConf(simDelta.simulated?.confidence)})
                                                    </div>
                                                    <div className="text-xs text-slate-600 font-mono">
                                                        level={simDelta.simulated?.level} · conf={formatConf(simDelta.simulated?.confidence)}
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        {simDelta.changes?.length > 0 && (
                                            <div className="mt-3 pt-3 border-t border-emerald-200">
                                                <div className="text-xs font-medium text-slate-600 mb-1">Changes</div>
                                                <ul className="list-disc list-inside text-sm text-emerald-900 space-y-0.5">
                                                    {simDelta.changes.map((c, i) => (
                                                        <li key={i}>{c}</li>
                                                    ))}
                                                </ul>
                                            </div>
                                        )}
                                    </div>
                                ) : (
                                    <p className="mt-4 text-sm text-amber-800 bg-amber-50 border border-amber-100 rounded px-3 py-2">
                                        No stored Brand Intelligence row to compare — run a full score first, or compare numbers in the raw JSON below.
                                    </p>
                                )}
                                <div className="mt-4 text-xs overflow-auto max-h-[40rem] border border-slate-100 rounded p-2 bg-slate-50">
                                    <JsonView value={simPayload} collapsed={2} />
                                </div>
                            </>
                        )}
                    </div>
                </div>
            </main>
            <AppFooter />
        </div>
    )
}
