import { Link, router, usePage } from '@inertiajs/react'
import { useEffect, useState } from 'react'
import AppNav from '../../../../Components/AppNav'

const STAGE_LABELS = {
    scraping_homepage: 'Analyzing homepage',
    discovering_pages: 'Discovering key pages',
    scraping_pages: 'Analyzing key pages',
    normalizing_signals: 'Structuring brand signals',
    ai_extracting_signals: 'Extracting strategic signals',
    ai_synthesizing_brand: 'Building brand intelligence profile',
}

const ARCHETYPES = [
    'Creator', 'Caregiver', 'Ruler', 'Jester', 'Everyman', 'Lover',
    'Hero', 'Outlaw', 'Magician', 'Innocent', 'Sage', 'Explorer',
]

function mergePayload(base, incoming) {
    const result = JSON.parse(JSON.stringify(base))
    if (!incoming || typeof incoming !== 'object') return result
    if (incoming.identity) result.identity = { ...result.identity, ...incoming.identity }
    if (incoming.personality) {
        result.personality = {
            ...result.personality,
            ...incoming.personality,
            traits: Array.isArray(incoming.personality.traits) ? incoming.personality.traits : result.personality.traits,
        }
    }
    if (incoming.visual) result.visual = { ...result.visual, ...incoming.visual }
    if (incoming.typography) result.typography = { ...result.typography, ...incoming.typography }
    if (incoming.scoring_config) result.scoring_config = { ...result.scoring_config, ...incoming.scoring_config }
    if (incoming.scoring_rules) {
        const rawPalette = Array.isArray(incoming.scoring_rules.allowed_color_palette) ? incoming.scoring_rules.allowed_color_palette : result.scoring_rules.allowed_color_palette
        const normalizedPalette = (rawPalette || []).map((c) =>
            typeof c === 'string' ? { hex: c, role: null } : { hex: c?.hex ?? c, role: c?.role ?? null }
        )
        result.scoring_rules = {
            allowed_color_palette: normalizedPalette,
            allowed_fonts: Array.isArray(incoming.scoring_rules.allowed_fonts) ? incoming.scoring_rules.allowed_fonts : result.scoring_rules.allowed_fonts,
            banned_colors: Array.isArray(incoming.scoring_rules.banned_colors) ? incoming.scoring_rules.banned_colors : result.scoring_rules.banned_colors,
            tone_keywords: Array.isArray(incoming.scoring_rules.tone_keywords) ? incoming.scoring_rules.tone_keywords : result.scoring_rules.tone_keywords,
            banned_keywords: Array.isArray(incoming.scoring_rules.banned_keywords) ? incoming.scoring_rules.banned_keywords : result.scoring_rules.banned_keywords,
            photography_attributes: Array.isArray(incoming.scoring_rules.photography_attributes) ? incoming.scoring_rules.photography_attributes : result.scoring_rules.photography_attributes,
        }
    }
    return result
}

const DEFAULT_PAYLOAD = {
    identity: { tagline: '', mission: '', positioning: '', industry: '', target_audience: '' },
    personality: { archetype: '', traits: [], tone: '', voice: '' },
    visual: { style: '', composition: '', color_temperature: '' },
    typography: { primary_font_style: '', secondary_font_style: '', font_mood: '' },
    scoring_rules: {
        allowed_color_palette: [], allowed_fonts: [], banned_colors: [],
        tone_keywords: [], banned_keywords: [], photography_attributes: [],
    },
    scoring_config: { color_weight: 30, typography_weight: 20, tone_weight: 30, imagery_weight: 20 },
}

export default function BrandBootstrapShow({ brand, run }) {
    const { auth } = usePage().props
    const [rawJsonOpen, setRawJsonOpen] = useState(false)
    const [stageLogOpen, setStageLogOpen] = useState(false)
    const [payload, setPayload] = useState(() => mergePayload(DEFAULT_PAYLOAD, run?.ai_output_payload || {}))
    const [traitInput, setTraitInput] = useState('')
    const [scoringRuleInputs, setScoringRuleInputs] = useState({})
    const [newColorInput, setNewColorInput] = useState('')

    useEffect(() => {
        if (run?.ai_output_payload && Object.keys(run.ai_output_payload).length > 0) {
            setPayload(mergePayload(DEFAULT_PAYLOAD, run.ai_output_payload))
        }
    }, [run?.ai_output_payload])

    useEffect(() => {
        if (!run?.status || ['inferred', 'failed'].includes(run.status)) return
        const id = setInterval(() => router.reload({ preserveScroll: true }), 2000)
        return () => clearInterval(id)
    }, [run?.status])

    const statusBadgeClass = (status) => {
        switch (status) {
            case 'completed': return 'bg-green-100 text-green-800'
            case 'inferred': return 'bg-emerald-100 text-emerald-800'
            case 'failed': return 'bg-red-100 text-red-800'
            case 'running': return 'bg-blue-100 text-blue-800'
            default: return 'bg-gray-100 text-gray-800'
        }
    }

    const rawPayload = run?.raw_payload || {}
    const displayData = rawPayload.normalized || rawPayload.homepage || rawPayload
    const aiSignals = rawPayload.ai_signals || {}
    const meta = displayData.meta || {}
    const branding = displayData.branding || {}
    const headlines = displayData.headlines || {}
    const navigation = displayData.navigation || {}
    const colors = displayData.colors_detected || []
    const fontFamilies = displayData.font_families || []
    const isError = rawPayload.error
    const progressPercent = run?.progress_percent ?? 0
    const stageLabel = STAGE_LABELS[run?.stage] || run?.stage || ''
    const stageLog = run?.stage_log || []

    const updatePayload = (section, field, value) => {
        setPayload((prev) => ({
            ...prev,
            [section]: { ...prev[section], [field]: value },
        }))
    }

    const addTrait = () => {
        const t = traitInput.trim()
        if (t && !payload.personality.traits.includes(t)) {
            updatePayload('personality', 'traits', [...payload.personality.traits, t])
            setTraitInput('')
        }
    }

    const removeTrait = (idx) => {
        updatePayload('personality', 'traits', payload.personality.traits.filter((_, i) => i !== idx))
    }

    const addScoringRuleItem = (ruleKey, value) => {
        const v = (typeof value === 'string' ? value : '').trim()
        if (!v) return
        const arr = payload.scoring_rules?.[ruleKey] ?? []
        if (arr.includes(v)) return
        setPayload((prev) => ({
            ...prev,
            scoring_rules: { ...prev.scoring_rules, [ruleKey]: [...arr, v] },
        }))
        setScoringRuleInputs((prev) => ({ ...prev, [ruleKey]: '' }))
    }

    const removeScoringRuleItem = (ruleKey, idx) => {
        const arr = (payload.scoring_rules?.[ruleKey] ?? []).filter((_, i) => i !== idx)
        setPayload((prev) => ({
            ...prev,
            scoring_rules: { ...prev.scoring_rules, [ruleKey]: arr },
        }))
    }

    const weightTotal = Math.round(
        ((payload.scoring_config?.color_weight ?? 30) +
        (payload.scoring_config?.typography_weight ?? 20) +
        (payload.scoring_config?.tone_weight ?? 30) +
        (payload.scoring_config?.imagery_weight ?? 20))
    )
    const weightsValid = weightTotal === 100

    const handleApprove = () => {
        if (!weightsValid) return
        const cfg = payload.scoring_config || {}
        const modelPayload = {
            ...payload,
            scoring_config: {
                color_weight: (Number(cfg.color_weight) || 30) / 100,
                typography_weight: (Number(cfg.typography_weight) || 20) / 100,
                tone_weight: (Number(cfg.tone_weight) || 30) / 100,
                imagery_weight: (Number(cfg.imagery_weight) || 20) / 100,
            },
        }
        const url = typeof route === 'function' ? route('brands.dna.bootstrap.approve', { brand: brand.id, run: run.id }) : `/app/brands/${brand.id}/dna/bootstrap/${run.id}/approve`
        router.post(url, { model_payload: modelPayload }, { preserveScroll: true })
    }

    const COLOR_ROLES = [
        { value: null, label: '—' },
        { value: 'primary', label: 'Primary' },
        { value: 'secondary', label: 'Secondary' },
        { value: 'accent', label: 'Accent' },
        { value: 'neutral', label: 'Neutral' },
    ]

    const addColorToPalette = (hex, role = null) => {
        let h = (hex || '').trim()
        if (!h) return
        if (!h.startsWith('#')) h = '#' + h
        const arr = payload.scoring_rules?.allowed_color_palette ?? []
        if (arr.some((c) => (typeof c === 'string' ? c : c?.hex) === h)) return
        setPayload((prev) => ({
            ...prev,
            scoring_rules: {
                ...prev.scoring_rules,
                allowed_color_palette: [...(prev.scoring_rules?.allowed_color_palette ?? []), { hex: h, role }],
            },
        }))
        setNewColorInput('')
    }

    const updateColorInPalette = (idx, updates) => {
        const arr = [...(payload.scoring_rules?.allowed_color_palette ?? [])]
        const item = arr[idx]
        const current = typeof item === 'string' ? { hex: item, role: null } : { hex: item?.hex, role: item?.role }
        arr[idx] = { ...current, ...updates }
        setPayload((prev) => ({
            ...prev,
            scoring_rules: { ...prev.scoring_rules, allowed_color_palette: arr },
        }))
    }

    const removeColorFromPalette = (idx) => {
        const arr = (payload.scoring_rules?.allowed_color_palette ?? []).filter((_, i) => i !== idx)
        setPayload((prev) => ({
            ...prev,
            scoring_rules: { ...prev.scoring_rules, allowed_color_palette: arr },
        }))
    }

    const renderColorPaletteField = () => {
        const items = payload.scoring_rules?.allowed_color_palette ?? []
        const normalized = items.map((c) => (typeof c === 'string' ? { hex: c, role: null } : { hex: c?.hex ?? '', role: c?.role ?? null }))
        return (
            <div className="space-y-1">
                <label className="block text-xs font-medium text-gray-500">Allowed Colors</label>
                <div className="flex flex-wrap gap-2">
                    {normalized.map((item, i) => (
                        <div key={i} className="inline-flex items-center gap-1 rounded border border-gray-200 bg-white px-2 py-1">
                            <input
                                type="color"
                                value={item.hex?.startsWith('#') ? item.hex : '#6366f1'}
                                onChange={(e) => updateColorInPalette(i, { hex: e.target.value })}
                                className="h-5 w-5 cursor-pointer rounded border border-gray-200"
                            />
                            <input
                                type="text"
                                value={item.hex || ''}
                                onChange={(e) => updateColorInPalette(i, { hex: e.target.value })}
                                placeholder="#hex"
                                className="w-16 rounded border border-gray-200 px-1 py-0.5 text-[10px] font-mono"
                            />
                            <select
                                value={item.role ?? ''}
                                onChange={(e) => updateColorInPalette(i, { role: e.target.value || null })}
                                className="rounded border border-gray-200 px-1 py-0.5 text-[10px]"
                            >
                                {COLOR_ROLES.map((r) => (
                                    <option key={r.value ?? 'none'} value={r.value ?? ''}>{r.label}</option>
                                ))}
                            </select>
                            <button type="button" onClick={() => removeColorFromPalette(i)} className="text-indigo-400 hover:text-indigo-600">×</button>
                        </div>
                    ))}
                </div>
                <div className="flex gap-1">
                    <input
                        type="color"
                        value={/^#[0-9A-Fa-f]{6}$/.test(newColorInput) ? newColorInput : '#6366f1'}
                        className="h-6 w-6 cursor-pointer rounded border"
                        onChange={(e) => setNewColorInput(e.target.value)}
                    />
                    <input
                        type="text"
                        value={newColorInput}
                        onChange={(e) => setNewColorInput(e.target.value)}
                        placeholder="#hex"
                        className="flex-1 rounded border border-gray-200 px-2 py-1 text-xs"
                        onKeyDown={(e) => e.key === 'Enter' && (e.preventDefault(), addColorToPalette(newColorInput))}
                    />
                    <button type="button" onClick={() => addColorToPalette(newColorInput)} className="rounded bg-gray-100 px-2 py-1 text-xs">Add</button>
                </div>
            </div>
        )
    }

    const renderTagArrayField = (ruleKey, label, placeholder) => {
        const items = payload.scoring_rules?.[ruleKey] ?? []
        const inputVal = scoringRuleInputs[ruleKey] ?? ''
        return (
            <div key={ruleKey} className="space-y-1">
                <label className="block text-xs font-medium text-gray-500">{label}</label>
                <div className="flex flex-wrap gap-1.5">
                    {items.map((t, i) => (
                        <span key={i} className="inline-flex items-center gap-1 rounded-full bg-indigo-50 px-2 py-0.5 text-xs text-indigo-800">
                            {t}
                            <button type="button" onClick={() => removeScoringRuleItem(ruleKey, i)} className="text-indigo-400 hover:text-indigo-600">×</button>
                        </span>
                    ))}
                </div>
                <div className="flex gap-1">
                    <input
                        type="text"
                        value={inputVal}
                        onChange={(e) => setScoringRuleInputs((prev) => ({ ...prev, [ruleKey]: e.target.value }))}
                        onKeyDown={(e) => e.key === 'Enter' && (e.preventDefault(), addScoringRuleItem(ruleKey, inputVal))}
                        placeholder={placeholder}
                        className="block flex-1 rounded border border-gray-200 px-2 py-1 text-xs focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                    />
                    <button type="button" onClick={() => addScoringRuleItem(ruleKey, inputVal)} className="rounded bg-gray-100 px-2 py-1 text-xs font-medium text-gray-700 hover:bg-gray-200">Add</button>
                </div>
            </div>
        )
    }

    return (
        <div className="min-h-full">
            <AppNav brand={auth?.activeBrand} tenant={null} />
            <main className="bg-gray-50">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
                    <Link
                        href={typeof route === 'function' ? route('brands.dna.bootstrap.index', { brand: brand.id }) : `/app/brands/${brand.id}/dna/bootstrap`}
                        className="text-sm font-medium text-gray-500 hover:text-gray-700"
                    >
                        ← Back to Bootstrap
                    </Link>
                    <div className="mt-6 flex items-center justify-between">
                        <h1 className="text-2xl font-bold text-gray-900">Bootstrap Run</h1>
                        <span className={`inline-flex rounded-full px-3 py-1 text-sm font-medium ${statusBadgeClass(run?.status)}`}>
                            {run?.status}
                        </span>
                    </div>
                    {run?.source_url && (
                        <p className="mt-2 text-sm text-gray-600">{run.source_url}</p>
                    )}
                    <p className="mt-1 text-xs text-gray-500">
                        Created: {run?.created_at ? new Date(run.created_at).toLocaleString() : '—'}
                    </p>

                    {run?.status !== 'inferred' && run?.status !== 'failed' && (
                        <div className="mt-6 space-y-2">
                            <div className="flex items-center justify-between text-sm">
                                <span className="font-medium text-gray-700">{stageLabel || 'Processing…'}</span>
                                <span className="text-gray-500">{progressPercent}%</span>
                            </div>
                            <div className="h-2 w-full rounded-full bg-gray-200">
                                <div
                                    className="h-2 rounded-full bg-blue-600 transition-all duration-300"
                                    style={{ width: `${progressPercent}%` }}
                                />
                            </div>
                        </div>
                    )}

                    {run?.status === 'running' && !stageLabel && (
                        <div className="mt-8 flex items-center gap-3 rounded-lg border border-blue-200 bg-blue-50 p-4">
                            <svg className="h-5 w-5 animate-spin text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                            </svg>
                            <span className="text-sm font-medium text-blue-800">Processing…</span>
                        </div>
                    )}

                    {(run?.status === 'running' || run?.status === 'pending') && displayData && Object.keys(displayData).length > 0 && !isError && (
                        <div className="mt-8 space-y-8">
                            {meta.title && <section><h2 className="text-sm font-semibold uppercase tracking-wide text-gray-500">Meta Title</h2><p className="mt-1 text-gray-900">{meta.title}</p></section>}
                            {meta.description && <section><h2 className="text-sm font-semibold uppercase tracking-wide text-gray-500">Description</h2><p className="mt-1 text-gray-600">{meta.description}</p></section>}
                            {branding.logo_candidates?.length > 0 && (
                                <section>
                                    <h2 className="text-sm font-semibold uppercase tracking-wide text-gray-500">Logo Candidates</h2>
                                    <div className="mt-2 flex flex-wrap gap-4">
                                        {branding.logo_candidates.map((src, i) => (
                                            <a key={i} href={src} target="_blank" rel="noopener noreferrer" className="block rounded border border-gray-200 bg-white p-2 hover:border-gray-300">
                                                <img src={src} alt={`Logo ${i + 1}`} className="h-16 w-auto max-w-[120px] object-contain" onError={(e) => e.target.style.display = 'none'} />
                                            </a>
                                        ))}
                                    </div>
                                </section>
                            )}
                            {colors.length > 0 && (
                                <section>
                                    <h2 className="text-sm font-semibold uppercase tracking-wide text-gray-500">Detected Colors</h2>
                                    <div className="mt-2 flex flex-wrap gap-2">
                                        {colors.map((hex, i) => (
                                            <span key={i} className="inline-flex items-center gap-2 rounded border border-gray-200 bg-white px-2 py-1">
                                                <span className="h-6 w-6 rounded border border-gray-200" style={{ backgroundColor: hex }} />
                                                <span className="text-xs font-mono text-gray-700">{hex}</span>
                                            </span>
                                        ))}
                                    </div>
                                </section>
                            )}
                            {fontFamilies.length > 0 && (
                                <section>
                                    <h2 className="text-sm font-semibold uppercase tracking-wide text-gray-500">Detected Fonts</h2>
                                    <div className="mt-2 flex flex-wrap gap-2">
                                        {fontFamilies.map((font, i) => (
                                            <span key={i} className="inline-flex items-center gap-2 rounded border border-gray-200 bg-white px-2 py-1">
                                                <span className="text-xs font-medium text-gray-700" style={{ fontFamily: font }}>{font}</span>
                                                <button
                                                    type="button"
                                                    onClick={() => updatePayload('typography', 'primary_font_style', font)}
                                                    className="text-xs text-indigo-600 hover:text-indigo-800"
                                                >
                                                    Set as primary
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => updatePayload('typography', 'secondary_font_style', font)}
                                                    className="text-xs text-indigo-600 hover:text-indigo-800"
                                                >
                                                    Set as secondary
                                                </button>
                                            </span>
                                        ))}
                                    </div>
                                </section>
                            )}
                        </div>
                    )}

                    {run?.status === 'inferred' && (
                        <div className="mt-8 grid grid-cols-1 gap-8 lg:grid-cols-2">
                            {/* Left column — Strategic analysis */}
                            <div className="space-y-6">
                                <div className="rounded-xl bg-gradient-to-br from-slate-50 to-slate-100/80 p-6 ring-1 ring-slate-200/50">
                                    <h2 className="text-sm font-semibold uppercase tracking-wide text-slate-600">Strategic Analysis</h2>
                                    <div className="mt-4 space-y-4">
                                        {aiSignals.messaging_themes?.length > 0 && (
                                            <div>
                                                <h3 className="text-xs font-medium text-slate-500">Messaging Clusters</h3>
                                                <div className="mt-1 flex flex-wrap gap-1.5">
                                                    {aiSignals.messaging_themes.map((t, i) => (
                                                        <span key={i} className="rounded-full bg-indigo-100 px-2.5 py-0.5 text-xs text-indigo-800">{t}</span>
                                                    ))}
                                                </div>
                                            </div>
                                        )}
                                        {aiSignals.tone_indicators?.length > 0 && (
                                            <div>
                                                <h3 className="text-xs font-medium text-slate-500">Tone Analysis</h3>
                                                <p className="mt-1 text-sm text-slate-700">{aiSignals.tone_indicators.join(', ')}</p>
                                            </div>
                                        )}
                                        {aiSignals.industry_guess && (
                                            <div>
                                                <h3 className="text-xs font-medium text-slate-500">Industry Guess</h3>
                                                <p className="mt-1 text-sm font-medium text-slate-800">{aiSignals.industry_guess}</p>
                                            </div>
                                        )}
                                        {aiSignals.visual_style && (
                                            <div>
                                                <h3 className="text-xs font-medium text-slate-500">Visual Classification</h3>
                                                <p className="mt-1 text-sm text-slate-700">{aiSignals.visual_style}</p>
                                            </div>
                                        )}
                                        {aiSignals.color_profile && (
                                            <div>
                                                <h3 className="text-xs font-medium text-slate-500">Color Profile</h3>
                                                <p className="mt-1 text-sm text-slate-700">{aiSignals.color_profile}</p>
                                            </div>
                                        )}
                                        {fontFamilies.length > 0 && (
                                            <div>
                                                <h3 className="text-xs font-medium text-slate-500">Detected Fonts</h3>
                                                <div className="mt-2 flex flex-wrap gap-2">
                                                    {fontFamilies.map((font, i) => (
                                                        <span key={i} className="inline-flex items-center gap-2 rounded border border-slate-200 bg-white px-2 py-1">
                                                            <span className="text-xs font-medium text-slate-700" style={{ fontFamily: font }}>{font}</span>
                                                            <button
                                                                type="button"
                                                                onClick={() => {
                                                                    updatePayload('typography', 'primary_font_style', font)
                                                                    setPayload((prev) => ({
                                                                        ...prev,
                                                                        scoring_rules: {
                                                                            ...prev.scoring_rules,
                                                                            allowed_fonts: [...new Set([...(prev.scoring_rules?.allowed_fonts ?? []), font])],
                                                                        },
                                                                    }))
                                                                }}
                                                                className="text-xs text-indigo-600 hover:text-indigo-800"
                                                            >
                                                                Set as primary
                                                            </button>
                                                            <button
                                                                type="button"
                                                                onClick={() => {
                                                                    updatePayload('typography', 'secondary_font_style', font)
                                                                    setPayload((prev) => ({
                                                                        ...prev,
                                                                        scoring_rules: {
                                                                            ...prev.scoring_rules,
                                                                            allowed_fonts: [...new Set([...(prev.scoring_rules?.allowed_fonts ?? []), font])],
                                                                        },
                                                                    }))
                                                                }}
                                                                className="text-xs text-indigo-600 hover:text-indigo-800"
                                                            >
                                                                Set as secondary
                                                            </button>
                                                        </span>
                                                    ))}
                                                </div>
                                            </div>
                                        )}
                                        {(aiSignals.confidence_score != null) && (
                                            <div>
                                                <h3 className="text-xs font-medium text-slate-500">Confidence Score</h3>
                                                <div className="mt-2 h-2 w-full rounded-full bg-slate-200">
                                                    <div
                                                        className="h-2 rounded-full bg-indigo-500 transition-all"
                                                        style={{ width: `${Math.min(100, Math.max(0, aiSignals.confidence_score ?? 0))}%` }}
                                                    />
                                                </div>
                                                <p className="mt-1 text-xs text-slate-600">{aiSignals.confidence_score ?? 0}%</p>
                                            </div>
                                        )}
                                    </div>
                                </div>

                                <button
                                    type="button"
                                    onClick={() => setStageLogOpen(!stageLogOpen)}
                                    className="flex items-center gap-2 text-sm font-medium text-gray-600 hover:text-gray-900"
                                >
                                    {stageLogOpen ? '▼' : '▶'} Stage Log
                                </button>
                                {stageLogOpen && stageLog.length > 0 && (
                                    <ul className="space-y-1 rounded-lg border border-gray-200 bg-white p-3 text-xs text-gray-600">
                                        {stageLog.map((entry, i) => (
                                            <li key={i}>{entry.message || JSON.stringify(entry)}</li>
                                        ))}
                                    </ul>
                                )}
                            </div>

                            {/* Right column — Editable AI Draft */}
                            <div className="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200/50">
                                <h2 className="text-sm font-semibold uppercase tracking-wide text-gray-600">Editable AI Draft</h2>
                                <div className="mt-6 space-y-6">
                                    <section>
                                        <h3 className="text-xs font-semibold text-gray-500 mb-2">Identity</h3>
                                        <div className="space-y-2">
                                            {['tagline', 'mission', 'positioning', 'industry', 'target_audience'].map((f) => (
                                                <div key={f}>
                                                    <label className="block text-xs text-gray-500 capitalize">{f.replace('_', ' ')}</label>
                                                    <input
                                                        type="text"
                                                        value={payload.identity?.[f] ?? ''}
                                                        onChange={(e) => updatePayload('identity', f, e.target.value)}
                                                        className="mt-0.5 block w-full rounded border border-gray-200 px-2 py-1.5 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                                                    />
                                                </div>
                                            ))}
                                        </div>
                                    </section>
                                    <section>
                                        <h3 className="text-xs font-semibold text-gray-500 mb-2">Personality</h3>
                                        <div className="space-y-2">
                                            <div>
                                                <label className="block text-xs text-gray-500">Archetype</label>
                                                <select
                                                    value={payload.personality?.archetype ?? ''}
                                                    onChange={(e) => updatePayload('personality', 'archetype', e.target.value)}
                                                    className="mt-0.5 block w-full rounded border border-gray-200 px-2 py-1.5 text-sm"
                                                >
                                                    <option value="">Select</option>
                                                    {ARCHETYPES.map((a) => <option key={a} value={a}>{a}</option>)}
                                                </select>
                                            </div>
                                            <div>
                                                <label className="block text-xs text-gray-500">Tone</label>
                                                <input type="text" value={payload.personality?.tone ?? ''} onChange={(e) => updatePayload('personality', 'tone', e.target.value)} className="mt-0.5 block w-full rounded border border-gray-200 px-2 py-1.5 text-sm" />
                                            </div>
                                            <div>
                                                <label className="block text-xs text-gray-500">Voice</label>
                                                <input type="text" value={payload.personality?.voice ?? ''} onChange={(e) => updatePayload('personality', 'voice', e.target.value)} className="mt-0.5 block w-full rounded border border-gray-200 px-2 py-1.5 text-sm" />
                                            </div>
                                            <div>
                                                <label className="block text-xs text-gray-500">Traits</label>
                                                <div className="mt-1 flex flex-wrap gap-1.5">
                                                    {(payload.personality?.traits ?? []).map((t, i) => (
                                                        <span key={i} className="inline-flex items-center gap-1 rounded-full bg-indigo-50 px-2 py-0.5 text-xs text-indigo-800">
                                                            {t}
                                                            <button type="button" onClick={() => removeTrait(i)} className="text-indigo-400 hover:text-indigo-600">×</button>
                                                        </span>
                                                    ))}
                                                </div>
                                                <div className="mt-2 flex gap-1">
                                                    <input type="text" value={traitInput} onChange={(e) => setTraitInput(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && (e.preventDefault(), addTrait())} placeholder="Add trait" className="block flex-1 rounded border border-gray-200 px-2 py-1 text-sm" />
                                                    <button type="button" onClick={addTrait} className="rounded bg-gray-100 px-2 py-1 text-xs font-medium text-gray-700 hover:bg-gray-200">Add</button>
                                                </div>
                                            </div>
                                        </div>
                                    </section>
                                    <section>
                                        <h3 className="text-xs font-semibold text-gray-500 mb-2">Visual</h3>
                                        <div className="space-y-2">
                                            {['style', 'composition', 'color_temperature'].map((f) => (
                                                <div key={f}>
                                                    <label className="block text-xs text-gray-500 capitalize">{f.replace('_', ' ')}</label>
                                                    <input type="text" value={payload.visual?.[f] ?? ''} onChange={(e) => updatePayload('visual', f, e.target.value)} className="mt-0.5 block w-full rounded border border-gray-200 px-2 py-1.5 text-sm" />
                                                </div>
                                            ))}
                                        </div>
                                    </section>
                                    <section>
                                        <h3 className="text-xs font-semibold text-gray-500 mb-2">Typography</h3>
                                        <div className="space-y-2">
                                            {['primary_font_style', 'secondary_font_style', 'font_mood'].map((f) => (
                                                <div key={f}>
                                                    <label className="block text-xs text-gray-500 capitalize">{f.replace(/_/g, ' ')}</label>
                                                    <input type="text" value={payload.typography?.[f] ?? ''} onChange={(e) => updatePayload('typography', f, e.target.value)} className="mt-0.5 block w-full rounded border border-gray-200 px-2 py-1.5 text-sm" />
                                                </div>
                                            ))}
                                        </div>
                                    </section>
                                    <section>
                                        <h3 className="text-xs font-semibold text-gray-500 mb-2">Scoring Rules</h3>
                                        <div className="space-y-3">
                                            {renderColorPaletteField()}
                                            {renderTagArrayField('allowed_fonts', 'Allowed Fonts', 'e.g. Inter')}
                                            {renderTagArrayField('banned_colors', 'Banned Colors', '')}
                                            {renderTagArrayField('tone_keywords', 'Tone Keywords', '')}
                                            {renderTagArrayField('banned_keywords', 'Banned Keywords', '')}
                                            {renderTagArrayField('photography_attributes', 'Photography Attributes', '')}
                                        </div>
                                    </section>
                                    <section>
                                        <h3 className="text-xs font-semibold text-gray-500 mb-2">Scoring Weights (must total 100%)</h3>
                                        {[
                                            { key: 'color_weight', label: 'Color' },
                                            { key: 'typography_weight', label: 'Typography' },
                                            { key: 'tone_weight', label: 'Tone' },
                                            { key: 'imagery_weight', label: 'Imagery' },
                                        ].map(({ key, label }) => {
                                            const raw = payload.scoring_config?.[key]
                                            const val = typeof raw === 'number' ? (raw <= 1 ? Math.round(raw * 100) : Math.round(raw)) : 25
                                            return (
                                                <div key={key} className="flex items-center gap-3 mb-2">
                                                    <label className="w-24 text-xs text-gray-600">{label}</label>
                                                    <input
                                                        type="range"
                                                        min={0}
                                                        max={100}
                                                        value={val}
                                                        onChange={(e) => setPayload((prev) => ({
                                                            ...prev,
                                                            scoring_config: { ...prev.scoring_config, [key]: Number(e.target.value) },
                                                        }))}
                                                        className="flex-1 h-2 rounded-lg accent-indigo-600"
                                                    />
                                                    <span className="w-8 text-xs font-medium text-gray-700">{val}%</span>
                                                </div>
                                            )
                                        })}
                                        <p className={`mt-1 text-xs font-medium ${weightsValid ? 'text-green-600' : 'text-amber-600'}`}>
                                            Total: {weightTotal}% {!weightsValid && '— Must equal 100%'}
                                        </p>
                                    </section>
                                    <button
                                        type="button"
                                        onClick={handleApprove}
                                        disabled={!weightsValid}
                                        className="w-full rounded-lg bg-emerald-600 px-4 py-3 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-50 disabled:cursor-not-allowed"
                                    >
                                        Create Draft from This
                                    </button>
                                </div>
                            </div>
                        </div>
                    )}

                    {run?.status === 'failed' && isError && (
                        <div className="mt-8 rounded-lg border border-red-200 bg-red-50 p-4">
                            <p className="text-sm font-medium text-red-800">Error: {rawPayload.error}</p>
                        </div>
                    )}

                    <div className="mt-8 border-t border-gray-200 pt-6">
                        <button
                            type="button"
                            onClick={() => setRawJsonOpen(!rawJsonOpen)}
                            className="flex items-center gap-2 text-sm font-medium text-gray-500 hover:text-gray-700"
                        >
                            {rawJsonOpen ? '▼' : '▶'} Debug: Raw JSON
                        </button>
                        {rawJsonOpen && (
                            <div className="mt-2 space-y-2">
                                {run?.raw_payload && (
                                    <div>
                                        <p className="text-xs font-medium text-gray-500">raw_payload</p>
                                        <pre className="mt-1 rounded border border-gray-200 bg-gray-50 p-3 text-xs overflow-auto max-h-48">{JSON.stringify(run.raw_payload, null, 2)}</pre>
                                    </div>
                                )}
                                {run?.ai_output_payload && (
                                    <div>
                                        <p className="text-xs font-medium text-gray-500">ai_output_payload</p>
                                        <pre className="mt-1 rounded border border-gray-200 bg-gray-50 p-3 text-xs overflow-auto max-h-48">{JSON.stringify(run.ai_output_payload, null, 2)}</pre>
                                    </div>
                                )}
                            </div>
                        )}
                    </div>
                </div>
            </main>
        </div>
    )
}
