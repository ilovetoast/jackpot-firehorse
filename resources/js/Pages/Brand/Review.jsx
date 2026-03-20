/**
 * Brand Review — AI output validation page.
 * Shows a human-readable summary of what the AI extracted, section health,
 * strengths/risks, and a clear path to continue building.
 */

import { useState, useCallback, useEffect } from 'react'
import { router } from '@inertiajs/react'
import { motion } from 'framer-motion'
import AppNav from '../../Components/AppNav'
import AppHead from '../../Components/AppHead'
import axios from 'axios'

const SECTION_LABELS = {
    purpose: 'Purpose & Mission',
    archetype: 'Brand Archetype',
    expression: 'Expression & Voice',
    positioning: 'Positioning',
    standards: 'Visual Standards',
    background: 'Background Research',
}

const SECTION_ICONS = {
    purpose: (
        <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={1.5}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M12 18v-5.25m0 0a6.01 6.01 0 001.5-.189m-1.5.189a6.01 6.01 0 01-1.5-.189m3.75 7.478a12.06 12.06 0 01-4.5 0m3.75 2.383a14.406 14.406 0 01-3 0M14.25 18v-.192c0-.983.658-1.823 1.508-2.316a7.5 7.5 0 10-7.517 0c.85.493 1.509 1.333 1.509 2.316V18" />
        </svg>
    ),
    archetype: (
        <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={1.5}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" />
        </svg>
    ),
    expression: (
        <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={1.5}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.129.166 2.27.293 3.423.379.35.026.67.21.865.501L12 21l2.755-4.133a1.14 1.14 0 01.865-.501 48.172 48.172 0 003.423-.379c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z" />
        </svg>
    ),
    positioning: (
        <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={1.5}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" />
        </svg>
    ),
    standards: (
        <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={1.5}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M4.098 19.902a3.75 3.75 0 005.304 0l6.401-6.402M6.75 21A3.75 3.75 0 013 17.25V4.125C3 3.504 3.504 3 4.125 3h5.25c.621 0 1.125.504 1.125 1.125v4.072M6.75 21a3.75 3.75 0 003.75-3.75V8.197M6.75 21h13.125c.621 0 1.125-.504 1.125-1.125v-5.25c0-.621-.504-1.125-1.125-1.125h-4.072M10.5 8.197l2.88-2.88c.438-.439 1.15-.439 1.59 0l3.712 3.713c.44.44.44 1.152 0 1.59l-2.879 2.88M6.75 17.25h.008v.008H6.75v-.008z" />
        </svg>
    ),
    background: (
        <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={1.5}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m5.231 13.481L15 17.25m-4.5-15H5.625c-.621 0-1.125.504-1.125 1.125v16.5c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9zm3.75 11.625a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
        </svg>
    ),
}

function scoreColor(score) {
    if (score >= 80) return 'text-emerald-400'
    if (score >= 50) return 'text-amber-400'
    return 'text-red-400'
}

function scoreBg(score) {
    if (score >= 80) return 'bg-emerald-500'
    if (score >= 50) return 'bg-amber-500'
    return 'bg-red-500'
}

function scoreLabel(score) {
    if (score >= 90) return 'Excellent'
    if (score >= 80) return 'Strong'
    if (score >= 60) return 'Good'
    if (score >= 40) return 'Needs work'
    if (score > 0) return 'Weak'
    return 'Not started'
}

export default function Review({
    brand,
    version,
    snapshot,
    suggestions,
    coherence,
    alignment,
    insightState: initialInsightState,
    snapshotId,
    modelPayload,
}) {
    const [advancing, setAdvancing] = useState(false)

    useEffect(() => {
        document.documentElement.classList.add('scrollbar-cinematic')
        return () => document.documentElement.classList.remove('scrollbar-cinematic')
    }, [])

    const accentColor = brand.primary_color || '#6366f1'

    const handleAdvanceToBuild = useCallback(async () => {
        setAdvancing(true)
        try {
            await axios.post(route('brands.review.advance-to-build', { brand: brand.id }))
            router.visit(route('brands.brand-guidelines.builder', { brand: brand.id }))
        } catch (err) {
            console.error('Advance failed', err)
        } finally {
            setAdvancing(false)
        }
    }, [brand.id])

    const typography = snapshot?.typography ?? {}

    // Snapshot data is flat — fields live at the root, not nested
    const extracted = {
        mission: snapshot?.mission || snapshot?.identity?.mission,
        tagline: snapshot?.tagline || snapshot?.identity?.tagline,
        positioning: snapshot?.positioning || snapshot?.identity?.positioning,
        industry: snapshot?.industry || snapshot?.identity?.industry,
        target_audience: snapshot?.target_audience || snapshot?.identity?.target_audience,
        archetype: snapshot?.primary_archetype || snapshot?.personality?.primary_archetype,
        tone_keywords: snapshot?.tone_keywords || snapshot?.personality?.tone_keywords || snapshot?.scoring_rules?.tone_keywords,
        voice_description: snapshot?.voice_description || snapshot?.personality?.voice_description,
        brand_look: snapshot?.brand_look || snapshot?.personality?.brand_look,
        visual_style: snapshot?.visual_style || snapshot?.visual?.visual_style,
        photography_style: snapshot?.photography_style || snapshot?.visual?.photography_style,
        primary_colors: snapshot?.primary_colors || snapshot?.visual?.primary_colors || [],
        secondary_colors: snapshot?.secondary_colors || snapshot?.visual?.secondary_colors || [],
        primary_font: typography.primary_font,
        secondary_font: typography.secondary_font,
    }

    const overall = coherence?.overall ?? {}
    const overallScore = overall.score ?? 0
    const sections = coherence?.sections ?? {}
    const strengths = coherence?.strengths ?? []
    const risks = coherence?.risks ?? []

    const suggestionItems = Array.isArray(suggestions) ? suggestions : (suggestions?.items ?? [])
    const suggestionCount = suggestionItems.length

    const sortedSections = Object.entries(sections)
        .map(([key, data]) => ({ key, ...data }))
        .sort((a, b) => (b.score ?? 0) - (a.score ?? 0))

    const hasExtractedData = Object.values(extracted).some(v => v && (!Array.isArray(v) || v.length > 0))

    return (
        <>
            <AppHead title={`Review — ${brand.name}`} />
            <div className="min-h-screen bg-[#0B0B0D] relative">
                {/* Cinematic background */}
                <div
                    className="fixed inset-0 pointer-events-none"
                    style={{
                        background: `radial-gradient(ellipse at 20% 0%, ${accentColor}30, transparent 70%), radial-gradient(ellipse at 80% 100%, ${accentColor}20, transparent 60%), #0B0B0D`,
                    }}
                />
                <div
                    className="fixed inset-0 pointer-events-none"
                    style={{
                        background: `radial-gradient(circle at 60% 30%, ${accentColor}12, transparent 50%)`,
                    }}
                />
                <div className="fixed inset-0 pointer-events-none">
                    <div className="absolute inset-0 bg-black/20" />
                    <div className="absolute inset-0 bg-gradient-to-b from-black/10 via-transparent to-black/40" />
                </div>
                <div
                    className="fixed inset-0 opacity-[0.03] pointer-events-none"
                    style={{
                        backgroundImage: `url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noise'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noise)'/%3E%3C/svg%3E")`,
                    }}
                />

                <div className="relative z-10">
                <AppNav />

                <div className="max-w-4xl mx-auto px-6 pt-10 pb-32">
                    {/* Header */}
                    <div className="mb-10">
                        <div className="flex items-center gap-3 mb-2">
                            <span className="text-white/40 text-sm">v{version.version_number}</span>
                            <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-purple-500/20 text-purple-400">
                                Review
                            </span>
                        </div>
                        <h1 className="text-3xl font-bold text-white tracking-tight">
                            Research Summary
                        </h1>
                        <p className="text-white/50 mt-2 text-base">
                            Here's what we extracted from your brand materials. Review the results, then continue to the builder.
                        </p>
                    </div>

                    <div className="space-y-6">
                        {/* Overall Score + Apply All */}
                        <div className="rounded-2xl bg-white/[0.04] border border-white/[0.06] p-6">
                            <div className="flex items-center justify-between gap-6 flex-wrap">
                                <div className="flex items-center gap-5">
                                    {/* Score ring */}
                                    <div className="relative w-20 h-20 flex-shrink-0">
                                        <svg className="w-20 h-20 -rotate-90" viewBox="0 0 80 80">
                                            <circle cx="40" cy="40" r="34" fill="none" stroke="rgba(255,255,255,0.06)" strokeWidth="6" />
                                            <motion.circle
                                                cx="40" cy="40" r="34" fill="none"
                                                stroke={overallScore >= 80 ? '#34d399' : overallScore >= 50 ? '#fbbf24' : '#f87171'}
                                                strokeWidth="6"
                                                strokeLinecap="round"
                                                strokeDasharray={`${2 * Math.PI * 34}`}
                                                initial={{ strokeDashoffset: 2 * Math.PI * 34 }}
                                                animate={{ strokeDashoffset: 2 * Math.PI * 34 * (1 - overallScore / 100) }}
                                                transition={{ duration: 1, ease: 'easeOut' }}
                                            />
                                        </svg>
                                        <div className="absolute inset-0 flex items-center justify-center">
                                            <span className={`text-xl font-bold ${scoreColor(overallScore)}`}>{overallScore}</span>
                                        </div>
                                    </div>
                                    <div>
                                        <h3 className="text-lg font-semibold text-white/90">Brand Coherence</h3>
                                        <p className="text-sm text-white/40 mt-0.5">{scoreLabel(overallScore)}</p>
                                    </div>
                                </div>

                                {suggestionCount > 0 && (
                                    <span className="inline-flex items-center gap-1.5 text-sm text-white/40">
                                        <svg className="w-4 h-4 text-indigo-400/60" fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={1.5}>
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" />
                                        </svg>
                                        {suggestionCount} suggestions ready in builder
                                    </span>
                                )}
                            </div>
                        </div>

                        {/* Section Breakdown */}
                        {sortedSections.length > 0 && (
                            <div className="rounded-2xl bg-white/[0.04] border border-white/[0.06] p-6">
                                <h3 className="text-sm font-medium text-white/50 uppercase tracking-wider mb-4">Section Health</h3>
                                <div className="space-y-3">
                                    {sortedSections.map(section => {
                                        const sectionScore = section.score ?? 0
                                        return (
                                            <div key={section.key} className="flex items-center gap-3">
                                                <span className="text-white/30 flex-shrink-0">
                                                    {SECTION_ICONS[section.key] || SECTION_ICONS.background}
                                                </span>
                                                <span className="text-sm text-white/70 w-36 flex-shrink-0 truncate">
                                                    {SECTION_LABELS[section.key] || section.key}
                                                </span>
                                                <div className="flex-1 h-1.5 rounded-full bg-white/[0.06] overflow-hidden">
                                                    <motion.div
                                                        className={`h-full rounded-full ${scoreBg(sectionScore)}`}
                                                        initial={{ width: 0 }}
                                                        animate={{ width: `${sectionScore}%` }}
                                                        transition={{ duration: 0.6, ease: 'easeOut', delay: 0.1 }}
                                                    />
                                                </div>
                                                <span className={`text-xs font-medium w-8 text-right ${scoreColor(sectionScore)}`}>
                                                    {sectionScore}
                                                </span>
                                            </div>
                                        )
                                    })}
                                </div>
                            </div>
                        )}

                        {/* Strengths & Risks */}
                        {(strengths.length > 0 || risks.length > 0) && (
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                {strengths.length > 0 && (
                                    <div className="rounded-2xl bg-white/[0.04] border border-white/[0.06] p-5">
                                        <h4 className="text-sm font-medium text-emerald-400/80 mb-3 flex items-center gap-2">
                                            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={2}>
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                            </svg>
                                            Strengths
                                        </h4>
                                        <div className="space-y-2">
                                            {strengths.map((s, i) => (
                                                <div key={s.id || i}>
                                                    <p className="text-sm text-white/70">{s.label}</p>
                                                    {s.detail && <p className="text-xs text-white/35 mt-0.5">{s.detail}</p>}
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                )}
                                {risks.length > 0 && (
                                    <div className="rounded-2xl bg-white/[0.04] border border-white/[0.06] p-5">
                                        <h4 className="text-sm font-medium text-amber-400/80 mb-3 flex items-center gap-2">
                                            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={2}>
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126z" />
                                            </svg>
                                            Needs Attention
                                        </h4>
                                        <div className="space-y-2">
                                            {risks.map((r, i) => (
                                                <div key={r.id || i}>
                                                    <p className="text-sm text-white/70">{r.label}</p>
                                                    {r.detail && <p className="text-xs text-white/35 mt-0.5">{r.detail}</p>}
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                )}
                            </div>
                        )}

                        {/* Extracted Identity Summary */}
                        {hasExtractedData && (
                            <div className="rounded-2xl bg-white/[0.04] border border-white/[0.06] p-6">
                                <h3 className="text-sm font-medium text-white/50 uppercase tracking-wider mb-4">What We Found</h3>
                                <div className="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-4">
                                    {extracted.mission && (
                                        <div>
                                            <span className="text-xs font-medium text-white/35">Mission</span>
                                            <p className="text-sm text-white/70 mt-0.5">{extracted.mission}</p>
                                        </div>
                                    )}
                                    {extracted.positioning && (
                                        <div>
                                            <span className="text-xs font-medium text-white/35">Positioning</span>
                                            <p className="text-sm text-white/70 mt-0.5">{extracted.positioning}</p>
                                        </div>
                                    )}
                                    {extracted.tagline && (
                                        <div>
                                            <span className="text-xs font-medium text-white/35">Tagline</span>
                                            <p className="text-sm text-white/70 mt-0.5">{extracted.tagline}</p>
                                        </div>
                                    )}
                                    {extracted.archetype && (
                                        <div>
                                            <span className="text-xs font-medium text-white/35">Archetype</span>
                                            <p className="text-sm text-white/70 mt-0.5">{extracted.archetype}</p>
                                        </div>
                                    )}
                                    {extracted.tone_keywords && (
                                        <div>
                                            <span className="text-xs font-medium text-white/35">Tone</span>
                                            <div className="flex flex-wrap gap-1.5 mt-1">
                                                {(Array.isArray(extracted.tone_keywords) ? extracted.tone_keywords : [extracted.tone_keywords]).map((t, i) => (
                                                    <span key={i} className="px-2 py-0.5 rounded-md bg-white/[0.06] text-xs text-white/60">{t}</span>
                                                ))}
                                            </div>
                                        </div>
                                    )}
                                    {extracted.voice_description && (
                                        <div>
                                            <span className="text-xs font-medium text-white/35">Voice</span>
                                            <p className="text-sm text-white/70 mt-0.5">{extracted.voice_description}</p>
                                        </div>
                                    )}
                                    {extracted.industry && (
                                        <div>
                                            <span className="text-xs font-medium text-white/35">Industry</span>
                                            <p className="text-sm text-white/70 mt-0.5">{extracted.industry}</p>
                                        </div>
                                    )}
                                    {extracted.target_audience && (
                                        <div>
                                            <span className="text-xs font-medium text-white/35">Target Audience</span>
                                            <p className="text-sm text-white/70 mt-0.5">{extracted.target_audience}</p>
                                        </div>
                                    )}
                                    {extracted.visual_style && (
                                        <div>
                                            <span className="text-xs font-medium text-white/35">Visual Style</span>
                                            <p className="text-sm text-white/70 mt-0.5">{extracted.visual_style}</p>
                                        </div>
                                    )}
                                    {extracted.brand_look && (
                                        <div>
                                            <span className="text-xs font-medium text-white/35">Brand Look</span>
                                            <p className="text-sm text-white/70 mt-0.5">{extracted.brand_look}</p>
                                        </div>
                                    )}
                                </div>

                                {/* Colors + Fonts */}
                                {(extracted.primary_colors.length > 0 || extracted.primary_font) && (
                                    <div className="mt-5 pt-4 border-t border-white/5 flex flex-wrap gap-6">
                                        {extracted.primary_colors.length > 0 && (
                                            <div>
                                                <span className="text-xs font-medium text-white/35 block mb-1.5">Colors</span>
                                                <div className="flex gap-1.5">
                                                    {[...extracted.primary_colors, ...extracted.secondary_colors].map((c, i) => (
                                                        <div
                                                            key={i}
                                                            className="w-7 h-7 rounded-lg border border-white/10"
                                                            style={{ backgroundColor: c }}
                                                            title={c}
                                                        />
                                                    ))}
                                                </div>
                                            </div>
                                        )}
                                        {extracted.primary_font && (
                                            <div>
                                                <span className="text-xs font-medium text-white/35 block mb-1.5">Primary Font</span>
                                                <span className="text-sm text-white/60">{extracted.primary_font}</span>
                                            </div>
                                        )}
                                        {extracted.secondary_font && (
                                            <div>
                                                <span className="text-xs font-medium text-white/35 block mb-1.5">Secondary Font</span>
                                                <span className="text-sm text-white/60">{extracted.secondary_font}</span>
                                            </div>
                                        )}
                                    </div>
                                )}
                            </div>
                        )}
                    </div>
                </div>
                </div>

                {/* Sticky Footer */}
                <div className="fixed bottom-0 inset-x-0 z-20 bg-[#0B0B0D]/80 backdrop-blur-xl border-t border-white/[0.06]">
                    <div className="max-w-4xl mx-auto px-6 py-4 flex items-center justify-between">
                        <button
                            onClick={() => router.visit(route('brands.research.show', { brand: brand.id }))}
                            className="px-4 py-2 text-sm text-white/50 hover:text-white/70 transition"
                        >
                            Back to Research
                        </button>

                        <button
                            onClick={handleAdvanceToBuild}
                            disabled={advancing}
                            className="px-5 py-2.5 rounded-lg text-sm font-medium text-white transition disabled:opacity-50"
                            style={{ backgroundColor: accentColor }}
                        >
                            {advancing ? 'Advancing…' : 'Continue to Builder →'}
                        </button>
                    </div>
                </div>
            </div>
        </>
    )
}
