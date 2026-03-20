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

export default function BrandIntelligenceShow({ auth, asset, metadata, score, dna_warnings: dnaWarnings }) {
    const [simResult, setSimResult] = useState(null)
    const [simError, setSimError] = useState(null)
    const [simLoading, setSimLoading] = useState(false)

    const breakdown = score?.breakdown_json ?? {}
    const refs = breakdown.reference_similarity ?? {}
    const signals = breakdown.signals ?? {}
    const recs = breakdown.recommendations ?? []

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
            setSimResult(res.data?.payload ?? null)
        } catch (e) {
            setSimError(e.response?.data?.message || e.message || 'Simulation failed')
            setSimResult(null)
        } finally {
            setSimLoading(false)
        }
    }

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
                        <h1 className="mt-2 text-2xl font-bold text-slate-900">Brand Intelligence — Asset</h1>
                        <p className="mt-1 text-sm text-slate-600 font-mono">{asset.id}</p>
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

                    {!score && (
                        <p className="text-sm text-slate-600 mb-6">No stored Brand Intelligence score for this asset.</p>
                    )}

                    {score ? (
                        <div className="space-y-6 mb-6">
                            <Section title="A. Summary">
                                {kv('Level', score.level)}
                                {kv('Confidence', typeof score.confidence === 'number' ? score.confidence.toFixed(3) : score.confidence)}
                                {kv('Engine version', score.engine_version)}
                                {kv('Overall score (raw)', score.overall_score)}
                                {kv('AI used (stored)', String(score.ai_used))}
                                {kv('Updated', score.updated_at ? new Date(score.updated_at).toLocaleString() : '—')}
                            </Section>

                            <Section title="B. Signals">
                                {kv('has_text', String(signals.has_text ?? '—'))}
                                {kv('has_typography', String(signals.has_typography ?? '—'))}
                                {kv('has_visual', String(signals.has_visual ?? '—'))}
                            </Section>

                            <Section title="C. Reference similarity">
                                {kv('used', String(refs.used ?? '—'))}
                                {kv('score', refs.score != null ? String(refs.score) : '—')}
                                {kv('confidence', refs.confidence != null ? String(refs.confidence) : '—')}
                                {kv('normalized', refs.normalized != null ? String(refs.normalized) : '—')}
                                {kv('reference_count', refs.reference_count != null ? String(refs.reference_count) : '—')}
                            </Section>

                            <Section title="D. AI insight (stored)">
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

                            <details className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                                <summary className="cursor-pointer text-sm font-semibold text-slate-700">
                                    F. Raw breakdown JSON
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
                        {simResult && (
                            <div className="mt-4 text-xs overflow-auto max-h-[40rem] border border-slate-100 rounded p-2 bg-slate-50">
                                <JsonView value={simResult} collapsed={2} />
                            </div>
                        )}
                    </div>
                </div>
            </main>
            <AppFooter />
        </div>
    )
}
