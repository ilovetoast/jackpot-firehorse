import { useState } from 'react'
import { Link, usePage } from '@inertiajs/react'
import AppNav from '../../../Components/AppNav'
import AppHead from '../../../Components/AppHead'

// Darken hex for gradient background
function darkenHex(hex, amount = 0.3) {
    if (!hex || !hex.startsWith('#')) return '#1e1b4b'
    const num = parseInt(hex.slice(1), 16)
    const r = Math.max(0, ((num >> 16) & 0xff) * (1 - amount))
    const g = Math.max(0, ((num >> 8) & 0xff) * (1 - amount))
    const b = Math.max(0, (num & 0xff) * (1 - amount))
    return `#${Math.round(r).toString(16).padStart(2, '0')}${Math.round(g).toString(16).padStart(2, '0')}${Math.round(b).toString(16).padStart(2, '0')}`
}

function copyToClipboard(text) {
    navigator.clipboard?.writeText(text).then(() => {
        // Optional: show brief toast
    })
}

export default function BrandGuidelinesIndex({ brand, brandModel, modelPayload, hasActiveVersion }) {
    const { auth } = usePage().props
    const [copiedHex, setCopiedHex] = useState(null)
    const isEnabled = brandModel?.is_enabled ?? false
    const showCallout = !isEnabled || !hasActiveVersion

    const identity = modelPayload?.identity ?? {}
    const typography = modelPayload?.typography ?? {}
    const personality = modelPayload?.personality ?? {}
    const visual = modelPayload?.visual ?? {}
    const scoringRules = modelPayload?.scoring_rules ?? {}

    const primaryColor = brand.primary_color || '#6366f1'
    const secondaryColor = brand.secondary_color || '#8b5cf6'
    const accentColor = brand.accent_color || '#06b6d4'
    const primaryDark = darkenHex(primaryColor)
    const tintBg = `${primaryColor}08`

    const logoUrl = auth?.activeBrand?.id === brand.id ? auth?.activeBrand?.logo_thumbnail_url : null
    const heroSubheading = identity.tagline || personality.archetype || brand.name

    const handleCopyHex = (hex, label) => {
        copyToClipboard(hex)
        setCopiedHex(label)
        setTimeout(() => setCopiedHex(null), 1200)
    }

    return (
        <div className="min-h-full">
            <AppHead title="Brand Guidelines" />
            <AppNav brand={auth?.activeBrand} tenant={null} />
            <main className="bg-white">
                {showCallout ? (
                    <div className="min-h-screen flex flex-col items-center justify-center px-4 py-16">
                        <Link
                            href={typeof route === 'function' ? route('brands.edit', { brand: brand.id }) : `/app/brands/${brand.id}/edit`}
                            className="absolute top-20 left-6 text-sm font-medium text-gray-500 hover:text-gray-700"
                        >
                            ← Back to Brand Settings
                        </Link>
                        <div className="max-w-md text-center">
                            <h1 className="text-2xl font-bold text-gray-900">Enable Brand DNA</h1>
                            <p className="mt-4 text-gray-600">
                                Configure your Brand DNA in Brand Settings to generate guidelines.
                            </p>
                            <Link
                                href={typeof route === 'function' ? route('brands.dna.index', { brand: brand.id }) : `/app/brands/${brand.id}/dna`}
                                className="mt-6 inline-flex rounded-md bg-indigo-600 px-6 py-3 text-sm font-medium text-white hover:bg-indigo-700"
                            >
                                Configure Brand DNA
                            </Link>
                        </div>
                    </div>
                ) : (
                    <>
                        {/* 1. Hero Section */}
                        <section
                            className="relative w-full py-32 overflow-hidden"
                            style={{
                                background: `linear-gradient(135deg, ${primaryDark} 0%, ${primaryColor} 50%, ${primaryDark} 100%)`,
                            }}
                        >
                            <div className="absolute top-8 left-6 z-10">
                                <Link
                                    href={typeof route === 'function' ? route('brands.edit', { brand: brand.id }) : `/app/brands/${brand.id}/edit`}
                                    className="text-sm font-medium text-white/80 hover:text-white transition-colors"
                                >
                                    ← Back to Brand Settings
                                </Link>
                            </div>
                            <div className="absolute inset-0 bg-black/40" />
                            <div className="relative mx-auto max-w-5xl px-6 lg:px-8 text-center">
                                {logoUrl && (
                                    <img
                                        src={logoUrl}
                                        alt={brand.name}
                                        className="mx-auto h-24 w-auto object-contain mb-8 opacity-95"
                                    />
                                )}
                                <h1 className="text-5xl md:text-7xl font-bold tracking-tight text-white drop-shadow-lg">
                                    Brand Guidelines
                                </h1>
                                <p className="mt-6 text-xl md:text-2xl text-white/90 font-light max-w-2xl mx-auto">
                                    {heroSubheading}
                                </p>
                            </div>
                        </section>

                        {/* 2. Identity Section */}
                        <section className="py-28" style={{ backgroundColor: tintBg }}>
                            <div className="mx-auto max-w-6xl px-6 lg:px-8">
                                <div className="grid grid-cols-1 lg:grid-cols-12 gap-16">
                                    <div className="lg:col-span-8">
                                        {identity.mission && (
                                            <blockquote className="text-3xl md:text-4xl font-light text-gray-900 leading-relaxed border-l-4 pl-8 border-gray-300">
                                                "{identity.mission}"
                                            </blockquote>
                                        )}
                                        {identity.positioning && (
                                            <p className="mt-10 text-lg text-gray-700 max-w-3xl leading-relaxed">
                                                {identity.positioning}
                                            </p>
                                        )}
                                        {!identity.mission && !identity.positioning && (
                                            <p className="text-2xl font-light text-gray-500">{brand.name}</p>
                                        )}
                                    </div>
                                    <div className="lg:col-span-4 space-y-6 text-sm text-gray-500">
                                        {identity.industry && (
                                            <div>
                                                <span className="font-medium text-gray-400 uppercase tracking-wider">Industry</span>
                                                <p className="mt-1 text-gray-700">{identity.industry}</p>
                                            </div>
                                        )}
                                        {identity.target_audience && (
                                            <div>
                                                <span className="font-medium text-gray-400 uppercase tracking-wider">Target Audience</span>
                                                <p className="mt-1 text-gray-700 whitespace-pre-wrap">{identity.target_audience}</p>
                                            </div>
                                        )}
                                    </div>
                                </div>
                            </div>
                        </section>

                        {/* 3. Color System */}
                        <section className="py-24">
                            <div className="mx-auto max-w-6xl px-6 lg:px-8">
                                <h2 className="text-2xl font-semibold text-gray-900 mb-12">Color System</h2>
                                <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
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
                                            {copiedHex === label && (
                                                <span className="absolute top-3 right-3 text-xs font-medium text-white bg-black/30 px-2 py-1 rounded">
                                                    Copied
                                                </span>
                                            )}
                                        </button>
                                    ))}
                                </div>
                                {scoringRules?.allowed_color_palette?.length > 0 && (
                                    <div className="mt-12">
                                        <p className="text-sm font-medium text-gray-500 uppercase tracking-wider mb-4">Allowed Palette</p>
                                        <div className="flex flex-wrap gap-4">
                                            {scoringRules.allowed_color_palette.map((c, i) => {
                                                const hex = typeof c === 'string' ? c : c?.hex
                                                const role = typeof c === 'object' ? c?.role : null
                                                const isHex = hex && String(hex).startsWith('#')
                                                return (
                                                    <div
                                                        key={i}
                                                        className="w-20 h-20 rounded-xl transition-shadow hover:shadow-lg flex flex-col items-center justify-center gap-1"
                                                        style={{
                                                            backgroundColor: isHex ? hex : '#e5e7eb',
                                                        }}
                                                        title={hex + (role ? ` (${role})` : '')}
                                                    >
                                                        {!isHex && <span className="text-[10px] font-mono text-gray-500 px-1 truncate max-w-full">{hex}</span>}
                                                        {role && <span className="text-[9px] text-white/80 bg-black/20 px-1 rounded">{role}</span>}
                                                    </div>
                                                )
                                            })}
                                        </div>
                                    </div>
                                )}
                            </div>
                        </section>

                        {/* 4. Typography */}
                        <section className="py-24" style={{ backgroundColor: tintBg }}>
                            <div className="mx-auto max-w-6xl px-6 lg:px-8">
                                <h2 className="text-2xl font-semibold text-gray-900 mb-12">Typography</h2>
                                <div className="space-y-12">
                                    {typography.primary_font && (
                                        <div>
                                            <p
                                                className="text-4xl md:text-5xl font-bold text-gray-900"
                                                style={{ fontFamily: `${typography.primary_font}, system-ui, sans-serif` }}
                                            >
                                                The quick brown fox jumps over the lazy dog.
                                            </p>
                                            <p className="mt-2 text-sm text-gray-500">{typography.primary_font}</p>
                                        </div>
                                    )}
                                    {typography.secondary_font && (
                                        <div>
                                            <p
                                                className="text-lg md:text-xl text-gray-700 leading-relaxed max-w-2xl"
                                                style={{ fontFamily: `${typography.secondary_font}, system-ui, sans-serif` }}
                                            >
                                                Use this font for body copy, captions, and supporting text. It should feel readable and on-brand across all applications.
                                            </p>
                                            <p className="mt-2 text-sm text-gray-500">{typography.secondary_font}</p>
                                        </div>
                                    )}
                                    {(typography.heading_style || typography.body_style) && (
                                        <div className="pt-8 border-t border-gray-200/60 space-y-2">
                                            {typography.heading_style && <p className="text-sm text-gray-600"><span className="font-medium text-gray-500">Heading:</span> {typography.heading_style}</p>}
                                            {typography.body_style && <p className="text-sm text-gray-600"><span className="font-medium text-gray-500">Body:</span> {typography.body_style}</p>}
                                        </div>
                                    )}
                                    {!typography.primary_font && !typography.secondary_font && (
                                        <p className="text-gray-500 italic">No typography configured.</p>
                                    )}
                                </div>
                            </div>
                        </section>

                        {/* 5. Personality Section */}
                        <section className="py-28">
                            <div className="mx-auto max-w-4xl px-6 lg:px-8 text-center">
                                {personality.archetype && (
                                    <h2 className="text-5xl md:text-6xl font-bold text-gray-900 tracking-tight">
                                        {personality.archetype}
                                    </h2>
                                )}
                                {personality.traits?.length > 0 && (
                                    <div className="mt-10 flex flex-wrap justify-center gap-3">
                                        {personality.traits.map((t, i) => (
                                            <span
                                                key={i}
                                                className="inline-flex items-center rounded-full px-5 py-2 text-sm font-medium bg-gray-100 text-gray-800 ring-1 ring-gray-200/80 hover:bg-gray-200/80 hover:scale-105 transition-all duration-200"
                                            >
                                                {t}
                                            </span>
                                        ))}
                                    </div>
                                )}
                                {(personality.tone || personality.voice_description) && (
                                    <div className="mt-12 max-w-2xl mx-auto">
                                        {personality.tone && <p className="text-lg font-medium text-gray-700">{personality.tone}</p>}
                                        {personality.voice_description && (
                                            <p className="mt-4 text-gray-600 leading-relaxed whitespace-pre-wrap">
                                                {personality.voice_description}
                                            </p>
                                        )}
                                    </div>
                                )}
                                {(!personality.archetype && !personality.tone && (!personality.traits || personality.traits.length === 0)) && (
                                    <p className="text-gray-500 italic">No personality configured.</p>
                                )}
                            </div>
                        </section>

                        {/* 6. Photography - Dark Section */}
                        <section
                            className="py-28 text-white"
                            style={{
                                background: `linear-gradient(180deg, ${primaryDark} 0%, #0f0f23 100%)`,
                            }}
                        >
                            <div className="mx-auto max-w-6xl px-6 lg:px-8">
                                <h2 className="text-2xl font-semibold mb-8">Photography Style</h2>
                                {visual.photography_style && (
                                    <p className="text-xl text-white/95 max-w-3xl leading-relaxed">
                                        {visual.photography_style}
                                    </p>
                                )}
                                {visual.composition_style && (
                                    <p className="mt-6 text-lg text-white/80">{visual.composition_style}</p>
                                )}
                                {scoringRules?.photography_attributes?.length > 0 && (
                                    <div className="mt-10 flex flex-wrap gap-3">
                                        {scoringRules.photography_attributes.map((a, i) => (
                                            <span
                                                key={i}
                                                className="inline-flex items-center rounded-lg bg-white/10 backdrop-blur-sm px-4 py-2 text-sm font-medium text-white/95 ring-1 ring-white/20"
                                            >
                                                {a}
                                            </span>
                                        ))}
                                    </div>
                                )}
                                {(!visual.photography_style && (!scoringRules?.photography_attributes || scoringRules.photography_attributes.length === 0)) && (
                                    <p className="text-white/60 italic">No photography style configured.</p>
                                )}
                            </div>
                        </section>
                    </>
                )}
            </main>
        </div>
    )
}
