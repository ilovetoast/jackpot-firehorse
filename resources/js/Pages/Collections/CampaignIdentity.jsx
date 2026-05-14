import { useCallback, useState, useEffect, useRef } from 'react'
import { Link, usePage } from '@inertiajs/react'
import AppHead from '../../Components/AppHead'
import AppNav from '../../Components/AppNav'
import AppFooter from '../../Components/AppFooter'
import SettingsInPageNavLabel from '../../Components/settings/SettingsInPageNavLabel'
import ColorPickerControl from '../../Components/BrandGuidelines/controls/ColorPickerControl'
import FontManager from '../../Components/BrandGuidelines/FontManager'
import { SparklesIcon, ChevronDownIcon, ChevronUpIcon } from '@heroicons/react/24/outline'

const READINESS_COLORS = {
    incomplete: 'bg-red-100 text-red-800',
    partial: 'bg-amber-100 text-amber-800',
    ready: 'bg-emerald-100 text-emerald-800',
}

const STATUS_OPTIONS = ['draft', 'active', 'completed', 'archived']

const NAV_SECTIONS = [
    { id: 'campaign-basics', label: 'Basics' },
    { id: 'campaign-colors', label: 'Colors' },
    { id: 'campaign-typography', label: 'Typography' },
    { id: 'campaign-scoring', label: 'Scoring' },
    { id: 'campaign-advanced', label: 'Advanced' },
]

function ReadinessBadge({ status }) {
    const cls = READINESS_COLORS[status] || 'bg-slate-100 text-slate-700'
    return (
        <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${cls}`}>
            {status}
        </span>
    )
}

function SectionCard({ id, title, subtitle, children }) {
    return (
        <div id={id} className="scroll-mt-6 rounded-xl bg-white shadow-sm ring-1 ring-gray-200/30 overflow-hidden">
            <div className="px-6 py-8 sm:px-8 sm:py-10">
                <h2 className="text-xl font-semibold text-gray-900">{title}</h2>
                {subtitle && <p className="mt-1 text-sm text-gray-600 leading-relaxed">{subtitle}</p>}
                <div className="mt-6 space-y-5">{children}</div>
            </div>
        </div>
    )
}

function FieldLabel({ children, hint, htmlFor }) {
    return (
        <label htmlFor={htmlFor} className="block text-sm font-medium text-gray-700">
            {children}
            {hint && <span className="ml-1 text-xs font-normal text-gray-400">{hint}</span>}
        </label>
    )
}

function TagInput({ value, onChange, placeholder }) {
    const [inputVal, setInputVal] = useState('')
    const tags = Array.isArray(value) ? value : []
    const addTag = () => {
        const t = inputVal.trim()
        if (t && !tags.includes(t)) onChange([...tags, t])
        setInputVal('')
    }
    const removeTag = (idx) => onChange(tags.filter((_, i) => i !== idx))
    return (
        <div className="mt-1">
            {tags.length > 0 && (
                <div className="flex flex-wrap gap-1 mb-2">
                    {tags.map((t, i) => (
                        <span key={i} className="inline-flex items-center gap-0.5 rounded-md bg-gray-100 px-2 py-0.5 text-xs text-gray-700">
                            {t}
                            <button type="button" onClick={() => removeTag(i)} className="ml-0.5 text-gray-400 hover:text-gray-600">&times;</button>
                        </span>
                    ))}
                </div>
            )}
            <div className="flex gap-1.5">
                <input
                    type="text"
                    value={inputVal}
                    onChange={(e) => setInputVal(e.target.value)}
                    onKeyDown={(e) => e.key === 'Enter' && (e.preventDefault(), addTag())}
                    placeholder={placeholder}
                    className="block flex-1 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                />
                <button type="button" onClick={addTag} className="rounded-md bg-gray-100 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-200">
                    Add
                </button>
            </div>
        </div>
    )
}

function CampaignFieldSuggest({ collectionId, fieldPath, currentValue, onSuggestion }) {
    const [loading, setLoading] = useState(false)
    const [suggestion, setSuggestion] = useState(null)
    const [error, setError] = useState(null)

    const hasContent = !!(currentValue && currentValue.trim())
    const mode = hasContent ? 'improve' : 'suggest'

    const handleSuggest = async () => {
        setLoading(true)
        setError(null)
        setSuggestion(null)
        try {
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content
            const res = await fetch(`/app/collections/${collectionId}/campaign/suggest-field`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf ?? '' },
                credentials: 'same-origin',
                body: JSON.stringify({ field_path: fieldPath, mode, current_value: hasContent ? currentValue.trim() : null }),
            })
            const data = await res.json()
            if (!res.ok) { setError(data.error || 'Failed to generate suggestion'); return }
            setSuggestion(data.suggestion)
        } catch { setError('Network error') }
        finally { setLoading(false) }
    }

    if (suggestion) {
        return (
            <div className="mt-2 rounded-lg border border-violet-200/60 bg-violet-50/50 p-3">
                <div className="flex items-start gap-2">
                    <SparklesIcon className="h-4 w-4 mt-0.5 shrink-0 text-violet-500" />
                    <div className="min-w-0 flex-1">
                        <p className="text-xs font-medium text-violet-700 mb-1">{mode === 'improve' ? 'Improved version' : 'AI suggestion'}</p>
                        <p className="text-sm text-gray-800 leading-relaxed">{suggestion}</p>
                        <div className="mt-2 flex gap-2">
                            <button type="button" onClick={() => { onSuggestion(suggestion); setSuggestion(null) }} className="rounded-md bg-violet-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-violet-500">
                                {mode === 'improve' ? 'Replace' : 'Use'}
                            </button>
                            <button type="button" onClick={() => setSuggestion(null)} className="text-xs font-medium text-gray-500 hover:text-gray-700">
                                Dismiss
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        )
    }

    return (
        <div className="mt-1.5 flex items-center gap-2">
            <button
                type="button"
                onClick={handleSuggest}
                disabled={loading}
                className={`inline-flex items-center gap-1 rounded-md px-2.5 py-1 text-xs font-medium disabled:opacity-50 transition-colors ${
                    hasContent
                        ? 'bg-amber-50 text-amber-700 hover:bg-amber-100'
                        : 'bg-violet-50 text-violet-700 hover:bg-violet-100'
                }`}
            >
                {loading ? (
                    <><svg className="h-3 w-3 animate-spin" viewBox="0 0 24 24" fill="none"><circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" /><path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg> Thinking&hellip;</>
                ) : hasContent ? (
                    <><SparklesIcon className="h-3 w-3" /> Improve</>
                ) : (
                    <><SparklesIcon className="h-3 w-3" /> Suggest</>
                )}
            </button>
            {error && <span className="text-xs text-red-600">{error}</span>}
        </div>
    )
}

function BrandColorSwatch({ color, label, onClick }) {
    if (!color) return null
    return (
        <button
            type="button"
            onClick={() => onClick(color)}
            className="flex items-center gap-1.5 rounded-md border border-gray-200 px-2 py-1 text-xs text-gray-600 hover:bg-gray-50 transition-colors"
            title={`Use ${label}: ${color}`}
        >
            <span className="h-4 w-4 rounded-sm border border-gray-200 shrink-0" style={{ backgroundColor: color }} />
            {label}
        </button>
    )
}

function CampaignColorRow({ label, value, onChange }) {
    return (
        <div className="flex items-center justify-between">
            <span className="text-sm font-medium text-gray-700">{label}</span>
            <ColorPickerControl label="" value={value} onChange={onChange} hideLabel />
        </div>
    )
}

export default function CampaignIdentity({ collection, campaign_identity: existingIdentity, collection_images: collectionImages = [] }) {
    const { auth } = usePage().props
    const isEditing = !!existingIdentity

    const [saving, setSaving] = useState(false)
    const [saved, setSaved] = useState(false)
    const [error, setError] = useState(null)

    const defaultPayload = existingIdentity?.identity_payload ?? {}

    const [name, setName] = useState(existingIdentity?.campaign_name ?? (!isEditing ? collection.name : ''))
    const [slugManual, setSlugManual] = useState(false)
    const [slug, setSlug] = useState(existingIdentity?.campaign_slug ?? '')
    const [status, setStatus] = useState(existingIdentity?.campaign_status ?? 'draft')
    const [goal, setGoal] = useState(existingIdentity?.campaign_goal ?? '')
    const [description, setDescription] = useState(existingIdentity?.campaign_description ?? '')
    const [scoringEnabled, setScoringEnabled] = useState(existingIdentity?.scoring_enabled ?? false)
    const [featuredAssetId, setFeaturedAssetId] = useState(existingIdentity?.featured_asset_id ?? null)

    // Colors — stored as array of hex strings
    const [palette, setPalette] = useState(defaultPayload.visual?.palette ?? [])
    const [accentColors, setAccentColors] = useState(defaultPayload.visual?.accent_colors ?? [])

    // Visual (advanced)
    const [styleDescription, setStyleDescription] = useState(defaultPayload.visual?.style_description ?? '')
    const [motifs, setMotifs] = useState(defaultPayload.visual?.motifs ?? [])
    const [compositionNotes, setCompositionNotes] = useState(defaultPayload.visual?.composition_notes ?? '')

    // Typography — FontManager stores font objects
    const [campaignFonts, setCampaignFonts] = useState(defaultPayload.typography?.fonts ?? [])
    const [typoDirection, setTypoDirection] = useState(defaultPayload.typography?.direction ?? '')

    // Messaging (advanced)
    const [tone, setTone] = useState(defaultPayload.messaging?.tone ?? '')
    const [voiceNotes, setVoiceNotes] = useState(defaultPayload.messaging?.voice_notes ?? '')
    const [pillars, setPillars] = useState(defaultPayload.messaging?.pillars ?? [])
    const [approvedPhrases, setApprovedPhrases] = useState(defaultPayload.messaging?.approved_phrases ?? [])
    const [discouragedPhrases, setDiscouragedPhrases] = useState(defaultPayload.messaging?.discouraged_phrases ?? [])
    const [ctaDirection, setCtaDirection] = useState(defaultPayload.messaging?.cta_direction ?? '')
    const [requiredCtaPatterns, setRequiredCtaPatterns] = useState(defaultPayload.messaging?.required_cta_patterns ?? [])

    // Rules (advanced)
    const [requiredMotifs, setRequiredMotifs] = useState(defaultPayload.rules?.required_motifs ?? [])
    const [requiredPhrases, setRequiredPhrases] = useState(defaultPayload.rules?.required_phrases ?? [])
    const [rulesDiscouragedPhrases, setRulesDiscouragedPhrases] = useState(defaultPayload.rules?.discouraged_phrases ?? [])
    const [logoTreatmentNotes, setLogoTreatmentNotes] = useState(defaultPayload.rules?.logo_treatment_notes ?? '')
    const [categoryNotes, setCategoryNotes] = useState(defaultPayload.rules?.category_notes ?? '')

    const [readinessStatus, setReadinessStatus] = useState(existingIdentity?.readiness_status ?? 'incomplete')
    const [showAdvanced, setShowAdvanced] = useState(false)

    // Auto-generate slug from name unless manually edited
    useEffect(() => {
        if (!slugManual && !isEditing) {
            setSlug(name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, ''))
        }
    }, [name, slugManual, isEditing])

    const brandColors = {
        primary: auth?.activeBrand?.primary_color,
        secondary: auth?.activeBrand?.secondary_color,
        accent: auth?.activeBrand?.accent_color,
    }

    const addPaletteColor = (hex) => {
        if (hex && !palette.includes(hex)) setPalette([...palette, hex])
    }
    const addAccentColor = (hex) => {
        if (hex && !accentColors.includes(hex)) setAccentColors([...accentColors, hex])
    }
    const removePaletteColor = (idx) => setPalette(palette.filter((_, i) => i !== idx))
    const removeAccentColor = (idx) => setAccentColors(accentColors.filter((_, i) => i !== idx))

    const buildPayload = useCallback(() => {
        const primaryFont = campaignFonts.find((f) => f.role === 'primary' || f.role === 'display')
        const secondaryFont = campaignFonts.find((f) => f.role === 'secondary' || f.role === 'body')
        return {
            visual: {
                palette,
                accent_colors: accentColors,
                style_description: styleDescription || null,
                motifs,
                composition_notes: compositionNotes || null,
            },
            typography: {
                fonts: campaignFonts,
                primary_font: primaryFont?.name || null,
                signature_font: secondaryFont?.name || null,
                direction: typoDirection || null,
            },
            messaging: {
                tone: tone || null,
                voice_notes: voiceNotes || null,
                pillars,
                approved_phrases: approvedPhrases,
                discouraged_phrases: discouragedPhrases,
                cta_direction: ctaDirection || null,
                required_cta_patterns: requiredCtaPatterns,
            },
            rules: {
                required_motifs: requiredMotifs,
                required_phrases: requiredPhrases,
                discouraged_phrases: rulesDiscouragedPhrases,
                logo_treatment_notes: logoTreatmentNotes || null,
                category_notes: categoryNotes || null,
            },
        }
    }, [palette, accentColors, styleDescription, motifs, compositionNotes, campaignFonts, typoDirection, tone, voiceNotes, pillars, approvedPhrases, discouragedPhrases, ctaDirection, requiredCtaPatterns, requiredMotifs, requiredPhrases, rulesDiscouragedPhrases, logoTreatmentNotes, categoryNotes])

    const handleSave = async () => {
        if (!name.trim()) { setError('Campaign name is required'); return }
        setSaving(true)
        setError(null)
        setSaved(false)
        try {
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content
            const body = {
                campaign_name: name,
                campaign_slug: slug || null,
                campaign_status: status,
                campaign_goal: goal || null,
                campaign_description: description || null,
                identity_payload: buildPayload(),
                scoring_enabled: scoringEnabled,
                featured_asset_id: featuredAssetId || null,
            }
            const res = await fetch(`/app/collections/${collection.id}/campaign`, {
                method: isEditing ? 'PUT' : 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf ?? '' },
                credentials: 'same-origin',
                body: JSON.stringify(body),
            })
            if (!res.ok) {
                const data = await res.json().catch(() => ({}))
                setError(data.message || 'Failed to save')
                return
            }
            const data = await res.json()
            setReadinessStatus(data.campaign_identity?.readiness_status ?? readinessStatus)
            setSaved(true)
            setTimeout(() => setSaved(false), 4000)
        } catch (e) {
            setError(e.message || 'Network error')
        } finally {
            setSaving(false)
        }
    }

    const scrollTo = (id) => document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' })

    return (
        <div className="min-h-full">
            <AppHead title={`Campaign Identity — ${collection.name}`} />
            <AppNav brand={auth?.activeBrand} tenant={null} />
            <main className="bg-gray-50/80">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
                    {/* Header */}
                    <div className="mb-8">
                        <Link href="/app/collections" className="text-sm font-medium text-gray-500 hover:text-gray-700">
                            &larr; Collections
                        </Link>
                        <h1 className="mt-3 text-2xl font-bold text-gray-900">
                            Campaign Identity
                        </h1>
                        <p className="mt-1 text-sm text-gray-500">
                            Configure campaign-specific identity for <span className="font-medium text-gray-700">{collection.name}</span>
                        </p>
                        <div className="mt-3 flex items-center gap-3">
                            <ReadinessBadge status={readinessStatus} />
                            <span className="text-xs text-gray-500">{scoringEnabled ? 'Scoring enabled' : 'Scoring disabled'}</span>
                        </div>
                    </div>

                    <div className="flex gap-8">
                        {/* Left sticky nav */}
                        <div className="hidden lg:block w-40 shrink-0">
                            <div className="sticky top-8">
                                <SettingsInPageNavLabel />
                                <nav className="space-y-1">
                                    {NAV_SECTIONS.map((s) => (
                                        <button
                                            key={s.id}
                                            type="button"
                                            onClick={() => scrollTo(s.id)}
                                            className="block w-full text-left text-sm text-gray-600 hover:text-gray-900 px-2 py-1 rounded transition-colors"
                                        >
                                            {s.label}
                                        </button>
                                    ))}
                                </nav>
                            </div>
                        </div>

                        {/* Center content */}
                        <div className="flex-1 min-w-0 space-y-8">
                            {/* ───── BASICS ───── */}
                            <SectionCard id="campaign-basics" title="Campaign Basics" subtitle="Name, goal, and core campaign information.">
                                <div>
                                    <FieldLabel htmlFor="campaign-name">Campaign name <span className="text-red-500">*</span></FieldLabel>
                                    <input
                                        id="campaign-name"
                                        type="text"
                                        value={name}
                                        onChange={(e) => setName(e.target.value)}
                                        placeholder="e.g. Black Friday 2026"
                                        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                    />
                                    {slug && (
                                        <p className="mt-1 text-xs text-gray-400 font-mono">
                                            slug: {slug}
                                            {!slugManual && !isEditing && (
                                                <button type="button" onClick={() => setSlugManual(true)} className="ml-2 text-indigo-500 hover:text-indigo-700 font-sans">edit</button>
                                            )}
                                        </p>
                                    )}
                                    {slugManual && (
                                        <input
                                            type="text"
                                            value={slug}
                                            onChange={(e) => setSlug(e.target.value)}
                                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm text-xs font-mono"
                                            placeholder="custom-slug"
                                        />
                                    )}
                                </div>
                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <FieldLabel htmlFor="campaign-status">Status</FieldLabel>
                                        <select
                                            id="campaign-status"
                                            value={status}
                                            onChange={(e) => setStatus(e.target.value)}
                                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm capitalize"
                                        >
                                            {STATUS_OPTIONS.map((s) => (
                                                <option key={s} value={s}>{s}</option>
                                            ))}
                                        </select>
                                    </div>
                                </div>
                                <div>
                                    <FieldLabel htmlFor="campaign-goal">Campaign goal / intent</FieldLabel>
                                    <textarea
                                        id="campaign-goal"
                                        value={goal}
                                        onChange={(e) => setGoal(e.target.value)}
                                        placeholder="What is this campaign trying to achieve?"
                                        rows={3}
                                        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                    />
                                    <CampaignFieldSuggest collectionId={collection.id} fieldPath="campaign_goal" currentValue={goal} onSuggestion={setGoal} />
                                </div>
                                <div>
                                    <FieldLabel htmlFor="campaign-desc" hint="(optional)">Campaign description</FieldLabel>
                                    <textarea
                                        id="campaign-desc"
                                        value={description}
                                        onChange={(e) => setDescription(e.target.value)}
                                        placeholder="Describe the campaign"
                                        rows={3}
                                        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                    />
                                    <CampaignFieldSuggest collectionId={collection.id} fieldPath="campaign_description" currentValue={description} onSuggestion={setDescription} />
                                </div>

                                {/* Featured image picker */}
                                <div>
                                    <FieldLabel hint="(optional)">Featured image</FieldLabel>
                                    <p className="text-xs text-gray-500 mt-0.5">Shown on the collection header. Auto-selected from collection assets if not set.</p>
                                    {collectionImages.length > 0 ? (
                                        <div className="mt-2 grid grid-cols-5 sm:grid-cols-6 md:grid-cols-8 gap-2">
                                            {collectionImages.map((img) => (
                                                <button
                                                    key={img.id}
                                                    type="button"
                                                    onClick={() => setFeaturedAssetId(featuredAssetId === img.id ? null : img.id)}
                                                    className={`relative aspect-square rounded-lg overflow-hidden border-2 transition-all ${
                                                        featuredAssetId === img.id
                                                            ? 'border-indigo-500 ring-2 ring-indigo-500/30 scale-[1.02]'
                                                            : 'border-gray-200 hover:border-gray-400'
                                                    }`}
                                                    title={img.title}
                                                >
                                                    <img src={img.thumbnail_url} alt="" className="w-full h-full object-cover" />
                                                    {featuredAssetId === img.id && (
                                                        <div className="absolute inset-0 bg-indigo-500/10 flex items-center justify-center">
                                                            <span className="bg-indigo-500 text-white text-[9px] font-bold px-1.5 py-0.5 rounded">Selected</span>
                                                        </div>
                                                    )}
                                                </button>
                                            ))}
                                        </div>
                                    ) : (
                                        <p className="mt-2 text-xs text-gray-400">No image assets in this collection yet. Add images to the collection first.</p>
                                    )}
                                    {featuredAssetId && (
                                        <button type="button" onClick={() => setFeaturedAssetId(null)} className="mt-1.5 text-xs text-gray-400 hover:text-red-500">
                                            Clear selection (use auto)
                                        </button>
                                    )}
                                </div>
                            </SectionCard>

                            {/* ───── COLORS ───── */}
                            <SectionCard id="campaign-colors" title="Campaign Colors" subtitle="Define the campaign color palette. Brand colors are shown for quick reuse.">
                                {/* Brand palette recommendation */}
                                {(brandColors.primary || brandColors.secondary || brandColors.accent) && (
                                    <div className="rounded-lg border border-indigo-100 bg-indigo-50/50 p-4">
                                        <p className="text-xs font-semibold text-indigo-900 mb-2">Brand palette</p>
                                        <div className="flex flex-wrap gap-2">
                                            <BrandColorSwatch color={brandColors.primary} label="Primary" onClick={addPaletteColor} />
                                            <BrandColorSwatch color={brandColors.secondary} label="Secondary" onClick={addPaletteColor} />
                                            <BrandColorSwatch color={brandColors.accent} label="Accent" onClick={addPaletteColor} />
                                        </div>
                                    </div>
                                )}

                                <div>
                                    <FieldLabel>Campaign palette</FieldLabel>
                                    <div className="mt-2 space-y-2">
                                        {palette.map((color, i) => (
                                            <div key={i} className="flex items-center gap-2">
                                                <ColorPickerControl label="" value={color} onChange={(hex) => { const next = [...palette]; next[i] = hex; setPalette(next) }} hideLabel />
                                                <button type="button" onClick={() => removePaletteColor(i)} className="text-xs text-gray-400 hover:text-red-500">&times;</button>
                                            </div>
                                        ))}
                                        <button type="button" onClick={() => addPaletteColor('#6366f1')} className="inline-flex items-center gap-1 text-xs font-medium text-indigo-600 hover:text-indigo-800">
                                            + Add color
                                        </button>
                                    </div>
                                </div>

                                <div>
                                    <FieldLabel hint="(optional)">Accent / seasonal colors</FieldLabel>
                                    <div className="mt-2 space-y-2">
                                        {accentColors.map((color, i) => (
                                            <div key={i} className="flex items-center gap-2">
                                                <ColorPickerControl label="" value={color} onChange={(hex) => { const next = [...accentColors]; next[i] = hex; setAccentColors(next) }} hideLabel />
                                                <button type="button" onClick={() => removeAccentColor(i)} className="text-xs text-gray-400 hover:text-red-500">&times;</button>
                                            </div>
                                        ))}
                                        <button type="button" onClick={() => addAccentColor('#f59e0b')} className="inline-flex items-center gap-1 text-xs font-medium text-indigo-600 hover:text-indigo-800">
                                            + Add accent color
                                        </button>
                                    </div>
                                </div>

                                {/* Color preview strip */}
                                {(palette.length > 0 || accentColors.length > 0) && (
                                    <div className="flex gap-1 mt-2">
                                        {[...palette, ...accentColors].map((c, i) => (
                                            <div key={i} className="h-8 flex-1 rounded-md border border-gray-200 first:rounded-l-lg last:rounded-r-lg" style={{ backgroundColor: c }} title={c} />
                                        ))}
                                    </div>
                                )}
                            </SectionCard>

                            {/* ───── TYPOGRAPHY ───── */}
                            <SectionCard id="campaign-typography" title="Campaign Typography" subtitle="Select fonts for this campaign. Choose from Google Fonts, upload custom fonts, or enter manually.">
                                <div className="rounded-xl bg-[#1a1920] p-4 -mx-1">
                                    <FontManager
                                        brandId={collection.brand_id}
                                        fonts={campaignFonts}
                                        onChange={(fonts) => setCampaignFonts(fonts)}
                                    />
                                </div>
                                <div>
                                    <FieldLabel hint="(optional)">Typography direction notes</FieldLabel>
                                    <textarea
                                        value={typoDirection}
                                        onChange={(e) => setTypoDirection(e.target.value)}
                                        placeholder="Overall typography direction for this campaign"
                                        rows={2}
                                        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                    />
                                </div>
                            </SectionCard>

                            {/* ───── SCORING ───── */}
                            <SectionCard id="campaign-scoring" title="Scoring" subtitle="Enable campaign alignment scoring for assets in this collection.">
                                <div className="flex items-center gap-3">
                                    <button
                                        type="button"
                                        role="switch"
                                        aria-checked={scoringEnabled}
                                        onClick={() => setScoringEnabled((v) => !v)}
                                        className={`relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 ${scoringEnabled ? 'bg-indigo-600' : 'bg-gray-200'}`}
                                    >
                                        <span className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out ${scoringEnabled ? 'translate-x-5' : 'translate-x-0'}`} />
                                    </button>
                                    <span className="text-sm text-gray-700">Enable campaign alignment scoring</span>
                                </div>
                                <p className="text-xs text-gray-500">
                                    Scoring runs when enabled and identity is at least partially configured. Current readiness: <ReadinessBadge status={readinessStatus} />
                                </p>
                            </SectionCard>

                            {/* ───── ADVANCED ───── */}
                            <div id="campaign-advanced" className="scroll-mt-6">
                                <button
                                    type="button"
                                    onClick={() => setShowAdvanced((v) => !v)}
                                    className="w-full flex items-center justify-between rounded-xl bg-white shadow-sm ring-1 ring-gray-200/30 px-6 py-4 sm:px-8 text-left hover:bg-gray-50/50 transition-colors"
                                >
                                    <div>
                                        <h2 className="text-base font-semibold text-gray-900">Advanced Settings</h2>
                                        <p className="text-xs text-gray-500 mt-0.5">Messaging, voice, rules, visual style, motifs, and more</p>
                                    </div>
                                    {showAdvanced
                                        ? <ChevronUpIcon className="h-5 w-5 text-gray-400" />
                                        : <ChevronDownIcon className="h-5 w-5 text-gray-400" />
                                    }
                                </button>

                                {showAdvanced && (
                                    <div className="mt-4 space-y-6">
                                        {/* Visual style details */}
                                        <SectionCard title="Visual Style" subtitle="Style description, motifs, and art direction for this campaign.">
                                            <div>
                                                <FieldLabel>Visual style description</FieldLabel>
                                                <textarea value={styleDescription} onChange={(e) => setStyleDescription(e.target.value)} placeholder="Describe the campaign visual style" rows={3} className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" />
                                            </div>
                                            <div>
                                                <FieldLabel hint="(optional)">Visual motifs / themes</FieldLabel>
                                                <TagInput value={motifs} onChange={setMotifs} placeholder="Add motif" />
                                            </div>
                                            <div>
                                                <FieldLabel hint="(optional)">Composition / art direction notes</FieldLabel>
                                                <textarea value={compositionNotes} onChange={(e) => setCompositionNotes(e.target.value)} placeholder="Layout, framing, art direction notes" rows={2} className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" />
                                            </div>
                                        </SectionCard>

                                        {/* Messaging */}
                                        <SectionCard title="Messaging & Voice" subtitle="Tone, voice, CTA direction, and campaign copy guidelines.">
                                            <div>
                                                <FieldLabel>Tone</FieldLabel>
                                                <input type="text" value={tone} onChange={(e) => setTone(e.target.value)} placeholder="e.g. Urgent, playful, deal-driven" className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" />
                                            </div>
                                            <div>
                                                <FieldLabel hint="(optional)">Voice notes</FieldLabel>
                                                <textarea value={voiceNotes} onChange={(e) => setVoiceNotes(e.target.value)} placeholder="Specific voice guidelines" rows={2} className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" />
                                            </div>
                                            <div>
                                                <FieldLabel hint="(optional)">CTA direction</FieldLabel>
                                                <input type="text" value={ctaDirection} onChange={(e) => setCtaDirection(e.target.value)} placeholder="e.g. Shop Now, Grab the Deal" className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" />
                                            </div>
                                            <div>
                                                <FieldLabel hint="(optional)">Required CTA patterns</FieldLabel>
                                                <TagInput value={requiredCtaPatterns} onChange={setRequiredCtaPatterns} placeholder="Add CTA pattern" />
                                            </div>
                                            <div>
                                                <FieldLabel hint="(optional)">Messaging pillars</FieldLabel>
                                                <TagInput value={pillars} onChange={setPillars} placeholder="Add pillar" />
                                            </div>
                                            <div>
                                                <FieldLabel hint="(optional)">Approved phrases</FieldLabel>
                                                <TagInput value={approvedPhrases} onChange={setApprovedPhrases} placeholder="Add approved phrase" />
                                            </div>
                                            <div>
                                                <FieldLabel hint="(optional)">Discouraged / banned phrases</FieldLabel>
                                                <TagInput value={discouragedPhrases} onChange={setDiscouragedPhrases} placeholder="Add discouraged phrase" />
                                            </div>
                                        </SectionCard>

                                        {/* Rules */}
                                        <SectionCard title="Rules & Expectations" subtitle="Required motifs, phrases, logo treatment, and category notes.">
                                            <div>
                                                <FieldLabel hint="(optional)">Required motifs</FieldLabel>
                                                <TagInput value={requiredMotifs} onChange={setRequiredMotifs} placeholder="Add required motif" />
                                            </div>
                                            <div>
                                                <FieldLabel hint="(optional)">Required phrases</FieldLabel>
                                                <TagInput value={requiredPhrases} onChange={setRequiredPhrases} placeholder="Add required phrase" />
                                            </div>
                                            <div>
                                                <FieldLabel hint="(optional)">Discouraged phrases</FieldLabel>
                                                <TagInput value={rulesDiscouragedPhrases} onChange={setRulesDiscouragedPhrases} placeholder="Add discouraged phrase" />
                                            </div>
                                            <div>
                                                <FieldLabel hint="(optional)">Logo treatment notes</FieldLabel>
                                                <textarea value={logoTreatmentNotes} onChange={(e) => setLogoTreatmentNotes(e.target.value)} placeholder="How should the logo appear?" rows={2} className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" />
                                            </div>
                                            <div>
                                                <FieldLabel hint="(optional)">Category / execution notes</FieldLabel>
                                                <textarea value={categoryNotes} onChange={(e) => setCategoryNotes(e.target.value)} placeholder="Packaging, execution, category-specific notes" rows={2} className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" />
                                            </div>
                                        </SectionCard>
                                    </div>
                                )}
                            </div>

                            {/* ───── ACTIONS ───── */}
                            {error && (
                                <div className="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">{error}</div>
                            )}
                            {saved && !error && (
                                <div className="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">
                                    Campaign identity saved successfully.
                                </div>
                            )}

                            <div className="flex items-center gap-3 pb-8">
                                <button
                                    type="button"
                                    onClick={handleSave}
                                    disabled={saving}
                                    className="inline-flex items-center rounded-md bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50 transition-colors"
                                >
                                    {saving ? 'Saving\u2026' : isEditing ? 'Update Campaign Identity' : 'Create Campaign Identity'}
                                </button>
                                <Link href="/app/collections" className="text-sm font-medium text-gray-500 hover:text-gray-700">
                                    Cancel
                                </Link>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
            <AppFooter />
        </div>
    )
}
