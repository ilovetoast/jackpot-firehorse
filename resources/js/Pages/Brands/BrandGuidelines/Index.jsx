import { useState, useEffect, useRef, useCallback } from 'react'
import { Link, router, usePage } from '@inertiajs/react'
import AppNav from '../../../Components/AppNav'
import AppHead from '../../../Components/AppHead'
import ConfirmDialog from '../../../Components/ConfirmDialog'

function unwrapValue(field) {
    if (field && typeof field === 'object' && !Array.isArray(field) && 'value' in field) return field.value
    return field
}

function darkenHex(hex, amount = 0.3) {
    if (!hex || !hex.startsWith('#')) return '#1e1b4b'
    const num = parseInt(hex.slice(1), 16)
    const r = Math.max(0, ((num >> 16) & 0xff) * (1 - amount))
    const g = Math.max(0, ((num >> 8) & 0xff) * (1 - amount))
    const b = Math.max(0, (num & 0xff) * (1 - amount))
    return `#${Math.round(r).toString(16).padStart(2, '0')}${Math.round(g).toString(16).padStart(2, '0')}${Math.round(b).toString(16).padStart(2, '0')}`
}

function lightenHex(hex, amount = 0.15) {
    if (!hex || !hex.startsWith('#')) return '#e2e8f0'
    const num = parseInt(hex.slice(1), 16)
    const r = Math.min(255, ((num >> 16) & 0xff) + (255 - ((num >> 16) & 0xff)) * amount)
    const g = Math.min(255, ((num >> 8) & 0xff) + (255 - ((num >> 8) & 0xff)) * amount)
    const b = Math.min(255, (num & 0xff) + (255 - (num & 0xff)) * amount)
    return `#${Math.round(r).toString(16).padStart(2, '0')}${Math.round(g).toString(16).padStart(2, '0')}${Math.round(b).toString(16).padStart(2, '0')}`
}

function hexToRgba(hex, alpha = 1) {
    if (!hex || !hex.startsWith('#')) return `rgba(0,0,0,${alpha})`
    const num = parseInt(hex.slice(1), 16)
    return `rgba(${(num >> 16) & 0xff},${(num >> 8) & 0xff},${num & 0xff},${alpha})`
}

function copyToClipboard(text) {
    navigator.clipboard?.writeText(text).then(() => {})
}

const LOGO_VISUAL_TREATMENTS = {
    clear_space: (src) => (
        <div className="relative w-full aspect-[3/2] bg-white rounded-t-xl flex items-center justify-center">
            <div className="relative">
                <div className="absolute inset-0 -m-5 border-2 border-dashed border-blue-400/50 rounded" />
                <div className="absolute -top-5 left-1/2 -translate-x-1/2 flex flex-col items-center">
                    <div className="w-px h-4 bg-blue-400/60" />
                    <span className="text-[8px] text-blue-500 font-medium">x</span>
                </div>
                <div className="absolute -left-5 top-1/2 -translate-y-1/2 flex items-center">
                    <div className="h-px w-4 bg-blue-400/60" />
                    <span className="text-[8px] text-blue-500 font-medium ml-0.5">x</span>
                </div>
                <img src={src} alt="" className="h-10 max-w-[100px] object-contain" />
            </div>
        </div>
    ),
    minimum_size: (src) => (
        <div className="w-full aspect-[3/2] bg-white rounded-t-xl flex items-end justify-center gap-6 pb-4 px-4">
            <div className="flex flex-col items-center gap-1">
                <img src={src} alt="" className="h-10 max-w-[80px] object-contain" />
                <span className="text-[8px] text-gray-500 font-medium">Full size</span>
            </div>
            <div className="flex flex-col items-center gap-1">
                <img src={src} alt="" className="h-5 max-w-[40px] object-contain" />
                <span className="text-[8px] text-gray-500 font-medium">Min size</span>
            </div>
            <div className="flex flex-col items-center gap-1 opacity-30">
                <img src={src} alt="" className="h-2.5 max-w-[20px] object-contain" />
                <div className="flex items-center gap-0.5">
                    <span className="text-red-500 text-[10px]">✕</span>
                    <span className="text-[8px] text-red-500 font-medium">Too small</span>
                </div>
            </div>
        </div>
    ),
    color_usage: (src, colors, isTransparent) => (
        <div className="w-full aspect-[3/2] rounded-t-xl overflow-hidden grid grid-cols-2">
            <div className="bg-white flex items-center justify-center p-3">
                <img src={src} alt="" className="h-8 max-w-[70px] object-contain" />
            </div>
            <div className="flex items-center justify-center p-3" style={{ backgroundColor: colors?.primary || '#1a1a2e' }}>
                <img src={src} alt="" className={`h-8 max-w-[70px] object-contain${isTransparent ? '' : ' brightness-0 invert'}`} />
            </div>
            <div className="flex items-center justify-center p-3" style={{ backgroundColor: colors?.secondary || '#f0f0f0' }}>
                <img src={src} alt="" className="h-8 max-w-[70px] object-contain" />
            </div>
            <div className="bg-gray-800 flex items-center justify-center p-3">
                <img src={src} alt="" className={`h-8 max-w-[70px] object-contain${isTransparent ? '' : ' brightness-0 invert'}`} />
            </div>
        </div>
    ),
    background_contrast: (src, colors, isTransparent) => (
        <div className="w-full aspect-[3/2] rounded-t-xl overflow-hidden grid grid-cols-2">
            <div className="flex items-center justify-center p-3 relative" style={{ backgroundColor: colors?.primary || '#002A3A' }}>
                <img src={src} alt="" className={`h-8 max-w-[70px] object-contain relative z-10${isTransparent ? '' : ' brightness-0 invert'}`} />
                <span className="absolute bottom-1 text-[8px] text-white/60 font-medium">✓ Good</span>
            </div>
            <div className="flex items-center justify-center p-3 relative bg-[repeating-conic-gradient(#e0e0e0_0%_25%,#fff_0%_50%)] bg-[length:16px_16px]">
                <img src={src} alt="" className="h-8 max-w-[70px] object-contain opacity-40 relative z-10" />
                <span className="absolute bottom-1 text-[8px] text-red-500 font-medium z-10">✕ Busy bg</span>
            </div>
        </div>
    ),
    dont_stretch: (src) => (
        <div className="w-full aspect-[3/2] bg-white rounded-t-xl flex items-center justify-center gap-4 px-4 relative">
            <div className="flex flex-col items-center gap-1">
                <img src={src} alt="" className="h-8 max-w-[60px] object-contain" style={{ transform: 'scaleX(1.6)' }} />
            </div>
            <div className="flex flex-col items-center gap-1">
                <img src={src} alt="" className="h-12 max-w-[30px] object-contain" style={{ transform: 'scaleY(1.5) scaleX(0.6)' }} />
            </div>
            <div className="absolute inset-0 flex items-center justify-center pointer-events-none">
                <div className="w-12 h-12 rounded-full border-[3px] border-red-500/70 flex items-center justify-center">
                    <div className="w-10 h-[3px] bg-red-500/70 rotate-45 rounded-full" />
                </div>
            </div>
        </div>
    ),
    dont_rotate: (src) => (
        <div className="w-full aspect-[3/2] bg-white rounded-t-xl flex items-center justify-center px-4 relative">
            <img src={src} alt="" className="h-10 max-w-[80px] object-contain" style={{ transform: 'rotate(-15deg)' }} />
            <div className="absolute inset-0 flex items-center justify-center pointer-events-none">
                <div className="w-12 h-12 rounded-full border-[3px] border-red-500/70 flex items-center justify-center">
                    <div className="w-10 h-[3px] bg-red-500/70 rotate-45 rounded-full" />
                </div>
            </div>
        </div>
    ),
    dont_recolor: (src) => (
        <div className="w-full aspect-[3/2] bg-white rounded-t-xl flex items-center justify-center px-4 relative">
            <img src={src} alt="" className="h-10 max-w-[80px] object-contain" style={{ filter: 'hue-rotate(180deg) saturate(2)' }} />
            <div className="absolute inset-0 flex items-center justify-center pointer-events-none">
                <div className="w-12 h-12 rounded-full border-[3px] border-red-500/70 flex items-center justify-center">
                    <div className="w-10 h-[3px] bg-red-500/70 rotate-45 rounded-full" />
                </div>
            </div>
        </div>
    ),
    dont_crop: (src) => (
        <div className="w-full aspect-[3/2] bg-white rounded-t-xl flex items-center justify-end overflow-hidden relative">
            <img src={src} alt="" className="h-10 max-w-[80px] object-contain mr-[-20px]" />
            <div className="absolute inset-0 flex items-center justify-center pointer-events-none">
                <div className="w-12 h-12 rounded-full border-[3px] border-red-500/70 flex items-center justify-center">
                    <div className="w-10 h-[3px] bg-red-500/70 rotate-45 rounded-full" />
                </div>
            </div>
        </div>
    ),
    dont_add_effects: (src) => (
        <div className="w-full aspect-[3/2] bg-white rounded-t-xl flex items-center justify-center px-4 relative">
            <img src={src} alt="" className="h-10 max-w-[80px] object-contain" style={{ filter: 'drop-shadow(4px 4px 6px rgba(0,0,0,0.5))' }} />
            <div className="absolute top-2 right-2 px-1.5 py-0.5 bg-yellow-400/90 rounded text-[7px] font-bold text-black tracking-wide">GLOW</div>
            <div className="absolute inset-0 flex items-center justify-center pointer-events-none">
                <div className="w-12 h-12 rounded-full border-[3px] border-red-500/70 flex items-center justify-center">
                    <div className="w-10 h-[3px] bg-red-500/70 rotate-45 rounded-full" />
                </div>
            </div>
        </div>
    ),
}

function SectionLabel({ children, color = '#94a3b8' }) {
    return (
        <div className="flex items-center gap-4 mb-6">
            <div className="w-8 h-px" style={{ backgroundColor: color }} />
            <span className="text-xs font-semibold uppercase tracking-[0.2em]" style={{ color }}>{children}</span>
        </div>
    )
}

export default function BrandGuidelinesIndex({ brand, brandModel, modelPayload, logoAssets = [], visualReferences = {}, hasActiveVersion, hasDraft, builderProcessing = false, researchFinalized = false, resumeStep = 'background', resumeLabel = 'Continue Brand Guidelines', resumeUrl = null }) {
    const { auth } = usePage().props

    useEffect(() => {
        if (brand?.id && auth?.activeBrand?.id && brand.id !== auth.activeBrand.id) {
            router.post(`/app/brands/${brand.id}/switch`, {}, {
                preserveScroll: true,
                preserveState: true,
            })
        }
    }, [brand?.id, auth?.activeBrand?.id])

    const [copiedHex, setCopiedHex] = useState(null)
    const [showStartOverConfirm, setShowStartOverConfirm] = useState(false)
    const [dismissedProcessingBanner, setDismissedProcessingBanner] = useState(() => {
        try {
            const key = `brand-guidelines-banner-dismissed-${brand?.id}`
            return sessionStorage?.getItem(key) === '1'
        } catch {
            return false
        }
    })
    const isEnabled = brandModel?.is_enabled ?? false
    const showCallout = !isEnabled || !hasActiveVersion

    const u = (v) => unwrapValue(v)
    const rawIdentity = modelPayload?.identity ?? {}
    const rawTypography = modelPayload?.typography ?? {}
    const rawPersonality = modelPayload?.personality ?? {}
    const rawVisual = modelPayload?.visual ?? {}
    const rawScoringRules = modelPayload?.scoring_rules ?? {}

    const identity = {
        mission: u(rawIdentity.mission),
        positioning: u(rawIdentity.positioning),
        industry: u(rawIdentity.industry),
        target_audience: u(rawIdentity.target_audience),
        tagline: u(rawIdentity.tagline),
        beliefs: (u(rawIdentity.beliefs) || []).map(b => typeof b === 'string' ? b : u(b)).filter(Boolean),
        values: (u(rawIdentity.values) || []).map(v => typeof v === 'string' ? v : u(v)).filter(Boolean),
    }
    const typography = {
        primary_font: u(rawTypography.primary_font),
        secondary_font: u(rawTypography.secondary_font),
        heading_style: u(rawTypography.heading_style),
        body_style: u(rawTypography.body_style),
        fonts: (u(rawTypography.fonts) || []).map(f => typeof f === 'object' ? { ...f, name: u(f?.name), role: u(f?.role) } : f),
    }
    const personality = {
        primary_archetype: u(rawPersonality.primary_archetype),
        archetype: u(rawPersonality.archetype),
        traits: (u(rawPersonality.traits) || []).map(t => typeof t === 'string' ? t : u(t)).filter(Boolean),
        tone: u(rawPersonality.tone),
        tone_keywords: (u(rawPersonality.tone_keywords) || []).map(t => typeof t === 'string' ? t : u(t)).filter(Boolean),
        voice_description: u(rawPersonality.voice_description),
        brand_look: u(rawPersonality.brand_look),
    }
    const visual = {
        photography_style: u(rawVisual.photography_style),
        visual_style: u(rawVisual.visual_style),
        composition_style: u(rawVisual.composition_style),
        show_logo_visual_treatment: rawVisual.show_logo_visual_treatment !== false,
        logo_usage_guidelines: (() => {
            const raw = rawVisual.logo_usage_guidelines ?? {}
            const result = {}
            for (const [k, v] of Object.entries(raw)) {
                result[k] = typeof v === 'string' ? v : u(v)
            }
            return result
        })(),
    }
    const scoringRules = {
        allowed_color_palette: (u(rawScoringRules.allowed_color_palette) || []).map(c => {
            if (typeof c === 'string') return c
            if (c && typeof c === 'object' && 'value' in c && 'source' in c) return u(c)
            return c
        }),
        photography_attributes: (u(rawScoringRules.photography_attributes) || []).map(a => typeof a === 'string' ? a : u(a)),
        tone_keywords: (u(rawScoringRules.tone_keywords) || []).map(t => typeof t === 'string' ? t : u(t)).filter(Boolean),
    }

    const primaryColor = brand.primary_color || '#6366f1'
    const secondaryColor = brand.secondary_color || '#8b5cf6'
    const accentColor = brand.accent_color || '#06b6d4'
    const primaryDark = darkenHex(primaryColor, 0.4)
    const primaryDeep = darkenHex(primaryColor, 0.6)
    const secondaryDark = darkenHex(secondaryColor, 0.3)

    const logoUrl = brand.logo_url || (auth?.activeBrand?.id === brand.id ? auth?.activeBrand?.logo_thumbnail_url : null)
    const logoIsTransparent = logoUrl && /\.(png|svg|webp)(\?|$)/i.test(logoUrl)
    const archetypeDisplay = personality.archetype || personality.primary_archetype
    const heroSubheading = identity.tagline || archetypeDisplay || brand.name
    const hasValues = identity.values.length > 0
    const hasBeliefs = identity.beliefs.length > 0
    const hasToneKeywords = personality.tone_keywords.length > 0 || scoringRules.tone_keywords.length > 0
    const toneKeywords = personality.tone_keywords.length > 0 ? personality.tone_keywords : scoringRules.tone_keywords
    const hasLogoGuidelines = Object.values(visual.logo_usage_guidelines).some(v => v)
    const hasFonts = typography.fonts.length > 0 || typography.primary_font || typography.secondary_font
    const photographyRefs = visualReferences?.photography || []
    const graphicsRefs = visualReferences?.graphics || []
    const hasVisualRefs = photographyRefs.length > 0 || graphicsRefs.length > 0

    const handleCopyHex = (hex, label) => {
        copyToClipboard(hex)
        setCopiedHex(label)
        setTimeout(() => setCopiedHex(null), 1200)
    }

    const NAV_SECTIONS = [
        { id: 'sec-hero', label: brand.name, parent: true },
        { id: 'sec-purpose', label: 'Purpose', parent: true, show: !!(identity.mission || identity.positioning) },
        { id: 'sec-values', label: 'Values & Beliefs', show: hasValues || hasBeliefs },
        { id: 'sec-voice', label: 'Brand Voice', parent: true, show: !!(personality.voice_description || hasToneKeywords) },
        { id: 'sec-archetype', label: 'Archetype', parent: true },
        { id: 'sec-visual', label: 'Visual Style', show: !!(visual.photography_style || visual.visual_style || visual.composition_style || scoringRules.photography_attributes.length > 0) },
        { id: 'sec-photography', label: 'Visual References', show: hasVisualRefs },
        { id: 'sec-colors', label: 'Color System', parent: true },
        { id: 'sec-typography', label: 'Typography', show: hasFonts },
        { id: 'sec-logo', label: 'Brand Identity', parent: true, show: !!logoUrl },
        { id: 'sec-logo-standards', label: 'Logo Standards', show: hasLogoGuidelines },
    ].filter(s => s.show !== false)

    const [activeSection, setActiveSection] = useState('sec-hero')
    const [sectionTheme, setSectionTheme] = useState('dark')
    const darkSections = new Set(['sec-hero', 'sec-archetype', 'sec-logo-standards'])

    useEffect(() => {
        const sectionIds = NAV_SECTIONS.map(s => s.id)
        const observer = new IntersectionObserver(
            (entries) => {
                let topEntry = null
                for (const entry of entries) {
                    if (entry.isIntersecting) {
                        if (!topEntry || entry.boundingClientRect.top < topEntry.boundingClientRect.top) {
                            topEntry = entry
                        }
                    }
                }
                if (topEntry) {
                    setActiveSection(topEntry.target.id)
                    setSectionTheme(darkSections.has(topEntry.target.id) ? 'dark' : 'light')
                }
            },
            { rootMargin: '-20% 0px -60% 0px', threshold: 0 }
        )
        for (const id of sectionIds) {
            const el = document.getElementById(id)
            if (el) observer.observe(el)
        }
        return () => observer.disconnect()
    }, [])

    const scrollToSection = useCallback((id) => {
        const el = document.getElementById(id)
        if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' })
    }, [])

    const showProcessingBanner = hasDraft && (builderProcessing || researchFinalized) && !dismissedProcessingBanner
    const dismissBanner = () => {
        setDismissedProcessingBanner(true)
        try {
            sessionStorage?.setItem(`brand-guidelines-banner-dismissed-${brand?.id}`, '1')
        } catch {}
    }

    return (
        <div className="min-h-full bg-white">
            <AppHead title={`Brand Guidelines — ${brand.name}`} />
            <AppNav brand={auth?.activeBrand} tenant={null} />
            {showProcessingBanner && (
                <div className="bg-indigo-50 border-b border-indigo-100 px-4 py-3 flex items-center justify-between gap-4">
                    <p className="text-sm text-indigo-800">
                        {builderProcessing ? (
                            <>Brand research for <strong>{brand.name}</strong> is still processing in the background. We&apos;ll notify you when it&apos;s ready.</>
                        ) : (
                            <>Brand research for <strong>{brand.name}</strong> is ready.</>
                        )}
                    </p>
                    <div className="flex items-center gap-2 flex-shrink-0">
                        {researchFinalized && (
                            <button
                                type="button"
                                onClick={() => router.get(resumeUrl || route('brands.brand-guidelines.builder', { brand: brand.id, step: 'research-summary' }))}
                                className="inline-flex rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-700"
                            >
                                Review Research
                            </button>
                        )}
                        <button type="button" onClick={dismissBanner} className="p-1.5 rounded text-indigo-600 hover:bg-indigo-100" aria-label="Dismiss">
                            <svg className="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z" /></svg>
                        </button>
                    </div>
                </div>
            )}
            <main className="relative">
                {/* Floating Section Nav — desktop only */}
                {!showCallout && (
                    <nav
                        className="hidden xl:flex fixed left-6 top-1/2 -translate-y-1/2 z-50 flex-col gap-1"
                        style={{ maxHeight: '70vh', filter: sectionTheme === 'dark' ? 'invert(1)' : 'none', transition: 'filter 0.4s ease' }}
                    >
                        {NAV_SECTIONS.map((sec) => {
                            const isActive = activeSection === sec.id
                            return (
                                <button
                                    key={sec.id}
                                    type="button"
                                    onClick={() => scrollToSection(sec.id)}
                                    className={`text-left transition-all duration-300 ${sec.parent ? 'text-[11px] font-semibold tracking-wide' : 'text-[10px] tracking-wide pl-3'} ${isActive ? 'opacity-100 translate-x-0' : 'opacity-40 -translate-x-0.5 hover:opacity-70'}`}
                                    style={{ color: 'rgba(0,0,0,0.75)', lineHeight: '1.8' }}
                                >
                                    <span className="flex items-center gap-2">
                                        <span
                                            className="inline-block rounded-full transition-all duration-300"
                                            style={{
                                                width: isActive ? 12 : 4,
                                                height: 4,
                                                backgroundColor: isActive ? secondaryColor : 'rgba(0,0,0,0.15)',
                                            }}
                                        />
                                        {sec.label}
                                    </span>
                                </button>
                            )
                        })}
                    </nav>
                )}

                {showCallout ? (
                    <div className="min-h-screen flex flex-col items-center justify-center px-4 py-16 bg-white">
                        <Link
                            href={typeof route === 'function' ? route('brands.edit', { brand: brand.id }) : `/app/brands/${brand.id}/edit`}
                            className="absolute top-20 left-6 text-sm font-medium text-gray-500 hover:text-gray-700"
                        >
                            &larr; Back to Brand Settings
                        </Link>
                        <div className="max-w-lg text-center space-y-6">
                            <h1 className="text-2xl font-bold text-gray-900">Brand Guidelines &mdash; {brand.name}</h1>
                            <p className="text-gray-600">
                                {hasActiveVersion ? 'Update your brand guidelines or run the builder again.' : 'Start the Brand Guidelines Builder to define your brand DNA.'}
                            </p>
                            <div className="flex flex-col items-center gap-3">
                                <div className="flex flex-wrap items-center justify-center gap-2">
                                    <button
                                        type="button"
                                        onClick={() => {
                                            if (hasDraft) {
                                                router.get(resumeUrl || route('brands.brand-guidelines.builder', { brand: brand.id, step: resumeStep }))
                                            } else {
                                                router.post(route('brands.brand-dna.builder.start', { brand: brand.id }))
                                            }
                                        }}
                                        className="inline-flex rounded-md bg-indigo-600 px-6 py-3 text-sm font-medium text-white hover:bg-indigo-700"
                                    >
                                        {hasDraft ? resumeLabel : 'Start Brand Guidelines'}
                                    </button>
                                    {hasDraft && (
                                        <>
                                            <button type="button" onClick={() => setShowStartOverConfirm(true)} className="inline-flex rounded-md border border-gray-300 px-6 py-3 text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">Start over</button>
                                            <ConfirmDialog open={showStartOverConfirm} onClose={() => setShowStartOverConfirm(false)} onConfirm={() => { setShowStartOverConfirm(false); router.post(route('brands.brand-dna.builder.start', { brand: brand.id })) }} title="Start over" message="Your current draft will be replaced with a fresh one. This cannot be undone." confirmText="Start over" cancelText="Cancel" variant="warning" />
                                        </>
                                    )}
                                </div>
                                <p className="text-sm text-gray-500">{hasDraft ? 'Resume where you left off, or start fresh.' : 'You can import a PDF or start from scratch on the first step.'}</p>
                            </div>
                            <Link href={typeof route === 'function' ? route('brands.edit', { brand: brand.id, tab: 'brand_model' }) : `/app/brands/${brand.id}/edit?tab=brand_model`} className="block text-sm text-gray-500 hover:text-gray-700">Or configure Brand DNA in Settings</Link>
                        </div>
                    </div>
                ) : (
                    <>
                        {/* ═══ 1. HERO ═══ */}
                        <section id="sec-hero" className="relative w-full overflow-hidden" style={{ minHeight: '70vh' }}>
                            <div
                                className="absolute inset-0"
                                style={{
                                    background: `
                                        radial-gradient(ellipse 120% 80% at 20% 50%, ${hexToRgba(secondaryColor, 0.15)} 0%, transparent 70%),
                                        radial-gradient(ellipse 80% 120% at 80% 20%, ${hexToRgba(accentColor, 0.08)} 0%, transparent 60%),
                                        linear-gradient(160deg, ${primaryDeep} 0%, ${primaryDark} 35%, ${primaryColor} 100%)
                                    `,
                                }}
                            />
                            <div className="absolute inset-0 bg-black/30" />
                            <div className="absolute inset-0 opacity-[0.03]" style={{ backgroundImage: 'url("data:image/svg+xml,%3Csvg width=\'60\' height=\'60\' viewBox=\'0 0 60 60\' xmlns=\'http://www.w3.org/2000/svg\'%3E%3Cg fill=\'none\' fill-rule=\'evenodd\'%3E%3Cg fill=\'%23ffffff\' fill-opacity=\'1\'%3E%3Cpath d=\'M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z\'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E")' }} />

                            <div className="absolute top-8 left-6 z-10">
                                <Link href={typeof route === 'function' ? route('brands.edit', { brand: brand.id }) : `/app/brands/${brand.id}/edit`} className="text-sm font-medium text-white/70 hover:text-white transition-colors">&larr; Brand Settings</Link>
                            </div>

                            <div className="relative flex flex-col items-center justify-center px-6 lg:px-8" style={{ minHeight: '70vh' }}>
                                {logoUrl && (
                                    <img src={logoUrl} alt={brand.name} className="h-20 md:h-28 w-auto object-contain mb-10 drop-shadow-2xl" />
                                )}
                                <h1 className="text-4xl md:text-6xl lg:text-7xl font-black tracking-tight text-white text-center whitespace-nowrap">
                                    Brand Guidelines
                                </h1>
                                {heroSubheading && (
                                    <p className="mt-8 text-lg md:text-xl text-white/80 font-light tracking-wide max-w-xl text-center">
                                        {heroSubheading}
                                    </p>
                                )}
                                <div className="mt-12 flex items-center gap-3">
                                    <div className="w-3 h-3 rounded-full" style={{ backgroundColor: primaryColor }} />
                                    <div className="w-3 h-3 rounded-full" style={{ backgroundColor: secondaryColor }} />
                                    <div className="w-3 h-3 rounded-full" style={{ backgroundColor: accentColor }} />
                                </div>
                            </div>
                        </section>

                        {/* ═══ 2. PURPOSE & POSITIONING (Builder Step 3) ═══ */}
                        {(identity.mission || identity.positioning) && (
                            <section id="sec-purpose" className="py-28 md:py-36 bg-white">
                                <div className="mx-auto max-w-6xl px-6 lg:px-8">
                                    <div className="grid grid-cols-1 lg:grid-cols-12 gap-20">
                                        <div className="lg:col-span-7">
                                            <SectionLabel color={secondaryColor}>Purpose</SectionLabel>
                                            {identity.mission && (
                                                <blockquote className="text-3xl md:text-5xl font-light text-gray-900 leading-[1.2] tracking-tight">
                                                    &ldquo;{identity.mission}&rdquo;
                                                </blockquote>
                                            )}
                                            {identity.positioning && (
                                                <p className="mt-10 text-xl md:text-2xl font-light leading-relaxed" style={{ color: darkenHex(primaryColor, 0.1) }}>
                                                    {identity.positioning}
                                                </p>
                                            )}
                                        </div>
                                        <div className="lg:col-span-5 flex flex-col justify-center space-y-8">
                                            {identity.industry && (
                                                <div>
                                                    <span className="text-[11px] font-semibold uppercase tracking-[0.15em] text-gray-400">Industry</span>
                                                    <p className="mt-1 text-lg text-gray-800 font-medium">{identity.industry}</p>
                                                </div>
                                            )}
                                            {identity.target_audience && (
                                                <div>
                                                    <span className="text-[11px] font-semibold uppercase tracking-[0.15em] text-gray-400">Target Audience</span>
                                                    <p className="mt-1 text-base text-gray-700 leading-relaxed">{identity.target_audience}</p>
                                                </div>
                                            )}
                                            {identity.tagline && identity.tagline !== heroSubheading && (
                                                <div>
                                                    <span className="text-[11px] font-semibold uppercase tracking-[0.15em] text-gray-400">Tagline</span>
                                                    <p className="mt-1 text-lg text-gray-800 font-medium italic">{identity.tagline}</p>
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            </section>
                        )}

                        {/* ═══ 4. VALUES & BELIEFS (Builder Step 3) ═══ */}
                        {(hasValues || hasBeliefs) && (
                            <section
                                id="sec-values"
                                className="py-28 md:py-36"
                                style={{ background: `linear-gradient(180deg, ${hexToRgba(primaryColor, 0.04)} 0%, white 100%)` }}
                            >
                                <div className="mx-auto max-w-6xl px-6 lg:px-8">
                                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-20">
                                        {hasValues && (
                                            <div>
                                                <SectionLabel color={secondaryColor}>Core Values</SectionLabel>
                                                <div className="space-y-0">
                                                    {identity.values.map((v, i) => (
                                                        <div key={i} className="group py-6 border-b border-gray-100 last:border-b-0">
                                                            <div className="flex items-baseline gap-5">
                                                                <span className="text-5xl md:text-6xl font-black leading-none" style={{ color: hexToRgba(secondaryColor, 0.15) }}>
                                                                    {String(i + 1).padStart(2, '0')}
                                                                </span>
                                                                <h3 className="text-2xl md:text-3xl font-bold text-gray-900 tracking-tight">{v}</h3>
                                                            </div>
                                                        </div>
                                                    ))}
                                                </div>
                                            </div>
                                        )}
                                        {hasBeliefs && (
                                            <div>
                                                <SectionLabel color={secondaryColor}>What We Believe</SectionLabel>
                                                <div className="space-y-6">
                                                    {identity.beliefs.map((b, i) => (
                                                        <div key={i} className="relative pl-6">
                                                            <div className="absolute left-0 top-2 w-2 h-2 rounded-full" style={{ backgroundColor: secondaryColor }} />
                                                            <p className="text-lg text-gray-700 leading-relaxed">{b}</p>
                                                        </div>
                                                    ))}
                                                </div>
                                            </div>
                                        )}
                                    </div>
                                </div>
                            </section>
                        )}

                        {/* ═══ 5. BRAND VOICE (Builder Step 4: Expression) ═══ */}
                        {(personality.voice_description || hasToneKeywords) && (
                            <section id="sec-voice" className="py-28 md:py-36" style={{ background: `linear-gradient(180deg, ${hexToRgba(secondaryColor, 0.04)} 0%, ${hexToRgba(primaryColor, 0.02)} 100%)` }}>
                                <div className="mx-auto max-w-6xl px-6 lg:px-8">
                                    <div className="grid grid-cols-1 lg:grid-cols-12 gap-20">
                                        <div className="lg:col-span-7">
                                            <SectionLabel color={secondaryColor}>Brand Voice</SectionLabel>
                                            {personality.voice_description && (
                                                <p className="text-xl md:text-2xl text-gray-800 leading-relaxed font-light whitespace-pre-wrap">
                                                    {personality.voice_description}
                                                </p>
                                            )}
                                        </div>
                                        {hasToneKeywords && (
                                            <div className="lg:col-span-5 flex flex-col justify-center">
                                                <span className="text-xs font-semibold uppercase tracking-[0.2em] text-gray-400 mb-6">Tone of Voice</span>
                                                <div className="space-y-3">
                                                    {toneKeywords.map((kw, i) => (
                                                        <div
                                                            key={i}
                                                            className="flex items-center gap-4 px-6 py-4 rounded-xl transition-all duration-200 hover:scale-[1.01]"
                                                            style={{
                                                                border: `2px solid ${hexToRgba(secondaryColor, 0.2)}`,
                                                                backgroundColor: hexToRgba(secondaryColor, 0.03),
                                                            }}
                                                        >
                                                            <div className="w-1 h-8 rounded-full" style={{ backgroundColor: secondaryColor }} />
                                                            <span className="text-lg font-bold uppercase tracking-wide" style={{ color: secondaryColor }}>{kw}</span>
                                                        </div>
                                                    ))}
                                                </div>
                                            </div>
                                        )}
                                    </div>
                                </div>
                            </section>
                        )}

                        {/* ═══ 5b. ARCHETYPE & PERSONALITY ═══ */}
                        <section
                            id="sec-archetype"
                            className="relative py-32 md:py-40 overflow-hidden text-white"
                            style={{
                                background: `linear-gradient(135deg, ${primaryDeep} 0%, ${primaryDark} 50%, ${darkenHex(primaryColor, 0.5)} 100%)`,
                            }}
                        >
                            <div className="absolute inset-0 opacity-[0.04]" style={{ backgroundImage: `radial-gradient(circle at 1px 1px, white 1px, transparent 0)`, backgroundSize: '40px 40px' }} />

                            <div className="relative mx-auto max-w-5xl px-6 lg:px-8 text-center">
                                {archetypeDisplay && (
                                    <>
                                        <span className="text-xs font-semibold uppercase tracking-[0.3em] text-white/50">Brand Archetype</span>
                                        <h2 className="mt-4 text-6xl md:text-8xl font-black tracking-tight leading-none">
                                            {archetypeDisplay}
                                        </h2>
                                    </>
                                )}
                                {personality.traits.length > 0 && (
                                    <div className="mt-14 flex flex-wrap justify-center gap-3">
                                        {personality.traits.map((t, i) => (
                                            <span
                                                key={i}
                                                className="inline-flex items-center rounded-full px-6 py-2.5 text-sm font-semibold tracking-wide"
                                                style={{
                                                    backgroundColor: hexToRgba(secondaryColor, 0.15),
                                                    color: lightenHex(secondaryColor, 0.4),
                                                    border: `1px solid ${hexToRgba(secondaryColor, 0.25)}`,
                                                }}
                                            >
                                                {t}
                                            </span>
                                        ))}
                                    </div>
                                )}
                                {personality.brand_look && (
                                    <p className="mt-14 text-lg md:text-xl text-white/75 leading-relaxed max-w-3xl mx-auto font-light">
                                        {personality.brand_look}
                                    </p>
                                )}
                                {(!archetypeDisplay && personality.traits.length === 0) && (
                                    <p className="text-white/50 italic">No personality configured.</p>
                                )}
                            </div>
                        </section>

                        {/* ═══ 6. VISUAL STYLE (Builder Step 4: Expression) ═══ */}
                        {(visual.photography_style || visual.visual_style || visual.composition_style || scoringRules.photography_attributes.length > 0) && (
                            <section id="sec-visual" className="py-28 md:py-36 bg-white">
                                <div className="mx-auto max-w-6xl px-6 lg:px-8">
                                    <SectionLabel color={secondaryColor}>Visual Style</SectionLabel>

                                    <div className="grid grid-cols-1 lg:grid-cols-12 gap-16 mt-4">
                                        <div className="lg:col-span-7">
                                            {visual.photography_style && (
                                                <p className="text-xl md:text-2xl text-gray-800 leading-relaxed font-light">
                                                    {visual.photography_style}
                                                </p>
                                            )}
                                            {visual.visual_style && !visual.photography_style && (
                                                <p className="text-xl md:text-2xl text-gray-800 leading-relaxed font-light">
                                                    {visual.visual_style}
                                                </p>
                                            )}
                                            {visual.composition_style && (
                                                <p className="mt-8 text-lg text-gray-600 leading-relaxed">{visual.composition_style}</p>
                                            )}
                                        </div>
                                        {scoringRules.photography_attributes.length > 0 && (
                                            <div className="lg:col-span-5 flex flex-col justify-center">
                                                <span className="text-xs font-semibold uppercase tracking-[0.15em] text-gray-400 mb-4">Attributes</span>
                                                <div className="flex flex-wrap gap-2">
                                                    {scoringRules.photography_attributes.map((a, i) => (
                                                        <span
                                                            key={i}
                                                            className="inline-flex items-center rounded-lg px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 ring-1 ring-gray-200/80"
                                                        >
                                                            {a}
                                                        </span>
                                                    ))}
                                                </div>
                                            </div>
                                        )}
                                    </div>
                                </div>
                            </section>
                        )}

                        {/* ═══ 6b. VISUAL REFERENCES — Photography, Textures, Patterns ═══ */}
                        {hasVisualRefs && (
                            <section id="sec-photography" className="py-28 md:py-36 relative overflow-hidden" style={{ background: `linear-gradient(180deg, ${hexToRgba(primaryColor, 0.03)} 0%, white 40%, ${hexToRgba(secondaryColor, 0.03)} 100%)` }}>
                                <div className="mx-auto max-w-7xl px-6 lg:px-8">
                                    <SectionLabel color={secondaryColor}>Visual References</SectionLabel>
                                    <h2 className="text-3xl md:text-4xl font-bold text-gray-900 mt-2 mb-4">Photography &amp; Visual Language</h2>
                                    <p className="text-lg text-gray-500 max-w-2xl mb-12">
                                        {visual.photography_style || 'Reference imagery that defines the visual direction of the brand.'}
                                    </p>

                                    {photographyRefs.length > 0 && (
                                        <div className="mb-16">
                                            <span className="text-xs font-semibold uppercase tracking-[0.15em] text-gray-400 mb-6 block">Photography</span>
                                            <div className="grid grid-cols-2 md:grid-cols-4 gap-4 auto-rows-[200px] md:auto-rows-[260px]">
                                                {photographyRefs.slice(0, 8).map((img, i) => {
                                                    const isLarge = i === 0 || i === 3
                                                    return (
                                                        <div
                                                            key={img.id || i}
                                                            className={`relative rounded-2xl overflow-hidden group ${isLarge ? 'col-span-2 row-span-2' : ''}`}
                                                        >
                                                            <img
                                                                src={img.url || img.thumbnail_url}
                                                                alt={img.title || `Reference ${i + 1}`}
                                                                className="absolute inset-0 w-full h-full object-cover transition-transform duration-700 group-hover:scale-105"
                                                            />
                                                            <div className="absolute inset-0 bg-gradient-to-t from-black/40 via-transparent to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-500" />
                                                            {img.title && (
                                                                <div className="absolute bottom-0 left-0 right-0 p-4 opacity-0 group-hover:opacity-100 transition-opacity duration-500">
                                                                    <span className="text-sm font-medium text-white drop-shadow-lg">{img.title}</span>
                                                                </div>
                                                            )}
                                                        </div>
                                                    )
                                                })}
                                            </div>
                                        </div>
                                    )}

                                    {graphicsRefs.length > 0 && (
                                        <div>
                                            <span className="text-xs font-semibold uppercase tracking-[0.15em] text-gray-400 mb-6 block">Graphics</span>
                                            <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                                                {graphicsRefs.slice(0, 8).map((img, i) => (
                                                    <div key={img.id || i} className="relative rounded-2xl overflow-hidden aspect-square group">
                                                        <img
                                                            src={img.url || img.thumbnail_url}
                                                            alt={img.title || `Graphic ${i + 1}`}
                                                            className="w-full h-full object-cover transition-transform duration-700 group-hover:scale-110"
                                                        />
                                                        <div className="absolute inset-0 ring-1 ring-inset ring-black/5 rounded-2xl" />
                                                    </div>
                                                ))}
                                            </div>
                                        </div>
                                    )}
                                </div>
                            </section>
                        )}

                        {/* ═══ 7. COLOR SYSTEM (Builder Step 6: Standards) ═══ */}
                        <section id="sec-colors" className="py-24 bg-white">
                            <div className="mx-auto max-w-6xl px-6 lg:px-8">
                                <SectionLabel color={secondaryColor}>Color System</SectionLabel>
                                <div className="grid grid-cols-1 md:grid-cols-3 gap-6 mt-4">
                                    {[
                                        { color: primaryColor, label: 'Primary' },
                                        { color: secondaryColor, label: 'Secondary' },
                                        { color: accentColor, label: 'Accent' },
                                    ].map(({ color, label }) => (
                                        <button
                                            key={label}
                                            type="button"
                                            onClick={() => handleCopyHex(color, label)}
                                            className="group relative min-h-[180px] rounded-2xl transition-all duration-200 hover:shadow-xl hover:scale-[1.02] focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-400"
                                            style={{ backgroundColor: color }}
                                        >
                                            <span className="absolute bottom-3 left-3 font-mono text-xs font-medium px-2 py-1 rounded bg-black/20 text-white backdrop-blur-sm">
                                                {color}
                                            </span>
                                            <span className="absolute top-3 left-3 text-xs font-semibold uppercase tracking-wider text-white/70">
                                                {label}
                                            </span>
                                            {copiedHex === label && (
                                                <span className="absolute top-3 right-3 text-xs font-medium text-white bg-black/30 px-2 py-1 rounded">Copied</span>
                                            )}
                                        </button>
                                    ))}
                                </div>
                                {scoringRules.allowed_color_palette.length > 0 && (
                                    <div className="mt-12">
                                        <p className="text-sm font-medium text-gray-500 uppercase tracking-wider mb-4">Extended Palette</p>
                                        <div className="flex flex-wrap gap-4">
                                            {scoringRules.allowed_color_palette.map((c, i) => {
                                                const hex = typeof c === 'string' ? c : c?.hex
                                                const role = typeof c === 'object' ? c?.role : null
                                                const isHex = hex && String(hex).startsWith('#')
                                                return (
                                                    <button
                                                        key={i}
                                                        type="button"
                                                        onClick={() => isHex && handleCopyHex(hex, `palette-${i}`)}
                                                        className="w-20 h-20 rounded-xl transition-all duration-200 hover:shadow-lg hover:scale-105 flex flex-col items-center justify-end pb-1.5 gap-0.5"
                                                        style={{ backgroundColor: isHex ? hex : '#e5e7eb' }}
                                                        title={hex + (role ? ` (${role})` : '')}
                                                    >
                                                        {isHex && <span className="text-[10px] font-mono text-white/80 bg-black/15 px-1 rounded">{hex}</span>}
                                                        {copiedHex === `palette-${i}` && <span className="text-[10px] font-medium text-white bg-black/30 px-1 rounded">Copied</span>}
                                                    </button>
                                                )
                                            })}
                                        </div>
                                    </div>
                                )}
                            </div>
                        </section>

                        {/* ═══ 8. TYPOGRAPHY (Builder Step 6: Standards) ═══ */}
                        {hasFonts && (
                            <section id="sec-typography" className="py-24 md:py-32" style={{ background: `linear-gradient(180deg, ${hexToRgba(primaryColor, 0.04)} 0%, white 100%)` }}>
                                <div className="mx-auto max-w-6xl px-6 lg:px-8">
                                    <SectionLabel color={secondaryColor}>Typography</SectionLabel>

                                    {typography.fonts.length > 0 ? (
                                        <div className="space-y-16 mt-4">
                                            {typography.fonts.map((font, i) => {
                                                const name = typeof font === 'string' ? font : (font?.name || 'Unknown')
                                                const role = typeof font === 'string' ? null : font?.role
                                                const styles = typeof font === 'string' ? [] : (font?.styles || [])
                                                const usageNotes = typeof font === 'string' ? null : font?.usage_notes
                                                return (
                                                    <div key={i} className="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">
                                                        <div className="lg:col-span-8">
                                                            <p
                                                                className="text-4xl md:text-5xl font-bold text-gray-900 leading-tight"
                                                                style={{ fontFamily: `"${name}", system-ui, sans-serif` }}
                                                            >
                                                                {i === 0 ? 'The quick brown fox jumps over the lazy dog.' : 'Pack my box with five dozen liquor jugs.'}
                                                            </p>
                                                        </div>
                                                        <div className="lg:col-span-4 space-y-2">
                                                            <p className="text-xl font-bold text-gray-900">{name}</p>
                                                            {role && <p className="text-sm text-gray-500 uppercase tracking-wider font-medium">{role}</p>}
                                                            {styles.length > 0 && <p className="text-sm text-gray-500">{styles.join(' · ')}</p>}
                                                            {usageNotes && <p className="text-sm text-gray-600 mt-2 leading-relaxed">{usageNotes}</p>}
                                                        </div>
                                                    </div>
                                                )
                                            })}
                                        </div>
                                    ) : (
                                        <div className="space-y-12 mt-4">
                                            {typography.primary_font && (
                                                <div className="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">
                                                    <div className="lg:col-span-8">
                                                        <p className="text-4xl md:text-5xl font-bold text-gray-900" style={{ fontFamily: `"${typography.primary_font}", system-ui, sans-serif` }}>
                                                            The quick brown fox jumps over the lazy dog.
                                                        </p>
                                                    </div>
                                                    <div className="lg:col-span-4">
                                                        <p className="text-xl font-bold text-gray-900">{typography.primary_font}</p>
                                                        <p className="text-sm text-gray-500 uppercase tracking-wider font-medium">Primary</p>
                                                    </div>
                                                </div>
                                            )}
                                            {typography.secondary_font && (
                                                <div className="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">
                                                    <div className="lg:col-span-8">
                                                        <p className="text-lg md:text-xl text-gray-700 leading-relaxed" style={{ fontFamily: `"${typography.secondary_font}", system-ui, sans-serif` }}>
                                                            Use this font for body copy, captions, and supporting text. It should feel readable and on-brand across all applications.
                                                        </p>
                                                    </div>
                                                    <div className="lg:col-span-4">
                                                        <p className="text-xl font-bold text-gray-900">{typography.secondary_font}</p>
                                                        <p className="text-sm text-gray-500 uppercase tracking-wider font-medium">Secondary</p>
                                                    </div>
                                                </div>
                                            )}
                                        </div>
                                    )}
                                    {(typography.heading_style || typography.body_style) && (
                                        <div className="mt-16 pt-10 border-t border-gray-200/60 grid grid-cols-1 md:grid-cols-2 gap-8">
                                            {typography.heading_style && (
                                                <div>
                                                    <span className="text-xs font-semibold uppercase tracking-wider text-gray-400">Heading Style</span>
                                                    <p className="mt-2 text-base text-gray-700">{typography.heading_style}</p>
                                                </div>
                                            )}
                                            {typography.body_style && (
                                                <div>
                                                    <span className="text-xs font-semibold uppercase tracking-wider text-gray-400">Body Style</span>
                                                    <p className="mt-2 text-base text-gray-700">{typography.body_style}</p>
                                                </div>
                                            )}
                                        </div>
                                    )}
                                </div>
                            </section>
                        )}

                        {/* ═══ 9. BRAND IDENTITY / LOGO (Builder Step 6: Standards) ═══ */}
                        {logoUrl && (
                            <section id="sec-logo" className="py-28 md:py-36 bg-white relative overflow-hidden">
                                <div className="mx-auto max-w-6xl px-6 lg:px-8">
                                    <SectionLabel color={secondaryColor}>Brand Identity</SectionLabel>

                                    <div className="grid grid-cols-1 lg:grid-cols-12 gap-12 lg:gap-20 mt-4">
                                        <div className="lg:col-span-7">
                                            <div
                                                className="relative rounded-2xl p-12 md:p-16 flex items-center justify-center min-h-[320px] md:min-h-[400px]"
                                                style={{ backgroundColor: primaryColor }}
                                            >
                                                <img src={logoUrl} alt={`${brand.name} — Primary Brandmark`} className="max-h-32 md:max-h-44 w-auto object-contain drop-shadow-lg" />
                                                <span className="absolute bottom-4 left-5 text-[10px] font-semibold uppercase tracking-[0.15em] text-white/40">Primary Brandmark</span>
                                            </div>
                                        </div>
                                        <div className="lg:col-span-5 grid grid-rows-2 gap-6">
                                            <div className="relative rounded-2xl p-8 flex items-center justify-center" style={{ backgroundColor: '#ffffff', border: '1px solid #e5e7eb' }}>
                                                <img
                                                    src={logoUrl}
                                                    alt={`${brand.name} — On White`}
                                                    className="max-h-16 md:max-h-20 w-auto object-contain"
                                                    style={logoIsTransparent ? {} : { filter: 'brightness(0.2)' }}
                                                />
                                                <span className="absolute bottom-3 left-4 text-[10px] font-semibold uppercase tracking-[0.15em] text-gray-300">On Light Background</span>
                                            </div>
                                            <div className="relative rounded-2xl p-8 flex items-center justify-center" style={{ backgroundColor: secondaryColor }}>
                                                <img
                                                    src={logoUrl}
                                                    alt={`${brand.name} — Reversed`}
                                                    className={`max-h-16 md:max-h-20 w-auto object-contain${logoIsTransparent ? '' : ' brightness-0 invert'}`}
                                                />
                                                <span className="absolute bottom-3 left-4 text-[10px] font-semibold uppercase tracking-[0.15em] text-white/40">Reversed / On Color</span>
                                            </div>
                                        </div>
                                    </div>

                                    <div className="mt-8 grid grid-cols-2 md:grid-cols-4 gap-6">
                                        <div className="relative rounded-xl p-6 flex items-center justify-center min-h-[120px]" style={{ backgroundColor: accentColor }}>
                                            <img
                                                src={logoUrl}
                                                alt={`${brand.name} — On Accent`}
                                                className="max-h-12 w-auto object-contain"
                                                style={logoIsTransparent ? {} : { filter: 'brightness(0) saturate(100%) invert(8%) sepia(50%) saturate(4000%) hue-rotate(180deg)' }}
                                            />
                                            <span className="absolute bottom-2 left-3 text-[9px] font-semibold uppercase tracking-wider text-black/25">On Accent</span>
                                        </div>
                                        <div className="relative rounded-xl p-6 flex items-center justify-center min-h-[120px]" style={{ backgroundColor: '#111827' }}>
                                            <img
                                                src={logoUrl}
                                                alt={`${brand.name} — On Dark`}
                                                className={`max-h-12 w-auto object-contain${logoIsTransparent ? '' : ' brightness-0 invert'}`}
                                            />
                                            <span className="absolute bottom-2 left-3 text-[9px] font-semibold uppercase tracking-wider text-white/30">On Dark</span>
                                        </div>
                                        <div className="relative rounded-xl p-6 flex items-center justify-center min-h-[120px]" style={{ backgroundColor: primaryColor, opacity: 0.7 }}>
                                            <img src={logoUrl} alt={`${brand.name} — Reduced`} className="max-h-12 w-auto object-contain" />
                                            <span className="absolute bottom-2 left-3 text-[9px] font-semibold uppercase tracking-wider text-white/30">Reduced Opacity</span>
                                        </div>
                                        <div className="relative rounded-xl p-6 flex items-center justify-center min-h-[120px] bg-gradient-to-br from-gray-50 to-gray-100 border border-gray-200">
                                            <img src={logoUrl} alt={`${brand.name} — Minimum Size`} className="max-h-6 w-auto object-contain" />
                                            <span className="absolute bottom-2 left-3 text-[9px] font-semibold uppercase tracking-wider text-gray-300">Minimum Size</span>
                                        </div>
                                    </div>

                                    {logoAssets.filter(a => a.role === 'secondary').length > 0 && (
                                        <div className="mt-20">
                                            <div className="flex items-center gap-4 mb-8">
                                                <div className="w-8 h-px bg-gray-300" />
                                                <span className="text-xs font-semibold uppercase tracking-[0.2em] text-gray-400">Secondary Marks</span>
                                            </div>
                                            <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6">
                                                {logoAssets.filter(a => a.role === 'secondary').map((asset) => (
                                                    <div key={asset.id} className="relative rounded-xl border border-gray-200 bg-white p-8 flex flex-col items-center justify-center min-h-[160px] hover:shadow-lg transition-shadow duration-200">
                                                        <img src={asset.url} alt={asset.title} className="max-h-16 w-auto object-contain" />
                                                        <span className="mt-4 text-xs text-gray-400 font-medium truncate max-w-full">{asset.title}</span>
                                                    </div>
                                                ))}
                                            </div>
                                        </div>
                                    )}
                                </div>
                            </section>
                        )}

                        {/* ═══ 10. LOGO STANDARDS (Builder Step 6: Standards) ═══ */}
                        {hasLogoGuidelines && (() => {
                            const showVisual = visual.show_logo_visual_treatment && logoUrl
                            const brandColors = { primary: primaryColor, secondary: secondaryColor }
                            const isTransparent = logoIsTransparent
                            const guidelineLabels = {
                                clear_space: { title: 'Clear Space', icon: '◻', category: 'do' },
                                minimum_size: { title: 'Minimum Size', icon: '↕', category: 'do' },
                                color_usage: { title: 'Color Usage', icon: '◉', category: 'do' },
                                background_contrast: { title: 'Background Contrast', icon: '◐', category: 'do' },
                                dont_stretch: { title: "Don't Stretch", icon: '✕', category: 'dont' },
                                dont_recolor: { title: "Don't Recolor", icon: '✕', category: 'dont' },
                                dont_rotate: { title: "Don't Rotate", icon: '✕', category: 'dont' },
                                dont_crop: { title: "Don't Crop", icon: '✕', category: 'dont' },
                                dont_add_effects: { title: "Don't Add Effects", icon: '✕', category: 'dont' },
                            }
                            const allEntries = Object.entries(visual.logo_usage_guidelines).filter(([, v]) => v)
                            const doEntries = allEntries.filter(([key]) => (guidelineLabels[key]?.category || (key.startsWith('dont_') ? 'dont' : 'do')) === 'do')
                            const dontEntries = allEntries.filter(([key]) => (guidelineLabels[key]?.category || (key.startsWith('dont_') ? 'dont' : 'do')) === 'dont')

                            const renderCard = ([key, value], isDont) => {
                                const info = guidelineLabels[key] || { title: key.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase()), icon: '•' }
                                const treatment = showVisual ? LOGO_VISUAL_TREATMENTS[key] : null
                                return (
                                    <div
                                        key={key}
                                        className="rounded-lg overflow-hidden transition-all duration-200 hover:scale-[1.01]"
                                        style={{
                                            backgroundColor: isDont ? 'rgba(255,255,255,0.04)' : 'rgba(255,255,255,0.07)',
                                            border: `1px solid ${isDont ? 'rgba(255,100,100,0.12)' : 'rgba(255,255,255,0.08)'}`,
                                        }}
                                    >
                                        {treatment && (
                                            <div className="relative">
                                                {treatment(logoUrl, brandColors, isTransparent)}
                                                {isDont && (
                                                    <div className="absolute top-1.5 left-1.5 px-1 py-0.5 rounded bg-red-500/90 text-[8px] font-bold text-white uppercase tracking-wider z-10">
                                                        Don&apos;t
                                                    </div>
                                                )}
                                            </div>
                                        )}
                                        <div className="px-4 py-3">
                                            <div className="flex items-center gap-2 mb-1.5">
                                                <span className={`text-sm ${isDont ? 'text-red-400/80' : 'text-white/50'}`}>{info.icon}</span>
                                                <h3 className={`text-xs font-bold uppercase tracking-wider ${isDont ? 'text-red-300/80' : 'text-white/90'}`}>
                                                    {info.title}
                                                </h3>
                                            </div>
                                            <p className="text-xs text-white/55 leading-relaxed">{value}</p>
                                        </div>
                                    </div>
                                )
                            }

                            return (
                            <section
                                id="sec-logo-standards"
                                className="py-20 md:py-28 text-white relative overflow-hidden"
                                style={{
                                    background: `linear-gradient(160deg, ${primaryDeep} 0%, ${primaryDark} 60%, ${darkenHex(secondaryColor, 0.6)} 100%)`,
                                }}
                            >
                                <div className="absolute inset-0 opacity-[0.03]" style={{ backgroundImage: `radial-gradient(circle at 2px 2px, white 1px, transparent 0)`, backgroundSize: '30px 30px' }} />

                                <div className="relative mx-auto max-w-6xl px-6 lg:px-8">
                                    <SectionLabel color={hexToRgba(secondaryColor, 0.7)}>Logo Standards</SectionLabel>

                                    {logoUrl && (
                                        <div className="flex justify-center mb-10">
                                            <div className="bg-white/10 backdrop-blur-sm rounded-xl p-6 border border-white/10">
                                                <img src={logoUrl} alt={brand.name} className="h-12 md:h-16 w-auto object-contain" />
                                            </div>
                                        </div>
                                    )}

                                    {doEntries.length > 0 && (
                                        <div className="mb-8">
                                            <div className="flex items-center gap-3 mb-4">
                                                <span className="text-[10px] font-bold uppercase tracking-[0.2em] text-emerald-400/70">Best Practices</span>
                                                <div className="flex-1 h-px bg-white/10" />
                                            </div>
                                            <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
                                                {doEntries.map((entry) => renderCard(entry, false))}
                                            </div>
                                        </div>
                                    )}

                                    {dontEntries.length > 0 && (
                                        <div>
                                            <div className="flex items-center gap-3 mb-4">
                                                <span className="text-[10px] font-bold uppercase tracking-[0.2em] text-red-400/70">Avoid</span>
                                                <div className="flex-1 h-px bg-red-400/10" />
                                            </div>
                                            <div className="grid grid-cols-2 lg:grid-cols-5 gap-4">
                                                {dontEntries.map((entry) => renderCard(entry, true))}
                                            </div>
                                        </div>
                                    )}
                                </div>
                            </section>
                            )
                        })()}

                        {/* ═══ FOOTER ═══ */}
                        <section
                            className="py-16"
                            style={{
                                background: `linear-gradient(180deg, white 0%, ${hexToRgba(primaryColor, 0.06)} 100%)`,
                            }}
                        >
                            <div className="mx-auto max-w-6xl px-6 lg:px-8">
                                <div className="flex items-center justify-between border-t border-gray-200 pt-8">
                                    <div className="flex items-center gap-3">
                                        {logoUrl && <img src={logoUrl} alt={brand.name} className="h-8 w-auto object-contain opacity-60" />}
                                        <span className="text-sm text-gray-400 font-medium">{brand.name}</span>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <div className="w-2 h-2 rounded-full" style={{ backgroundColor: primaryColor }} />
                                        <div className="w-2 h-2 rounded-full" style={{ backgroundColor: secondaryColor }} />
                                        <div className="w-2 h-2 rounded-full" style={{ backgroundColor: accentColor }} />
                                    </div>
                                </div>
                            </div>
                        </section>
                    </>
                )}
            </main>
        </div>
    )
}
