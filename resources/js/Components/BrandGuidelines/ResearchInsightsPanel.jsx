/**
 * Research Insights Panel — right sidebar.
 * Status, Brand Coherence, Alignment Findings, Suggestions condensed.
 */
import { useMemo, useState } from 'react'

// Coherence section keys (from BrandCoherenceScoringService) — display order matches step flow
const SECTION_ORDER = ['background', 'archetype', 'purpose', 'expression', 'positioning', 'standards']

export default function ResearchInsightsPanel({
    brandId,
    crawlerRunning,
    latestSnapshotLite,
    latestCoherence,
    latestAlignment,
    latestSuggestions = {},
    insightState = { dismissed: [], accepted: [] },
    stepKeys,
    onDismiss,
    onAccept,
    onApplySuggestion,
    onJumpToStep,
    defaultExpanded,
}) {
    const hasHighSeverity = (latestAlignment?.findings || []).some((f) => f.severity === 'high')
    const coherenceLow = (latestCoherence?.overall?.score ?? 100) < 70
    const defaultOpen = defaultExpanded ?? (crawlerRunning || hasHighSeverity || coherenceLow)
    const [collapsed, setCollapsed] = useState(!defaultOpen)
    const dismissed = insightState.dismissed || []
    const accepted = insightState.accepted || []

    const hasData = crawlerRunning || latestSnapshotLite || latestCoherence || latestAlignment || Object.keys(latestSuggestions || {}).length > 0

    const alignmentFindings = (latestAlignment?.findings || []).filter(
        (f) => !dismissed.includes(f.id) && !dismissed.includes(`FND:${f.id}`)
    )
    // Derive suggestion step keys from: latestSuggestions keys, coherence risks, alignment findings
    const RISK_TO_STEP = { standards: 'standards', purpose: 'purpose_promise', purpose_promise: 'purpose_promise', positioning: 'positioning', expression: 'expression', background: 'background', archetype: 'archetype' }
    const suggestionStepKeys = useMemo(() => {
        const seen = new Set()
        const out = []
        const add = (label, stepKey) => {
            const key = (stepKey || label).toLowerCase().replace(/\s+/g, '_')
            if (key && !seen.has(key)) {
                seen.add(key)
                out.push({ label: label.replace(/_/g, ' '), stepKey: RISK_TO_STEP[key] || key })
            }
        }
        Object.keys(latestSuggestions || {}).forEach((k) => {
            const step = k.split('.')[0] || k
            if (step) add(step, step)
        })
        ;(latestCoherence?.risks || []).forEach((r) => {
            const m = (r.id || '').match(/COH:WEAK_(.+)/)
            if (m) add(m[1].replace(/_/g, ' '), RISK_TO_STEP[m[1].toLowerCase()] || m[1].toLowerCase())
        })
        alignmentFindings.forEach((f) => {
            const path = f.suggestion?.path || f.affected_paths?.[0] || ''
            const step = path.split('.')[0] || ''
            if (step) add(step.replace(/_/g, ' '), step)
        })
        return out
    }, [latestSuggestions, latestCoherence?.risks, alignmentFindings])
    const suggestionCount = suggestionStepKeys.length

    return (
        <div className="h-full flex flex-col overflow-hidden border-l border-white/10">
            <div className="flex items-center justify-between px-4 py-3 border-b border-white/10 flex-shrink-0">
                <h3 className="text-lg font-semibold text-white">Research Insights</h3>
                <button
                    type="button"
                    onClick={() => setCollapsed(!collapsed)}
                    className="text-white/60 hover:text-white p-1"
                    aria-label={collapsed ? 'Expand' : 'Collapse'}
                >
                    {collapsed ? (
                        <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                        </svg>
                    ) : (
                        <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
                        </svg>
                    )}
                </button>
            </div>
            {!collapsed && (
                <div className="flex-1 overflow-y-auto px-4 pb-4 space-y-6">
                    {!hasData ? (
                        <div className="text-sm text-white/60 space-y-2">
                            <p>Enter a website URL in the Background step and click <strong className="text-white/80">Analyze & Prefill</strong> to run research.</p>
                            <p className="text-xs text-white/50">Results will appear here: coherence score, alignment findings, and suggestions.</p>
                        </div>
                    ) : (
                        <>
                    {/* Status */}
                    <div>
                        <h4 className="text-xs font-medium text-white/60 uppercase tracking-wider mb-2">Status</h4>
                        {crawlerRunning ? (
                            <div className="flex items-center gap-2 text-indigo-200">
                                <svg className="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                                </svg>
                                <span>Analyzing…</span>
                            </div>
                        ) : latestSnapshotLite ? (
                            <div className="text-sm text-white/60">
                                <p>Last run: {latestSnapshotLite.created_at ? new Date(latestSnapshotLite.created_at).toLocaleString() : '—'}</p>
                                {latestSnapshotLite.source_url && (
                                    <a href={latestSnapshotLite.source_url} target="_blank" rel="noopener noreferrer" className="text-indigo-400 hover:text-indigo-300 text-xs truncate block mt-1">
                                        {latestSnapshotLite.source_url}
                                    </a>
                                )}
                            </div>
                        ) : (
                            <p className="text-sm text-white/50">No research yet</p>
                        )}
                    </div>

                    {/* Brand Coherence */}
                    {latestCoherence && (
                        <div>
                            <h4 className="text-xs font-medium text-white/60 uppercase tracking-wider mb-2">Brand Coherence</h4>
                            <div className="flex items-center gap-3 mb-3">
                                <div
                                    className="w-14 h-14 rounded-2xl flex items-center justify-center text-xl font-bold text-white"
                                    style={{
                                        backgroundColor: (latestCoherence.overall?.score ?? 0) >= 70 ? 'rgba(16,185,129,0.3)' : 'rgba(245,158,11,0.3)',
                                        border: (latestCoherence.overall?.score ?? 0) >= 70 ? '1px solid rgba(16,185,129,0.5)' : '1px solid rgba(245,158,11,0.5)',
                                    }}
                                >
                                    {latestCoherence.overall?.score ?? 0}
                                </div>
                                <div className="text-xs text-white/60">
                                    <p>Coverage: {latestCoherence.overall?.coverage ?? 0}%</p>
                                    <p>Confidence: {latestCoherence.overall?.confidence ?? 0}%</p>
                                </div>
                            </div>
                            {latestCoherence.sections && (
                                <div className="space-y-1">
                                    {SECTION_ORDER.filter((s) => latestCoherence.sections[s]).map((key) => (
                                        <div key={key} className="flex items-center gap-2">
                                            <span className="text-xs text-white/70 w-20 capitalize">{key}</span>
                                            <div className="flex-1 h-1.5 rounded-full bg-white/10 overflow-hidden">
                                                <div
                                                    className="h-full rounded-full bg-indigo-500/70"
                                                    style={{ width: `${latestCoherence.sections[key]?.score ?? 0}%` }}
                                                />
                                            </div>
                                            <span className="text-xs text-white/50 w-8">{latestCoherence.sections[key]?.score ?? 0}%</span>
                                        </div>
                                    ))}
                                </div>
                            )}
                            {(latestCoherence.strengths || []).length > 0 && (
                                <div className="mt-3 space-y-1">
                                    {(latestCoherence.strengths || []).slice(0, 3).map((s) => (
                                        <p key={s.id} className="text-xs text-emerald-400/90">• {s.label}</p>
                                    ))}
                                </div>
                            )}
                        </div>
                    )}

                    {/* Top risks — standalone section (from coherence.risks) */}
                    {(latestCoherence?.risks || []).length > 0 && (
                        <div>
                            <h4 className="text-xs font-medium text-white/60 uppercase tracking-wider mb-2">Top risks</h4>
                            <ul className="space-y-1">
                                {(latestCoherence.risks || []).slice(0, 5).map((r) => (
                                    <li key={r.id} className="text-xs text-amber-400/90">• {r.label}</li>
                                ))}
                            </ul>
                        </div>
                    )}

                    {/* Alignment Findings */}
                    {alignmentFindings.length > 0 && (
                        <div>
                            <h4 className="text-xs font-medium text-white/60 uppercase tracking-wider mb-2">Alignment Findings</h4>
                            <div className="space-y-2">
                                {alignmentFindings.slice(0, 5).map((f) => (
                                    <div
                                        key={f.id}
                                        className="rounded-lg border border-white/10 bg-white/5 p-3"
                                    >
                                        <div className="flex items-center justify-between gap-2 mb-1">
                                            <div className="flex items-center gap-2">
                                                <span
                                                    className={`text-xs px-2 py-0.5 rounded ${
                                                        f.severity === 'high' ? 'bg-red-500/30 text-red-200' : f.severity === 'med' ? 'bg-amber-500/30 text-amber-200' : 'bg-white/20 text-white/80'
                                                    }`}
                                                >
                                                    {f.severity}
                                                </span>
                                                <span className="text-sm font-medium text-white/90">{f.title}</span>
                                            </div>
                                            {onDismiss && (
                                                <button
                                                    type="button"
                                                    onClick={() => onDismiss(f.id)}
                                                    className="text-xs text-white/50 hover:text-white/80"
                                                >
                                                    Dismiss
                                                </button>
                                            )}
                                        </div>
                                        <p className="text-xs text-white/60 mb-2">{f.detail}</p>
                                        {(f.suggestion?.path || f.affected_paths?.[0]) && (
                                            <p className="text-xs text-white/40 mb-2 font-mono">{f.suggestion?.path || f.affected_paths[0]}</p>
                                        )}
                                        {f.suggestion && onApplySuggestion && (
                                            <button
                                                type="button"
                                                onClick={() => onApplySuggestion(f)}
                                                className="text-xs px-2 py-1 rounded bg-indigo-600/80 text-white hover:bg-indigo-500"
                                            >
                                                Apply suggestion
                                            </button>
                                        )}
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}

                    {/* Suggestions — pills from coherence risks, alignment findings, and suggestions */}
                    {suggestionCount > 0 && (
                        <div>
                            <h4 className="text-xs font-medium text-white/60 uppercase tracking-wider mb-2">Suggestions</h4>
                            <p className="text-sm text-white/70">{suggestionCount} available</p>
                            <div className="mt-2 flex flex-wrap gap-1">
                                {suggestionStepKeys.map(({ label, stepKey }) => (
                                    <button
                                        key={stepKey}
                                        type="button"
                                        onClick={() => onJumpToStep?.(stepKey)}
                                        className="text-xs px-2 py-1 rounded-md bg-indigo-500/20 text-indigo-200 border border-indigo-400/30 hover:bg-indigo-500/30 capitalize"
                                    >
                                        {label}
                                    </button>
                                ))}
                            </div>
                        </div>
                    )}
                        </>
                    )}
                </div>
            )}
        </div>
    )
}
