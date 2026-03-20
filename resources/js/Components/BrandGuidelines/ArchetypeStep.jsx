import { useState, useCallback } from 'react'
import { motion, AnimatePresence } from 'framer-motion'

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

function ArchetypeCard({ archetype, selected = false, isPrimary = false, elevated = false, compact = false, onClick, disabled = false, showActions, onApply, onReject }) {
    return (
        <motion.button
            type="button"
            onClick={onClick}
            disabled={disabled && !selected}
            layout
            layoutId={`archetype-${archetype.id}`}
            whileHover={!disabled || selected ? { y: -2 } : {}}
            whileTap={!disabled || selected ? { scale: 0.98 } : {}}
            className={`relative rounded-2xl border text-left transition-all overflow-hidden flex flex-col w-full ${
                compact ? 'p-4 min-h-[120px]' : elevated ? 'p-8 min-h-[200px]' : 'p-5 min-h-[160px]'
            } ${
                selected
                    ? 'border-white/25 bg-white/[0.06]'
                    : disabled
                        ? 'border-white/[0.05] bg-white/[0.015] opacity-40 cursor-not-allowed'
                        : 'border-white/[0.08] bg-white/[0.02] hover:border-white/15 hover:bg-white/[0.04] cursor-pointer'
            }`}
        >
            {/* Subtle accent glow — only for selected state */}
            {selected && (
                <div
                    className="absolute inset-0 opacity-[0.08]"
                    style={{ background: `radial-gradient(ellipse at 30% 20%, ${archetype.accent}, transparent 70%)` }}
                />
            )}

            <div className="relative z-10 flex flex-col flex-1">
                <div className={`flex items-center gap-3 ${compact ? 'mb-2' : 'mb-3'}`}>
                    <span
                        className={`${elevated ? 'text-3xl' : compact ? 'text-lg' : 'text-xl'} transition-colors`}
                        style={{ color: selected ? archetype.accent : 'rgba(255,255,255,0.25)' }}
                    >
                        {archetype.symbol}
                    </span>
                    {selected && isPrimary && (
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

                <h3 className={`font-semibold text-white ${elevated ? 'text-2xl' : compact ? 'text-base' : 'text-lg'} ${!selected ? 'text-white/80' : ''}`}>
                    {archetype.id}
                </h3>

                <p className={`mt-1 ${elevated ? 'text-sm' : 'text-xs'} ${selected ? 'text-white/50' : 'text-white/30'}`}>
                    {archetype.essence}
                </p>

                {!compact && (
                    <p className={`mt-1.5 ${elevated ? 'text-sm' : 'text-xs'} ${selected ? 'text-white/40' : 'text-white/20'}`}>
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

function ArchetypePaths({ onPath }) {
    const paths = [
        {
            id: 'explore',
            title: 'I know my archetype',
            desc: 'Jump straight to the selection grid',
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
            id: 'explore',
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

function ArchetypeGrid({ selected, onSelect, maxSelections = 1 }) {
    const primaryId = selected[0] || null
    const primaryArchetype = primaryId ? ARCHETYPES.find((a) => a.id === primaryId) : null
    const remaining = ARCHETYPES.filter((a) => !selected.includes(a.id))

    return (
        <motion.div
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            transition={{ duration: 0.4 }}
            className="space-y-8"
        >
            <div className="text-center space-y-2">
                <h3 className="text-xl font-semibold text-white">Choose your archetype</h3>
                <p className="text-sm text-white/35">Select the identity that best represents your brand.</p>
            </div>

            {/* Selected archetype — elevated at top */}
            <AnimatePresence>
                {primaryArchetype && (
                    <motion.div
                        key={`selected-${primaryId}`}
                        initial={{ opacity: 0, y: -10 }}
                        animate={{ opacity: 1, y: 0 }}
                        exit={{ opacity: 0, y: -10, transition: { duration: 0.2 } }}
                        transition={{ type: 'spring', stiffness: 200, damping: 25 }}
                        className="max-w-lg mx-auto"
                    >
                        <p className="text-[10px] font-medium uppercase tracking-widest text-white/25 mb-3 text-center">Selected</p>
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

            {/* Divider when something is selected */}
            {primaryArchetype && (
                <div className="flex items-center gap-4 px-4">
                    <div className="flex-1 h-px bg-white/[0.06]" />
                    <span className="text-[10px] uppercase tracking-widest text-white/20">All archetypes</span>
                    <div className="flex-1 h-px bg-white/[0.06]" />
                </div>
            )}

            {/* Grid of remaining archetypes */}
            <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
                {(primaryArchetype ? remaining : ARCHETYPES).map((a, i) => {
                    const isSelected = selected.includes(a.id)
                    const canSelect = selected.length < maxSelections
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
                                disabled={!canSelect && !isSelected}
                                onClick={() => {
                                    if (isSelected) {
                                        onSelect(selected.filter((x) => x !== a.id))
                                    } else if (canSelect) {
                                        onSelect([...selected, a.id])
                                    }
                                }}
                            />
                        </motion.div>
                    )
                })}
            </div>
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
}) {
    const primaryArchetype = typeof personality.primary_archetype === 'object' && 'value' in personality.primary_archetype
        ? personality.primary_archetype.value
        : personality.primary_archetype || null
    const candidateArchetypes = ((typeof personality.candidate_archetypes === 'object' && 'value' in (personality.candidate_archetypes || {}))
        ? personality.candidate_archetypes?.value
        : personality.candidate_archetypes) || []
    const candidates = Array.isArray(candidateArchetypes)
        ? candidateArchetypes.map((c) => typeof c === 'object' && 'value' in c ? c.value : c).filter(Boolean)
        : []
    const rejectedArchetypes = ((typeof personality.rejected_archetypes === 'object' && 'value' in (personality.rejected_archetypes || {}))
        ? personality.rejected_archetypes?.value
        : personality.rejected_archetypes) || []
    const rejected = Array.isArray(rejectedArchetypes)
        ? rejectedArchetypes.map((r) => typeof r === 'object' && 'value' in r ? r.value : r).filter(Boolean)
        : []

    const selected = [primaryArchetype, ...candidates].filter(Boolean)

    const recommended = effectiveSuggestions?.recommended_archetypes
    const recommendedId = Array.isArray(recommended) && recommended.length > 0
        ? (typeof recommended[0] === 'string' ? recommended[0] : recommended[0]?.label || recommended[0]?.value)
        : null

    const hasRecommendation = !!recommendedId && ARCHETYPES.some((a) => a.id === recommendedId)
    const hasExistingSelection = !!primaryArchetype

    const getInitialMode = () => {
        if (hasExistingSelection) return 'explore'
        if (hasRecommendation) return 'recommendation'
        return 'paths'
    }

    const [mode, setMode] = useState(getInitialMode)

    const updateArchetypes = useCallback((primary, cands = [], rej) => {
        onUpdate({
            primary_archetype: primary,
            candidate_archetypes: cands,
            ...(rej !== undefined ? { rejected_archetypes: rej } : {}),
        })
    }, [onUpdate])

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
        <div className="space-y-10">
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
                        <ArchetypePaths onPath={(path) => setMode(path)} />
                    </motion.div>
                )}

                {mode === 'explore' && (
                    <motion.div key="explore" exit={{ opacity: 0, y: -12 }} transition={{ duration: 0.3 }}>
                        <ArchetypeGrid
                            selected={selected}
                            onSelect={handleGridSelect}
                            maxSelections={2}
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
                    className="flex items-center justify-center gap-4 pt-4 border-t border-white/[0.04]"
                >
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
                </motion.div>
            )}
        </div>
    )
}

export { ARCHETYPES }
