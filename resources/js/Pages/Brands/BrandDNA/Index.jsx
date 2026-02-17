import { useState, useEffect } from 'react'
import { Link, router, usePage } from '@inertiajs/react'
import AppNav from '../../../Components/AppNav'
import AssetImagePickerField from '../../../Components/media/AssetImagePickerField'
import AssetImagePickerFieldMulti from '../../../Components/media/AssetImagePickerFieldMulti'
import CollapsibleSection from '../../../Components/CollapsibleSection'
import axios from 'axios'

const DEFAULT_PAYLOAD = {
    identity: {
        mission: '',
        positioning: '',
        tagline: '',
        industry: '',
        target_audience: '',
    },
    personality: {
        archetype: '',
        traits: [],
        tone: '',
        voice_description: '',
    },
    visual: {
        color_temperature: 'neutral',
        visual_density: 'balanced',
        photography_style: '',
        composition_style: '',
    },
    typography: {
        primary_font: '',
        secondary_font: '',
        heading_style: '',
        body_style: '',
    },
    scoring_config: {
        color_weight: 0.1,
        typography_weight: 0.2,
        tone_weight: 0.2,
        imagery_weight: 0.5,
    },
    scoring_rules: {
        allowed_color_palette: [],
        allowed_fonts: [],
        banned_colors: [],
        tone_keywords: [],
        banned_keywords: [],
        photography_attributes: [],
    },
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

export default function BrandDNAIndex({ brand, brandModel, activeVersion, editingVersion, allVersions, complianceAggregate, topExecutions, bottomExecutions, visualReferences = [] }) {
    const { auth } = usePage().props
    const [activeSection, setActiveSection] = useState('identity')
    const [selectedVersionId, setSelectedVersionId] = useState(null)
    const [payload, setPayload] = useState(() => {
        const source = editingVersion || activeVersion
        return mergePayload(DEFAULT_PAYLOAD, source?.model_payload || {})
    })
    const [traitInput, setTraitInput] = useState('')

    const currentVersion = editingVersion || (selectedVersionId
        ? allVersions?.find((v) => v.id === selectedVersionId)
        : null) || activeVersion

    const isEditingDraft = currentVersion?.status === 'draft'
    const canActivate = isEditingDraft && currentVersion

    useEffect(() => {
        if (editingVersion) {
            setPayload(mergePayload(DEFAULT_PAYLOAD, editingVersion.model_payload))
            setSelectedVersionId(editingVersion.id)
        } else if (activeVersion && !selectedVersionId) {
            setPayload(mergePayload(DEFAULT_PAYLOAD, activeVersion.model_payload))
            setSelectedVersionId(activeVersion.id)
        }
    }, [editingVersion?.id, activeVersion?.id])

    const handleVersionSelect = async (versionId) => {
        setSelectedVersionId(versionId)
        if (!versionId) {
            setPayload(mergePayload(DEFAULT_PAYLOAD, activeVersion?.model_payload))
            return
        }
        if (versionId === activeVersion?.id) {
            setPayload(mergePayload(DEFAULT_PAYLOAD, activeVersion.model_payload))
            return
        }
        if (versionId === editingVersion?.id) {
            setPayload(mergePayload(DEFAULT_PAYLOAD, editingVersion.model_payload))
            return
        }
        try {
            const url = typeof route === 'function'
                ? route('brands.dna.versions.show', { brand: brand.id, version: versionId })
                : `/app/brands/${brand.id}/dna/versions/${versionId}`
            const { data } = await axios.get(url)
            setPayload(mergePayload(DEFAULT_PAYLOAD, data.version?.model_payload))
        } catch {
            setPayload(mergePayload(DEFAULT_PAYLOAD, {}))
        }
    }

    const [saving, setSaving] = useState(false)

    const weightTotal = Math.round(
        ((payload.scoring_config?.color_weight ?? 0.1) +
        (payload.scoring_config?.typography_weight ?? 0.2) +
        (payload.scoring_config?.tone_weight ?? 0.2) +
        (payload.scoring_config?.imagery_weight ?? 0.5)) * 100
    )
    const weightsValid = weightTotal === 100

    const handleSave = (e) => {
        e.preventDefault()
        if (!weightsValid) return
        setSaving(true)
        const url = typeof route === 'function'
            ? route('brands.dna.store', { brand: brand.id })
            : `/app/brands/${brand.id}/dna`
        const data = {
            model_payload: payload,
            version_id: isEditingDraft ? currentVersion?.id : null,
        }
        router.post(url, data, {
            preserveScroll: true,
            onFinish: () => setSaving(false),
        })
    }

    const handleToggleEnabled = () => {
        const url = typeof route === 'function'
            ? route('brands.dna.store', { brand: brand.id })
            : `/app/brands/${brand.id}/dna`
        router.post(url, { is_enabled: !brandModel?.is_enabled }, { preserveScroll: true })
    }

    const handleCreateDraft = () => {
        const url = typeof route === 'function'
            ? route('brands.dna.versions.store', { brand: brand.id })
            : `/app/brands/${brand.id}/dna/versions`
        router.post(url, {}, { preserveScroll: true })
    }

    const handleActivateDraft = () => {
        if (!currentVersion?.id) return
        const url = typeof route === 'function'
            ? route('brands.dna.versions.activate', { brand: brand.id, version: currentVersion.id })
            : `/app/brands/${brand.id}/dna/versions/${currentVersion.id}/activate`
        router.post(url, {}, { preserveScroll: true })
    }

    const updatePayload = (section, field, value) => {
        setPayload((prev) => ({
            ...prev,
            [section]: {
                ...prev[section],
                [field]: value,
            },
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

    const [scoringRuleInputs, setScoringRuleInputs] = useState({})
    const [newColorInput, setNewColorInput] = useState('')

    const addScoringRuleItem = (ruleKey, value) => {
        const v = (typeof value === 'string' ? value : '').trim()
        if (!v) return
        const arr = payload.scoring_rules?.[ruleKey] ?? []
        if (arr.includes(v)) return
        setPayload((prev) => ({
            ...prev,
            scoring_rules: {
                ...prev.scoring_rules,
                [ruleKey]: [...arr, v],
            },
        }))
        setScoringRuleInputs((prev) => ({ ...prev, [ruleKey]: '' }))
    }

    const removeScoringRuleItem = (ruleKey, idx) => {
        const arr = (payload.scoring_rules?.[ruleKey] ?? []).filter((_, i) => i !== idx)
        setPayload((prev) => ({
            ...prev,
            scoring_rules: {
                ...prev.scoring_rules,
                [ruleKey]: arr,
            },
        }))
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
            <div>
                <label className="block text-sm font-medium text-gray-700">Allowed Color Palette</label>
                <div className="mt-2 grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3">
                    {normalized.map((item, i) => (
                        <div key={i} className="flex items-center gap-2 rounded-lg border border-gray-200 bg-white p-2">
                            <input
                                type="color"
                                value={item.hex?.startsWith('#') ? item.hex : '#6366f1'}
                                onChange={(e) => updateColorInPalette(i, { hex: e.target.value })}
                                className="h-8 w-8 cursor-pointer rounded border border-gray-200"
                            />
                            <input
                                type="text"
                                value={item.hex || ''}
                                onChange={(e) => updateColorInPalette(i, { hex: e.target.value })}
                                placeholder="#hex"
                                className="flex-1 min-w-0 rounded border border-gray-200 px-2 py-1 text-xs font-mono"
                            />
                            <select
                                value={item.role ?? ''}
                                onChange={(e) => updateColorInPalette(i, { role: e.target.value || null })}
                                className="rounded border border-gray-200 px-2 py-1 text-xs"
                            >
                                {COLOR_ROLES.map((r) => (
                                    <option key={r.value ?? 'none'} value={r.value ?? ''}>{r.label}</option>
                                ))}
                            </select>
                            <button type="button" onClick={() => removeColorFromPalette(i)} className="text-gray-500 hover:text-red-600">×</button>
                        </div>
                    ))}
                </div>
                <div className="mt-2 flex gap-2">
                    <input
                        type="color"
                        value={/^#[0-9A-Fa-f]{6}$/.test(newColorInput) ? newColorInput : '#6366f1'}
                        className="h-8 w-8 cursor-pointer rounded border border-gray-200"
                        onChange={(e) => setNewColorInput(e.target.value)}
                    />
                    <input
                        type="text"
                        value={newColorInput}
                        onChange={(e) => setNewColorInput(e.target.value)}
                        placeholder="#003388 or hex"
                        className="flex-1 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') {
                                e.preventDefault()
                                addColorToPalette(newColorInput)
                            }
                        }}
                    />
                    <button type="button" onClick={() => addColorToPalette(newColorInput)} className="rounded-md bg-gray-100 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-200">Add</button>
                </div>
            </div>
        )
    }

    const renderTagArrayField = (ruleKey, label, placeholder) => {
        const items = payload.scoring_rules?.[ruleKey] ?? []
        const inputVal = scoringRuleInputs[ruleKey] ?? ''
        return (
            <div key={ruleKey}>
                <label className="block text-sm font-medium text-gray-700">{label}</label>
                <div className="mt-1 flex flex-wrap gap-2">
                    {items.map((t, i) => (
                        <span key={i} className="inline-flex items-center gap-1 rounded-full bg-gray-100 px-2.5 py-0.5 text-sm text-gray-800">
                            {t}
                            <button type="button" onClick={() => removeScoringRuleItem(ruleKey, i)} className="text-gray-500 hover:text-gray-700">×</button>
                        </span>
                    ))}
                </div>
                <div className="mt-2 flex gap-2">
                    <input
                        type="text"
                        value={inputVal}
                        onChange={(e) => setScoringRuleInputs((prev) => ({ ...prev, [ruleKey]: e.target.value }))}
                        onKeyDown={(e) => e.key === 'Enter' && (e.preventDefault(), addScoringRuleItem(ruleKey, inputVal))}
                        placeholder={placeholder}
                        className="block flex-1 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                    />
                    <button type="button" onClick={() => addScoringRuleItem(ruleKey, inputVal)} className="rounded-md bg-gray-100 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-200">Add</button>
                </div>
            </div>
        )
    }

    const sections = [
        { id: 'identity', label: 'Identity' },
        { id: 'personality', label: 'Personality' },
        { id: 'visual', label: 'Visual' },
        { id: 'typography', label: 'Typography' },
        { id: 'scoring', label: 'Scoring Rules' },
    ]

    const logoRef = visualReferences?.find((r) => r.type === 'logo')
    const lifestyleRefs = visualReferences?.filter((r) => r.type === 'lifestyle_photography') ?? []
    const productRefs = visualReferences?.filter((r) => r.type === 'product_photography') ?? []
    const graphicsRefs = visualReferences?.filter((r) => r.type === 'graphics_layout') ?? []
    const [logoAssetId, setLogoAssetId] = useState(logoRef?.asset_id ?? null)
    const [logoPreviewUrl, setLogoPreviewUrl] = useState(logoRef?.asset?.thumbnail_url ?? null)
    const [lifestyleAssets, setLifestyleAssets] = useState(() =>
        lifestyleRefs.map((r) => ({ asset_id: r.asset_id, preview_url: r.asset?.thumbnail_url ?? null, title: r.asset?.title }))
    )
    const [productAssets, setProductAssets] = useState(() =>
        productRefs.map((r) => ({ asset_id: r.asset_id, preview_url: r.asset?.thumbnail_url ?? null, title: r.asset?.title }))
    )
    const [graphicsAssets, setGraphicsAssets] = useState(() =>
        graphicsRefs.map((r) => ({ asset_id: r.asset_id, preview_url: r.asset?.thumbnail_url ?? null, title: r.asset?.title }))
    )
    const [visualRefsSaving, setVisualRefsSaving] = useState(false)

    const fetchAssetsForRefs = (opts) => {
        const params = new URLSearchParams({ format: 'json' })
        if (opts?.category) params.set('category', opts.category)
        return fetch(`/app/assets?${params}`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        }).then((r) => r.json())
    }

    const fetchDeliverablesForRefs = (opts) => {
        const params = new URLSearchParams({ format: 'json' })
        if (opts?.category) params.set('category', opts.category)
        return fetch(`/app/deliverables?${params}`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        }).then((r) => r.json())
    }

    useEffect(() => {
        const logo = visualReferences?.find((r) => r.type === 'logo')
        const lifestyle = visualReferences?.filter((r) => r.type === 'lifestyle_photography') ?? []
        const product = visualReferences?.filter((r) => r.type === 'product_photography') ?? []
        const graphics = visualReferences?.filter((r) => r.type === 'graphics_layout') ?? []
        const legacyPhotos = visualReferences?.filter((r) => r.type === 'photography_reference') ?? []
        setLogoAssetId(logo?.asset_id ?? null)
        setLogoPreviewUrl(logo?.asset?.thumbnail_url ?? null)
        const lifestyleMapped = lifestyle.map((r) => ({ asset_id: r.asset_id, preview_url: r.asset?.thumbnail_url ?? null, title: r.asset?.title }))
        const legacyMapped = legacyPhotos.map((r) => ({ asset_id: r.asset_id, preview_url: r.asset?.thumbnail_url ?? null, title: r.asset?.title }))
        setLifestyleAssets(lifestyleMapped.length ? lifestyleMapped : legacyMapped)
        setProductAssets(product.map((r) => ({ asset_id: r.asset_id, preview_url: r.asset?.thumbnail_url ?? null, title: r.asset?.title })))
        setGraphicsAssets(graphics.map((r) => ({ asset_id: r.asset_id, preview_url: r.asset?.thumbnail_url ?? null, title: r.asset?.title })))
    }, [visualReferences])

    const handleSaveVisualReferences = (e) => {
        e.preventDefault()
        setVisualRefsSaving(true)
        const url = typeof route === 'function'
            ? route('brands.dna.visual_references.store', { brand: brand.id })
            : `/app/brands/${brand.id}/dna/visual-references`
        router.post(url, {
            logo_asset_id: logoAssetId || null,
            photography_asset_ids: [],
            lifestyle_photography_ids: lifestyleAssets.filter((a) => a?.asset_id).map((a) => a.asset_id),
            product_photography_ids: productAssets.filter((a) => a?.asset_id).map((a) => a.asset_id),
            graphics_layout_ids: graphicsAssets.filter((a) => a?.asset_id).map((a) => a.asset_id),
        }, {
            preserveScroll: true,
            onFinish: () => setVisualRefsSaving(false),
        })
    }

    const hasAnyVisualRef = logoAssetId || lifestyleAssets?.length || productAssets?.length || graphicsAssets?.length

    return (
        <div className="min-h-full">
            <AppNav brand={auth?.activeBrand} tenant={null} />
            <main className="bg-gray-50">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
                    <Link
                        href={typeof route === 'function' ? route('brands.edit', { brand: brand.id }) : `/app/brands/${brand.id}/edit`}
                        className="text-sm font-medium text-gray-500 hover:text-gray-700"
                    >
                        ← Back to Brand Settings
                    </Link>
                    <div className="mt-4 flex items-center justify-between">
                        <div>
                            <h1 className="text-2xl font-bold tracking-tight text-gray-900">{brand.name}</h1>
                            <p className="mt-1 text-sm text-gray-600">Brand DNA</p>
                        </div>
                        <div className="flex items-center gap-4">
                            <span className="text-sm text-gray-600">Enabled</span>
                            <button
                                type="button"
                                onClick={handleToggleEnabled}
                                className={`relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-indigo-600 focus:ring-offset-2 ${
                                    brandModel?.is_enabled ? 'bg-indigo-600' : 'bg-gray-200'
                                }`}
                                role="switch"
                                aria-checked={brandModel?.is_enabled}
                            >
                                <span
                                    className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out ${
                                        brandModel?.is_enabled ? 'translate-x-5' : 'translate-x-0'
                                    }`}
                                />
                            </button>
                        </div>
                    </div>

                    {/* Execution Alignment Overview — Phase 8 */}
                    {complianceAggregate && (
                        <div className="mt-6 rounded-xl bg-gradient-to-br from-indigo-50/80 to-slate-50/80 p-6 ring-1 ring-indigo-100/50">
                            <h2 className="text-sm font-semibold uppercase tracking-wide text-indigo-800/90">Execution Alignment Overview</h2>
                            <div className="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
                                <div className="rounded-lg bg-white/70 px-4 py-3 backdrop-blur-sm">
                                    <p className="text-xs font-medium text-slate-500">Average On-Brand Score</p>
                                    <p className="mt-1 text-2xl font-bold text-indigo-700">
                                        {complianceAggregate.avg_score != null ? `${complianceAggregate.avg_score.toFixed(1)}%` : 'No execution alignment data yet.'}
                                    </p>
                                </div>
                                <div className="rounded-lg bg-white/70 px-4 py-3 backdrop-blur-sm">
                                    <p className="text-xs font-medium text-slate-500">Total Executions</p>
                                    <p className="mt-1 text-2xl font-bold text-slate-800">{complianceAggregate.execution_count ?? 0}</p>
                                </div>
                                <div className="rounded-lg bg-white/70 px-4 py-3 backdrop-blur-sm">
                                    <p className="text-xs font-medium text-slate-500">% High Alignment (≥85)</p>
                                    <p className="mt-1 text-2xl font-bold text-emerald-600">
                                        {complianceAggregate.execution_count > 0 && complianceAggregate.avg_score != null
                                            ? ((complianceAggregate.high_score_count / complianceAggregate.execution_count) * 100).toFixed(0) + '%'
                                            : '—'}
                                    </p>
                                </div>
                                <div className="rounded-lg bg-white/70 px-4 py-3 backdrop-blur-sm">
                                    <p className="text-xs font-medium text-slate-500">% Low Alignment (&lt;60)</p>
                                    <p className="mt-1 text-2xl font-bold text-amber-600">
                                        {complianceAggregate.execution_count > 0 && complianceAggregate.avg_score != null
                                            ? ((complianceAggregate.low_score_count / complianceAggregate.execution_count) * 100).toFixed(0) + '%'
                                            : '—'}
                                    </p>
                                </div>
                            </div>
                            {(topExecutions?.length > 0 || bottomExecutions?.length > 0) && (
                                <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div className="rounded-lg bg-white/60 p-4 backdrop-blur-sm">
                                        <p className="text-xs font-semibold text-emerald-700">Top 3 Aligned</p>
                                        <ul className="mt-2 space-y-1.5">
                                            {topExecutions?.map((e, i) => (
                                                <li key={i} className="flex items-center justify-between gap-2 text-sm">
                                                    <Link href={typeof route === 'function' ? route('deliverables.index', { asset: e.id }) : `/app/deliverables?asset=${e.id}`} className="truncate text-slate-700 hover:text-indigo-600">
                                                        {e.title || 'Untitled'}
                                                    </Link>
                                                    <span className="flex-shrink-0 rounded bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800">{e.score != null ? `${e.score}%` : '—'}</span>
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                    <div className="rounded-lg bg-white/60 p-4 backdrop-blur-sm">
                                        <p className="text-xs font-semibold text-amber-700">Bottom 3 — Review</p>
                                        <ul className="mt-2 space-y-1.5">
                                            {bottomExecutions?.map((e, i) => (
                                                <li key={i} className="flex items-center justify-between gap-2 text-sm">
                                                    <Link href={typeof route === 'function' ? route('deliverables.index', { asset: e.id }) : `/app/deliverables?asset=${e.id}`} className="truncate text-slate-700 hover:text-indigo-600">
                                                        {e.title || 'Untitled'}
                                                    </Link>
                                                    <span className="flex-shrink-0 rounded bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">{e.score != null ? `${e.score}%` : '—'}</span>
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                </div>
                            )}
                            {complianceAggregate.last_scored_at && (
                                <p className="mt-3 text-xs text-slate-500">Last scored: {new Date(complianceAggregate.last_scored_at).toLocaleString()}</p>
                            )}
                        </div>
                    )}

                    {/* Version bar */}
                    <div className="mt-6 flex flex-wrap items-center gap-3 rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200/20">
                        {activeVersion && (
                            <span className="inline-flex items-center rounded-md bg-green-50 px-2.5 py-0.5 text-xs font-medium text-green-700 ring-1 ring-inset ring-green-600/20">
                                Active: v{activeVersion.version_number}
                            </span>
                        )}
                        <select
                            value={selectedVersionId ?? ''}
                            onChange={(e) => handleVersionSelect(e.target.value ? Number(e.target.value) : null)}
                            className="rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                        >
                            <option value="">Select version</option>
                            {allVersions?.map((v) => (
                                <option key={v.id} value={v.id}>
                                    v{v.version_number} ({v.status}) {v.created_at ? new Date(v.created_at).toLocaleDateString() : ''}
                                </option>
                            ))}
                        </select>
                        <button
                            type="button"
                            onClick={handleCreateDraft}
                            className="rounded-md bg-gray-100 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-200"
                        >
                            Create Draft Version
                        </button>
                        {canActivate && (
                            <button
                                type="button"
                                onClick={handleActivateDraft}
                                className="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-700"
                            >
                                Activate Draft
                            </button>
                        )}
                        <div>
                            <Link
                                href={typeof route === 'function' ? route('brands.dna.bootstrap.index', { brand: brand.id }) : `/app/brands/${brand.id}/dna/bootstrap`}
                                className="rounded-md bg-gray-100 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-200"
                            >
                                Run AI Brand Research
                            </Link>
                            <p className="mt-0.5 text-xs text-gray-500">This analyzes a website and proposes a new Brand DNA draft. Your active Brand DNA will not change automatically.</p>
                        </div>
                    </div>

                    <div className="mt-8 flex gap-8">
                        {/* Left sidebar */}
                        <nav className="w-48 flex-shrink-0 space-y-1">
                            {sections.map((s) => (
                                <button
                                    key={s.id}
                                    type="button"
                                    onClick={() => setActiveSection(s.id)}
                                    className={`block w-full rounded-lg px-3 py-2 text-left text-sm font-medium ${
                                        activeSection === s.id
                                            ? 'bg-indigo-50 text-indigo-700'
                                            : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'
                                    }`}
                                >
                                    {s.label}
                                </button>
                            ))}
                        </nav>

                        {/* Right panel */}
                        <form onSubmit={handleSave} className="min-w-0 flex-1">
                            <div className="rounded-xl bg-white shadow-sm ring-1 ring-gray-200/20 overflow-hidden">
                                <div className="px-6 py-8 sm:px-8 sm:py-10">
                                    {activeSection === 'identity' && (
                                        <div className="space-y-6">
                                            <h2 className="text-lg font-semibold text-gray-900">Identity</h2>
                                            <div>
                                                <label className="block text-sm font-medium text-gray-700">Mission</label>
                                                <textarea
                                                    value={payload.identity?.mission ?? ''}
                                                    onChange={(e) => updatePayload('identity', 'mission', e.target.value)}
                                                    rows={3}
                                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                                />
                                            </div>
                                            <div>
                                                <label className="block text-sm font-medium text-gray-700">Positioning</label>
                                                <textarea
                                                    value={payload.identity?.positioning ?? ''}
                                                    onChange={(e) => updatePayload('identity', 'positioning', e.target.value)}
                                                    rows={3}
                                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                                />
                                            </div>
                                            <div>
                                                <label className="block text-sm font-medium text-gray-700">Tagline</label>
                                                <input
                                                    type="text"
                                                    value={payload.identity?.tagline ?? ''}
                                                    onChange={(e) => updatePayload('identity', 'tagline', e.target.value)}
                                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                                />
                                            </div>
                                            <div>
                                                <label className="block text-sm font-medium text-gray-700">Industry</label>
                                                <input
                                                    type="text"
                                                    value={payload.identity?.industry ?? ''}
                                                    onChange={(e) => updatePayload('identity', 'industry', e.target.value)}
                                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                                />
                                            </div>
                                            <div>
                                                <label className="block text-sm font-medium text-gray-700">Target Audience</label>
                                                <textarea
                                                    value={payload.identity?.target_audience ?? ''}
                                                    onChange={(e) => updatePayload('identity', 'target_audience', e.target.value)}
                                                    rows={3}
                                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                                />
                                            </div>
                                        </div>
                                    )}

                                    {activeSection === 'personality' && (
                                        <div className="space-y-6">
                                            <h2 className="text-lg font-semibold text-gray-900">Personality</h2>
                                            <div>
                                                <label className="block text-sm font-medium text-gray-700">Archetype</label>
                                                <select
                                                    value={payload.personality?.archetype ?? ''}
                                                    onChange={(e) => updatePayload('personality', 'archetype', e.target.value)}
                                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                                >
                                                    <option value="">Select archetype</option>
                                                    {ARCHETYPES.map((a) => (
                                                        <option key={a} value={a}>{a}</option>
                                                    ))}
                                                </select>
                                            </div>
                                            <div>
                                                <label className="block text-sm font-medium text-gray-700">Tone</label>
                                                <input
                                                    type="text"
                                                    value={payload.personality?.tone ?? ''}
                                                    onChange={(e) => updatePayload('personality', 'tone', e.target.value)}
                                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                                />
                                            </div>
                                            <div>
                                                <label className="block text-sm font-medium text-gray-700">Traits</label>
                                                <div className="mt-1 flex flex-wrap gap-2">
                                                    {(payload.personality?.traits ?? []).map((t, i) => (
                                                        <span
                                                            key={i}
                                                            className="inline-flex items-center gap-1 rounded-full bg-gray-100 px-2.5 py-0.5 text-sm text-gray-800"
                                                        >
                                                            {t}
                                                            <button type="button" onClick={() => removeTrait(i)} className="text-gray-500 hover:text-gray-700">×</button>
                                                        </span>
                                                    ))}
                                                </div>
                                                <div className="mt-2 flex gap-2">
                                                    <input
                                                        type="text"
                                                        value={traitInput}
                                                        onChange={(e) => setTraitInput(e.target.value)}
                                                        onKeyDown={(e) => e.key === 'Enter' && (e.preventDefault(), addTrait())}
                                                        placeholder="Add trait"
                                                        className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                                    />
                                                    <button type="button" onClick={addTrait} className="rounded-md bg-gray-100 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-200">
                                                        Add
                                                    </button>
                                                </div>
                                            </div>
                                            <div>
                                                <label className="block text-sm font-medium text-gray-700">Voice Description</label>
                                                <textarea
                                                    value={payload.personality?.voice_description ?? ''}
                                                    onChange={(e) => updatePayload('personality', 'voice_description', e.target.value)}
                                                    rows={3}
                                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                                />
                                            </div>
                                        </div>
                                    )}

                                    {activeSection === 'visual' && (
                                        <div className="space-y-6">
                                            <h2 className="text-lg font-semibold text-gray-900">Visual</h2>
                                            <div>
                                                <label className="block text-sm font-medium text-gray-700">Color Temperature</label>
                                                <select
                                                    value={payload.visual?.color_temperature ?? 'neutral'}
                                                    onChange={(e) => updatePayload('visual', 'color_temperature', e.target.value)}
                                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                                >
                                                    <option value="warm">Warm</option>
                                                    <option value="cool">Cool</option>
                                                    <option value="neutral">Neutral</option>
                                                </select>
                                            </div>
                                            <div>
                                                <label className="block text-sm font-medium text-gray-700">Visual Density</label>
                                                <select
                                                    value={payload.visual?.visual_density ?? 'balanced'}
                                                    onChange={(e) => updatePayload('visual', 'visual_density', e.target.value)}
                                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                                >
                                                    <option value="minimal">Minimal</option>
                                                    <option value="balanced">Balanced</option>
                                                    <option value="dense">Dense</option>
                                                </select>
                                            </div>
                                            <div>
                                                <label className="block text-sm font-medium text-gray-700">Photography Style</label>
                                                <input
                                                    type="text"
                                                    value={payload.visual?.photography_style ?? ''}
                                                    onChange={(e) => updatePayload('visual', 'photography_style', e.target.value)}
                                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                                />
                                            </div>
                                            <div>
                                                <label className="block text-sm font-medium text-gray-700">Composition Style</label>
                                                <input
                                                    type="text"
                                                    value={payload.visual?.composition_style ?? ''}
                                                    onChange={(e) => updatePayload('visual', 'composition_style', e.target.value)}
                                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                                />
                                            </div>

                                            {/* Approved Visual References — contained in a color well */}
                                            <div className="mt-8 rounded-xl border border-gray-200 bg-slate-50/80 p-6 shadow-sm">
                                                <h3 className="text-base font-semibold text-gray-900">Approved Visual References</h3>
                                                <p className="mt-1 text-sm text-gray-600">
                                                    Reference images used for imagery similarity scoring during compliance evaluation.
                                                </p>

                                                <CollapsibleSection title="How Brand Alignment Is Calculated" defaultExpanded={false} className="mt-4 rounded-lg border border-gray-200 bg-white/80">
                                                    <div className="text-sm text-gray-700 space-y-3">
                                                        <p>
                                                            Brand alignment scoring considers several factors to assess how well an asset matches your brand:
                                                        </p>
                                                        <ul className="list-disc list-inside space-y-1.5 ml-1">
                                                            <li><strong>Visual similarity</strong> — How closely the asset resembles your approved reference images in style, subject matter, and composition.</li>
                                                            <li><strong>Color harmony</strong> — Whether colors align with your brand palette and overall color temperature.</li>
                                                            <li><strong>Style and composition</strong> — Consistency with the look and feel of your approved examples.</li>
                                                            <li><strong>Governance signals</strong> — Ratings, starred assets, and approval status can influence scoring when available.</li>
                                                        </ul>
                                                        <p>
                                                            Adding more approved references improves scoring accuracy by giving the system a clearer picture of your brand’s visual identity.
                                                        </p>
                                                    </div>
                                                </CollapsibleSection>

                                                {!hasAnyVisualRef ? (
                                                    <div className="mt-4 rounded-lg border border-gray-200 bg-white/60 p-6">
                                                        <p className="text-sm text-gray-500">No visual references configured.</p>
                                                        <p className="mt-2 text-sm text-gray-600">Click below to add your brand logo reference.</p>
                                                        <div className="mt-4 max-w-xs">
                                                            <AssetImagePickerField
                                                                value={{ asset_id: null, preview_url: null }}
                                                                onChange={(v) => {
                                                                    if (v?.asset_id) {
                                                                        setLogoAssetId(v.asset_id)
                                                                        setLogoPreviewUrl(v.preview_url ?? v.thumbnail_url ?? null)
                                                                    }
                                                                }}
                                                                fetchAssets={fetchAssetsForRefs}
                                                                title="Select brand logo"
                                                                defaultCategoryLabel="Logos"
                                                                contextCategory="logos"
                                                                placeholder="Add Logo Reference"
                                                                helperText="Primary logo for visual alignment scoring"
                                                            />
                                                        </div>
                                                    </div>
                                                ) : (
                                                    <div className="mt-4 space-y-6">
                                                        <div>
                                                            <label className="block text-sm font-medium text-gray-700 mb-2">Logo</label>
                                                            <p className="text-xs text-gray-500 mb-2">1 required</p>
                                                            <div className="max-w-xs">
                                                                <AssetImagePickerField
                                                                    value={{
                                                                        asset_id: logoAssetId,
                                                                        preview_url: logoPreviewUrl,
                                                                    }}
                                                                    onChange={(v) => {
                                                                        if (v == null) {
                                                                            setLogoAssetId(null)
                                                                            setLogoPreviewUrl(null)
                                                                        } else if (v?.asset_id) {
                                                                            setLogoAssetId(v.asset_id)
                                                                            setLogoPreviewUrl(v.preview_url ?? v.thumbnail_url ?? null)
                                                                        }
                                                                    }}
                                                                    fetchAssets={fetchAssetsForRefs}
                                                                    title="Select brand logo"
                                                                    defaultCategoryLabel="Logos"
                                                                    contextCategory="logos"
                                                                    placeholder="Select logo reference"
                                                                    helperText="Primary logo for visual alignment scoring"
                                                                />
                                                            </div>
                                                        </div>
                                                        <div>
                                                            <AssetImagePickerFieldMulti
                                                                value={lifestyleAssets}
                                                                onChange={setLifestyleAssets}
                                                                fetchAssets={fetchAssetsForRefs}
                                                                fetchDeliverables={fetchDeliverablesForRefs}
                                                                title="Select lifestyle photography"
                                                                defaultCategoryLabel="Photography"
                                                                contextCategory="photography"
                                                                maxSelection={6}
                                                                recommendedText="Recommended: 3–6 images"
                                                                label="Lifestyle Photography"
                                                            />
                                                        </div>
                                                        <div>
                                                            <AssetImagePickerFieldMulti
                                                                value={productAssets}
                                                                onChange={setProductAssets}
                                                                fetchAssets={fetchAssetsForRefs}
                                                                fetchDeliverables={fetchDeliverablesForRefs}
                                                                title="Select product photography"
                                                                defaultCategoryLabel="Photography"
                                                                contextCategory="photography"
                                                                maxSelection={6}
                                                                recommendedText="Recommended: 3–6 images"
                                                                label="Product Photography"
                                                            />
                                                        </div>
                                                        <div>
                                                            <AssetImagePickerFieldMulti
                                                                value={graphicsAssets}
                                                                onChange={setGraphicsAssets}
                                                                fetchAssets={fetchAssetsForRefs}
                                                                fetchDeliverables={fetchDeliverablesForRefs}
                                                                title="Select graphics / layout examples"
                                                                defaultCategoryLabel="Graphics"
                                                                contextCategory={null}
                                                                maxSelection={4}
                                                                recommendedText="Recommended: 2–4 examples"
                                                                label="Graphics / Layout"
                                                            />
                                                        </div>
                                                        <div className="pt-4 mt-4 border-t border-gray-200/80">
                                                            <button
                                                                type="button"
                                                                onClick={handleSaveVisualReferences}
                                                                disabled={visualRefsSaving}
                                                                className="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50"
                                                            >
                                                                {visualRefsSaving ? 'Saving…' : 'Save Visual References'}
                                                            </button>
                                                        </div>
                                                    </div>
                                                )}
                                            </div>
                                        </div>
                                    )}

                                    {activeSection === 'typography' && (
                                        <div className="space-y-6">
                                            <h2 className="text-lg font-semibold text-gray-900">Typography</h2>
                                            <div>
                                                <label className="block text-sm font-medium text-gray-700">Primary Font</label>
                                                <input
                                                    type="text"
                                                    value={payload.typography?.primary_font ?? ''}
                                                    onChange={(e) => updatePayload('typography', 'primary_font', e.target.value)}
                                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                                />
                                            </div>
                                            <div>
                                                <label className="block text-sm font-medium text-gray-700">Secondary Font</label>
                                                <input
                                                    type="text"
                                                    value={payload.typography?.secondary_font ?? ''}
                                                    onChange={(e) => updatePayload('typography', 'secondary_font', e.target.value)}
                                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                                />
                                            </div>
                                            <div>
                                                <label className="block text-sm font-medium text-gray-700">Heading Style</label>
                                                <input
                                                    type="text"
                                                    value={payload.typography?.heading_style ?? ''}
                                                    onChange={(e) => updatePayload('typography', 'heading_style', e.target.value)}
                                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                                />
                                            </div>
                                            <div>
                                                <label className="block text-sm font-medium text-gray-700">Body Style</label>
                                                <input
                                                    type="text"
                                                    value={payload.typography?.body_style ?? ''}
                                                    onChange={(e) => updatePayload('typography', 'body_style', e.target.value)}
                                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                                />
                                            </div>
                                        </div>
                                    )}

                                    {activeSection === 'scoring' && (
                                        <div className="space-y-6">
                                            <h2 className="text-lg font-semibold text-gray-900">Scoring Rules</h2>
                                            <p className="text-sm text-gray-600">Define rules for deterministic compliance scoring. Used when Brand DNA is enabled.</p>

                                            <div className="rounded-lg border border-gray-200 bg-gray-50/50 p-4">
                                                <h3 className="text-sm font-medium text-gray-700 mb-3">Scoring Weights (must total 100%)</h3>
                                                {[
                                                    { key: 'color_weight', label: 'Color Weight' },
                                                    { key: 'typography_weight', label: 'Typography Weight' },
                                                    { key: 'tone_weight', label: 'Tone Weight' },
                                                    { key: 'imagery_weight', label: 'Imagery Weight' },
                                                ].map(({ key, label }) => {
                                                    const defaults = { color_weight: 0.1, typography_weight: 0.2, tone_weight: 0.2, imagery_weight: 0.5 }
                                                    const val = Math.round((payload.scoring_config?.[key] ?? defaults[key] ?? 0.2) * 100)
                                                    return (
                                                        <div key={key} className="flex items-center gap-3 mb-3">
                                                            <label className="w-40 text-sm text-gray-700">{label}</label>
                                                            <input
                                                                type="range"
                                                                min={0}
                                                                max={100}
                                                                value={val}
                                                                onChange={(e) => {
                                                                    const v = Number(e.target.value) / 100
                                                                    setPayload((prev) => ({
                                                                        ...prev,
                                                                        scoring_config: { ...prev.scoring_config, [key]: v },
                                                                    }))
                                                                }}
                                                                className="flex-1 h-2 rounded-lg appearance-none cursor-pointer bg-gray-200 accent-indigo-600"
                                                            />
                                                            <span className="w-10 text-sm font-medium text-gray-700">{val}%</span>
                                                        </div>
                                                    )
                                                })}
                                                {(() => {
                                                    const total = Math.round(
                                                        ((payload.scoring_config?.color_weight ?? 0.1) +
                                                        (payload.scoring_config?.typography_weight ?? 0.2) +
                                                        (payload.scoring_config?.tone_weight ?? 0.2) +
                                                        (payload.scoring_config?.imagery_weight ?? 0.5)) * 100
                                                    )
                                                    return (
                                                        <div className={`mt-2 text-sm font-medium ${total === 100 ? 'text-green-600' : 'text-red-600'}`}>
                                                            Total: {total}% {total !== 100 && '— Must equal 100% to save'}
                                                        </div>
                                                    )
                                                })()}
                                            </div>

                                            {renderColorPaletteField()}
                                            {renderTagArrayField('allowed_fonts', 'Allowed Fonts', 'e.g. Helvetica, Inter')}
                                            {renderTagArrayField('banned_colors', 'Banned Colors', 'Colors to penalize')}
                                            {renderTagArrayField('tone_keywords', 'Tone Keywords', 'Words that match brand tone')}
                                            {renderTagArrayField('banned_keywords', 'Banned Keywords', 'Words to penalize')}
                                            {renderTagArrayField('photography_attributes', 'Photography Attributes', 'e.g. minimal, lifestyle')}
                                        </div>
                                    )}

                                </div>
                                <div className="border-t border-gray-200 px-6 py-4 bg-gray-50">
                                    <button
                                        type="submit"
                                        disabled={saving || !weightsValid}
                                        className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50"
                                    >
                                        {saving ? 'Saving…' : 'Save'}
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </main>
        </div>
    )
}
