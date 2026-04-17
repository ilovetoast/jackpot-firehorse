import { useState, useCallback, useRef } from 'react'
import { motion, AnimatePresence } from 'framer-motion'
import {
    CloudArrowUpIcon,
    PhotoIcon,
    DocumentTextIcon,
    PaintBrushIcon,
    CheckCircleIcon,
} from '@heroicons/react/24/outline'

const ASSET_CATEGORIES = [
    { key: 'logos', label: 'Logos', icon: PaintBrushIcon, desc: 'Primary logo, alternate marks', accept: '.svg,.png,.jpg,.jpeg,.webp,.ai,.eps,.pdf' },
    { key: 'photos', label: 'Photos', icon: PhotoIcon, desc: 'Brand photography, hero images', accept: '.jpg,.jpeg,.png,.webp,.tiff,.heic' },
    { key: 'graphics', label: 'Graphics', icon: PaintBrushIcon, desc: 'Icons, illustrations, patterns', accept: '.svg,.png,.ai,.eps,.psd,.fig' },
    { key: 'documents', label: 'Documents', icon: DocumentTextIcon, desc: 'Guidelines, templates, PDFs', accept: '.pdf,.docx,.doc,.pptx,.ppt,.txt' },
]

function UploadedFileRow({ file, brandColor, category }) {
    const ext = file.name.split('.').pop()?.toUpperCase() || ''
    const sizeMB = (file.size / (1024 * 1024)).toFixed(1)

    return (
        <motion.div
            initial={{ opacity: 0, x: -8 }}
            animate={{ opacity: 1, x: 0 }}
            className="flex items-center gap-3 rounded-lg px-3 py-2 bg-white/[0.03] border border-white/[0.06]"
        >
            <div
                className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-[10px] font-bold uppercase"
                style={{ backgroundColor: `${brandColor}15`, color: `${brandColor}cc` }}
            >
                {ext.slice(0, 3)}
            </div>
            <div className="min-w-0 flex-1">
                <p className="text-sm text-white/70 truncate">{file.name}</p>
                <p className="text-xs text-white/30">
                    {sizeMB} MB{category ? ` · ${category}` : ''}
                </p>
            </div>
            <CheckCircleIcon className="h-4 w-4 shrink-0" style={{ color: brandColor }} />
        </motion.div>
    )
}

function ThresholdBadge({ met, label }) {
    return (
        <span className={`inline-flex items-center gap-1 text-xs font-medium px-2 py-0.5 rounded-full ${
            met ? 'bg-emerald-500/15 text-emerald-400' : 'bg-white/[0.06] text-white/40'
        }`}>
            {met && <CheckCircleIcon className="h-3 w-3" />}
            {label}
        </span>
    )
}

export default function StarterAssetsStep({
    brandColor = '#6366f1',
    initialCount = 0,
    requiredMin = 1,
    recommendedMin = 3,
    onSave,
    onBack,
}) {
    const [files, setFiles] = useState([])
    const [dragOver, setDragOver] = useState(false)
    const [uploading, setUploading] = useState(false)
    const fileInputRef = useRef(null)
    const categoryInputRef = useRef(null)
    const [activeCategoryKey, setActiveCategoryKey] = useState(null)

    const totalCount = initialCount + files.length
    const meetsRequired = totalCount >= requiredMin
    const meetsRecommended = totalCount >= recommendedMin

    const categoryCounts = files.reduce((acc, f) => {
        if (f._category) acc[f._category] = (acc[f._category] || 0) + 1
        return acc
    }, {})

    const handleFiles = useCallback((newFiles, category = null) => {
        const tagged = Array.from(newFiles).map(f => {
            f._category = category
            return f
        })
        setFiles(prev => [...prev, ...tagged])
    }, [])

    const handleCategoryClick = useCallback((cat) => {
        setActiveCategoryKey(cat.key)
        if (categoryInputRef.current) {
            categoryInputRef.current.accept = cat.accept
            categoryInputRef.current.click()
        }
    }, [])

    const handleDrop = useCallback((e) => {
        e.preventDefault()
        setDragOver(false)
        if (e.dataTransfer.files?.length) handleFiles(e.dataTransfer.files)
    }, [handleFiles])

    const handleContinue = useCallback(async () => {
        if (uploading) return
        setUploading(true)

        try {
            if (files.length > 0) {
                const formData = new FormData()
                files.forEach((file, idx) => {
                    formData.append(`files[${idx}]`, file)
                    if (file._category) {
                        formData.append(`categories[${idx}]`, file._category)
                    }
                })

                const response = await fetch('/app/onboarding/upload-assets', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    },
                })

                if (!response.ok) {
                    console.warn('Upload response not ok:', response.status)
                }
            }

            await onSave(totalCount)
        } finally {
            setUploading(false)
        }
    }, [files, totalCount, uploading, onSave])

    return (
        <motion.div
            initial={{ opacity: 0, y: 16 }}
            animate={{ opacity: 1, y: 0 }}
            exit={{ opacity: 0, y: -12 }}
            transition={{ duration: 0.4 }}
            className="w-full max-w-3xl mx-auto"
        >
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-8 lg:gap-12 items-start">
                <div>
                    <h2 className="text-2xl sm:text-3xl font-semibold tracking-tight text-white/95">
                        Upload starter assets
                    </h2>
                    <p className="mt-2 text-sm text-white/40 leading-relaxed">
                        Add a few core files to start your library. You can always upload more later.
                    </p>

                    {/* Drop zone */}
                    <div className="mt-6">
                        <div
                            onDrop={handleDrop}
                            onDragOver={e => { e.preventDefault(); setDragOver(true) }}
                            onDragLeave={() => setDragOver(false)}
                            onClick={() => fileInputRef.current?.click()}
                            className={`relative cursor-pointer rounded-2xl border-2 border-dashed p-8 text-center transition-all duration-300 ${
                                dragOver ? 'border-opacity-60' : 'border-white/10 hover:border-white/20'
                            }`}
                            style={{
                                borderColor: dragOver ? brandColor : undefined,
                                backgroundColor: dragOver ? `${brandColor}08` : 'rgba(255,255,255,0.02)',
                            }}
                        >
                            <input
                                ref={fileInputRef}
                                type="file"
                                multiple
                                className="hidden"
                                onChange={e => {
                                    if (e.target.files?.length) handleFiles(e.target.files)
                                    e.target.value = ''
                                }}
                            />
                            <input
                                ref={categoryInputRef}
                                type="file"
                                multiple
                                className="hidden"
                                onChange={e => {
                                    if (e.target.files?.length) {
                                        const label = ASSET_CATEGORIES.find(c => c.key === activeCategoryKey)?.label || null
                                        handleFiles(e.target.files, label)
                                    }
                                    e.target.value = ''
                                    setActiveCategoryKey(null)
                                }}
                            />
                            <CloudArrowUpIcon
                                className="mx-auto h-10 w-10 mb-3"
                                style={{ color: dragOver ? brandColor : 'rgba(255,255,255,0.25)' }}
                            />
                            <p className="text-sm text-white/60 font-medium">
                                Drop files here or click to browse
                            </p>
                            <p className="mt-1 text-xs text-white/30">
                                Images, PDFs, videos, design files — any format
                            </p>
                        </div>
                    </div>

                    {/* Progress + threshold badges */}
                    <div className="mt-4 space-y-2">
                        <div className="flex-1 h-1 rounded-full bg-white/[0.06] overflow-hidden">
                            <motion.div
                                className="h-full rounded-full"
                                style={{ backgroundColor: brandColor }}
                                animate={{ width: `${Math.min(100, (totalCount / recommendedMin) * 100)}%` }}
                                transition={{ duration: 0.4 }}
                            />
                        </div>
                        <div className="flex items-center gap-2 flex-wrap">
                            <ThresholdBadge met={meetsRequired} label="Minimum complete" />
                            <ThresholdBadge met={meetsRecommended} label={`${recommendedMin} recommended`} />
                            <span className="text-xs text-white/30 ml-auto">
                                {totalCount} uploaded
                            </span>
                        </div>
                    </div>

                    {/* File list */}
                    {initialCount > 0 && files.length === 0 && (
                        <p className="mt-4 text-xs text-white/30">
                            {initialCount} file{initialCount !== 1 ? 's' : ''} already in your library. Drop more above or pick a category.
                        </p>
                    )}
                    {files.length > 0 && (
                        <div className="mt-4 space-y-1.5 max-h-48 overflow-y-auto [scrollbar-width:thin] [scrollbar-color:rgba(255,255,255,0.1)_transparent]">
                            {initialCount > 0 && (
                                <p className="text-xs text-white/25 mb-2">
                                    + {initialCount} already in library
                                </p>
                            )}
                            {files.map((file, idx) => (
                                <UploadedFileRow key={`${file.name}-${idx}`} file={file} brandColor={brandColor} category={file._category} />
                            ))}
                        </div>
                    )}

                    {/* Actions */}
                    <div className="mt-8 flex items-center gap-3">
                        <button
                            type="button"
                            onClick={onBack}
                            className="px-5 py-2.5 rounded-xl text-sm font-medium text-white/40 hover:text-white/60 transition-colors"
                        >
                            Back
                        </button>
                        <button
                            type="button"
                            onClick={handleContinue}
                            disabled={!meetsRequired || uploading}
                            className="px-6 py-2.5 rounded-xl text-sm font-semibold text-white transition-all duration-300 hover:brightness-110 disabled:opacity-40 disabled:cursor-not-allowed"
                            style={{
                                background: meetsRequired
                                    ? `linear-gradient(135deg, ${brandColor}, ${brandColor}dd)`
                                    : undefined,
                                backgroundColor: meetsRequired ? undefined : 'rgba(255,255,255,0.06)',
                                boxShadow: meetsRequired ? `0 4px 16px ${brandColor}30` : 'none',
                            }}
                        >
                            {uploading ? 'Uploading…' : 'Continue'}
                        </button>
                        {files.length === 0 && initialCount >= requiredMin && (
                            <button
                                type="button"
                                onClick={() => onSave(totalCount)}
                                className="text-sm text-white/30 hover:text-white/50 transition-colors"
                            >
                                Skip — assets already uploaded
                            </button>
                        )}
                    </div>
                </div>

                {/* Category upload shortcuts */}
                <div className="hidden lg:block pt-8">
                    <p className="text-xs font-semibold uppercase tracking-wider text-white/30 mb-4">
                        Upload by category
                    </p>
                    <div className="space-y-3">
                        {ASSET_CATEGORIES.map((cat, idx) => {
                            const count = categoryCounts[cat.label] || 0
                            return (
                                <motion.button
                                    key={cat.key}
                                    type="button"
                                    onClick={() => handleCategoryClick(cat)}
                                    initial={{ opacity: 0, x: 8 }}
                                    animate={{ opacity: 1, x: 0 }}
                                    transition={{ delay: 0.1 + idx * 0.08, duration: 0.3 }}
                                    className="w-full flex items-center gap-3 rounded-xl px-4 py-3 bg-white/[0.03] border border-white/[0.06] hover:bg-white/[0.06] hover:border-white/[0.12] transition-all duration-200 text-left cursor-pointer group"
                                >
                                    <div
                                        className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg transition-colors duration-200"
                                        style={{ backgroundColor: count > 0 ? `${brandColor}20` : `${brandColor}12` }}
                                    >
                                        {count > 0 ? (
                                            <CheckCircleIcon className="h-4 w-4" style={{ color: brandColor }} />
                                        ) : (
                                            <cat.icon className="h-4 w-4 group-hover:opacity-80 transition-opacity" style={{ color: `${brandColor}99` }} />
                                        )}
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <p className="text-sm font-medium text-white/60 group-hover:text-white/80 transition-colors">{cat.label}</p>
                                        <p className="text-xs text-white/25">{cat.desc}</p>
                                    </div>
                                    {count > 0 && (
                                        <span className="text-[10px] font-semibold px-1.5 py-0.5 rounded-full" style={{ backgroundColor: `${brandColor}20`, color: `${brandColor}cc` }}>
                                            {count}
                                        </span>
                                    )}
                                </motion.button>
                            )
                        })}
                    </div>
                </div>
            </div>
        </motion.div>
    )
}
