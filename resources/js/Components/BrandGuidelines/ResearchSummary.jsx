/**
 * Research Summary — full-page view of AI ingestion results.
 * Shown after Processing, before Archetype. Displays snapshot, suggestions, coherence, alignment.
 */
import { useState, useEffect, useCallback, useRef } from 'react'
import axios from 'axios'
import { route } from 'ziggy-js'

const SECTION_ORDER = ['background', 'archetype', 'purpose', 'expression', 'positioning', 'standards']

function FieldCard({ title, children, className = '' }) {
    return (
        <div className={`rounded-2xl border border-white/20 bg-white/5 p-6 ${className}`}>
            <h4 className="text-sm font-medium text-white/80 uppercase tracking-wider mb-4">{title}</h4>
            {children}
        </div>
    )
}

const PATH_LABELS = {
    'personality.primary_archetype': 'Archetype',
    'identity.mission': 'Mission',
    'identity.positioning': 'Positioning',
    'scoring_rules.tone_keywords': 'Tone keywords',
    'scoring_rules.allowed_color_palette': 'Primary colors',
    'typography.primary_font': 'Fonts',
}

function unwrapValue(field) {
    if (field && typeof field === 'object' && 'value' in field) return field.value
    return field
}

const FIELD_LABELS = {
    'identity.mission': 'Mission',
    'identity.positioning': 'Positioning',
    'identity.vision': 'Vision',
    'identity.industry': 'Industry',
    'identity.tagline': 'Tagline',
    'personality.primary_archetype': 'Archetype',
    'personality.tone_keywords': 'Tone keywords',
}

export default function ResearchSummary({
    brandId,
    polledResearch,
    initialSnapshot = {},
    initialSuggestions = {},
    modelPayload = {},
    initialCoherence = null,
    initialAlignment = null,
    initialInsightState = { dismissed: [], accepted: [] },
    ingestionProcessing = false,
    researchFinalized = null,
    onApplySuggestion,
    onDismissInsight,
    onApplySafeSuggestions,
    accentColor = '#6366f1',
    isLocal = false,
}) {
    const [research, setResearch] = useState(polledResearch || null)
    const [devModeOpen, setDevModeOpen] = useState(false)
    const [pageAnalysisOpen, setPageAnalysisOpen] = useState(false)
    const [rawJsonOpen, setRawJsonOpen] = useState(false)
    const [applyingSafe, setApplyingSafe] = useState(false)
    const [rerunLoading, setRerunLoading] = useState(false)

    const snapshot = research?.latestSnapshot ?? initialSnapshot
    const suggestions = research?.latestSuggestions ?? initialSuggestions
    const report = research?.report ?? null
    const detectedConfidently = report?.detected_confidently ?? {}
    const suggestedForReview = report?.suggested_for_review ?? {}
    const notConfidentlyDetected = report?.not_confidently_detected ?? []
    const payload = research?.modelPayload ?? modelPayload
    const coherence = research?.latestCoherence ?? initialCoherence
    const alignment = research?.latestAlignment ?? initialAlignment
    const sections = research?.developer_data?.sections ?? null
    const pageAnalysis = research?.developer_data?.page_analysis ?? []
    const pipelineStatus = research?.pipelineStatus ?? research?.developer_data?.pipeline_status ?? {}
    const evidenceMap = research?.developer_data?.evidence_map ?? {}
    const narrativeFieldDebug = research?.developer_data?.narrative_field_debug ?? {}
    const pageClassifications = research?.developer_data?.page_classifications ?? null
    const pageExtractions = research?.developer_data?.page_extractions ?? null
    const pipelineVersion = research?.developer_data?.pipeline_version ?? null
    const visualPipelineEnabled = research?.developer_data?.visual_pipeline_enabled ?? null
    const snapshotGeneratedAt = research?.developer_data?.snapshot_generated_at ?? null
    const staleSnapshotWarning = research?.developer_data?.stale_snapshot_warning ?? null
    const debugInfo = research?.developer_data?._debug ?? null

    const hasDebugData = !!(
        (pipelineStatus && Object.keys(pipelineStatus).length > 0) ||
        (evidenceMap && Object.keys(evidenceMap).length > 0) ||
        (narrativeFieldDebug && Object.keys(narrativeFieldDebug).length > 0) ||
        (pageAnalysis?.length > 0) ||
        (Array.isArray(pageClassifications) && pageClassifications.length > 0) ||
        (Array.isArray(pageExtractions) && pageExtractions.length > 0)
    )
    const showDeveloperTools = hasDebugData || isLocal
    const insightState = research?.insightState ?? initialInsightState
    const processing = research?.ingestionProcessing ?? ingestionProcessing
    const finalized = research?.researchFinalized ?? researchFinalized ?? false

    const dismissed = insightState?.dismissed ?? []
    const alignmentFindings = (alignment?.findings || []).filter(
        (f) => !dismissed.includes(f.id) && !dismissed.includes(`FND:${f.id}`)
    )

    const latestSnapshotLite = research?.latestSnapshotLite
    const hasSnapshot = !!latestSnapshotLite || (snapshot && Object.keys(snapshot).length > 0)
    const canProceed = finalized || (hasSnapshot && !processing)

    useEffect(() => {
        if (!brandId) return
        const poll = async () => {
            try {
                const res = await axios.get(route('brands.brand-dna.builder.research-insights', { brand: brandId }))
                setResearch(res.data)
            } catch {
                // keep previous state
            }
        }
        poll()
        const id = setInterval(poll, 2000)
        return () => clearInterval(id)
    }, [brandId])

    const handleApplySafe = useCallback(async () => {
        if (!onApplySafeSuggestions) return
        setApplyingSafe(true)
        try {
            await onApplySafeSuggestions({ snapshot, suggestions, coherence, alignment })
        } finally {
            setApplyingSafe(false)
        }
    }, [onApplySafeSuggestions, snapshot, suggestions, coherence, alignment])

    const researchData = {
        snapshot,
        suggestions,
        coherence,
        alignment,
        sections,
        ingestionProcessing: processing,
    }

    const devSectionRef = useRef(null)
    const scrollToDevTools = useCallback(() => {
        setDevModeOpen(true)
        setTimeout(() => devSectionRef.current?.scrollIntoView({ behavior: 'smooth' }), 100)
    }, [])

    return (
        <div className="space-y-8">
            {/* Developer tools shortcut — always visible when showDeveloperTools */}
            {showDeveloperTools && (
                <div className="flex justify-end">
                    <button
                        type="button"
                        onClick={scrollToDevTools}
                        className="text-xs text-white/50 hover:text-white/70 px-2 py-1 rounded border border-white/10 hover:border-white/20"
                    >
                        Developer Research Tools
                    </button>
                </div>
            )}
            {/* Finalizing state: show processing message until research_finalized */}
            {!finalized && (processing || hasSnapshot) && (
                <div className="rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-4 text-sm text-amber-200/90">
                    <p className="font-medium">Finalizing research insights…</p>
                    <p className="mt-1 text-amber-200/70">
                        We&apos;ve extracted page-level findings and are compiling the final brand intelligence report.
                    </p>
                </div>
            )}

            {/* Section 1: Detected Confidently / Suggested / Missing */}
            {(Object.keys(detectedConfidently).length > 0 || Object.keys(suggestedForReview).length > 0 || notConfidentlyDetected.length > 0) && finalized && (
                <div className="space-y-4">
                    <h3 className="text-lg font-semibold text-white/90">Detection Summary</h3>
                    <div className="grid gap-4 md:grid-cols-3">
                        {Object.keys(detectedConfidently).length > 0 && (
                            <FieldCard title="Detected Confidently" className="border-emerald-500/30">
                                <div className="space-y-2">
                                    {detectedConfidently.archetype && (
                                        <div>
                                            <p className="text-xs text-white/50">Archetype</p>
                                            <p className="text-sm font-medium text-white/90">{detectedConfidently.archetype}</p>
                                        </div>
                                    )}
                                    {(detectedConfidently.primary_colors?.length ?? 0) > 0 && (
                                        <div>
                                            <p className="text-xs text-white/50 mb-1">Primary colors</p>
                                            <div className="flex flex-wrap gap-2">
                                                {detectedConfidently.primary_colors.map((c, i) => {
                                                    const hex = typeof c === 'string' ? c : (c?.hex ?? c)
                                                    return (
                                                        <div
                                                            key={i}
                                                            className="w-8 h-8 rounded border border-white/20 shrink-0"
                                                            style={{ backgroundColor: hex || '#666' }}
                                                            title={hex}
                                                        />
                                                    )
                                                })}
                                            </div>
                                        </div>
                                    )}
                                </div>
                            </FieldCard>
                        )}
                        {Object.keys(suggestedForReview).length > 0 && (
                            <FieldCard title="Suggested for Review" className="border-amber-500/30">
                                <div className="space-y-2 text-sm text-white/80">
                                    {suggestedForReview.archetype?.length > 0 && (
                                        <p>Archetype: {suggestedForReview.archetype.join(', ')}</p>
                                    )}
                                    {suggestedForReview.mission && <p>Mission: {String(suggestedForReview.mission).slice(0, 60)}…</p>}
                                    {suggestedForReview.positioning && <p>Positioning: {String(suggestedForReview.positioning).slice(0, 60)}…</p>}
                                    {(suggestedForReview.tone_keywords?.length ?? 0) > 0 && (
                                        <p>Tone: {suggestedForReview.tone_keywords.slice(0, 3).join(', ')}</p>
                                    )}
                                </div>
                            </FieldCard>
                        )}
                        {notConfidentlyDetected.length > 0 && (
                            <FieldCard title="Missing / Not Yet Detected" className="border-white/10">
                                <ul className="text-sm text-white/50 space-y-1">
                                    {notConfidentlyDetected.map((key) => (
                                        <li key={key}>• {key.replace(/_/g, ' ')}</li>
                                    ))}
                                </ul>
                            </FieldCard>
                        )}
                    </div>
                </div>
            )}

            {/* Section 2: Brand Snapshot + Visual System + Strategy Insights */}
            <div className="space-y-6">
                <h3 className="text-lg font-semibold text-white/90">Research Summary</h3>

                <div className={`grid gap-6 md:grid-cols-2 ${!finalized ? 'opacity-60 pointer-events-none' : ''}`}>
                    {/* Brand Snapshot */}
                    <FieldCard title="Brand Snapshot">
                        <div className="space-y-4">
                            {snapshot?.logo_url ? (
                                <div className="flex items-start gap-4">
                                    <img
                                        src={snapshot.logo_url}
                                        alt="Detected logo"
                                        className="w-16 h-16 object-contain rounded-lg bg-white/10"
                                    />
                                    <div className="flex-1 min-w-0" />
                                </div>
                            ) : null}
                            <div>
                                <p className="text-xs text-white/50 mb-1">Brand description</p>
                                {snapshot?.brand_bio ? (
                                    <p className="text-sm text-white/80 line-clamp-4">{snapshot.brand_bio}</p>
                                ) : (
                                    <p className="text-sm text-white/50 italic">Not confidently detected</p>
                                )}
                            </div>
                            {(snapshot?.hero_headlines?.length ?? 0) > 0 && (
                                <div>
                                    <p className="text-xs text-white/50 mb-1">Detected headlines</p>
                                    <ul className="text-sm text-white/80 space-y-1">
                                        {snapshot.hero_headlines.slice(0, 5).map((h, i) => (
                                            <li key={i}>• {h}</li>
                                        ))}
                                    </ul>
                                </div>
                            )}
                            {(!snapshot?.logo_url && !snapshot?.brand_bio && (!snapshot?.hero_headlines?.length) && (!snapshot?.primary_colors?.length)) && (
                                <p className="text-sm text-white/50">No brand snapshot data yet.</p>
                            )}
                        </div>
                    </FieldCard>

                    {/* Visual System */}
                    <FieldCard title="Visual System">
                        <div className="space-y-4">
                            {(snapshot?.primary_colors?.length ?? 0) > 0 && (
                                <div>
                                    <p className="text-xs text-white/50 mb-2">Primary colors</p>
                                    <div className="flex flex-wrap gap-2">
                                        {snapshot.primary_colors.map((c, i) => {
                                            const hex = typeof c === 'string' ? c : (c?.hex ?? c)
                                            return (
                                                <div
                                                    key={i}
                                                    className="w-10 h-10 rounded-lg border border-white/20 shrink-0"
                                                    style={{ backgroundColor: hex || '#666' }}
                                                    title={hex}
                                                />
                                            )
                                        })}
                                    </div>
                                </div>
                            )}
                            {(snapshot?.detected_fonts?.length ?? 0) > 0 && (
                                <div>
                                    <p className="text-xs text-white/50 mb-1">Detected typography</p>
                                    <p className="text-sm text-white/80">{snapshot.detected_fonts.join(', ')}</p>
                                </div>
                            )}
                            {(!snapshot?.primary_colors?.length && !snapshot?.detected_fonts?.length) && (
                                <p className="text-sm text-white/50">No visual data detected yet.</p>
                            )}
                        </div>
                    </FieldCard>
                </div>

                {/* Auto Applied Fields */}
                {((suggestions?.auto_applied?.length ?? 0) > 0) && (
                    <FieldCard title="Auto Applied Fields" className="border-emerald-500/30">
                        <div className="space-y-4">
                            <p className="text-xs text-white/50">These fields were auto-filled with high-confidence AI results.</p>
                            {suggestions.auto_applied.map((path) => {
                                const label = PATH_LABELS[path] ?? path.replace(/\./g, ' › ')
                                const parts = path.split('.')
                                let val = payload
                                for (const p of parts) { val = val?.[p] }
                                const displayVal = unwrapValue(val)
                                const confidence = val && typeof val === 'object' && val.confidence != null ? Math.round(val.confidence * 100) : null
                                return (
                                    <div key={path} className="rounded-lg bg-emerald-500/10 border border-emerald-500/20 p-3">
                                        <p className="text-xs text-emerald-300/90 font-medium">{label}</p>
                                        {Array.isArray(displayVal) ? (
                                            <div className="mt-1 flex flex-wrap gap-2">
                                                {displayVal.slice(0, 8).map((item, i) => {
                                                    const v = typeof item === 'object' && item?.hex ? item.hex : (item?.value ?? item)
                                                    return typeof v === 'string' && v.startsWith('#') ? (
                                                        <div key={i} className="w-8 h-8 rounded border border-white/20" style={{ backgroundColor: v }} title={v} />
                                                    ) : (
                                                        <span key={i} className="text-sm text-white/80">{String(v)}</span>
                                                    )
                                                })}
                                            </div>
                                        ) : (
                                            <p className="text-sm text-white/80 mt-0.5">{String(displayVal ?? '—')}</p>
                                        )}
                                        {confidence != null && <span className="text-xs text-emerald-400/80">AI detected ({confidence}% confidence)</span>}
                                    </div>
                                )
                            })}
                        </div>
                    </FieldCard>
                )}

                {/* Suggestions Needing Review */}
                {((suggestions?.suggested?.length ?? 0) > 0) && (
                    <FieldCard title="Suggestions Needing Review">
                        <div className="space-y-2">
                            <p className="text-xs text-white/50">Review and apply these medium-confidence suggestions in the Builder.</p>
                            <ul className="text-sm text-white/80 space-y-1">
                                {suggestions.suggested.map((path) => {
                                    const label = PATH_LABELS[path] ?? path.replace(/\./g, ' › ')
                                    const item = suggestions?.items?.find((s) => s.path === path)
                                    const conf = item?.confidence != null ? Math.round(item.confidence * 100) : null
                                    return (
                                        <li key={path} className="flex items-center gap-2">
                                            <span>{label}</span>
                                            {conf != null && <span className="text-xs text-white/50">({conf}%)</span>}
                                        </li>
                                    )
                                })}
                            </ul>
                        </div>
                    </FieldCard>
                )}

                {/* Strategy Insights */}
                <FieldCard title="Strategy Insights">
                    <div className="space-y-4">
                        {(suggestions?.recommended_archetypes?.length ?? 0) > 0 && (
                            <div>
                                <p className="text-xs text-white/50 mb-2">Recommended archetypes</p>
                                <div className="flex flex-wrap gap-2">
                                    {suggestions.recommended_archetypes.map((a, i) => {
                                        const label = typeof a === 'string' ? a : (a?.label ?? a?.archetype ?? a)
                                        const confidence = typeof a === 'object' && a?.confidence != null ? Math.round(a.confidence * 100) : null
                                        const isPrimary = i === 0
                                        const item = suggestions?.items?.find((s) => s.path === 'personality.primary_archetype')
                                        const evidence = item?.evidence || []
                                        const section = item?.section
                                        return (
                                            <div key={i} className="rounded-lg bg-white/10 p-3 space-y-2">
                                                <div className="flex items-center gap-2 flex-wrap">
                                                    <span className="font-medium text-white/90">{label}</span>
                                                    {confidence != null && (
                                                        <span className="text-xs text-white/50">Confidence: {confidence}%</span>
                                                    )}
                                                    {section && (
                                                        <span className="text-xs text-white/40">Detected in: {section}</span>
                                                    )}
                                                    {isPrimary && (
                                                        <span className="text-xs text-white/40">(primary)</span>
                                                    )}
                                                    {onApplySuggestion && (
                                                        <button
                                                            type="button"
                                                            onClick={() => onApplySuggestion({ suggestion: { path: 'personality.primary_archetype', value: label } })}
                                                            className="text-xs text-indigo-300 hover:text-indigo-200"
                                                        >
                                                            Apply
                                                        </button>
                                                    )}
                                                </div>
                                                {evidence.length > 0 && (
                                                    <div className="mt-2">
                                                        <p className="text-xs text-white/50 mb-1">Evidence</p>
                                                        <ul className="text-xs text-white/70 space-y-0.5">
                                                            {evidence.slice(0, 3).map((e, j) => (
                                                                <li key={j}>• {e}</li>
                                                            ))}
                                                        </ul>
                                                    </div>
                                                )}
                                            </div>
                                        )
                                    })}
                                </div>
                            </div>
                        )}
                        {suggestions?.mission_suggestion && (
                            <div className="rounded-lg bg-white/10 p-3">
                                <p className="text-xs text-white/50 mb-1">Suggested mission</p>
                                <p className="text-sm text-white/80 mb-1">{suggestions.mission_suggestion}</p>
                                {(() => {
                                    const item = suggestions?.items?.find((s) => s.path === 'identity.mission')
                                    const evidence = item?.evidence || []
                                    const section = item?.section
                                    return (
                                        <>
                                        {section && <p className="text-xs text-white/40 mb-1">Detected in: {section}</p>}
                                        {evidence.length > 0 ? (
                                        <div className="mt-2 mb-2">
                                            <p className="text-xs text-white/50 mb-1">Evidence</p>
                                            <ul className="text-xs text-white/70 space-y-0.5">
                                                {evidence.slice(0, 3).map((e, j) => (
                                                    <li key={j}>• {e}</li>
                                                ))}
                                            </ul>
                                        </div>
                                    ) : null}
                                    </>
                                    )
                                })()}
                                {onApplySuggestion && (
                                    <button
                                        type="button"
                                        onClick={() => onApplySuggestion({ suggestion: { path: 'identity.mission', value: suggestions.mission_suggestion } })}
                                        className="text-xs text-indigo-300 hover:text-indigo-200"
                                    >
                                        Apply
                                    </button>
                                )}
                            </div>
                        )}
                        {suggestions?.positioning_suggestion && (
                            <div className="rounded-lg bg-white/10 p-3">
                                <p className="text-xs text-white/50 mb-1">Suggested positioning</p>
                                <p className="text-sm text-white/80 mb-1">{suggestions.positioning_suggestion}</p>
                                {(() => {
                                    const item = suggestions?.items?.find((s) => s.path === 'identity.positioning')
                                    const evidence = item?.evidence || []
                                    const section = item?.section
                                    return (
                                        <>
                                        {section && <p className="text-xs text-white/40 mb-1">Detected in: {section}</p>}
                                        {evidence.length > 0 ? (
                                        <div className="mt-2 mb-2">
                                            <p className="text-xs text-white/50 mb-1">Evidence</p>
                                            <ul className="text-xs text-white/70 space-y-0.5">
                                                {evidence.slice(0, 3).map((e, j) => (
                                                    <li key={j}>• {e}</li>
                                                ))}
                                            </ul>
                                        </div>
                                    ) : null}
                                    </>
                                    )
                                })()}
                                {onApplySuggestion && (
                                    <button
                                        type="button"
                                        onClick={() => onApplySuggestion({ suggestion: { path: 'identity.positioning', value: suggestions.positioning_suggestion } })}
                                        className="text-xs text-indigo-300 hover:text-indigo-200"
                                    >
                                        Apply
                                    </button>
                                )}
                            </div>
                        )}
                        {!suggestions?.recommended_archetypes?.length && !suggestions?.mission_suggestion && !suggestions?.positioning_suggestion && (
                            <p className="text-sm text-white/50">No strategy suggestions yet.</p>
                        )}
                    </div>
                </FieldCard>
            </div>

            {/* Section 2: Alignment Findings + Coherence + Suggestions */}
            <div className="grid gap-6 md:grid-cols-2">
                {/* Alignment Findings */}
                <FieldCard title="Alignment Findings">
                    {alignmentFindings.length > 0 ? (
                        <div className="space-y-3">
                            {alignmentFindings.slice(0, 6).map((f) => (
                                <div
                                    key={f.id}
                                    className="rounded-lg border border-white/10 bg-white/5 p-3"
                                >
                                    <div className="flex items-center justify-between gap-2 mb-1">
                                        <span
                                            className={`text-xs px-2 py-0.5 rounded ${
                                                f.severity === 'high' ? 'bg-red-500/30 text-red-200' :
                                                f.severity === 'med' ? 'bg-amber-500/30 text-amber-200' :
                                                'bg-white/20 text-white/80'
                                            }`}
                                        >
                                            {f.severity}
                                        </span>
                                        {onDismissInsight && (
                                            <button
                                                type="button"
                                                onClick={() => onDismissInsight(f.id)}
                                                className="text-xs text-white/50 hover:text-white/80"
                                            >
                                                Dismiss
                                            </button>
                                        )}
                                    </div>
                                    <p className="text-sm font-medium text-white/90">{f.title}</p>
                                    <p className="text-xs text-white/60 mt-0.5">{f.detail}</p>
                                    {f.suggestion && onApplySuggestion && (
                                        <button
                                            type="button"
                                            onClick={() => onApplySuggestion(f)}
                                            className="mt-2 text-xs px-2 py-1 rounded bg-indigo-600/80 text-white hover:bg-indigo-500"
                                        >
                                            Apply suggestion
                                        </button>
                                    )}
                                </div>
                            ))}
                        </div>
                    ) : (
                        <p className="text-sm text-white/50">No alignment findings.</p>
                    )}
                </FieldCard>

                {/* Coherence Score */}
                <FieldCard title="Brand Coherence">
                    {coherence ? (
                        <div className="space-y-4">
                            <div className="flex items-center gap-4">
                                <div
                                    className="w-16 h-16 rounded-2xl flex items-center justify-center text-2xl font-bold text-white"
                                    style={{
                                        backgroundColor: (coherence.overall?.score ?? 0) >= 70 ? 'rgba(16,185,129,0.3)' : 'rgba(245,158,11,0.3)',
                                        border: (coherence.overall?.score ?? 0) >= 70 ? '1px solid rgba(16,185,129,0.5)' : '1px solid rgba(245,158,11,0.5)',
                                    }}
                                >
                                    {coherence.overall?.score ?? 0}
                                </div>
                                <div className="text-sm text-white/60">
                                    <p>Coverage: {coherence.overall?.coverage ?? 0}%</p>
                                    <p>Confidence: {coherence.overall?.confidence ?? 0}%</p>
                                </div>
                            </div>
                            {coherence.sections && (
                                <div className="space-y-1.5">
                                    <p className="text-xs text-white/50">Section coverage</p>
                                    {SECTION_ORDER.filter((s) => coherence.sections[s]).map((key) => (
                                        <div key={key} className="flex items-center gap-2">
                                            <span className="text-xs text-white/70 w-24 capitalize">{key.replace('_', ' ')}</span>
                                            <div className="flex-1 h-1.5 rounded-full bg-white/10 overflow-hidden">
                                                <div
                                                    className="h-full rounded-full bg-indigo-500/70"
                                                    style={{ width: `${coherence.sections[key]?.score ?? 0}%` }}
                                                />
                                            </div>
                                            <span className="text-xs text-white/50 w-8">{coherence.sections[key]?.score ?? 0}%</span>
                                        </div>
                                    ))}
                                </div>
                            )}
                            {(coherence.risks?.length ?? 0) > 0 && (
                                <div className="mt-3">
                                    <p className="text-xs text-white/50 mb-1">Risks</p>
                                    <ul className="text-xs text-amber-400/90 space-y-0.5">
                                        {coherence.risks.slice(0, 3).map((r) => (
                                            <li key={r.id}>• {r.label}</li>
                                        ))}
                                    </ul>
                                </div>
                            )}
                        </div>
                    ) : (
                        <p className="text-sm text-white/50">No coherence data yet.</p>
                    )}
                </FieldCard>
            </div>

            {/* Apply All Safe Suggestions (confidence >= 75%) */}
            {onApplySafeSuggestions && (() => {
                const hasLegacy = suggestions?.recommended_archetypes?.length || suggestions?.mission_suggestion || suggestions?.positioning_suggestion
                const safeItems = (suggestions?.items || []).filter((s) => (s.confidence ?? 0) >= 0.75)
                return hasLegacy || safeItems.length > 0
            })() && (
                <div className="flex justify-center">
                    <button
                        type="button"
                        onClick={handleApplySafe}
                        disabled={applyingSafe}
                        className="px-6 py-3 rounded-xl font-medium text-white transition-colors disabled:opacity-50"
                        style={{ backgroundColor: accentColor }}
                    >
                        {applyingSafe ? 'Applying…' : 'Apply All Safe Suggestions'}
                    </button>
                </div>
            )}

            {/* Developer Research Tools — visible when debug data exists or in local */}
            {showDeveloperTools && (
            <div ref={devSectionRef} className="rounded-2xl border border-white/10 bg-white/5 overflow-hidden">
                <button
                    type="button"
                    onClick={() => setDevModeOpen(!devModeOpen)}
                    className="w-full px-6 py-4 flex items-center justify-between text-left text-sm font-medium text-white/80 hover:text-white/90"
                >
                    <span>Developer Research Tools</span>
                    <svg
                        className={`w-5 h-5 transition-transform ${devModeOpen ? 'rotate-180' : ''}`}
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                    >
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
                    </svg>
                </button>
                {devModeOpen && (
                    <div className="px-6 pb-6 space-y-6">
                        {/* Stale Snapshot Warning */}
                        {staleSnapshotWarning && (
                            <div className="rounded-lg border border-amber-500/40 bg-amber-500/10 p-4 flex items-center justify-between gap-4">
                                <p className="text-sm font-medium text-amber-200">{staleSnapshotWarning}</p>
                                {isLocal && (
                                    <button
                                        type="button"
                                        onClick={async () => {
                                            setRerunLoading(true)
                                            try {
                                                await axios.post(route('brands.brand-dna.builder.invalidate-and-rerun-research', { brand: brandId }))
                                                const res = await axios.get(route('brands.brand-dna.builder.research-insights', { brand: brandId }))
                                                setResearch(res.data)
                                            } finally {
                                                setRerunLoading(false)
                                            }
                                        }}
                                        disabled={rerunLoading}
                                        className="shrink-0 px-3 py-1.5 rounded-lg text-xs font-medium bg-amber-500/30 text-amber-200 hover:bg-amber-500/40 disabled:opacity-50"
                                    >
                                        {rerunLoading ? 'Re-running…' : 'Re-run analysis'}
                                    </button>
                                )}
                            </div>
                        )}

                        {/* Re-run / Invalidate (dev only) */}
                        {isLocal && !staleSnapshotWarning && hasSnapshot && (
                            <div className="rounded-lg border border-white/10 bg-white/5 p-4">
                                <p className="text-xs text-white/60 mb-2">Delete current snapshot and re-run pipeline (dev only)</p>
                                <button
                                    type="button"
                                    onClick={async () => {
                                        setRerunLoading(true)
                                        try {
                                            await axios.post(route('brands.brand-dna.builder.invalidate-and-rerun-research', { brand: brandId }))
                                            const res = await axios.get(route('brands.brand-dna.builder.research-insights', { brand: brandId }))
                                            setResearch(res.data)
                                        } finally {
                                            setRerunLoading(false)
                                        }
                                    }}
                                    disabled={rerunLoading}
                                    className="px-3 py-1.5 rounded-lg text-xs font-medium bg-white/10 text-white/80 hover:bg-white/20 disabled:opacity-50"
                                >
                                    {rerunLoading ? 'Re-running…' : 'Invalidate & re-run'}
                                </button>
                            </div>
                        )}

                        {/* Debug: config and snapshot state */}
                        {debugInfo && (
                            <div className="space-y-2">
                                <h5 className="text-xs font-medium text-white/60 uppercase">Debug</h5>
                                <div className="grid grid-cols-2 sm:grid-cols-4 gap-2 text-xs">
                                    <div className="rounded bg-amber-500/10 px-2 py-1.5 border border-amber-500/20">
                                        <p className="text-white/50">config visual_enabled</p>
                                        <p className={`font-medium ${debugInfo.config_visual_enabled ? 'text-emerald-400' : 'text-amber-400'}`}>
                                            {String(debugInfo.config_visual_enabled)}
                                        </p>
                                    </div>
                                    <div className="rounded bg-white/5 px-2 py-1.5">
                                        <p className="text-white/50">snapshot has classifications</p>
                                        <p className={`font-medium ${debugInfo.snapshot_has_page_classifications ? 'text-emerald-400' : 'text-amber-400'}`}>
                                            {String(debugInfo.snapshot_has_page_classifications)}
                                        </p>
                                    </div>
                                    <div className="rounded bg-white/5 px-2 py-1.5">
                                        <p className="text-white/50">snapshot has extractions</p>
                                        <p className={`font-medium ${debugInfo.snapshot_has_page_extractions ? 'text-emerald-400' : 'text-amber-400'}`}>
                                            {String(debugInfo.snapshot_has_page_extractions)}
                                        </p>
                                    </div>
                                    <div className="rounded bg-white/5 px-2 py-1.5">
                                        <p className="text-white/50">snapshot has page_analysis</p>
                                        <p className={`font-medium ${debugInfo.snapshot_has_page_analysis_in_payload ? 'text-emerald-400' : 'text-amber-400'}`}>
                                            {String(debugInfo.snapshot_has_page_analysis_in_payload)}
                                        </p>
                                    </div>
                                </div>
                                {!debugInfo.snapshot_has_page_classifications && (
                                    <p className="text-xs text-amber-400/90">
                                        Queue worker may have visual pipeline disabled. Restart queue container: <code className="bg-black/30 px-1 rounded">sail restart queue</code>
                                    </p>
                                )}
                            </div>
                        )}

                        {/* Snapshot Metadata */}
                        {(pipelineVersion != null || visualPipelineEnabled != null || snapshotGeneratedAt) && (
                            <div className="space-y-2">
                                <h5 className="text-xs font-medium text-white/60 uppercase">Snapshot Metadata</h5>
                                <div className="grid grid-cols-2 sm:grid-cols-4 gap-2 text-xs">
                                    {pipelineVersion != null && (
                                        <div className="rounded bg-white/5 px-2 py-1.5">
                                            <p className="text-white/50">pipeline_version</p>
                                            <p className="text-white/90">{pipelineVersion}</p>
                                        </div>
                                    )}
                                    {visualPipelineEnabled != null && (
                                        <div className="rounded bg-white/5 px-2 py-1.5">
                                            <p className="text-white/50">visual_pipeline_enabled</p>
                                            <p className="text-white/90">{String(visualPipelineEnabled)}</p>
                                        </div>
                                    )}
                                    {snapshotGeneratedAt && (
                                        <div className="rounded bg-white/5 px-2 py-1.5">
                                            <p className="text-white/50">snapshot_generated_at</p>
                                            <p className="text-white/90">{snapshotGeneratedAt}</p>
                                        </div>
                                    )}
                                </div>
                            </div>
                        )}

                        {/* Pipeline Status */}
                        <div className="space-y-3">
                            <h5 className="text-xs font-medium text-white/60 uppercase">Pipeline Status</h5>
                            {pipelineStatus && Object.keys(pipelineStatus).length > 0 ? (
                            <div className="grid grid-cols-2 sm:grid-cols-3 gap-2">
                                {[
                                    'pdf_render_complete',
                                    'page_classification_complete',
                                    'page_extraction_complete',
                                    'fusion_complete',
                                    'snapshot_persisted',
                                    'suggestions_ready',
                                    'coherence_ready',
                                    'alignment_ready',
                                    'research_finalized',
                                ].map((key) => (
                                    <div key={key} className="rounded bg-white/5 px-2 py-1.5 flex items-center justify-between">
                                        <span className="text-xs text-white/70">{key.replace(/_/g, ' ')}</span>
                                        <span className={`text-xs font-medium ${pipelineStatus[key] ? 'text-emerald-400' : 'text-amber-400'}`}>
                                            {pipelineStatus[key] ? '✓' : '—'}
                                        </span>
                                    </div>
                                ))}
                            </div>
                            ) : (
                                <p className="text-xs text-white/50 italic">Pipeline status not available (research not yet loaded or no PDF).</p>
                            )}
                        </div>

                        {/* Narrative Field Debug — cards by field */}
                        <div className="space-y-3">
                            <h5 className="text-xs font-medium text-white/60 uppercase">Narrative Field Debug</h5>
                            {Object.keys(narrativeFieldDebug).length > 0 ? (
                                <div className="grid gap-3 sm:grid-cols-2">
                                    {Object.entries(narrativeFieldDebug).map(([fieldPath, debug]) => {
                                        const candidatePages = Array.isArray(debug.candidate_pages) ? debug.candidate_pages : []
                                        const attempted = debug.attempted ?? 0
                                        const acceptedCount = Array.isArray(debug.accepted) ? debug.accepted.length : 0
                                        const rejectedCount = Array.isArray(debug.rejected) ? debug.rejected.length : 0
                                        const noPagesRouted = candidatePages.length === 0
                                        return (
                                            <div key={fieldPath} className="rounded-lg border border-white/10 bg-black/20 p-3 space-y-2">
                                                <p className="text-sm font-medium text-white/90">{FIELD_LABELS[fieldPath] ?? fieldPath}</p>
                                                <p className="text-xs text-white/60">
                                                    Candidate pages: {noPagesRouted ? 'none' : `[${candidatePages.join(', ')}]`}
                                                </p>
                                                {noPagesRouted && (
                                                    <p className="text-xs text-amber-400/90 italic">No pages were routed to this field.</p>
                                                )}
                                                <p className="text-xs text-white/60">Attempted: {attempted}</p>
                                                <p className="text-xs text-white/60">Accepted: {acceptedCount}</p>
                                                <p className="text-xs text-white/60">Rejected: {rejectedCount}</p>
                                                {rejectedCount > 0 && (
                                                    <p className="text-xs text-amber-400/90">
                                                        Reasons: {debug.rejected.map((r) => r.reason ?? 'rejected').filter(Boolean).join(', ') || '—'}
                                                    </p>
                                                )}
                                            </div>
                                        )
                                    })}
                                </div>
                            ) : (
                                <p className="text-xs text-white/50 italic">No narrative candidate pages were routed for identity.mission, identity.positioning, or tone_keywords.</p>
                            )}
                        </div>

                        {/* Evidence Map with provenance warning */}
                        <div className="space-y-3">
                            <h5 className="text-xs font-medium text-white/60 uppercase">Evidence Map</h5>
                            {Object.keys(evidenceMap).length > 0 ? (
                                <div className="space-y-2">
                                    {Object.entries(evidenceMap).map(([path, entry]) => {
                                        const hasMissingProvenance = (entry.winning_source === 'pdf_visual') &&
                                            (entry.winning_page == null || entry.winning_page_type == null)
                                        return (
                                            <div key={path} className={`rounded-lg border p-3 ${hasMissingProvenance ? 'border-amber-500/40 bg-amber-500/5' : 'border-white/10 bg-black/20'}`}>
                                                {hasMissingProvenance && (
                                                    <span className="inline-block px-2 py-0.5 rounded text-xs font-medium bg-amber-500/30 text-amber-200 mb-2">Missing visual provenance</span>
                                                )}
                                                <p className="text-xs text-white/70">
                                                    <span className="text-white/50">{FIELD_LABELS[path] ?? path}:</span>{' '}
                                                    {typeof entry.final_value === 'string' ? `"${entry.final_value.slice(0, 80)}${entry.final_value.length > 80 ? '…' : ''}"` : JSON.stringify(entry.final_value)}
                                                </p>
                                                <p className="text-xs text-white/50 mt-1">
                                                    winning_source: {entry.winning_source} · winning_page: {entry.winning_page ?? 'null'} · winning_page_type: {entry.winning_page_type ?? 'null'} · {entry.winning_reason}
                                                </p>
                                            </div>
                                        )
                                    })}
                                </div>
                            ) : (
                                <p className="text-xs text-white/50 italic">No evidence map entries.</p>
                            )}
                        </div>

                        {/* Page Analysis Inspector */}
                        <div className="space-y-3">
                            <button
                                type="button"
                                onClick={() => setPageAnalysisOpen(!pageAnalysisOpen)}
                                className="w-full flex items-center justify-between text-left text-xs font-medium text-white/70 hover:text-white/90"
                            >
                                <span className="uppercase">Page Analysis</span>
                                <span className="text-white/50">
                                    {pageAnalysis.length > 0
                                        ? `${pageAnalysis.length} pages · ${pageAnalysis.filter((p) => (p.used_in_final_merge?.length ?? 0) > 0).length} contributing`
                                        : processing
                                            ? 'Processing…'
                                            : 'No page data'}
                                </span>
                                <svg
                                    className={`w-4 h-4 transition-transform ${pageAnalysisOpen ? 'rotate-180' : ''}`}
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke="currentColor"
                                >
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
                                </svg>
                            </button>
                            {pageAnalysisOpen && (
                                <div className="space-y-4 rounded-lg border border-white/10 bg-black/20 p-4">
                                    {pageAnalysis.length > 0 ? (
                                        <>
                                            <div className="grid grid-cols-2 sm:grid-cols-5 gap-2 text-xs">
                                                <div className="rounded bg-white/5 px-2 py-1.5">
                                                    <p className="text-white/50">Total pages</p>
                                                    <p className="font-medium text-white/90">{pageAnalysis.length}</p>
                                                </div>
                                                <div className="rounded bg-white/5 px-2 py-1.5">
                                                    <p className="text-white/50">Classified</p>
                                                    <p className="font-medium text-white/90">{pageAnalysis.filter((p) => p.page_type && p.page_type !== 'unknown').length}</p>
                                                </div>
                                                <div className="rounded bg-white/5 px-2 py-1.5">
                                                    <p className="text-white/50">With accepted</p>
                                                    <p className="font-medium text-emerald-400/90">{pageAnalysis.filter((p) => (p.accepted_candidates?.length ?? 0) > 0).length}</p>
                                                </div>
                                                <div className="rounded bg-white/5 px-2 py-1.5">
                                                    <p className="text-white/50">With rejected</p>
                                                    <p className="font-medium text-amber-400/90">{pageAnalysis.filter((p) => (p.rejected_candidates?.length ?? 0) > 0).length}</p>
                                                </div>
                                                <div className="rounded bg-white/5 px-2 py-1.5">
                                                    <p className="text-white/50">Contributing</p>
                                                    <p className="font-medium text-indigo-400/90">{pageAnalysis.filter((p) => (p.used_in_final_merge?.length ?? 0) > 0).length}</p>
                                                </div>
                                            </div>
                                            <div className="space-y-3">
                                                {pageAnalysis.map((p, idx) => (
                                                    <div
                                                        key={idx}
                                                        className={`rounded-lg border p-3 space-y-2 ${
                                                            (p.used_in_final_merge?.length ?? 0) > 0 ? 'border-indigo-500/30 bg-indigo-500/5' : 'border-white/10 bg-white/5'
                                                        }`}
                                                    >
                                                        <div className="flex gap-3 items-start">
                                                            {p.thumbnail_url && (
                                                                <img src={p.thumbnail_url} alt={`Page ${p.page}`} className="w-20 h-24 object-contain rounded border border-white/10 shrink-0 bg-white/5" />
                                                            )}
                                                            <div className="flex-1 min-w-0">
                                                                <div className="flex flex-wrap items-center gap-2">
                                                                    <span className="font-medium text-white/90">Page {p.page}</span>
                                                                    <span className="text-xs px-1.5 py-0.5 rounded bg-white/20 text-white/80">{p.page_type ?? 'unknown'}</span>
                                                                    <span className="text-xs text-white/50">{(p.classification_confidence ?? 0) * 100}% confidence</span>
                                                                    {(p.used_in_final_merge?.length ?? 0) > 0 && (
                                                                        <span className="text-xs text-indigo-300">→ {p.used_in_final_merge.length} field(s) in merge</span>
                                                                    )}
                                                                </div>
                                                                {p.page_title && <p className="text-xs text-white/60 mt-0.5">{p.page_title}</p>}
                                                                {p.ocr_text_excerpt && <p className="text-xs text-white/50 mt-1 line-clamp-2 font-mono">{p.ocr_text_excerpt}</p>}
                                                                {(p.eligible_fields?.length ?? 0) > 0 && (
                                                                    <p className="text-xs text-white/50 mt-1">
                                                                        Eligible: {(p.eligible_fields || []).join(', ')}
                                                                    </p>
                                                                )}
                                                                {(p.attempted_fields?.length ?? 0) > 0 && (
                                                                    <p className="text-xs text-white/50">
                                                                        Attempted: {(p.attempted_fields || []).join(', ')}
                                                                    </p>
                                                                )}
                                                                {(p.accepted_fields?.length ?? 0) > 0 && (
                                                                    <p className="text-xs text-emerald-400/80">
                                                                        Accepted: {(p.accepted_fields || []).join(', ')}
                                                                    </p>
                                                                )}
                                                                {(p.rejected_fields?.length ?? 0) > 0 && (
                                                                    <p className="text-xs text-amber-400/80">
                                                                        Rejected: {(p.rejected_fields || []).map((r) => (typeof r === 'object' && r && 'path' in r) ? r.path : String(r)).filter(Boolean).join(', ')}
                                                                    </p>
                                                                )}
                                                                <div className="mt-2 flex flex-wrap gap-2">
                                                                    {(p.accepted_candidates?.length ?? 0) > 0 && <span className="text-xs text-emerald-400/90">✓ {p.accepted_candidates.length} accepted</span>}
                                                                    {(p.rejected_candidates?.length ?? 0) > 0 && <span className="text-xs text-amber-400/90">✗ {p.rejected_candidates.length} rejected</span>}
                                                                    {(p.used_in_final_merge?.length ?? 0) > 0 && <span className="text-xs text-indigo-300">{p.used_in_final_merge.join(', ')}</span>}
                                                                </div>
                                                                {(p.rejected_candidates?.length ?? 0) > 0 && (
                                                                    <div className="mt-2 space-y-1">
                                                                        <p className="text-xs text-white/50">Rejections:</p>
                                                                        {p.rejected_candidates.slice(0, 5).map((r, ri) => {
                                                                            const valStr = typeof r.value === 'string' ? r.value : JSON.stringify(r.value ?? '')
                                                                            return (
                                                                                <div key={ri} className="text-xs text-amber-300/80">
                                                                                    {r.path}: &quot;{valStr.slice(0, 40)}{valStr.length > 40 ? '…' : ''}&quot; — {r.reason}
                                                                                </div>
                                                                            )
                                                                        })}
                                                                    </div>
                                                                )}
                                                            </div>
                                                        </div>
                                                    </div>
                                                ))}
                                            </div>
                                        </>
                                    ) : (
                                        <div className="space-y-2 text-xs text-white/50">
                                            {processing ? (
                                                <p className="text-amber-400/90">Processing… Page analysis will appear when PDF extraction completes. Polling every 2s.</p>
                                            ) : (
                                                <>
                                                    {!pageClassifications && <p>Page classifications not available</p>}
                                                    {!pageExtractions && <p>Page extractions not available</p>}
                                                    {pageClassifications && pageExtractions && <p>No page analysis data was built from classifications/extractions.</p>}
                                                    {hasSnapshot && !pageClassifications && !pageExtractions && (
                                                        <p className="text-amber-400/90 mt-2">
                                                            This snapshot may be from before the visual pipeline ran. Ensure BRAND_DNA_VISUAL_PAGE_EXTRACTION_ENABLED=true, restart the queue worker, then use &quot;Invalidate &amp; re-run&quot; below.
                                                        </p>
                                                    )}
                                                </>
                                            )}
                                        </div>
                                    )}
                                </div>
                            )}
                        </div>

                        {/* Raw JSON — collapsed by default */}
                        <div className="space-y-2">
                            <button
                                type="button"
                                onClick={() => setRawJsonOpen(!rawJsonOpen)}
                                className="w-full flex items-center justify-between text-left text-xs font-medium text-white/70 hover:text-white/90"
                            >
                                <span className="uppercase">Raw JSON</span>
                                <svg className={`w-4 h-4 transition-transform ${rawJsonOpen ? 'rotate-180' : ''}`} fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
                                </svg>
                            </button>
                            {rawJsonOpen && (
                                <pre className="text-xs text-white/70 overflow-x-auto p-4 rounded-lg bg-black/30 font-mono whitespace-pre-wrap break-words max-h-96 overflow-y-auto">
                                    {JSON.stringify(researchData, null, 2)}
                                </pre>
                            )}
                        </div>

                        {sections?.sections?.length > 0 && (
                            <div className="space-y-3">
                                <h5 className="text-xs font-medium text-white/60 uppercase">PDF Sections</h5>
                                <div className="rounded-lg bg-white/10 p-3">
                                    {(sections.section_count_raw != null || sections.section_count_usable != null || sections.section_count_suppressed != null) ? (
                                        <div className="text-xs text-white/50 mb-2 space-y-0.5">
                                            <p>Parsed sections: {sections.section_count_raw ?? sections.sections.length}</p>
                                            <p>Usable sections: {sections.section_count_usable ?? sections.sections.length}</p>
                                            {(sections.section_count_suppressed ?? 0) > 0 && (
                                                <p>Suppressed repeated sections: {sections.section_count_suppressed}</p>
                                            )}
                                        </div>
                                    ) : (
                                        <p className="text-xs text-white/50 mb-2">{sections.sections.length} sections detected</p>
                                    )}
                                    <ul className="text-xs text-white/70 space-y-1">
                                        {sections.sections.slice(0, 20).map((s, i) => (
                                            <li key={i}>
                                                {s.title}
                                                {s.page != null && <span className="text-white/50"> (p.{s.page})</span>}
                                            </li>
                                        ))}
                                    </ul>
                                    {sections.suppressed_sections && sections.suppressed_sections.length > 0 && (
                                        <div className="mt-2 pt-2 border-t border-white/10">
                                            <p className="text-xs text-white/50 mb-1">Suppressed repeated sections</p>
                                            <ul className="text-xs text-white/60 space-y-0.5">
                                                {sections.suppressed_sections.slice(0, 10).map((s, i) => (
                                                    <li key={i}>{s.title}: {s.count} ({s.reason})</li>
                                                ))}
                                                {sections.suppressed_sections.length > 10 && (
                                                    <li className="text-white/40">…and {sections.suppressed_sections.length - 10} more</li>
                                                )}
                                            </ul>
                                        </div>
                                    )}
                                    {sections.toc_map && Object.keys(sections.toc_map).length > 0 && (
                                        <div className="mt-2 pt-2 border-t border-white/10">
                                            <p className="text-xs text-white/50 mb-1">Table of Contents map</p>
                                            <pre className="text-xs text-white/60 font-mono">{JSON.stringify(sections.toc_map, null, 2)}</pre>
                                        </div>
                                    )}
                                </div>
                            </div>
                        )}
                    </div>
                )}
            </div>
            )}

            {/* Processing notice (when not yet finalized) */}
            {processing && !finalized && (
                <div className="rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-200/90">
                    Ingestion still running. Results will update automatically.
                </div>
            )}
        </div>
    )
}

// Helper for parent: canProceed when research_finalized is true (or legacy: snapshot exists and not processing)
export function canProceedFromResearchSummary(research, initialSnapshotLite, initialSnapshot, ingestionProcessing, researchFinalized = null) {
    if (researchFinalized === true) {
        return true
    }
    if (researchFinalized === false) {
        return false
    }
    const lite = research?.latestSnapshotLite ?? initialSnapshotLite
    const processing = research?.ingestionProcessing ?? ingestionProcessing
    const snapshot = research?.latestSnapshot ?? initialSnapshot ?? {}
    const hasSnapshot = !!lite || (snapshot && Object.keys(snapshot).length > 0)
    return hasSnapshot && !processing
}
