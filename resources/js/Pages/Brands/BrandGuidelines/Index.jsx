import { useState, useEffect, useRef, useCallback, useMemo } from 'react'
import { Link, router, usePage } from '@inertiajs/react'
import AppNav from '../../../Components/AppNav'
import AppHead from '../../../Components/AppHead'
import ConfirmDialog from '../../../Components/ConfirmDialog'
import useLogoWhiteBgPreview from '../../../utils/useLogoWhiteBgPreview'

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

function guidelineWhiteOutlineClass(outlineWhiteBg) {
    return outlineWhiteBg ? 'drop-shadow-[0_0_1px_rgba(0,0,0,0.45)]' : ''
}

const LOGO_VISUAL_TREATMENTS = {
    clear_space: (src, _colors, _isTransparent, meta = {}) => {
        const w = meta.whiteBgSrc || src
        const oc = guidelineWhiteOutlineClass(meta.outlineWhiteBg)
        return (
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
                    <img src={w} alt="" className={`h-10 max-w-[100px] object-contain ${oc}`} />
                </div>
            </div>
        )
    },
    minimum_size: (src, _colors, _isTransparent, meta = {}) => {
        const w = meta.whiteBgSrc || src
        const oc = guidelineWhiteOutlineClass(meta.outlineWhiteBg)
        return (
            <div className="w-full aspect-[3/2] bg-white rounded-t-xl flex items-end justify-center gap-6 pb-4 px-4">
                <div className="flex flex-col items-center gap-1">
                    <img src={w} alt="" className={`h-10 max-w-[80px] object-contain ${oc}`} />
                    <span className="text-[8px] text-gray-500 font-medium">Full size</span>
                </div>
                <div className="flex flex-col items-center gap-1">
                    <img src={w} alt="" className={`h-5 max-w-[40px] object-contain ${oc}`} />
                    <span className="text-[8px] text-gray-500 font-medium">Min size</span>
                </div>
                <div className="flex flex-col items-center gap-1 opacity-30">
                    <img src={w} alt="" className={`h-2.5 max-w-[20px] object-contain ${oc}`} />
                    <div className="flex items-center gap-0.5">
                        <span className="text-red-500 text-[10px]">✕</span>
                        <span className="text-[8px] text-red-500 font-medium">Too small</span>
                    </div>
                </div>
            </div>
        )
    },
    color_usage: (src, colors, isTransparent, meta = {}) => {
        const w = meta.whiteBgSrc || src
        const oc = guidelineWhiteOutlineClass(meta.outlineWhiteBg)
        return (
            <div className="w-full aspect-[3/2] rounded-t-xl overflow-hidden grid grid-cols-2">
                <div className="bg-white flex items-center justify-center p-3">
                    <img src={w} alt="" className={`h-8 max-w-[70px] object-contain ${oc}`} />
                </div>
                <div className="flex items-center justify-center p-3" style={{ backgroundColor: colors?.primary || '#1a1a2e' }}>
                    <img src={src} alt="" className={`h-8 max-w-[70px] object-contain${isTransparent ? '' : ' brightness-0 invert'}`} />
                </div>
                <div className="flex items-center justify-center p-3" style={{ backgroundColor: colors?.secondary || '#f0f0f0' }}>
                    <img src={w} alt="" className={`h-8 max-w-[70px] object-contain ${oc}`} />
                </div>
                <div className="bg-gray-800 flex items-center justify-center p-3">
                    <img src={src} alt="" className={`h-8 max-w-[70px] object-contain${isTransparent ? '' : ' brightness-0 invert'}`} />
                </div>
            </div>
        )
    },
    background_contrast: (src, colors, isTransparent, meta = {}) => {
        const w = meta.whiteBgSrc || src
        const oc = guidelineWhiteOutlineClass(meta.outlineWhiteBg)
        return (
            <div className="w-full aspect-[3/2] rounded-t-xl overflow-hidden grid grid-cols-2">
                <div className="flex items-center justify-center p-3 relative" style={{ backgroundColor: colors?.primary || '#002A3A' }}>
                    <img src={src} alt="" className={`h-8 max-w-[70px] object-contain relative z-10${isTransparent ? '' : ' brightness-0 invert'}`} />
                    <span className="absolute bottom-1 text-[8px] text-white/60 font-medium">✓ Good</span>
                </div>
                <div className="flex items-center justify-center p-3 relative bg-[repeating-conic-gradient(#e0e0e0_0%_25%,#fff_0%_50%)] bg-[length:16px_16px]">
                    <img src={w} alt="" className={`h-8 max-w-[70px] object-contain opacity-40 relative z-10 ${oc}`} />
                    <span className="absolute bottom-1 text-[8px] text-red-500 font-medium z-10">✕ Busy bg</span>
                </div>
            </div>
        )
    },
    dont_stretch: (src, _colors, _isTransparent, meta = {}) => {
        const w = meta.whiteBgSrc || src
        const oc = guidelineWhiteOutlineClass(meta.outlineWhiteBg)
        return (
            <div className="w-full aspect-[3/2] bg-white rounded-t-xl flex items-center justify-center gap-4 px-4 relative">
                <div className="flex flex-col items-center gap-1">
                    <img src={w} alt="" className={`h-8 max-w-[60px] object-contain ${oc}`} style={{ transform: 'scaleX(1.6)' }} />
                </div>
                <div className="flex flex-col items-center gap-1">
                    <img src={w} alt="" className={`h-12 max-w-[30px] object-contain ${oc}`} style={{ transform: 'scaleY(1.5) scaleX(0.6)' }} />
                </div>
                <div className="absolute inset-0 flex items-center justify-center pointer-events-none">
                    <div className="w-12 h-12 rounded-full border-[3px] border-red-500/70 flex items-center justify-center">
                        <div className="w-10 h-[3px] bg-red-500/70 rotate-45 rounded-full" />
                    </div>
                </div>
            </div>
        )
    },
    dont_rotate: (src, _colors, _isTransparent, meta = {}) => {
        const w = meta.whiteBgSrc || src
        const oc = guidelineWhiteOutlineClass(meta.outlineWhiteBg)
        return (
            <div className="w-full aspect-[3/2] bg-white rounded-t-xl flex items-center justify-center px-4 relative">
                <img src={w} alt="" className={`h-10 max-w-[80px] object-contain ${oc}`} style={{ transform: 'rotate(-15deg)' }} />
                <div className="absolute inset-0 flex items-center justify-center pointer-events-none">
                    <div className="w-12 h-12 rounded-full border-[3px] border-red-500/70 flex items-center justify-center">
                        <div className="w-10 h-[3px] bg-red-500/70 rotate-45 rounded-full" />
                    </div>
                </div>
            </div>
        )
    },
    dont_recolor: (src, _colors, _isTransparent, meta = {}) => {
        const w = meta.whiteBgSrc || src
        const oc = guidelineWhiteOutlineClass(meta.outlineWhiteBg)
        return (
            <div className="w-full aspect-[3/2] bg-white rounded-t-xl flex items-center justify-center px-4 relative">
                <img src={w} alt="" className={`h-10 max-w-[80px] object-contain ${oc}`} style={{ filter: 'hue-rotate(180deg) saturate(2)' }} />
                <div className="absolute inset-0 flex items-center justify-center pointer-events-none">
                    <div className="w-12 h-12 rounded-full border-[3px] border-red-500/70 flex items-center justify-center">
                        <div className="w-10 h-[3px] bg-red-500/70 rotate-45 rounded-full" />
                    </div>
                </div>
            </div>
        )
    },
    dont_crop: (src, _colors, _isTransparent, meta = {}) => {
        const w = meta.whiteBgSrc || src
        const oc = guidelineWhiteOutlineClass(meta.outlineWhiteBg)
        return (
            <div className="w-full aspect-[3/2] bg-white rounded-t-xl flex items-center justify-end overflow-hidden relative">
                <img src={w} alt="" className={`h-10 max-w-[80px] object-contain mr-[-20px] ${oc}`} />
                <div className="absolute inset-0 flex items-center justify-center pointer-events-none">
                    <div className="w-12 h-12 rounded-full border-[3px] border-red-500/70 flex items-center justify-center">
                        <div className="w-10 h-[3px] bg-red-500/70 rotate-45 rounded-full" />
                    </div>
                </div>
            </div>
        )
    },
    dont_add_effects: (src, _colors, _isTransparent, meta = {}) => {
        const w = meta.whiteBgSrc || src
        const oc = guidelineWhiteOutlineClass(meta.outlineWhiteBg)
        return (
            <div className="w-full aspect-[3/2] bg-white rounded-t-xl flex items-center justify-center px-4 relative">
                <img src={w} alt="" className={`h-10 max-w-[80px] object-contain ${oc}`} style={{ filter: 'drop-shadow(4px 4px 6px rgba(0,0,0,0.5))' }} />
                <div className="absolute top-2 right-2 px-1.5 py-0.5 bg-yellow-400/90 rounded text-[7px] font-bold text-black tracking-wide">GLOW</div>
                <div className="absolute inset-0 flex items-center justify-center pointer-events-none">
                    <div className="w-12 h-12 rounded-full border-[3px] border-red-500/70 flex items-center justify-center">
                        <div className="w-10 h-[3px] bg-red-500/70 rotate-45 rounded-full" />
                    </div>
                </div>
            </div>
        )
    },
}

function SectionLabel({ children, color = '#94a3b8', bold = false, textured = false }) {
    if (textured) {
        return (
            <div className="flex items-center gap-5 mb-8">
                <div className="w-12 h-[2px]" style={{ background: `linear-gradient(90deg, ${color}, transparent)` }} />
                <span className="text-[11px] font-bold uppercase" style={{ color, letterSpacing: '0.35em' }}>{children}</span>
            </div>
        )
    }
    if (bold) {
        return (
            <div className="mb-8">
                <span
                    className="inline-block px-4 py-1.5 text-[11px] font-extrabold uppercase tracking-[0.25em] text-white"
                    style={{ backgroundColor: color }}
                >
                    {children}
                </span>
            </div>
        )
    }
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
    const presentationStyle = modelPayload?.presentation?.style || 'clean'
    const isBold = presentationStyle === 'bold'
    const isTextured = presentationStyle === 'textured'

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
    const logoOnLightUrl = brand.logo_on_light_url || null
    const { whiteBgSrc, showRiskBanner, outlineWhiteBg, loadingAnalysis } = useLogoWhiteBgPreview(logoUrl, logoOnLightUrl)
    const guidelineMeta = useMemo(() => ({ whiteBgSrc, outlineWhiteBg }), [whiteBgSrc, outlineWhiteBg])
    const logoDarkUrl = brand.logo_dark_url || null
    const heroLogoUrl = logoDarkUrl || logoUrl
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

    const allImageRefs = [...photographyRefs, ...graphicsRefs]
    const texBg = (idx = 0) => allImageRefs[idx % allImageRefs.length]?.url || allImageRefs[idx % allImageRefs.length]?.thumbnail_url || null
    const grainSvg = "data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.85' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E"

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
    const [scrolledPastHero, setScrolledPastHero] = useState(false)
    const darkSections = new Set(['sec-hero', 'sec-archetype', 'sec-logo-standards'])

    useEffect(() => {
        if (showCallout) return
        const onScroll = () => {
            setScrolledPastHero(window.scrollY > window.innerHeight * 0.4)
        }
        window.addEventListener('scroll', onScroll, { passive: true })
        onScroll()
        return () => window.removeEventListener('scroll', onScroll)
    }, [showCallout])

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
        <div className={showCallout ? 'h-screen overflow-hidden flex flex-col bg-[#0B0B0D]' : 'min-h-full bg-white'}>
            <AppHead title={`Brand Guidelines — ${brand.name}`} />
            {showCallout ? (
                <div className="absolute top-0 left-0 right-0 z-50">
                    <AppNav brand={auth?.activeBrand} tenant={null} variant="transparent" />
                </div>
            ) : (
                <div
                    className={`fixed top-0 left-0 right-0 z-50 transition-all duration-300 ${scrolledPastHero ? '' : 'bg-transparent'}`}
                >
                    <AppNav brand={auth?.activeBrand} tenant={null} variant={scrolledPastHero ? undefined : 'transparent'} />
                </div>
            )}
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
            <main className={showCallout ? 'relative flex-1 min-h-0 flex flex-col' : 'relative'}>
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
                    <div className="relative h-full overflow-hidden flex flex-col items-center justify-center px-4 py-16">
                        {/* Cinematic background */}
                        <div
                            className="absolute inset-0 will-change-transform"
                            style={{
                                background: `radial-gradient(circle at 20% 20%, ${hexToRgba(primaryColor, 0.2)} 0%, transparent 50%), radial-gradient(circle at 80% 80%, ${hexToRgba(secondaryColor, 0.15)} 0%, transparent 50%), #0B0B0D`,
                            }}
                        />
                        <div className="absolute inset-0 bg-black/30" />
                        <div className="absolute inset-0 bg-gradient-to-b from-black/20 via-transparent to-black/50" />

                        <Link
                            href={typeof route === 'function' ? route('brands.edit', { brand: brand.id }) : `/app/brands/${brand.id}/edit`}
                            className="absolute top-20 left-6 z-10 text-sm font-medium text-white/70 hover:text-white transition-colors"
                        >
                            &larr; Back to Brand Portal
                        </Link>
                        <div className="relative z-10 max-w-lg text-center space-y-6 animate-fadeInUp-d2">
                            <h1 className="text-2xl md:text-3xl font-bold text-white tracking-tight">
                                Brand Guidelines &mdash; {brand.name}
                            </h1>
                            <p className="text-white/70">
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
                                        className="inline-flex rounded-xl px-6 py-3 text-sm font-semibold text-white transition-all duration-200 hover:scale-[1.03] hover:shadow-[0_0_30px_rgba(99,102,241,0.4)]"
                                        style={{ backgroundColor: primaryColor }}
                                    >
                                        {hasDraft ? resumeLabel : 'Start Brand Guidelines'}
                                    </button>
                                    {hasDraft && (
                                        <>
                                            <button type="button" onClick={() => setShowStartOverConfirm(true)} className="inline-flex rounded-xl border border-white/20 px-6 py-3 text-sm font-medium text-white/90 hover:bg-white/10 transition-all duration-200 hover:scale-[1.02]">Start over</button>
                                            <ConfirmDialog open={showStartOverConfirm} onClose={() => setShowStartOverConfirm(false)} onConfirm={() => { setShowStartOverConfirm(false); router.post(route('brands.brand-dna.builder.start', { brand: brand.id })) }} title="Start over" message="Your current draft will be replaced with a fresh one. This cannot be undone." confirmText="Start over" cancelText="Cancel" variant="warning" />
                                        </>
                                    )}
                                </div>
                                <p className="text-sm text-white/50">{hasDraft ? 'Resume where you left off, or start fresh.' : 'You can import a PDF or start from scratch on the first step.'}</p>
                            </div>
                            <Link href={typeof route === 'function' ? route('brands.edit', { brand: brand.id, tab: 'strategy' }) : `/app/brands/${brand.id}/edit?tab=strategy`} className="block text-sm text-white/50 hover:text-white/80 transition-colors">Or configure Brand DNA in Settings</Link>
                        </div>
                    </div>
                ) : (
                    <>
                        {/* ═══ 1. HERO ═══ */}
                        <section id="sec-hero" className="relative w-full overflow-hidden" style={{ minHeight: '100vh' }}>
                            {/* Cinematic background */}
                            {isTextured && texBg(0) && (
                                <img src={texBg(0)} alt="" className="absolute inset-0 w-full h-full object-cover" />
                            )}
                            <div
                                className="absolute inset-0"
                                style={{
                                    ...(isTextured && texBg(0) ? { mixBlendMode: 'multiply' } : {}),
                                    background: isBold
                                        ? `linear-gradient(160deg, ${primaryDeep} 0%, ${primaryDark} 40%, ${primaryColor} 100%)`
                                        : isTextured
                                            ? `linear-gradient(160deg, ${hexToRgba(primaryDeep, 0.92)} 0%, ${hexToRgba(primaryDark, 0.88)} 35%, ${hexToRgba(primaryColor, 0.85)} 100%)`
                                            : `
                                                radial-gradient(ellipse 120% 80% at 20% 50%, ${hexToRgba(secondaryColor, 0.15)} 0%, transparent 70%),
                                                radial-gradient(ellipse 80% 120% at 80% 20%, ${hexToRgba(accentColor, 0.08)} 0%, transparent 60%),
                                                linear-gradient(160deg, ${primaryDeep} 0%, ${primaryDark} 35%, ${primaryColor} 100%)
                                            `,
                                }}
                            />
                            {!isBold && !isTextured && (
                                <div
                                    className="absolute inset-0 pointer-events-none"
                                    style={{
                                        background: `radial-gradient(circle at 30% 40%, ${hexToRgba(primaryColor, 0.12)}, transparent 50%)`,
                                    }}
                                />
                            )}
                            <div className={`absolute inset-0 ${isTextured ? 'bg-black/40' : 'bg-black/25'}`} />
                            <div className="absolute inset-0 bg-gradient-to-b from-black/15 via-transparent to-black/40" />
                            {(isTextured || !isBold) && (
                                <div
                                    className={`absolute inset-0 pointer-events-none ${isTextured ? 'opacity-[0.06]' : 'opacity-[0.03]'}`}
                                    style={{ backgroundImage: `url("${grainSvg}")` }}
                                />
                            )}

                            <div className="relative flex flex-col items-center justify-center px-6 lg:px-8" style={{ minHeight: '100vh' }}>
                                {heroLogoUrl && (
                                    <img src={heroLogoUrl} alt={brand.name} className={`h-20 md:h-28 w-auto object-contain mb-10 drop-shadow-2xl ${isTextured ? 'brightness-110' : ''}`} />
                                )}
                                {isBold ? (
                                    <div className="px-8 py-4" style={{ backgroundColor: hexToRgba(secondaryColor, 0.9) }}>
                                        <h1 className="text-4xl md:text-6xl lg:text-7xl font-black tracking-tight text-white text-center uppercase whitespace-nowrap">
                                            Brand Guidelines
                                        </h1>
                                    </div>
                                ) : isTextured ? (
                                    <h1 className="text-4xl md:text-6xl lg:text-7xl font-black text-white text-center uppercase" style={{ letterSpacing: '0.18em', textShadow: '0 4px 30px rgba(0,0,0,0.5)' }}>
                                        Brand Guidelines
                                    </h1>
                                ) : (
                                    <h1 className="text-4xl md:text-6xl lg:text-7xl font-black tracking-tight text-white text-center whitespace-nowrap">
                                        Brand Guidelines
                                    </h1>
                                )}
                                {heroSubheading && (
                                    <p className={`mt-8 text-lg md:text-xl text-white/80 max-w-xl text-center ${isTextured ? 'font-medium uppercase tracking-[0.25em]' : isBold ? 'font-semibold uppercase tracking-widest' : 'font-light tracking-wide'}`}
                                       style={isTextured ? { textShadow: '0 2px 12px rgba(0,0,0,0.4)' } : {}}
                                    >
                                        {heroSubheading}
                                    </p>
                                )}
                                <div className={`mt-12 flex items-center ${isTextured ? 'gap-1' : isBold ? 'gap-0' : 'gap-3'}`}>
                                    <div className={isTextured ? 'w-10 h-[3px]' : isBold ? 'w-8 h-2' : 'w-3 h-3 rounded-full'} style={{ backgroundColor: primaryColor }} />
                                    <div className={isTextured ? 'w-10 h-[3px]' : isBold ? 'w-8 h-2' : 'w-3 h-3 rounded-full'} style={{ backgroundColor: secondaryColor }} />
                                    <div className={isTextured ? 'w-10 h-[3px]' : isBold ? 'w-8 h-2' : 'w-3 h-3 rounded-full'} style={{ backgroundColor: accentColor }} />
                                </div>
                            </div>
                        </section>

                        {/* ═══ 2. PURPOSE & POSITIONING (Builder Step 3) ═══ */}
                        {(identity.mission || identity.positioning) && (
                            <section id="sec-purpose" className="py-28 md:py-36 relative overflow-hidden" style={{
                                background: isTextured
                                    ? primaryDeep
                                    : isBold ? `linear-gradient(180deg, ${hexToRgba(primaryColor, 0.06)} 0%, white 100%)` : 'white'
                            }}>
                                {isTextured && texBg(1) && (
                                    <>
                                        <img src={texBg(1)} alt="" className="absolute inset-0 w-full h-full object-cover opacity-20" style={{ mixBlendMode: 'screen' }} />
                                        <div className="absolute inset-0 opacity-[0.04]" style={{ backgroundImage: `url("${grainSvg}")` }} />
                                    </>
                                )}
                                <div className="mx-auto max-w-6xl px-6 lg:px-8 relative">
                                    <div className="grid grid-cols-1 lg:grid-cols-12 gap-20">
                                        <div className="lg:col-span-7">
                                            <SectionLabel color={isTextured ? hexToRgba(primaryColor, 0.8) : secondaryColor} bold={isBold} textured={isTextured}>Purpose</SectionLabel>
                                            {identity.mission && (
                                                isTextured ? (
                                                    <blockquote className="text-3xl md:text-5xl font-bold leading-[1.15] uppercase" style={{ color: 'rgba(255,255,255,0.92)', letterSpacing: '0.04em', textShadow: '0 2px 20px rgba(0,0,0,0.3)' }}>
                                                        &ldquo;{identity.mission}&rdquo;
                                                    </blockquote>
                                                ) : isBold ? (
                                                    <div className="border-l-4 pl-8" style={{ borderColor: primaryColor }}>
                                                        <blockquote className="text-3xl md:text-5xl font-black text-gray-900 leading-[1.15] tracking-tight uppercase">
                                                            &ldquo;{identity.mission}&rdquo;
                                                        </blockquote>
                                                    </div>
                                                ) : (
                                                    <blockquote className="text-3xl md:text-5xl font-light text-gray-900 leading-[1.2] tracking-tight">
                                                        &ldquo;{identity.mission}&rdquo;
                                                    </blockquote>
                                                )
                                            )}
                                            {identity.positioning && (
                                                <p className={`mt-10 text-xl md:text-2xl leading-relaxed ${isTextured ? 'font-light text-white/70' : isBold ? 'font-medium' : 'font-light'}`} style={isTextured ? {} : { color: darkenHex(primaryColor, 0.1) }}>
                                                    {identity.positioning}
                                                </p>
                                            )}
                                        </div>
                                        <div className="lg:col-span-5 flex flex-col justify-center space-y-8">
                                            {identity.industry && (
                                                <div className={isTextured ? 'p-5 border border-white/10 bg-white/[0.04] backdrop-blur-sm' : isBold ? 'p-5 border-2 border-gray-200' : ''}>
                                                    <span className={`text-[11px] font-semibold uppercase tracking-[0.15em] ${isTextured ? 'text-white/40' : 'text-gray-400'}`}>Industry</span>
                                                    <p className={`mt-1 text-lg font-medium ${isTextured ? 'text-white/90 uppercase tracking-wide' : isBold ? 'text-gray-800 uppercase tracking-wide' : 'text-gray-800'}`}>{identity.industry}</p>
                                                </div>
                                            )}
                                            {identity.target_audience && (
                                                <div className={isTextured ? 'p-5 border border-white/10 bg-white/[0.04] backdrop-blur-sm' : isBold ? 'p-5 border-2 border-gray-200' : ''}>
                                                    <span className={`text-[11px] font-semibold uppercase tracking-[0.15em] ${isTextured ? 'text-white/40' : 'text-gray-400'}`}>Target Audience</span>
                                                    <p className={`mt-1 text-base leading-relaxed ${isTextured ? 'text-white/80' : 'text-gray-700'}`}>{identity.target_audience}</p>
                                                </div>
                                            )}
                                            {identity.tagline && identity.tagline !== heroSubheading && (
                                                <div className={isTextured ? 'p-5 border border-white/10 bg-white/[0.04] backdrop-blur-sm' : isBold ? 'p-5 border-2 border-gray-200' : ''}>
                                                    <span className={`text-[11px] font-semibold uppercase tracking-[0.15em] ${isTextured ? 'text-white/40' : 'text-gray-400'}`}>Tagline</span>
                                                    <p className={`mt-1 text-lg font-medium ${isTextured ? 'text-white/90 uppercase tracking-wide' : isBold ? 'text-gray-800 uppercase tracking-wide' : 'text-gray-800 italic'}`}>{identity.tagline}</p>
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
                                className="py-28 md:py-36 relative overflow-hidden"
                                style={{ background: isTextured
                                    ? `linear-gradient(180deg, ${primaryDark} 0%, ${primaryDeep} 100%)`
                                    : isBold
                                        ? `linear-gradient(180deg, ${hexToRgba(primaryColor, 0.08)} 0%, ${hexToRgba(primaryColor, 0.03)} 100%)`
                                        : `linear-gradient(180deg, ${hexToRgba(primaryColor, 0.04)} 0%, white 100%)`
                                }}
                            >
                                {isTextured && texBg(2) && (
                                    <>
                                        <img src={texBg(2)} alt="" className="absolute inset-0 w-full h-full object-cover opacity-15" style={{ mixBlendMode: 'screen' }} />
                                        <div className="absolute inset-0 opacity-[0.04]" style={{ backgroundImage: `url("${grainSvg}")` }} />
                                    </>
                                )}
                                <div className="mx-auto max-w-6xl px-6 lg:px-8 relative">
                                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-20">
                                        {hasValues && (
                                            <div>
                                                <SectionLabel color={isTextured ? hexToRgba(primaryColor, 0.8) : secondaryColor} bold={isBold} textured={isTextured}>Core Values</SectionLabel>
                                                <div className={isBold ? 'space-y-4' : isTextured ? 'space-y-3' : 'space-y-0'}>
                                                    {identity.values.map((v, i) => (
                                                        isTextured ? (
                                                            <div key={i} className="flex items-center gap-5 p-5 border border-white/10 bg-white/[0.03] backdrop-blur-sm">
                                                                <span
                                                                    className="flex-shrink-0 w-12 h-12 flex items-center justify-center text-lg font-bold text-white/90"
                                                                    style={{ backgroundColor: hexToRgba(primaryColor, 0.5) }}
                                                                >
                                                                    {String(i + 1).padStart(2, '0')}
                                                                </span>
                                                                <h3 className="text-xl md:text-2xl font-bold text-white/90 uppercase" style={{ letterSpacing: '0.05em' }}>{v}</h3>
                                                            </div>
                                                        ) : isBold ? (
                                                            <div key={i} className="flex items-center gap-5 p-5 border-2 border-gray-200 bg-white">
                                                                <span
                                                                    className="flex-shrink-0 w-12 h-12 flex items-center justify-center text-lg font-black text-white"
                                                                    style={{ backgroundColor: secondaryColor }}
                                                                >
                                                                    {String(i + 1).padStart(2, '0')}
                                                                </span>
                                                                <h3 className="text-xl md:text-2xl font-black text-gray-900 tracking-tight uppercase">{v}</h3>
                                                            </div>
                                                        ) : (
                                                            <div key={i} className="group py-6 border-b border-gray-100 last:border-b-0">
                                                                <div className="flex items-baseline gap-5">
                                                                    <span className="text-5xl md:text-6xl font-black leading-none" style={{ color: hexToRgba(secondaryColor, 0.15) }}>
                                                                        {String(i + 1).padStart(2, '0')}
                                                                    </span>
                                                                    <h3 className="text-2xl md:text-3xl font-bold text-gray-900 tracking-tight">{v}</h3>
                                                                </div>
                                                            </div>
                                                        )
                                                    ))}
                                                </div>
                                            </div>
                                        )}
                                        {hasBeliefs && (
                                            <div>
                                                <SectionLabel color={isTextured ? hexToRgba(primaryColor, 0.8) : secondaryColor} bold={isBold} textured={isTextured}>What We Believe</SectionLabel>
                                                <div className={isBold ? 'space-y-4' : isTextured ? 'space-y-3' : 'space-y-6'}>
                                                    {identity.beliefs.map((b, i) => (
                                                        isTextured ? (
                                                            <div key={i} className="p-5 border-l-2 bg-white/[0.03] backdrop-blur-sm" style={{ borderColor: hexToRgba(primaryColor, 0.6) }}>
                                                                <p className="text-lg text-white/80 leading-relaxed">{b}</p>
                                                            </div>
                                                        ) : isBold ? (
                                                            <div key={i} className="p-5 border-l-4 bg-white" style={{ borderColor: primaryColor }}>
                                                                <p className="text-lg text-gray-800 leading-relaxed font-medium">{b}</p>
                                                            </div>
                                                        ) : (
                                                            <div key={i} className="relative pl-6">
                                                                <div className="absolute left-0 top-2 w-2 h-2 rounded-full" style={{ backgroundColor: secondaryColor }} />
                                                                <p className="text-lg text-gray-700 leading-relaxed">{b}</p>
                                                            </div>
                                                        )
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
                            <section id="sec-voice" className="py-28 md:py-36 relative overflow-hidden" style={{ background: isTextured
                                ? secondaryDark
                                : isBold
                                    ? `linear-gradient(180deg, white 0%, ${hexToRgba(secondaryColor, 0.06)} 100%)`
                                    : `linear-gradient(180deg, ${hexToRgba(secondaryColor, 0.04)} 0%, ${hexToRgba(primaryColor, 0.02)} 100%)`
                            }}>
                                {isTextured && texBg(3) && (
                                    <>
                                        <img src={texBg(3)} alt="" className="absolute inset-0 w-full h-full object-cover opacity-20" style={{ mixBlendMode: 'multiply' }} />
                                        <div className="absolute inset-0 opacity-[0.05]" style={{ backgroundImage: `url("${grainSvg}")` }} />
                                    </>
                                )}
                                <div className="mx-auto max-w-6xl px-6 lg:px-8 relative">
                                    <div className="grid grid-cols-1 lg:grid-cols-12 gap-20">
                                        <div className="lg:col-span-7">
                                            <SectionLabel color={isTextured ? hexToRgba(secondaryColor, 0.7) : secondaryColor} bold={isBold} textured={isTextured}>Brand Voice</SectionLabel>
                                            {personality.voice_description && (
                                                <p className={`text-xl md:text-2xl leading-relaxed whitespace-pre-wrap ${isTextured ? 'text-white/85 font-light' : isBold ? 'text-gray-800 font-medium' : 'text-gray-800 font-light'}`}>
                                                    {personality.voice_description}
                                                </p>
                                            )}
                                        </div>
                                        {hasToneKeywords && (
                                            <div className="lg:col-span-5 flex flex-col justify-center">
                                                <span className={`text-xs font-semibold uppercase tracking-[0.2em] ${isTextured ? 'text-white/40 mb-5' : isBold ? 'text-gray-400 mb-4' : 'text-gray-400 mb-6'}`}>Tone of Voice</span>
                                                <div className={isBold ? 'space-y-2' : 'space-y-3'}>
                                                    {toneKeywords.map((kw, i) => (
                                                        isTextured ? (
                                                            <div
                                                                key={i}
                                                                className="flex items-center gap-4 px-6 py-4 border border-white/10 bg-white/[0.04] backdrop-blur-sm transition-all duration-200 hover:bg-white/[0.08]"
                                                            >
                                                                <div className="w-1 h-8" style={{ backgroundColor: hexToRgba(secondaryColor, 0.6) }} />
                                                                <span className="text-lg font-bold uppercase text-white/90" style={{ letterSpacing: '0.12em' }}>{kw}</span>
                                                            </div>
                                                        ) : isBold ? (
                                                            <div
                                                                key={i}
                                                                className="flex items-center gap-4 px-6 py-4 text-white transition-all duration-200 hover:scale-[1.01]"
                                                                style={{ backgroundColor: secondaryColor }}
                                                            >
                                                                <div className="w-1.5 h-8 bg-white/30" />
                                                                <span className="text-lg font-black uppercase tracking-widest">{kw}</span>
                                                            </div>
                                                        ) : (
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
                                                        )
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
                            {isTextured && texBg(4) && (
                                <img src={texBg(4)} alt="" className="absolute inset-0 w-full h-full object-cover opacity-25" style={{ mixBlendMode: 'screen', filter: 'saturate(0.4)' }} />
                            )}
                            <div className={`absolute inset-0 ${isTextured ? 'opacity-[0.06]' : 'opacity-[0.04]'}`} style={{ backgroundImage: isTextured ? `url("${grainSvg}")` : `radial-gradient(circle at 1px 1px, white 1px, transparent 0)`, backgroundSize: isTextured ? undefined : '40px 40px' }} />

                            <div className="relative mx-auto max-w-5xl px-6 lg:px-8 text-center">
                                {archetypeDisplay && (
                                    <>
                                        <span className={`text-xs font-semibold uppercase text-white/50 ${isTextured ? 'tracking-[0.4em]' : 'tracking-[0.3em]'}`}>Brand Archetype</span>
                                        <h2 className="mt-4 text-6xl md:text-8xl font-black leading-none" style={isTextured ? { letterSpacing: '0.06em', textShadow: '0 4px 30px rgba(0,0,0,0.4)' } : { letterSpacing: '-0.02em' }}>
                                            {archetypeDisplay}
                                        </h2>
                                    </>
                                )}
                                {personality.traits.length > 0 && (
                                    <div className="mt-14 flex flex-wrap justify-center gap-3">
                                        {personality.traits.map((t, i) => (
                                            <span
                                                key={i}
                                                className={`inline-flex items-center px-6 py-2.5 text-sm font-semibold tracking-wide ${isTextured ? 'font-bold uppercase border border-white/15 backdrop-blur-sm' : isBold ? 'font-black uppercase tracking-widest' : 'rounded-full'}`}
                                                style={isTextured
                                                    ? { backgroundColor: hexToRgba(primaryColor, 0.25), color: 'rgba(255,255,255,0.9)', letterSpacing: '0.12em' }
                                                    : isBold
                                                        ? { backgroundColor: hexToRgba(secondaryColor, 0.3), color: '#ffffff', border: `2px solid ${hexToRgba(secondaryColor, 0.5)}` }
                                                        : { backgroundColor: hexToRgba(secondaryColor, 0.15), color: lightenHex(secondaryColor, 0.4), border: `1px solid ${hexToRgba(secondaryColor, 0.25)}` }
                                                }
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
                            <section id="sec-visual" className={`py-28 md:py-36 relative overflow-hidden ${isTextured ? '' : 'bg-white'}`}
                                style={isTextured ? { background: `linear-gradient(180deg, ${darkenHex(primaryColor, 0.55)} 0%, ${primaryDeep} 100%)` } : {}}
                            >
                                {isTextured && texBg(5) && (
                                    <>
                                        <img src={texBg(5)} alt="" className="absolute inset-0 w-full h-full object-cover opacity-15" style={{ mixBlendMode: 'screen', filter: 'saturate(0.3)' }} />
                                        <div className="absolute inset-0 opacity-[0.04]" style={{ backgroundImage: `url("${grainSvg}")` }} />
                                    </>
                                )}
                                <div className="mx-auto max-w-6xl px-6 lg:px-8 relative">
                                    <SectionLabel color={isTextured ? hexToRgba(secondaryColor, 0.7) : secondaryColor} bold={isBold} textured={isTextured}>Visual Style</SectionLabel>

                                    <div className="grid grid-cols-1 lg:grid-cols-12 gap-16 mt-4">
                                        <div className="lg:col-span-7">
                                            {visual.photography_style && (
                                                <p className={`text-xl md:text-2xl leading-relaxed ${isTextured ? 'text-white/85 font-light' : isBold ? 'text-gray-800 font-medium' : 'text-gray-800 font-light'}`}>
                                                    {visual.photography_style}
                                                </p>
                                            )}
                                            {visual.visual_style && !visual.photography_style && (
                                                <p className={`text-xl md:text-2xl leading-relaxed ${isTextured ? 'text-white/85 font-light' : isBold ? 'text-gray-800 font-medium' : 'text-gray-800 font-light'}`}>
                                                    {visual.visual_style}
                                                </p>
                                            )}
                                            {visual.composition_style && (
                                                <p className={`mt-8 text-lg leading-relaxed ${isTextured ? 'text-white/60' : 'text-gray-600'}`}>{visual.composition_style}</p>
                                            )}
                                        </div>
                                        {scoringRules.photography_attributes.length > 0 && (
                                            <div className="lg:col-span-5 flex flex-col justify-center">
                                                <span className={`text-xs font-semibold uppercase tracking-[0.15em] mb-4 ${isTextured ? 'text-white/40' : 'text-gray-400'}`}>Attributes</span>
                                                <div className="flex flex-wrap gap-2">
                                                    {scoringRules.photography_attributes.map((a, i) => (
                                                        <span
                                                            key={i}
                                                            className={isTextured
                                                                ? 'inline-flex items-center px-4 py-2 text-sm font-bold text-white/90 uppercase border border-white/10 backdrop-blur-sm'
                                                                : isBold
                                                                    ? 'inline-flex items-center px-4 py-2 text-sm font-bold text-white uppercase tracking-wider'
                                                                    : 'inline-flex items-center rounded-lg px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 ring-1 ring-gray-200/80'
                                                            }
                                                            style={isTextured
                                                                ? { backgroundColor: hexToRgba(primaryColor, 0.3), letterSpacing: '0.08em' }
                                                                : isBold ? { backgroundColor: primaryColor } : {}
                                                            }
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
                            <section id="sec-photography" className="py-28 md:py-36 relative overflow-hidden" style={{
                                background: isTextured
                                    ? `linear-gradient(180deg, ${primaryDeep} 0%, ${darkenHex(secondaryColor, 0.6)} 100%)`
                                    : `linear-gradient(180deg, ${hexToRgba(primaryColor, 0.03)} 0%, white 40%, ${hexToRgba(secondaryColor, 0.03)} 100%)`
                            }}>
                                {isTextured && (
                                    <div className="absolute inset-0 opacity-[0.04]" style={{ backgroundImage: `url("${grainSvg}")` }} />
                                )}
                                <div className="mx-auto max-w-7xl px-6 lg:px-8 relative">
                                    <SectionLabel color={isTextured ? hexToRgba(secondaryColor, 0.7) : secondaryColor} bold={isBold} textured={isTextured}>Visual References</SectionLabel>
                                    <h2 className={`text-3xl md:text-4xl font-bold mt-2 mb-4 ${isTextured ? 'text-white' : 'text-gray-900'}`}>Photography &amp; Visual Language</h2>
                                    <p className={`text-lg max-w-2xl mb-12 ${isTextured ? 'text-white/50' : 'text-gray-500'}`}>
                                        {visual.photography_style || 'Reference imagery that defines the visual direction of the brand.'}
                                    </p>

                                    {photographyRefs.length > 0 && (
                                        <div className="mb-16">
                                            <span className={`text-xs font-semibold uppercase tracking-[0.15em] mb-6 block ${isTextured ? 'text-white/40' : 'text-gray-400'}`}>Photography</span>
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
                                            <span className={`text-xs font-semibold uppercase tracking-[0.15em] mb-6 block ${isTextured ? 'text-white/40' : 'text-gray-400'}`}>Graphics</span>
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
                        <section id="sec-colors" className={`py-24 relative overflow-hidden ${isTextured ? '' : 'bg-white'}`}
                            style={isTextured ? { background: `linear-gradient(180deg, ${primaryDark} 0%, ${primaryDeep} 100%)` } : {}}
                        >
                            {isTextured && (
                                <div className="absolute inset-0 opacity-[0.04]" style={{ backgroundImage: `url("${grainSvg}")` }} />
                            )}
                            <div className="mx-auto max-w-6xl px-6 lg:px-8 relative">
                                <SectionLabel color={isTextured ? hexToRgba(secondaryColor, 0.7) : secondaryColor} bold={isBold} textured={isTextured}>Color System</SectionLabel>
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
                                            className={`group relative min-h-[180px] transition-all duration-200 hover:shadow-xl hover:scale-[1.02] focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-400 ${isTextured ? 'rounded-none border border-white/10' : isBold ? 'rounded-none' : 'rounded-2xl'}`}
                                            style={{ backgroundColor: color }}
                                        >
                                            <span className={`absolute bottom-3 left-3 font-mono text-xs font-medium px-2 py-1 text-white backdrop-blur-sm ${isTextured ? 'bg-black/30 font-bold' : isBold ? 'bg-black/40 font-bold' : 'bg-black/20 rounded'}`}>
                                                {color}
                                            </span>
                                            <span className={`absolute top-3 left-3 text-xs font-semibold uppercase text-white/70 ${isTextured ? 'tracking-[0.2em] font-bold' : isBold ? 'tracking-widest font-black' : 'tracking-wider'}`}>
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
                                        <p className={`text-sm font-medium uppercase tracking-wider mb-4 ${isTextured ? 'text-white/40' : 'text-gray-500'}`}>Extended Palette</p>
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
                                                        className={`w-20 h-20 transition-all duration-200 hover:shadow-lg hover:scale-105 flex flex-col items-center justify-end pb-1.5 gap-0.5 ${isTextured ? 'rounded-none border border-white/10' : 'rounded-xl'}`}
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
                            <section id="sec-typography" className="py-24 md:py-32 relative overflow-hidden" style={{ background: isTextured
                                ? `linear-gradient(180deg, ${darkenHex(secondaryColor, 0.55)} 0%, ${primaryDeep} 100%)`
                                : isBold
                                    ? `linear-gradient(180deg, ${hexToRgba(primaryColor, 0.07)} 0%, white 100%)`
                                    : `linear-gradient(180deg, ${hexToRgba(primaryColor, 0.04)} 0%, white 100%)`
                            }}>
                                {isTextured && texBg(6) && (
                                    <>
                                        <img src={texBg(6)} alt="" className="absolute inset-0 w-full h-full object-cover opacity-10" style={{ mixBlendMode: 'screen', filter: 'saturate(0.2)' }} />
                                        <div className="absolute inset-0 opacity-[0.04]" style={{ backgroundImage: `url("${grainSvg}")` }} />
                                    </>
                                )}
                                <div className="mx-auto max-w-6xl px-6 lg:px-8 relative">
                                    <SectionLabel color={isTextured ? hexToRgba(secondaryColor, 0.7) : secondaryColor} bold={isBold} textured={isTextured}>Typography</SectionLabel>

                                    {typography.fonts.length > 0 ? (
                                        <div className="space-y-16 mt-4">
                                            {typography.fonts.map((font, i) => {
                                                const name = typeof font === 'string' ? font : (font?.name || 'Unknown')
                                                const role = typeof font === 'string' ? null : font?.role
                                                const styles = typeof font === 'string' ? [] : (font?.styles || [])
                                                const usageNotes = typeof font === 'string' ? null : font?.usage_notes
                                                return (
                                                    <div key={i} className={`grid grid-cols-1 lg:grid-cols-12 gap-8 items-start ${isTextured && i > 0 ? 'pt-16 border-t border-white/10' : ''}`}>
                                                        <div className="lg:col-span-8">
                                                            <p
                                                                className={`text-4xl md:text-5xl font-bold leading-tight ${isTextured ? 'text-white/90' : 'text-gray-900'}`}
                                                                style={{ fontFamily: `"${name}", system-ui, sans-serif` }}
                                                            >
                                                                {i === 0 ? 'The quick brown fox jumps over the lazy dog.' : 'Pack my box with five dozen liquor jugs.'}
                                                            </p>
                                                        </div>
                                                        <div className="lg:col-span-4 space-y-2">
                                                            <p className={`text-xl font-bold ${isTextured ? 'text-white/90' : 'text-gray-900'}`}>{name}</p>
                                                            {role && <p className={`text-sm uppercase tracking-wider font-medium ${isTextured ? 'text-white/40' : 'text-gray-500'}`}>{role}</p>}
                                                            {styles.length > 0 && <p className={`text-sm ${isTextured ? 'text-white/40' : 'text-gray-500'}`}>{styles.join(' · ')}</p>}
                                                            {usageNotes && <p className={`text-sm mt-2 leading-relaxed ${isTextured ? 'text-white/60' : 'text-gray-600'}`}>{usageNotes}</p>}
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
                                                        <p className={`text-4xl md:text-5xl font-bold ${isTextured ? 'text-white/90' : 'text-gray-900'}`} style={{ fontFamily: `"${typography.primary_font}", system-ui, sans-serif` }}>
                                                            The quick brown fox jumps over the lazy dog.
                                                        </p>
                                                    </div>
                                                    <div className="lg:col-span-4">
                                                        <p className={`text-xl font-bold ${isTextured ? 'text-white/90' : 'text-gray-900'}`}>{typography.primary_font}</p>
                                                        <p className={`text-sm uppercase tracking-wider font-medium ${isTextured ? 'text-white/40' : 'text-gray-500'}`}>Primary</p>
                                                    </div>
                                                </div>
                                            )}
                                            {typography.secondary_font && (
                                                <div className="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">
                                                    <div className="lg:col-span-8">
                                                        <p className={`text-lg md:text-xl leading-relaxed ${isTextured ? 'text-white/70' : 'text-gray-700'}`} style={{ fontFamily: `"${typography.secondary_font}", system-ui, sans-serif` }}>
                                                            Use this font for body copy, captions, and supporting text. It should feel readable and on-brand across all applications.
                                                        </p>
                                                    </div>
                                                    <div className="lg:col-span-4">
                                                        <p className={`text-xl font-bold ${isTextured ? 'text-white/90' : 'text-gray-900'}`}>{typography.secondary_font}</p>
                                                        <p className={`text-sm uppercase tracking-wider font-medium ${isTextured ? 'text-white/40' : 'text-gray-500'}`}>Secondary</p>
                                                    </div>
                                                </div>
                                            )}
                                        </div>
                                    )}
                                    {(typography.heading_style || typography.body_style) && (
                                        <div className={`mt-16 pt-10 grid grid-cols-1 md:grid-cols-2 gap-8 ${isTextured ? 'border-t border-white/10' : 'border-t border-gray-200/60'}`}>
                                            {typography.heading_style && (
                                                <div>
                                                    <span className={`text-xs font-semibold uppercase tracking-wider ${isTextured ? 'text-white/40' : 'text-gray-400'}`}>Heading Style</span>
                                                    <p className={`mt-2 text-base ${isTextured ? 'text-white/70' : 'text-gray-700'}`}>{typography.heading_style}</p>
                                                </div>
                                            )}
                                            {typography.body_style && (
                                                <div>
                                                    <span className={`text-xs font-semibold uppercase tracking-wider ${isTextured ? 'text-white/40' : 'text-gray-400'}`}>Body Style</span>
                                                    <p className={`mt-2 text-base ${isTextured ? 'text-white/70' : 'text-gray-700'}`}>{typography.body_style}</p>
                                                </div>
                                            )}
                                        </div>
                                    )}
                                </div>
                            </section>
                        )}

                        {/* ═══ 9. BRAND IDENTITY / LOGO (Builder Step 6: Standards) ═══ */}
                        {logoUrl && (
                            <section id="sec-logo" className={`py-28 md:py-36 relative overflow-hidden ${isTextured ? '' : 'bg-white'}`}
                                style={isTextured ? { background: `linear-gradient(180deg, ${primaryDark} 0%, ${primaryDeep} 100%)` } : {}}
                            >
                                {isTextured && texBg(7) && (
                                    <>
                                        <img src={texBg(7)} alt="" className="absolute inset-0 w-full h-full object-cover opacity-10" style={{ mixBlendMode: 'screen', filter: 'saturate(0.2)' }} />
                                        <div className="absolute inset-0 opacity-[0.04]" style={{ backgroundImage: `url("${grainSvg}")` }} />
                                    </>
                                )}
                                <div className="mx-auto max-w-6xl px-6 lg:px-8 relative">
                                    <SectionLabel color={isTextured ? hexToRgba(secondaryColor, 0.7) : secondaryColor} bold={isBold} textured={isTextured}>Brand Identity</SectionLabel>

                                    <div className="grid grid-cols-1 lg:grid-cols-12 gap-12 lg:gap-20 mt-4">
                                        <div className="lg:col-span-7">
                                            <div
                                                className={`relative p-12 md:p-16 flex items-center justify-center min-h-[320px] md:min-h-[400px] ${isTextured ? 'rounded-none border border-white/10' : isBold ? 'rounded-none' : 'rounded-2xl'}`}
                                                style={{ backgroundColor: primaryColor }}
                                            >
                                                <img src={logoUrl} alt={`${brand.name} — Primary Brandmark`} className="max-h-32 md:max-h-44 w-auto object-contain drop-shadow-lg" />
                                                <span className={`absolute bottom-4 left-5 text-[10px] font-semibold uppercase text-white/40 ${isTextured ? 'tracking-[0.2em]' : isBold ? 'tracking-widest font-black' : 'tracking-[0.15em]'}`}>Primary Brandmark</span>
                                            </div>
                                        </div>
                                        <div className="lg:col-span-5 grid grid-rows-2 gap-6">
                                            <div className={`relative p-8 flex items-center justify-center ${isTextured ? 'rounded-none border border-white/10' : isBold ? 'rounded-none border-2 border-gray-300' : 'rounded-2xl'}`} style={isTextured ? { backgroundColor: 'rgba(255,255,255,0.05)' } : isBold ? { backgroundColor: '#ffffff' } : { backgroundColor: '#ffffff', border: '1px solid #e5e7eb' }}>
                                                <img
                                                    src={logoUrl}
                                                    alt={`${brand.name} — On White`}
                                                    className="max-h-16 md:max-h-20 w-auto object-contain"
                                                    style={logoIsTransparent ? {} : { filter: 'brightness(0.2)' }}
                                                />
                                                <span className={`absolute bottom-3 left-4 text-[10px] font-semibold uppercase ${isTextured ? 'text-white/30 tracking-[0.2em]' : isBold ? 'text-gray-300 tracking-widest' : 'text-gray-300 tracking-[0.15em]'}`}>On Light Background</span>
                                            </div>
                                            <div className={`relative p-8 flex items-center justify-center ${isTextured ? 'rounded-none border border-white/10' : isBold ? 'rounded-none' : 'rounded-2xl'}`} style={{ backgroundColor: secondaryColor }}>
                                                <img
                                                    src={logoDarkUrl || logoUrl}
                                                    alt={`${brand.name} — Reversed`}
                                                    className={`max-h-16 md:max-h-20 w-auto object-contain${!logoDarkUrl && logoIsTransparent ? '' : !logoDarkUrl ? ' brightness-0 invert' : ''}`}
                                                />
                                                <span className={`absolute bottom-3 left-4 text-[10px] font-semibold uppercase text-white/40 ${isBold ? 'tracking-widest' : 'tracking-[0.15em]'}`}>Reversed / On Color</span>
                                            </div>
                                        </div>
                                    </div>

                                    <div className="mt-8 grid grid-cols-2 md:grid-cols-4 gap-6">
                                        <div className={`relative p-6 flex items-center justify-center min-h-[120px] ${isTextured ? 'rounded-none border border-white/10' : isBold ? 'rounded-none' : 'rounded-xl'}`} style={{ backgroundColor: accentColor }}>
                                            <img
                                                src={logoUrl}
                                                alt={`${brand.name} — On Accent`}
                                                className="max-h-12 w-auto object-contain"
                                                style={logoIsTransparent ? {} : { filter: 'brightness(0) saturate(100%) invert(8%) sepia(50%) saturate(4000%) hue-rotate(180deg)' }}
                                            />
                                            <span className={`absolute bottom-2 left-3 text-[9px] font-semibold uppercase text-black/25 ${isTextured ? 'tracking-[0.15em]' : 'tracking-wider'}`}>On Accent</span>
                                        </div>
                                        <div className={`relative p-6 flex items-center justify-center min-h-[120px] ${isTextured ? 'rounded-none border border-white/10' : isBold ? 'rounded-none' : 'rounded-xl'}`} style={{ backgroundColor: '#111827' }}>
                                            <img
                                                src={logoDarkUrl || logoUrl}
                                                alt={`${brand.name} — On Dark`}
                                                className={`max-h-12 w-auto object-contain${!logoDarkUrl && logoIsTransparent ? '' : !logoDarkUrl ? ' brightness-0 invert' : ''}`}
                                            />
                                            <span className={`absolute bottom-2 left-3 text-[9px] font-semibold uppercase text-white/30 ${isTextured ? 'tracking-[0.15em]' : 'tracking-wider'}`}>On Dark</span>
                                        </div>
                                        <div className={`relative p-6 flex items-center justify-center min-h-[120px] ${isTextured ? 'rounded-none border border-white/10' : isBold ? 'rounded-none' : 'rounded-xl'}`} style={{ backgroundColor: primaryColor, opacity: 0.7 }}>
                                            <img src={logoUrl} alt={`${brand.name} — Reduced`} className="max-h-12 w-auto object-contain" />
                                            <span className={`absolute bottom-2 left-3 text-[9px] font-semibold uppercase text-white/30 ${isTextured ? 'tracking-[0.15em]' : 'tracking-wider'}`}>Reduced Opacity</span>
                                        </div>
                                        <div className={`relative p-6 flex items-center justify-center min-h-[120px] ${isTextured ? 'rounded-none border border-white/10 bg-white/[0.04]' : isBold ? 'rounded-none border-2 border-gray-300 bg-gray-50' : 'rounded-xl bg-gradient-to-br from-gray-50 to-gray-100 border border-gray-200'}`}>
                                            <img src={logoUrl} alt={`${brand.name} — Minimum Size`} className="max-h-6 w-auto object-contain" />
                                            <span className={`absolute bottom-2 left-3 text-[9px] font-semibold uppercase ${isTextured ? 'text-white/30 tracking-[0.15em]' : 'text-gray-300 tracking-wider'}`}>Minimum Size</span>
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
                                        className={`overflow-hidden transition-all duration-200 hover:scale-[1.01] ${isTextured || isBold ? 'rounded-none' : 'rounded-lg'}`}
                                        style={{
                                            backgroundColor: isDont ? 'rgba(255,255,255,0.04)' : 'rgba(255,255,255,0.07)',
                                            border: isBold
                                                ? `2px solid ${isDont ? 'rgba(255,100,100,0.25)' : 'rgba(255,255,255,0.15)'}`
                                                : `1px solid ${isDont ? 'rgba(255,100,100,0.12)' : 'rgba(255,255,255,0.08)'}`,
                                        }}
                                    >
                                        {treatment && (
                                            <div className="relative">
                                                {treatment(logoUrl, brandColors, isTransparent, guidelineMeta)}
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
                                {isTextured && texBg(0) && (
                                    <img src={texBg(0)} alt="" className="absolute inset-0 w-full h-full object-cover opacity-10" style={{ mixBlendMode: 'screen', filter: 'saturate(0.15)' }} />
                                )}
                                <div className={`absolute inset-0 ${isTextured ? 'opacity-[0.05]' : 'opacity-[0.03]'}`} style={{ backgroundImage: isTextured ? `url("${grainSvg}")` : `radial-gradient(circle at 2px 2px, white 1px, transparent 0)`, backgroundSize: isTextured ? undefined : '30px 30px' }} />

                                <div className="relative mx-auto max-w-6xl px-6 lg:px-8">
                                    <SectionLabel color={isTextured ? hexToRgba(secondaryColor, 0.7) : isBold ? secondaryColor : hexToRgba(secondaryColor, 0.7)} bold={isBold} textured={isTextured}>Logo Standards</SectionLabel>

                                    {loadingAnalysis && logoUrl && (
                                        <p className="text-center text-[10px] text-white/35 mb-4">Checking logo contrast on white…</p>
                                    )}
                                    {showRiskBanner && logoUrl && (
                                        <div className="mb-6 rounded-xl border border-amber-400/30 bg-amber-500/[0.08] px-4 py-3 max-w-2xl mx-auto text-left">
                                            <p className="text-xs font-medium text-amber-100/90">Light areas in this logo don’t read on pure white.</p>
                                            <p className="text-[11px] text-amber-100/60 mt-1">
                                                Examples below use a subtle edge or your <strong className="text-amber-50/90">on-light</strong> variant when available. Add an on-light logo in Brand DNA so light backgrounds always show the right mark.
                                            </p>
                                        </div>
                                    )}

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
                            className="py-16 relative overflow-hidden"
                            style={{
                                background: isTextured
                                    ? primaryDeep
                                    : `linear-gradient(180deg, white 0%, ${hexToRgba(primaryColor, 0.06)} 100%)`,
                            }}
                        >
                            {isTextured && (
                                <div className="absolute inset-0 opacity-[0.03]" style={{ backgroundImage: `url("${grainSvg}")` }} />
                            )}
                            <div className="mx-auto max-w-6xl px-6 lg:px-8 relative">
                                <div className={`flex items-center justify-between pt-8 ${isTextured ? 'border-t border-white/10' : 'border-t border-gray-200'}`}>
                                    <div className="flex items-center gap-3">
                                        {logoUrl && <img src={logoUrl} alt={brand.name} className={`h-8 w-auto object-contain ${isTextured ? 'opacity-70' : 'opacity-60'}`} />}
                                        <span className={`text-sm font-medium ${isTextured ? 'text-white/40' : 'text-gray-400'}`}>{brand.name}</span>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <div className={`w-2 h-2 ${isTextured ? '' : 'rounded-full'}`} style={{ backgroundColor: primaryColor }} />
                                        <div className={`w-2 h-2 ${isTextured ? '' : 'rounded-full'}`} style={{ backgroundColor: secondaryColor }} />
                                        <div className={`w-2 h-2 ${isTextured ? '' : 'rounded-full'}`} style={{ backgroundColor: accentColor }} />
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
