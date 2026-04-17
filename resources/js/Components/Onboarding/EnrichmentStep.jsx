import { useState, useCallback, useRef } from 'react'
import { motion } from 'framer-motion'
import {
    DocumentTextIcon,
    GlobeAltIcon,
    BuildingOfficeIcon,
    ArrowPathIcon,
} from '@heroicons/react/24/outline'

const INDUSTRY_OPTIONS = [
    'Technology', 'Healthcare', 'Finance', 'Education', 'Retail',
    'Real Estate', 'Food & Beverage', 'Fashion', 'Travel & Hospitality',
    'Entertainment', 'Manufacturing', 'Nonprofit', 'Professional Services',
    'Sports & Fitness', 'Other',
]

export default function EnrichmentStep({ brandColor = '#6366f1', initialWebsiteUrl, onSave, onBack, onSkip }) {
    const [websiteUrl, setWebsiteUrl] = useState(initialWebsiteUrl || '')
    const [industry, setIndustry] = useState('')
    const [guidelineFile, setGuidelineFile] = useState(null)
    const [saving, setSaving] = useState(false)
    const [dragOver, setDragOver] = useState(false)
    const fileRef = useRef(null)

    const handleGuideDrop = useCallback((e) => {
        e.preventDefault()
        setDragOver(false)
        const file = e.dataTransfer.files?.[0]
        if (file) setGuidelineFile(file)
    }, [])

    const hasAny = websiteUrl.trim() || industry || guidelineFile

    const handleSave = useCallback(async () => {
        if (saving) return
        setSaving(true)
        try {
            let guidelineAssetId = null
            if (guidelineFile) {
                const formData = new FormData()
                formData.append('guideline', guidelineFile)

                const response = await fetch('/app/onboarding/upload-guideline', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    },
                })
                if (response.ok) {
                    const json = await response.json()
                    guidelineAssetId = json.asset_id ?? null
                }
            }

            await onSave({
                website_url: websiteUrl.trim() || null,
                industry: industry || null,
                guideline_uploaded: !!guidelineAssetId,
                guideline_asset_id: guidelineAssetId,
            })
        } finally {
            setSaving(false)
        }
    }, [websiteUrl, industry, guidelineFile, saving, onSave])

    return (
        <motion.div
            initial={{ opacity: 0, y: 16 }}
            animate={{ opacity: 1, y: 0 }}
            exit={{ opacity: 0, y: -12 }}
            transition={{ duration: 0.4 }}
            className="w-full max-w-3xl mx-auto"
        >
            <div className="grid grid-cols-1 lg:grid-cols-5 gap-8 lg:gap-12 items-start">
                {/* Left: form (3 cols) */}
                <div className="lg:col-span-3">
                    <h2 className="text-2xl sm:text-3xl font-semibold tracking-tight text-white/95">
                        Give your workspace a head start
                    </h2>
                    <p className="mt-2 text-sm text-white/40 leading-relaxed">
                        Optional — share context and Jackpot will begin building your brand profile in the background.
                    </p>

                    <div className="mt-8 space-y-6">
                        {/* Website URL */}
                        <div>
                            <label className="flex items-center gap-2 text-sm font-medium text-white/60 mb-2">
                                <GlobeAltIcon className="h-4 w-4 text-white/30" />
                                Website URL
                            </label>
                            <input
                                type="url"
                                value={websiteUrl}
                                onChange={e => setWebsiteUrl(e.target.value)}
                                placeholder="https://yourbrand.com"
                                className="w-full px-4 py-3 bg-white/[0.04] border border-white/[0.08] rounded-xl text-white placeholder-white/20 focus:outline-none focus:border-white/20 transition-colors"
                            />
                            <p className="mt-1.5 text-xs text-white/25">
                                We'll analyze your site to extract brand colors, fonts, and tone.
                            </p>
                        </div>

                        {/* Industry */}
                        <div>
                            <label className="flex items-center gap-2 text-sm font-medium text-white/60 mb-2">
                                <BuildingOfficeIcon className="h-4 w-4 text-white/30" />
                                Industry
                            </label>
                            <select
                                value={industry}
                                onChange={e => setIndustry(e.target.value)}
                                className="w-full px-4 py-3 bg-white/[0.04] border border-white/[0.08] rounded-xl text-white/70 focus:outline-none focus:border-white/20 transition-colors appearance-none cursor-pointer"
                            >
                                <option value="" className="bg-[#1a1a1f] text-white/50">Select industry</option>
                                {INDUSTRY_OPTIONS.map(opt => (
                                    <option key={opt} value={opt} className="bg-[#1a1a1f] text-white">
                                        {opt}
                                    </option>
                                ))}
                            </select>
                        </div>

                        {/* Brand guidelines upload */}
                        <div>
                            <label className="flex items-center gap-2 text-sm font-medium text-white/60 mb-2">
                                <DocumentTextIcon className="h-4 w-4 text-white/30" />
                                Brand guidelines PDF
                            </label>
                            {guidelineFile ? (
                                <div className="flex items-center gap-3 rounded-xl px-4 py-3 bg-white/[0.04] border border-white/[0.06]">
                                    <DocumentTextIcon className="h-5 w-5 shrink-0" style={{ color: `${brandColor}99` }} />
                                    <div className="min-w-0 flex-1">
                                        <p className="text-sm text-white/70 truncate">{guidelineFile.name}</p>
                                        <p className="text-xs text-white/30">
                                            {(guidelineFile.size / (1024 * 1024)).toFixed(1)} MB
                                        </p>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={() => setGuidelineFile(null)}
                                        className="text-xs text-white/30 hover:text-white/50 transition-colors"
                                    >
                                        Remove
                                    </button>
                                </div>
                            ) : (
                                <div
                                    role="button"
                                    tabIndex={0}
                                    onClick={() => fileRef.current?.click()}
                                    onKeyDown={e => { if (e.key === 'Enter' || e.key === ' ') fileRef.current?.click() }}
                                    onDrop={handleGuideDrop}
                                    onDragOver={e => { e.preventDefault(); setDragOver(true) }}
                                    onDragLeave={() => setDragOver(false)}
                                    className={`w-full rounded-xl border-2 border-dashed px-4 py-4 text-center transition-all duration-200 cursor-pointer ${
                                        dragOver ? 'border-opacity-60 bg-opacity-10' : 'border-white/10 hover:border-white/20'
                                    }`}
                                    style={{
                                        borderColor: dragOver ? brandColor : undefined,
                                        backgroundColor: dragOver ? `${brandColor}08` : undefined,
                                    }}
                                >
                                    <p className="text-sm text-white/40">
                                        {dragOver ? 'Drop to upload' : 'Drop PDF or click to browse'}
                                    </p>
                                </div>
                            )}
                            <input
                                ref={fileRef}
                                type="file"
                                accept=".pdf"
                                className="hidden"
                                onChange={e => {
                                    if (e.target.files?.[0]) setGuidelineFile(e.target.files[0])
                                    e.target.value = ''
                                }}
                            />
                            <p className="mt-1.5 text-xs text-white/25">
                                We'll process this in the background to help populate your brand strategy.
                            </p>
                        </div>
                    </div>

                    {/* Actions */}
                    <div className="mt-8 flex items-center gap-3">
                        <button
                            type="button"
                            onClick={onBack}
                            className="px-5 py-2.5 rounded-xl text-sm font-medium text-white/40 hover:text-white/60 transition-colors"
                        >
                            Back
                        </button>
                        {hasAny ? (
                            <button
                                type="button"
                                onClick={handleSave}
                                disabled={saving}
                                className="px-6 py-2.5 rounded-xl text-sm font-semibold text-white transition-all duration-300 hover:brightness-110 disabled:opacity-40"
                                style={{
                                    background: `linear-gradient(135deg, ${brandColor}, ${brandColor}dd)`,
                                    boxShadow: `0 4px 16px ${brandColor}30`,
                                }}
                            >
                                {saving ? 'Saving…' : 'Continue'}
                            </button>
                        ) : (
                            <button
                                type="button"
                                onClick={onSkip}
                                className="px-6 py-2.5 rounded-xl text-sm font-semibold text-white/60 bg-white/[0.06] hover:bg-white/[0.1] transition-colors"
                            >
                                Skip for now
                            </button>
                        )}
                    </div>
                </div>

                {/* Right: info panel (2 cols) */}
                <div className="hidden lg:block lg:col-span-2 pt-8">
                    <div
                        className="rounded-2xl border border-white/[0.06] p-5"
                        style={{
                            background: `linear-gradient(135deg, ${brandColor}08, rgba(12,12,14,0.6))`,
                        }}
                    >
                        <div className="flex items-center gap-2 mb-3">
                            <ArrowPathIcon className="h-4 w-4" style={{ color: `${brandColor}99` }} />
                            <p className="text-xs font-semibold uppercase tracking-wider" style={{ color: `${brandColor}99` }}>
                                Background processing
                            </p>
                        </div>
                        <p className="text-sm text-white/50 leading-relaxed">
                            Jackpot analyzes your guidelines and website to extract brand standards,
                            color palettes, typography, and tone — surfaced later in Brand DNA.
                        </p>
                        <p className="mt-3 text-sm text-white/50 leading-relaxed">
                            You can continue using the workspace while this runs. Results typically appear
                            within a few minutes.
                        </p>
                    </div>
                </div>
            </div>
        </motion.div>
    )
}
