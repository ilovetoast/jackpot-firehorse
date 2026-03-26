import { useState, useCallback, useEffect, useRef, useId } from 'react'
import { motion, AnimatePresence } from 'framer-motion'

/** Coherence section score (0–100) at or above this → treat extracted snapshot archetype as a default selection */
const ARCHETYPE_SECTION_SCORE_MIN = 80

/** Clockwise from top — matches classic 12-archetype wheel (structure → journey → connection → mark) */
const ARCHETYPE_WHEEL_ORDER = [
    'Creator', 'Ruler', 'Caregiver', 'Everyman', 'Jester', 'Lover',
    'Hero', 'Magician', 'Outlaw', 'Explorer', 'Sage', 'Innocent',
]

const ARCHETYPES = [
    { id: 'Creator', desc: 'Innovation, imagination, self-expression', essence: 'Bring new things into being', accent: '#a78bfa', symbol: '✦' },
    { id: 'Caregiver', desc: 'Compassion, nurturing, protection', essence: 'Care for others selflessly', accent: '#5eead4', symbol: '♡' },
    { id: 'Ruler', desc: 'Leadership, control, responsibility', essence: 'Create order from chaos', accent: '#fbbf24', symbol: '♛' },
    { id: 'Jester', desc: 'Joy, humor, playfulness', essence: 'Live in the moment with joy', accent: '#fb923c', symbol: '✿' },
    { id: 'Everyman', desc: 'Belonging, realism, connection', essence: 'Connect through shared experience', accent: '#7dd3fc', symbol: '○' },
    { id: 'Lover', desc: 'Passion, intimacy, appreciation', essence: 'Find and give love', accent: '#f472b6', symbol: '❋' },
    { id: 'Hero', desc: 'Courage, mastery, triumph', essence: 'Prove worth through courageous action', accent: '#ef4444', symbol: '⚡' },
    { id: 'Outlaw', desc: 'Rebellion, liberation, disruption', essence: 'Break the rules that don\'t work', accent: '#dc2626', symbol: '✕' },
    { id: 'Magician', desc: 'Transformation, vision, catalyst', essence: 'Make the impossible possible', accent: '#818cf8', symbol: '◆' },
    { id: 'Innocent', desc: 'Purity, optimism, simplicity', essence: 'Keep it simple and true', accent: '#bae6fd', symbol: '○' },
    { id: 'Sage', desc: 'Wisdom, truth, clarity', essence: 'Use intelligence to understand the world', accent: '#60a5fa', symbol: '◈' },
    { id: 'Explorer', desc: 'Freedom, discovery, authenticity', essence: 'Find fulfillment through discovery', accent: '#34d399', symbol: '↗' },
]

function unwrapField(val) {
    if (val == null) return null
    if (typeof val === 'object' && !Array.isArray(val) && 'value' in val) return val.value
    return val
}

/**
 * Read archetype string from research snapshot (flat PDF/URL extraction + evidence map).
 */
function extractArchetypeFromResearchSnapshot(snapshot) {
    if (!snapshot || typeof snapshot !== 'object') return null
    const ev = snapshot.evidence_map?.['personality.primary_archetype']
    const finalVal = ev?.final_value
    const candidates = [
        snapshot.primary_archetype,
        snapshot.personality?.primary_archetype,
        finalVal,
    ]
    for (const raw of candidates) {
        if (raw == null) continue
        const u = unwrapField(raw)
        if (u) return String(u).trim()
    }
    return null
}

function normalizeArchetypeId(raw) {
    if (!raw) return null
    return ARCHETYPES.find(
        (a) => a.id === raw || a.id.toLowerCase() === String(raw).toLowerCase(),
    )?.id ?? null
}

/** Ambient halo behind the wheel — uses brand accent from Builder when provided */
function wheelAmbientBackground(accentColor) {
    if (!accentColor || typeof accentColor !== 'string') {
        return 'radial-gradient(ellipse at 50% 45%, rgba(99, 102, 241, 0.14), transparent 55%)'
    }
    const h = accentColor.trim()
    const hex = h.length === 4 && h.startsWith('#')
        ? `#${h[1]}${h[1]}${h[2]}${h[2]}${h[3]}${h[3]}`
        : h
    if (!/^#[0-9a-fA-F]{6}$/.test(hex)) {
        return 'radial-gradient(ellipse at 50% 45%, rgba(99, 102, 241, 0.14), transparent 55%)'
    }
    const r = parseInt(hex.slice(1, 3), 16)
    const g = parseInt(hex.slice(3, 5), 16)
    const b = parseInt(hex.slice(5, 7), 16)
    return `radial-gradient(ellipse at 50% 45%, rgba(${r},${g},${b},0.14), transparent 55%)`
}

/** RGB from any #hex (brand accent or archetype accent on the wheel) */
function accentRgbComponents(accentColor) {
    if (!accentColor || typeof accentColor !== 'string') {
        return { r: 6, g: 182, b: 212 }
    }
    const h = accentColor.trim()
    const hex = h.length === 4 && h.startsWith('#')
        ? `#${h[1]}${h[1]}${h[2]}${h[2]}${h[3]}${h[3]}`
        : h
    if (!/^#[0-9a-fA-F]{6}$/.test(hex)) {
        return { r: 6, g: 182, b: 212 }
    }
    return {
        r: parseInt(hex.slice(1, 3), 16),
        g: parseInt(hex.slice(3, 5), 16),
        b: parseInt(hex.slice(5, 7), 16),
    }
}

/**
 * Monochrome SVG glyphs for wheel segments — avoids Unicode / emoji that render with OS colors.
 * 16×16 viewBox, centered at origin via translate(-8,-8) by caller.
 */
function ArchetypeWheelGlyph({ archetypeId, stroke }) {
    const p = {
        fill: 'none',
        stroke,
        strokeWidth: 1.15,
        strokeLinecap: 'round',
        strokeLinejoin: 'round',
        vectorEffect: 'non-scaling-stroke',
    }
    const T = 'translate(-8 -8)'
    switch (archetypeId) {
        case 'Innocent':
            return <g transform={T}><circle cx="8" cy="8" r="4.5" {...p} /></g>
        case 'Creator':
            return (
                <g transform={T}>
                    <path
                        d="M8 2.2 L9.35 6.1 13.2 6.1 10.1 8.65 11.45 12.55 8 10.15 4.55 12.55 5.9 8.65 2.8 6.1 6.65 6.1 Z"
                        {...p}
                    />
                </g>
            )
        case 'Ruler':
            return (
                <g transform={T}>
                    <path d="M3.5 11 L4.5 6 L6.5 7.5 L8 5 L9.5 7.5 L11.5 6 L12.5 11 Z" {...p} />
                    <path d="M4 11h8" {...p} />
                </g>
            )
        case 'Caregiver':
        case 'Lover':
            return (
                <g transform={T}>
                    <path d="M8 12.5 C5 10 3.5 8 3.5 6.2 A2.2 2.2 0 0 1 8 5.2 A2.2 2.2 0 0 1 12.5 6.2 C12.5 8 11 10 8 12.5 Z" {...p} />
                </g>
            )
        case 'Everyman':
            return <g transform={T}><circle cx="8" cy="8" r="3.8" {...p} /></g>
        case 'Jester':
            return (
                <g transform={T}>
                    <circle cx="5.5" cy="6" r="0.9" fill={stroke} stroke="none" />
                    <circle cx="10.5" cy="6" r="0.9" fill={stroke} stroke="none" />
                    <path d="M5 10 Q8 12.5 11 10" {...p} />
                </g>
            )
        case 'Hero':
            return (
                <g transform={T}>
                    <path d="M9 2 L6 10h2.8l-1.4 6.5L12.5 6.5H9.8L11 2H9z" {...p} />
                </g>
            )
        case 'Outlaw':
            return (
                <g transform={T}>
                    <path d="M3.5 3.5 L12.5 12.5 M12.5 3.5 L3.5 12.5" {...p} />
                </g>
            )
        case 'Magician':
            return <g transform={T}><path d="M8 2.5 L13.5 8 L8 13.5 L2.5 8 Z" {...p} /></g>
        case 'Sage':
            return (
                <g transform={T}>
                    <path d="M8 2.5 L13.5 8 L8 13.5 L2.5 8 Z" {...p} />
                    <circle cx="8" cy="8" r="1.8" {...p} />
                </g>
            )
        case 'Explorer':
            return (
                <g transform={T}>
                    <path d="M4 12 L12 4" {...p} />
                    <path d="M12 4 H8 M12 4 V8" {...p} />
                </g>
            )
        default:
            return <g transform={T}><circle cx="8" cy="8" r="4" {...p} /></g>
    }
}

function ArchetypeCard({ archetype, selected = false, isPrimary = false, elevated = false, compact = false, onClick, disabled = false, showActions, onApply, onReject, preview = false }) {
    const isPreview = Boolean(preview)
    return (
        <motion.button
            type="button"
            onClick={isPreview ? undefined : onClick}
            disabled={(disabled && !selected) || isPreview}
            layout={!elevated}
            layoutId={elevated ? undefined : `archetype-${archetype.id}`}
            whileHover={!isPreview && (!disabled || selected) ? { y: -2 } : {}}
            whileTap={!isPreview && (!disabled || selected) ? { scale: 0.98 } : {}}
            className={`relative rounded-2xl border text-left transition-all overflow-hidden flex flex-col w-full ${
                compact ? 'p-4 min-h-[120px]' : elevated ? 'p-8 min-h-[200px]' : 'p-5 min-h-[160px]'
            } ${
                isPreview
                    ? 'border-violet-400/25 bg-violet-950/[0.15] cursor-default'
                    : selected
                        ? 'border-white/25 bg-white/[0.06]'
                        : disabled
                            ? 'border-white/[0.05] bg-white/[0.015] opacity-40 cursor-not-allowed'
                            : 'border-white/[0.08] bg-white/[0.02] hover:border-white/15 hover:bg-white/[0.04] cursor-pointer'
            }`}
        >
            {/* Subtle accent glow — selected or preview */}
            {(selected || isPreview) && (
                <div
                    className="absolute inset-0 opacity-[0.08]"
                    style={{ background: `radial-gradient(ellipse at 30% 20%, ${archetype.accent}, transparent 70%)` }}
                />
            )}

            <div className="relative z-10 flex flex-col flex-1">
                <div className={`flex items-center gap-3 ${compact ? 'mb-2' : 'mb-3'}`}>
                    <span
                        className={`${elevated ? 'text-3xl' : compact ? 'text-lg' : 'text-xl'} transition-colors`}
                        style={{ color: selected || isPreview ? archetype.accent : 'rgba(255,255,255,0.25)' }}
                    >
                        {archetype.symbol}
                    </span>
                    {isPreview && (
                        <motion.span
                            initial={{ opacity: 0, y: -4 }}
                            animate={{ opacity: 1, y: 0 }}
                            className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-medium tracking-wide uppercase border border-violet-400/30 text-violet-200/90 bg-violet-500/10"
                        >
                            Preview
                        </motion.span>
                    )}
                    {selected && isPrimary && !isPreview && (
                        <motion.span
                            initial={{ opacity: 0, scale: 0.8 }}
                            animate={{ opacity: 1, scale: 1 }}
                            className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-medium tracking-wide uppercase"
                            style={{ backgroundColor: archetype.accent + '20', color: archetype.accent }}
                        >
                            <svg className="w-2.5 h-2.5" fill="currentColor" viewBox="0 0 20 20"><path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" /></svg>
                            Primary
                        </motion.span>
                    )}
                </div>

                <h3 className={`font-semibold text-white ${elevated ? 'text-2xl' : compact ? 'text-base' : 'text-lg'} ${!selected && !isPreview ? 'text-white/80' : ''}`}>
                    {archetype.id}
                </h3>

                <p className={`mt-1 ${elevated ? 'text-sm' : 'text-xs'} ${selected || isPreview ? 'text-white/50' : 'text-white/30'}`}>
                    {archetype.essence}
                </p>

                {!compact && (
                    <p className={`mt-1.5 ${elevated ? 'text-sm' : 'text-xs'} ${selected || isPreview ? 'text-white/40' : 'text-white/20'}`}>
                        {archetype.desc}
                    </p>
                )}

                {showActions && !selected && (
                    <div className="mt-auto pt-3 flex gap-2" onClick={(e) => e.stopPropagation()}>
                        <button
                            type="button"
                            onClick={(e) => { e.stopPropagation(); onApply?.() }}
                            className="px-3 py-1.5 rounded-lg text-xs font-medium bg-white/[0.06] text-white/60 hover:bg-white/10 hover:text-white/80 border border-white/[0.08] transition"
                        >
                            This fits
                        </button>
                        <button
                            type="button"
                            onClick={(e) => { e.stopPropagation(); onReject?.() }}
                            className="px-3 py-1.5 rounded-lg text-xs font-medium text-white/25 hover:text-white/50 hover:bg-white/[0.04] transition"
                        >
                            Not us
                        </button>
                    </div>
                )}
            </div>
        </motion.button>
    )
}

function ArchetypeHero({ archetype, reasoning, onAccept, onReject }) {
    const a = ARCHETYPES.find((x) => x.id === archetype)
    if (!a) return null

    return (
        <motion.div
            initial={{ opacity: 0, y: 20 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.6, ease: 'easeOut' }}
            className="space-y-8"
        >
            <div className="text-center space-y-2">
                <p className="text-sm font-medium tracking-widest uppercase text-white/30">Recommended Archetype</p>
                <h2 className="text-2xl sm:text-3xl font-bold text-white">This is how your brand shows up in the world</h2>
            </div>

            <div className="max-w-xl mx-auto">
                <ArchetypeCard archetype={a} elevated selected isPrimary />
            </div>

            {reasoning && (
                <motion.div
                    initial={{ opacity: 0 }}
                    animate={{ opacity: 1 }}
                    transition={{ delay: 0.3 }}
                    className="max-w-xl mx-auto"
                >
                    <div className="rounded-xl bg-white/[0.03] border border-white/[0.06] p-5">
                        <p className="text-[10px] font-medium text-white/30 uppercase tracking-wider mb-2">Why this fits</p>
                        <p className="text-white/50 text-sm leading-relaxed">{reasoning}</p>
                    </div>
                </motion.div>
            )}

            <motion.div
                initial={{ opacity: 0, y: 10 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ delay: 0.4 }}
                className="flex flex-col sm:flex-row items-center justify-center gap-4"
            >
                <button
                    type="button"
                    onClick={onAccept}
                    className="px-8 py-3 rounded-xl font-semibold text-black text-base transition-all hover:scale-[1.02] active:scale-[0.97]"
                    style={{ backgroundColor: a.accent }}
                >
                    Use This Archetype
                </button>
                <button
                    type="button"
                    onClick={onReject}
                    className="px-6 py-3 rounded-xl font-medium text-white/40 hover:text-white/70 transition-colors"
                >
                    Not quite right
                </button>
            </motion.div>
        </motion.div>
    )
}

/** Donut wedge in local coords; 0° = +x axis, angles sweep clockwise in SVG */
function donutWedgePath(rIn, rOut, deg0, deg1) {
    const rad = Math.PI / 180
    const a0 = deg0 * rad
    const a1 = deg1 * rad
    const xo0 = rOut * Math.cos(a0)
    const yo0 = rOut * Math.sin(a0)
    const xo1 = rOut * Math.cos(a1)
    const yo1 = rOut * Math.sin(a1)
    const xi0 = rIn * Math.cos(a0)
    const yi0 = rIn * Math.sin(a0)
    const xi1 = rIn * Math.cos(a1)
    const yi1 = rIn * Math.sin(a1)
    const large = 0
    return `M ${xo0} ${yo0} A ${rOut} ${rOut} 0 ${large} 1 ${xo1} ${yo1} L ${xi1} ${yi1} A ${rIn} ${rIn} 0 ${large} 0 ${xi0} ${yi0} Z`
}

/** Center of segment wedge in global coords (mid-angle). */
function globalSegmentPolar(i, r) {
    const globalMidDeg = -90 + i * 30 + 15
    const rad = (globalMidDeg * Math.PI) / 180
    const x = r * Math.cos(rad)
    const y = r * Math.sin(rad)
    return { x, y, globalMidDeg }
}

/**
 * Circular 12-segment selector — symbol + name only on the ring; copy on hover below.
 */
function ArchetypeWheel({
    selected,
    onSelect,
    maxSelections = 1,
    recommendedId = null,
    accentColor = null,
    onHoverChange,
}) {
    const [hoveredId, setHoveredId] = useState(null)
    const filterUid = useId().replace(/:/g, '')
    const blurFilterId = `archetype-wheel-blur-${filterUid}`

    const primaryId = selected[0] || null
    const secondaryId = selected[1] || null
    const list = ARCHETYPE_WHEEL_ORDER.map((id) => ARCHETYPES.find((a) => a.id === id)).filter(Boolean)
    const hubTitleId = hoveredId || primaryId

    useEffect(() => {
        onHoverChange?.(hoveredId)
    }, [hoveredId, onHoverChange])

    useEffect(() => () => onHoverChange?.(null), [onHoverChange])

    /** Slightly larger ring; reference-style density (more air for label stack) */
    const rOut = 158
    const rIn = 36
    /** Labels sit radially outward (closer to outer rim than annulus midpoint) */
    const rLabel = rIn + (rOut - rIn) * 0.78
    const rgb = accentRgbComponents(accentColor)
    const hoveredArchetype = hoveredId ? list.find((x) => x.id === hoveredId) : null
    const hubStrokeRgb = hoveredArchetype ? accentRgbComponents(hoveredArchetype.accent) : rgb

    /** Hovered wedge draws last so it stacks above neighbors */
    const wedgeRenderIndices = list.map((_, i) => i).sort((a, b) => {
        if (list[a].id === hoveredId) return 1
        if (list[b].id === hoveredId) return -1
        return 0
    })

    const handleSegmentClick = (a) => {
        const isSelected = selected.includes(a.id)
        if (isSelected) {
            onSelect(selected.filter((x) => x !== a.id))
        } else if (maxSelections === 1) {
            onSelect([a.id])
        } else if (selected.length < maxSelections) {
            onSelect([...selected, a.id])
        }
    }

    /** Multi-select shortlist: block extras; single-select wheel always allows switching */
    const segmentDisabled = (isSelected) => maxSelections > 1 && selected.length >= maxSelections && !isSelected

    return (
        <div className="space-y-3">
            <div className="relative max-w-[min(100%,min(94vw,720px))] mx-auto px-1">
                <div
                    className="pointer-events-none absolute inset-[-8%] rounded-full opacity-40 blur-3xl"
                    style={{
                        background: wheelAmbientBackground(accentColor),
                    }}
                    aria-hidden
                />
                <div className="relative rounded-full border border-white/[0.09] bg-gradient-to-b from-white/[0.08] via-white/[0.02] to-transparent p-3 shadow-[inset_0_1px_0_rgba(255,255,255,0.07),0_25px_60px_-15px_rgba(0,0,0,0.55)] backdrop-blur-xl ring-1 ring-white/[0.04]">
                    <div className="relative aspect-square overflow-hidden">
                        <svg
                            viewBox="-175 -175 350 350"
                            className="w-full h-full"
                            role="img"
                            aria-label="Archetype wheel — twelve segments"
                        >
                            <defs>
                                <filter
                                    id={blurFilterId}
                                    x="-60%"
                                    y="-60%"
                                    width="220%"
                                    height="220%"
                                    colorInterpolationFilters="sRGB"
                                >
                                    <feGaussianBlur in="SourceGraphic" stdDeviation="5.5" result="blur" />
                                    <feMerge>
                                        <feMergeNode in="blur" />
                                    </feMerge>
                                </filter>
                                <radialGradient id="archetype-hub-glass" cx="50%" cy="38%" r="68%">
                                    <stop offset="0%" stopColor="rgba(255,255,255,0.14)" />
                                    <stop offset="45%" stopColor="rgba(255,255,255,0.04)" />
                                    <stop offset="100%" stopColor="rgba(8,8,12,0.92)" />
                                </radialGradient>
                            </defs>
                        {Array.from({ length: 12 }, (_, i) => {
                            const deg = -90 + i * 30
                            const rad = (deg * Math.PI) / 180
                            const c = Math.cos(rad)
                            const s = Math.sin(rad)
                            return (
                                <line
                                    key={`rad-${i}`}
                                    x1={rIn * c}
                                    y1={rIn * s}
                                    x2={rOut * c}
                                    y2={rOut * s}
                                    stroke="rgba(255,255,255,0.05)"
                                    strokeWidth="1"
                                    strokeDasharray="2 4"
                                    className="pointer-events-none"
                                />
                            )
                        })}

                        {wedgeRenderIndices.map((i) => {
                            const a = list[i]
                            const isSelected = selected.includes(a.id)
                            const isRecommended = recommendedId === a.id
                            const isHovered = hoveredId === a.id
                            const disabled = segmentDisabled(isSelected)
                            const segRot = -90 + i * 30
                            /** Outer edge extends on hover; light scale adds pop (viewBox sized to avoid clip) */
                            const rOutActive = isHovered && !disabled ? rOut + 8 : rOut
                            const wedgeD = donutWedgePath(rIn, rOutActive, 0, 30)
                            const hRgb = accentRgbComponents(a.accent)
                            const accentFillGlow = `rgba(${hRgb.r},${hRgb.g},${hRgb.b},0.5)`
                            const accentStroke = `rgba(${hRgb.r},${hRgb.g},${hRgb.b},0.9)`
                            const accentGlass = `rgba(${hRgb.r},${hRgb.g},${hRgb.b},0.24)`
                            const recR = rOutActive - 4
                            const recCx = recR * Math.cos((15 * Math.PI) / 180)
                            const recCy = recR * Math.sin((15 * Math.PI) / 180)

                            return (
                                <g
                                    key={a.id}
                                    transform={`rotate(${segRot})${isHovered && !disabled ? ' scale(1.03)' : ''}`}
                                    onMouseEnter={() => !disabled && setHoveredId(a.id)}
                                    onMouseLeave={() => setHoveredId((h) => (h === a.id ? null : h))}
                                    style={{ cursor: disabled ? 'not-allowed' : 'pointer' }}
                                >
                                    {/* Neutral glass wedge */}
                                    <path d={wedgeD} fill="rgba(22,22,30,0.92)" className="pointer-events-none" />
                                    {/* Per-archetype glow — blurred underlay */}
                                    {isHovered && !disabled && (
                                        <path
                                            d={wedgeD}
                                            fill={accentFillGlow}
                                            filter={`url(#${blurFilterId})`}
                                            className="pointer-events-none"
                                            opacity={0.92}
                                        />
                                    )}
                                    {/* Hover = archetype accent; selected = light ring */}
                                    <path
                                        d={wedgeD}
                                        fill={
                                            isHovered && !disabled
                                                ? accentGlass
                                                : isSelected
                                                    ? 'rgba(255,255,255,0.16)'
                                                    : 'rgba(255,255,255,0)'
                                        }
                                        fillOpacity={disabled ? 0.35 : 1}
                                        opacity={disabled ? 0.35 : 1}
                                        stroke={
                                            isSelected && !isHovered
                                                ? 'rgba(255,255,255,0.72)'
                                                : isHovered && !disabled
                                                    ? accentStroke
                                                    : 'rgba(255,255,255,0.09)'
                                        }
                                        strokeWidth={isSelected && !isHovered ? 2.25 : isHovered && !disabled ? 2 : 1.5}
                                        strokeOpacity={disabled ? 0.35 : 1}
                                        onClick={() => !disabled && handleSegmentClick(a)}
                                        style={{ cursor: disabled ? 'not-allowed' : 'pointer' }}
                                    />
                                    {isRecommended && (
                                        <circle
                                            cx={recCx}
                                            cy={recCy}
                                            r={3.5}
                                            fill={
                                                isHovered || isSelected
                                                    ? `rgba(${hRgb.r},${hRgb.g},${hRgb.b},0.85)`
                                                    : 'rgba(255,255,255,0.2)'
                                            }
                                            opacity={0.95}
                                            className="pointer-events-none"
                                        />
                                    )}
                                </g>
                            )
                        })}

                        {list.map((a, i) => {
                            const isHovered = hoveredId === a.id
                            const isSelected = selected.includes(a.id)
                            const segDisabled = segmentDisabled(isSelected)
                            const p = globalSegmentPolar(i, rLabel)
                            return (
                                <g
                                    key={`wheel-labels-${a.id}`}
                                    className="pointer-events-none select-none"
                                    opacity={segDisabled ? 0.32 : isHovered ? 1 : isSelected ? 0.9 : 0.62}
                                >
                                    <g transform={`translate(${p.x},${p.y})`}>
                                        <g>
                                            <g transform="translate(0, -10)">
                                                <ArchetypeWheelGlyph
                                                    archetypeId={a.id}
                                                    stroke={
                                                        isHovered || isSelected
                                                            ? 'rgba(255,255,255,0.92)'
                                                            : 'rgba(255,255,255,0.58)'
                                                    }
                                                />
                                            </g>
                                            <text
                                                textAnchor="middle"
                                                y={8}
                                                dominantBaseline="middle"
                                                fill={isHovered ? 'rgba(255,255,255,0.9)' : 'rgba(255,255,255,0.65)'}
                                                style={{
                                                    fontSize: 8.25,
                                                    fontWeight: 600,
                                                    letterSpacing: '0.06em',
                                                }}
                                            >
                                                {a.id}
                                            </text>
                                        </g>
                                    </g>
                                </g>
                            )
                        })}

                        <circle
                            cx="0"
                            cy="0"
                            r={rIn - 1}
                            fill="url(#archetype-hub-glass)"
                            stroke={
                                hoveredId
                                    ? `rgba(${hubStrokeRgb.r},${hubStrokeRgb.g},${hubStrokeRgb.b},0.5)`
                                    : 'rgba(255,255,255,0.12)'
                            }
                            strokeWidth={hoveredId ? 1.35 : 1}
                        />

                        <g className="pointer-events-none" transform="translate(0, 1)">
                            <text
                                x="0"
                                y="-7"
                                textAnchor="middle"
                                fill="rgba(255,255,255,0.28)"
                                style={{ fontSize: '5.5px', fontWeight: 500, letterSpacing: '0.14em' }}
                                className="uppercase"
                            >
                                Archetypes
                            </text>
                            <text
                                x="0"
                                y="7"
                                textAnchor="middle"
                                fill="rgba(255,255,255,0.88)"
                                style={{ fontSize: '11.5px', fontWeight: 500, letterSpacing: '0.01em' }}
                            >
                                {hubTitleId || '—'}
                            </text>
                            {secondaryId && (
                                <text
                                    x="0"
                                    y="19"
                                    textAnchor="middle"
                                    fill="rgba(255,255,255,0.32)"
                                    style={{ fontSize: '8.5px', fontWeight: 500 }}
                                    opacity={hoveredId ? 0 : 1}
                                >
                                    + {secondaryId}
                                </text>
                            )}
                        </g>
                    </svg>
                    </div>
                </div>
            </div>
        </div>
    )
}

function ArchetypePaths({ onPath }) {
    const paths = [
        {
            id: 'explore_direct',
            title: 'I know my archetype',
            desc: 'Open the 360° wheel to pick your archetype',
            icon: (
                <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                    <path strokeLinecap="round" strokeLinejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z" />
                </svg>
            ),
        },
        {
            id: 'guided',
            title: 'Help me find it',
            desc: 'Walk through each archetype and choose what fits',
            icon: (
                <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                    <path strokeLinecap="round" strokeLinejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.455 2.456L21.75 6l-1.036.259a3.375 3.375 0 00-2.455 2.456z" />
                </svg>
            ),
        },
        {
            id: 'explore_browse',
            title: 'Browse all archetypes',
            desc: 'See every archetype and choose freely',
            icon: (
                <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                    <path strokeLinecap="round" strokeLinejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                    <path strokeLinecap="round" strokeLinejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg>
            ),
        },
    ]

    return (
        <motion.div
            initial={{ opacity: 0, y: 16 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.5 }}
            className="space-y-6"
        >
            <div className="text-center space-y-2">
                <h3 className="text-xl font-semibold text-white">Choose your path</h3>
                <p className="text-sm text-white/40">Not feeling it? Find the right archetype your way.</p>
            </div>

            <div className="grid gap-3 max-w-2xl mx-auto">
                {paths.map((p, i) => (
                    <motion.button
                        key={`${p.id}-${i}`}
                        type="button"
                        onClick={() => onPath(p.id)}
                        initial={{ opacity: 0, x: -12 }}
                        animate={{ opacity: 1, x: 0 }}
                        transition={{ delay: i * 0.1 }}
                        whileHover={{ x: 4 }}
                        className="flex items-center gap-4 p-4 rounded-xl border border-white/[0.06] bg-white/[0.02] hover:bg-white/[0.04] hover:border-white/15 text-left transition-colors group"
                    >
                        <div className="flex-shrink-0 w-10 h-10 rounded-lg bg-white/[0.04] flex items-center justify-center text-white/30 group-hover:text-white/50 transition-colors">
                            {p.icon}
                        </div>
                        <div className="flex-1 min-w-0">
                            <h4 className="font-medium text-white/80 group-hover:text-white transition-colors">{p.title}</h4>
                            <p className="text-xs text-white/30">{p.desc}</p>
                        </div>
                        <svg className="w-4 h-4 text-white/15 group-hover:text-white/40 transition-colors flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                        </svg>
                    </motion.button>
                ))}
            </div>
        </motion.div>
    )
}

function ArchetypeGrid({ selected, onSelect, maxSelections = 1, recommendedId = null, showRecommendationHint = false, accentColor = null }) {
    const [useClassicGrid, setUseClassicGrid] = useState(false)
    const [wheelHoveredId, setWheelHoveredId] = useState(null)
    const primaryId = selected[0] || null
    const primaryArchetype = primaryId ? ARCHETYPES.find((a) => a.id === primaryId) : null
    const wheelHoverArchetype = wheelHoveredId ? ARCHETYPES.find((a) => a.id === wheelHoveredId) : null
    const remaining = ARCHETYPES.filter((a) => !selected.includes(a.id))

    useEffect(() => {
        if (useClassicGrid) setWheelHoveredId(null)
    }, [useClassicGrid])

    return (
        <motion.div
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            transition={{ duration: 0.4 }}
            className="space-y-4"
        >
            <div className="text-center space-y-1">
                <h3 className="text-xl font-semibold text-white">Choose your archetype</h3>
                <p className="text-sm text-white/35">Select the identity that best represents your brand.</p>
                {showRecommendationHint && recommendedId && ARCHETYPES.some((a) => a.id === recommendedId) && (
                    <p className="text-sm text-white/45 max-w-lg mx-auto pt-1">
                        We&apos;ve pre-selected <span className="text-white/80 font-medium">{recommendedId}</span> from your research — change it anytime below.
                    </p>
                )}
            </div>

            <div className="flex justify-center">
                <div
                    className="inline-flex rounded-xl border border-white/[0.08] bg-white/[0.02] p-1 gap-0.5"
                    role="group"
                    aria-label="Archetype layout"
                >
                    <button
                        type="button"
                        onClick={() => setUseClassicGrid(false)}
                        className={`px-4 py-2 rounded-lg text-xs font-medium transition-colors ${
                            !useClassicGrid
                                ? 'bg-white/[0.1] text-white/90 shadow-sm'
                                : 'text-white/40 hover:text-white/65'
                        }`}
                    >
                        360° wheel
                    </button>
                    <button
                        type="button"
                        onClick={() => setUseClassicGrid(true)}
                        className={`px-4 py-2 rounded-lg text-xs font-medium transition-colors ${
                            useClassicGrid
                                ? 'bg-white/[0.1] text-white/90 shadow-sm'
                                : 'text-white/40 hover:text-white/65'
                        }`}
                    >
                        Classic grid
                    </button>
                </div>
            </div>

            {!useClassicGrid && (
                <p className="text-center text-[11px] text-white/30 max-w-md mx-auto leading-snug">
                    Hover a segment to preview · click to select (click again to clear). The wheel center follows your pointer.
                </p>
            )}

            {/* Wheel: preview on hover above; classic grid: selected hero only */}
            {!useClassicGrid ? (
                <div className="max-w-lg mx-auto min-h-[200px] flex flex-col justify-center">
                    <AnimatePresence mode="wait">
                        {wheelHoverArchetype ? (
                            <motion.div
                                key={`preview-${wheelHoverArchetype.id}`}
                                initial={{ opacity: 0, y: 6 }}
                                animate={{ opacity: 1, y: 0 }}
                                exit={{ opacity: 0, y: 6 }}
                                transition={{ duration: 0.2 }}
                                className="rounded-2xl border border-white/[0.1] bg-gradient-to-b from-white/[0.07] to-white/[0.02] px-5 py-4 text-center shadow-[0_16px_40px_-18px_rgba(0,0,0,0.55)]"
                            >
                                <p className="text-[10px] font-medium uppercase tracking-[0.2em] text-white/35 mb-2">Preview</p>
                                <p className="text-lg font-semibold text-white">{wheelHoverArchetype.id}</p>
                                <p className="text-sm text-white/55 mt-1.5">{wheelHoverArchetype.desc}</p>
                                <p className="text-xs text-white/35 mt-2 leading-relaxed">{wheelHoverArchetype.essence}</p>
                            </motion.div>
                        ) : primaryArchetype ? (
                            <motion.div
                                key={`hero-${primaryArchetype.id}`}
                                initial={{ opacity: 0, y: -8 }}
                                animate={{ opacity: 1, y: 0 }}
                                exit={{ opacity: 0, y: -8 }}
                                transition={{ type: 'spring', stiffness: 200, damping: 25 }}
                            >
                                <p className="text-[10px] font-medium uppercase tracking-widest text-white/25 mb-2 text-center">
                                    Selected
                                </p>
                                <ArchetypeCard
                                    archetype={primaryArchetype}
                                    elevated
                                    selected
                                    isPrimary
                                    onClick={() => onSelect(selected.filter((x) => x !== primaryId))}
                                />
                                <p className="text-[11px] text-white/25 text-center mt-2">Click to deselect</p>
                            </motion.div>
                        ) : (
                            <motion.p
                                key="wheel-placeholder"
                                initial={{ opacity: 0 }}
                                animate={{ opacity: 1 }}
                                className="text-center text-sm text-white/28 py-6"
                            >
                                Hover the wheel to read each archetype here, then click a segment to choose.
                            </motion.p>
                        )}
                    </AnimatePresence>
                </div>
            ) : (
                <AnimatePresence>
                    {primaryArchetype && (
                        <motion.div
                            key={`hero-${primaryArchetype.id}`}
                            initial={{ opacity: 0, y: -10 }}
                            animate={{ opacity: 1, y: 0 }}
                            exit={{ opacity: 0, y: -10, transition: { duration: 0.2 } }}
                            transition={{ type: 'spring', stiffness: 200, damping: 25 }}
                            className="max-w-lg mx-auto"
                        >
                            <p className="text-[10px] font-medium uppercase tracking-widest text-white/25 mb-3 text-center">
                                Selected
                            </p>
                            <ArchetypeCard
                                archetype={primaryArchetype}
                                elevated
                                selected
                                isPrimary
                                onClick={() => onSelect(selected.filter((x) => x !== primaryId))}
                            />
                            <p className="text-[11px] text-white/25 text-center mt-2">Click to deselect</p>
                        </motion.div>
                    )}
                </AnimatePresence>
            )}

            {!useClassicGrid ? (
                <ArchetypeWheel
                    selected={selected}
                    onSelect={onSelect}
                    maxSelections={maxSelections}
                    recommendedId={recommendedId}
                    accentColor={accentColor}
                    onHoverChange={setWheelHoveredId}
                />
            ) : (
                <>
                    {primaryArchetype && (
                        <div className="flex items-center gap-4 px-4">
                            <div className="flex-1 h-px bg-white/[0.06]" />
                            <span className="text-[10px] uppercase tracking-widest text-white/20">All archetypes</span>
                            <div className="flex-1 h-px bg-white/[0.06]" />
                        </div>
                    )}

                    <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
                        {(primaryArchetype ? remaining : ARCHETYPES).map((a, i) => {
                            const isSelected = selected.includes(a.id)
                            const atCapacity = maxSelections > 1 && selected.length >= maxSelections && !isSelected
                            return (
                                <motion.div
                                    key={a.id}
                                    initial={{ opacity: 0, y: 12 }}
                                    animate={{ opacity: 1, y: 0 }}
                                    transition={{ delay: i * 0.03 }}
                                    layout
                                >
                                    <ArchetypeCard
                                        archetype={a}
                                        compact
                                        selected={isSelected}
                                        disabled={atCapacity}
                                        onClick={() => {
                                            if (isSelected) {
                                                onSelect(selected.filter((x) => x !== a.id))
                                            } else if (maxSelections === 1) {
                                                onSelect([a.id])
                                            } else if (selected.length < maxSelections) {
                                                onSelect([...selected, a.id])
                                            }
                                        }}
                                    />
                                </motion.div>
                            )
                        })}
                    </div>
                </>
            )}
        </motion.div>
    )
}

function ArchetypeGuided({ selected, rejected, onSelect, onReject, onRemoveSelected, onUnreject }) {
    return (
        <motion.div
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            transition={{ duration: 0.4 }}
            className="space-y-8"
        >
            <div className="text-center space-y-2">
                <h3 className="text-xl font-semibold text-white">Trust your instinct</h3>
                <p className="text-sm text-white/35">Select 1–3 archetypes that feel right. Eliminate ones that don't fit.</p>
            </div>

            {(selected.length > 0 || rejected.length > 0) && (
                <div className="grid md:grid-cols-2 gap-4 max-w-2xl mx-auto">
                    <div className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-4">
                        <h4 className="text-[10px] font-semibold text-white/40 uppercase tracking-wider mb-3">Applies to us</h4>
                        <div className="space-y-2 min-h-[48px]">
                            {selected.map((id) => {
                                const a = ARCHETYPES.find((x) => x.id === id)
                                if (!a) return null
                                return (
                                    <div key={id} className="flex items-center justify-between rounded-lg bg-white/[0.04] px-3 py-2">
                                        <div className="flex items-center gap-2">
                                            <span className="text-sm" style={{ color: a.accent + '80' }}>{a.symbol}</span>
                                            <span className="text-white/80 text-sm font-medium">{a.id}</span>
                                        </div>
                                        <button type="button" onClick={() => onRemoveSelected(id)} className="text-xs text-white/30 hover:text-white/60">Remove</button>
                                    </div>
                                )
                            })}
                            {selected.length === 0 && <p className="text-xs text-white/20 italic">None selected yet</p>}
                        </div>
                    </div>
                    <div className="rounded-xl border border-white/[0.05] bg-white/[0.01] p-4">
                        <h4 className="text-[10px] font-semibold text-white/30 uppercase tracking-wider mb-3">Doesn't fit</h4>
                        <div className="flex flex-wrap gap-2 min-h-[48px]">
                            {rejected.map((id) => {
                                const a = ARCHETYPES.find((x) => x.id === id)
                                if (!a) return null
                                return (
                                    <button key={id} type="button" onClick={() => onUnreject(id)} className="px-2.5 py-1.5 rounded-lg bg-white/[0.04] text-white/30 text-xs border border-white/[0.06] hover:bg-white/[0.08] hover:text-white/50">
                                        {a.id} ✕
                                    </button>
                                )
                            })}
                            {rejected.length === 0 && <p className="text-xs text-white/20 italic">None eliminated</p>}
                        </div>
                    </div>
                </div>
            )}

            <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
                {ARCHETYPES.filter((a) => !selected.includes(a.id) && !rejected.includes(a.id)).map((a, i) => (
                    <motion.div
                        key={a.id}
                        initial={{ opacity: 0, y: 12 }}
                        animate={{ opacity: 1, y: 0 }}
                        transition={{ delay: i * 0.03 }}
                        layout
                    >
                        <ArchetypeCard
                            archetype={a}
                            compact
                            disabled={selected.length >= 3}
                            showActions
                            onApply={() => onSelect(a.id)}
                            onReject={() => onReject(a.id)}
                            onClick={() => {
                                if (selected.length < 3) onSelect(a.id)
                            }}
                        />
                    </motion.div>
                ))}
            </div>

            {ARCHETYPES.filter((a) => !selected.includes(a.id) && !rejected.includes(a.id)).length === 0 && (
                <p className="text-center text-white/30 text-sm py-8">All archetypes have been sorted. Use the buckets above to adjust.</p>
            )}
        </motion.div>
    )
}

export default function ArchetypeStep({
    personality,
    effectiveSuggestions,
    onUpdate,
    accentColor,
    researchSnapshot = null,
    coherence = null,
}) {
    // typeof null === 'object' — must exclude null before using `in`
    const primaryArchetype = personality.primary_archetype != null
        && typeof personality.primary_archetype === 'object'
        && !Array.isArray(personality.primary_archetype)
        && 'value' in personality.primary_archetype
        ? personality.primary_archetype.value
        : personality.primary_archetype || null
    const candidateArchetypes = ((typeof personality.candidate_archetypes === 'object' && 'value' in (personality.candidate_archetypes || {}))
        ? personality.candidate_archetypes?.value
        : personality.candidate_archetypes) || []
    const candidates = Array.isArray(candidateArchetypes)
        ? candidateArchetypes.map((c) => (c != null && typeof c === 'object' && 'value' in c ? c.value : c)).filter(Boolean)
        : []
    const rejectedArchetypes = ((typeof personality.rejected_archetypes === 'object' && 'value' in (personality.rejected_archetypes || {}))
        ? personality.rejected_archetypes?.value
        : personality.rejected_archetypes) || []
    const rejected = Array.isArray(rejectedArchetypes)
        ? rejectedArchetypes.map((r) => (r != null && typeof r === 'object' && 'value' in r ? r.value : r)).filter(Boolean)
        : []

    const selected = [primaryArchetype, ...candidates].filter(Boolean)

    const recommended = effectiveSuggestions?.recommended_archetypes
    const rawFromSuggestions = Array.isArray(recommended) && recommended.length > 0
        ? (typeof recommended[0] === 'string' ? recommended[0] : recommended[0]?.label || recommended[0]?.value || recommended[0]?.archetype)
        : null

    const fromItems = Array.isArray(effectiveSuggestions?.items)
        ? effectiveSuggestions.items.find((p) => p?.path === 'personality.primary_archetype')
        : null
    const rawFromItems = fromItems?.value != null
        ? (typeof fromItems.value === 'object' && fromItems.value !== null
            ? (fromItems.value.label ?? fromItems.value.value ?? fromItems.value.archetype)
            : fromItems.value)
        : null

    const rawFromSnapshot = extractArchetypeFromResearchSnapshot(researchSnapshot)
    const archetypeSectionScore = coherence?.sections?.archetype?.score
    /** Use snapshot extraction when coherence says archetype section is strong (e.g. 96), or when no score yet but we have text */
    const snapshotUsable = rawFromSnapshot && (
        archetypeSectionScore == null || archetypeSectionScore >= ARCHETYPE_SECTION_SCORE_MIN
    )

    const recommendedId = normalizeArchetypeId(rawFromSuggestions)
        || normalizeArchetypeId(rawFromItems)
        || (snapshotUsable ? normalizeArchetypeId(rawFromSnapshot) : null)

    const hasRecommendation = !!recommendedId
    const hasExistingSelection = !!primaryArchetype

    /** Skip path picker + hero — go straight to grid with AI suggestion pre-selected */
    const getInitialMode = () => {
        if (hasExistingSelection) return 'explore'
        if (hasRecommendation) return 'explore'
        return 'paths'
    }

    const [mode, setMode] = useState(getInitialMode)
    const [optedOutOfRecommendation, setOptedOutOfRecommendation] = useState(false)
    const autoAppliedRecommendationRef = useRef(false)

    const updateArchetypes = useCallback((primary, cands = [], rej) => {
        onUpdate({
            primary_archetype: primary,
            candidate_archetypes: cands,
            ...(rej !== undefined ? { rejected_archetypes: rej } : {}),
        })
    }, [onUpdate])

    useEffect(() => {
        if (hasExistingSelection) autoAppliedRecommendationRef.current = true
    }, [hasExistingSelection])

    useEffect(() => {
        if (autoAppliedRecommendationRef.current) return
        if (!hasRecommendation || !recommendedId || hasExistingSelection) return
        if (primaryArchetype === recommendedId) {
            autoAppliedRecommendationRef.current = true
            return
        }
        autoAppliedRecommendationRef.current = true
        updateArchetypes(recommendedId, [])
    }, [hasRecommendation, recommendedId, hasExistingSelection, primaryArchetype, updateArchetypes])

    const handleExploreOtherOptions = useCallback(() => {
        setOptedOutOfRecommendation(true)
        updateArchetypes(null, [], [])
        setMode('paths')
    }, [updateArchetypes])

    const handleAcceptRecommendation = useCallback(() => {
        updateArchetypes(recommendedId, [])
    }, [recommendedId, updateArchetypes])

    const handleGridSelect = useCallback((newSelected) => {
        updateArchetypes(newSelected[0] || null, newSelected.slice(1))
    }, [updateArchetypes])

    const handleGuidedSelect = useCallback((id) => {
        const next = [...selected, id]
        updateArchetypes(next[0], next.slice(1), rejected)
    }, [selected, rejected, updateArchetypes])

    const handleGuidedReject = useCallback((id) => {
        const nextRejected = [...rejected, id]
        onUpdate({ rejected_archetypes: nextRejected })
    }, [rejected, onUpdate])

    const handleGuidedRemoveSelected = useCallback((id) => {
        const next = selected.filter((x) => x !== id)
        updateArchetypes(next[0] || null, next.slice(1), rejected)
    }, [selected, rejected, updateArchetypes])

    const handleGuidedUnreject = useCallback((id) => {
        const nextRejected = rejected.filter((x) => x !== id)
        onUpdate({ rejected_archetypes: nextRejected })
    }, [rejected, onUpdate])

    const buildReasoning = () => {
        if (!recommendedId) return null
        const parts = []
        const industry = personality.industry || personality.target_audience
        if (industry) parts.push(`your positioning in ${typeof industry === 'object' ? industry.value : industry}`)
        const traits = personality.traits
        if (Array.isArray(traits) && traits.length > 0) {
            const traitStrs = traits.slice(0, 3).map((t) => typeof t === 'object' ? t.value : t)
            parts.push(`brand traits like ${traitStrs.join(', ')}`)
        }
        if (parts.length === 0) return 'Based on your brand inputs, this archetype aligns strongest with your identity.'
        return `Based on ${parts.join(' and ')}, this archetype aligns strongest with your brand identity.`
    }

    return (
        <div className="space-y-6">
            <AnimatePresence mode="wait">
                {mode === 'recommendation' && (
                    <motion.div key="recommendation" exit={{ opacity: 0, y: -12 }} transition={{ duration: 0.3 }}>
                        <ArchetypeHero
                            archetype={recommendedId}
                            reasoning={buildReasoning()}
                            onAccept={handleAcceptRecommendation}
                            onReject={() => setMode('paths')}
                        />
                    </motion.div>
                )}

                {mode === 'paths' && (
                    <motion.div key="paths" exit={{ opacity: 0, y: -12 }} transition={{ duration: 0.3 }}>
                        <ArchetypePaths
                            onPath={(path) => {
                                if (path === 'guided') setMode('guided')
                                else if (path === 'explore_direct' || path === 'explore_browse') setMode('explore')
                            }}
                        />
                    </motion.div>
                )}

                {mode === 'explore' && (
                    <motion.div key="explore" exit={{ opacity: 0, y: -12 }} transition={{ duration: 0.3 }}>
                        <ArchetypeGrid
                            selected={selected}
                            onSelect={handleGridSelect}
                            maxSelections={1}
                            recommendedId={recommendedId}
                            showRecommendationHint={hasRecommendation && !optedOutOfRecommendation}
                            accentColor={accentColor}
                        />
                        {hasRecommendation && !optedOutOfRecommendation && (
                            <div className="flex justify-center mt-5">
                                <button
                                    type="button"
                                    onClick={handleExploreOtherOptions}
                                    className="px-5 py-2.5 rounded-xl text-sm font-medium border border-white/15 text-white/70 hover:text-white/90 hover:bg-white/[0.06] transition-colors"
                                >
                                    Explore other options
                                </button>
                            </div>
                        )}
                        {hasRecommendation && !optedOutOfRecommendation && hasExistingSelection && primaryArchetype === recommendedId && (
                            <div className="text-center mt-4">
                                <button
                                    type="button"
                                    onClick={() => setMode('recommendation')}
                                    className="text-sm text-white/30 hover:text-white/50 transition"
                                >
                                    See why we suggested this archetype
                                </button>
                            </div>
                        )}
                    </motion.div>
                )}

                {mode === 'guided' && (
                    <motion.div key="guided" exit={{ opacity: 0, y: -12 }} transition={{ duration: 0.3 }}>
                        <ArchetypeGuided
                            selected={selected}
                            rejected={rejected}
                            onSelect={handleGuidedSelect}
                            onReject={handleGuidedReject}
                            onRemoveSelected={handleGuidedRemoveSelected}
                            onUnreject={handleGuidedUnreject}
                        />
                        {!hasExistingSelection && hasRecommendation && (
                            <div className="text-center mt-6">
                                <button type="button" onClick={() => setMode('recommendation')} className="text-sm text-white/30 hover:text-white/50 transition">
                                    ← Back to recommendation
                                </button>
                            </div>
                        )}
                    </motion.div>
                )}
            </AnimatePresence>

            {mode !== 'recommendation' && mode !== 'paths' && (
                <motion.div
                    initial={{ opacity: 0 }}
                    animate={{ opacity: 1 }}
                    transition={{ delay: 0.5 }}
                    className="flex flex-col items-center gap-3 pt-4 border-t border-white/[0.04]"
                >
                    <div className="flex items-center justify-center gap-4 flex-wrap">
                        {mode !== 'explore' && (
                            <button type="button" onClick={() => setMode('explore')} className="text-xs text-white/25 hover:text-white/50 transition">
                                Browse all
                            </button>
                        )}
                        {mode !== 'guided' && (
                            <button type="button" onClick={() => setMode('guided')} className="text-xs text-white/25 hover:text-white/50 transition">
                                Guided selection
                            </button>
                        )}
                    </div>
                    <button
                        type="button"
                        onClick={() => setMode('paths')}
                        className="text-xs text-white/20 hover:text-white/45 transition"
                    >
                        Choose your path
                    </button>
                </motion.div>
            )}
        </div>
    )
}

export { ARCHETYPES }
