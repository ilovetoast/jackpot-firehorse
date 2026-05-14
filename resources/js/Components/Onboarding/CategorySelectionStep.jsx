import { useState, useCallback, useMemo } from 'react'
import { motion, AnimatePresence } from 'framer-motion'
import {
    CheckCircleIcon,
    EyeIcon,
    EyeSlashIcon,
    PlusCircleIcon,
} from '@heroicons/react/24/outline'
import { CategoryIcon } from '../../Helpers/categoryIcons'

function SectionHeader({ title, subtitle }) {
    return (
        <div className="mb-4">
            <h3 className="text-sm font-semibold text-white/70 uppercase tracking-wider">{title}</h3>
            {subtitle && <p className="mt-1 text-xs text-white/30">{subtitle}</p>}
        </div>
    )
}

function CategoryToggle({ category, isVisible, onToggle, brandColor }) {
    return (
        <motion.button
            type="button"
            aria-label={`${isVisible ? 'Hide' : 'Show'} folder ${category.name} in the library sidebar`}
            onClick={() => onToggle(category.id)}
            initial={{ opacity: 0, y: 4 }}
            animate={{ opacity: 1, y: 0 }}
            className={`w-full flex items-center gap-3 rounded-xl px-4 py-3 border transition-all duration-200 text-left ${
                isVisible
                    ? 'bg-white/[0.06] border-white/[0.12]'
                    : 'bg-white/[0.02] border-white/[0.04] opacity-50 hover:opacity-70'
            }`}
        >
            <div
                className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg"
                style={{ backgroundColor: isVisible ? `${brandColor}18` : 'rgba(255,255,255,0.04)' }}
            >
                <CategoryIcon
                    iconId={category.icon || 'folder'}
                    className="h-4 w-4"
                    style={{ color: isVisible ? brandColor : 'rgba(255,255,255,0.3)' }}
                />
            </div>
            <span className={`flex-1 text-sm font-medium ${isVisible ? 'text-white/80' : 'text-white/30'}`}>
                {category.name}
            </span>
            {isVisible ? (
                <EyeIcon className="h-4 w-4 text-white/40" />
            ) : (
                <EyeSlashIcon className="h-4 w-4 text-white/20" />
            )}
        </motion.button>
    )
}

export default function CategorySelectionStep({
    brandColor = '#6366f1',
    categories = [],
    onSave,
    onBack,
    onSkip,
}) {
    const assetCategories = useMemo(
        () => categories.filter(c => c.asset_type === 'asset'),
        [categories],
    )
    const deliverableCategories = useMemo(
        () => categories.filter(c => c.asset_type === 'deliverable'),
        [categories],
    )

    const [visibility, setVisibility] = useState(() => {
        const map = {}
        categories.forEach(c => { map[c.id] = !c.is_hidden })
        return map
    })

    const [customSuggestions, setCustomSuggestions] = useState([''])
    const [saving, setSaving] = useState(false)

    const toggleCategory = useCallback((id) => {
        setVisibility(prev => ({ ...prev, [id]: !prev[id] }))
    }, [])

    const visibleAssetCount = assetCategories.filter(c => visibility[c.id]).length
    const visibleDeliverableCount = deliverableCategories.filter(c => visibility[c.id]).length

    const handleAddSuggestionField = useCallback(() => {
        setCustomSuggestions(prev => prev.length < 3 ? [...prev, ''] : prev)
    }, [])

    const handleSuggestionChange = useCallback((idx, value) => {
        setCustomSuggestions(prev => {
            const next = [...prev]
            next[idx] = value
            return next
        })
    }, [])

    const handleSave = useCallback(async () => {
        if (saving) return
        setSaving(true)

        try {
            const visibleIds = Object.entries(visibility)
                .filter(([, v]) => v)
                .map(([id]) => Number(id))

            const suggestions = customSuggestions.filter(s => s.trim())

            await onSave({ visible_category_ids: visibleIds, custom_suggestions: suggestions })
        } finally {
            setSaving(false)
        }
    }, [visibility, customSuggestions, saving, onSave])

    return (
        <motion.div
            initial={{ opacity: 0, y: 16 }}
            animate={{ opacity: 1, y: 0 }}
            exit={{ opacity: 0, y: -12 }}
            transition={{ duration: 0.4 }}
            className="w-full max-w-3xl mx-auto"
        >
            <h2 className="text-2xl sm:text-3xl font-semibold tracking-tight text-white/95">
                Choose your folders
            </h2>
            <p className="mt-2 text-sm text-white/40 leading-relaxed max-w-xl">
                Pick which asset and execution folders are relevant. Folders organize your library; you can turn hidden
                folders back on anytime from Manage → Folders &amp; filters.
            </p>

            <div className="mt-8 grid grid-cols-1 lg:grid-cols-2 gap-8 lg:gap-12">
                {/* Asset Library Categories */}
                <div>
                    <SectionHeader
                        title="Asset Library"
                        subtitle={`${visibleAssetCount} visible in sidebar`}
                    />
                    <div className="space-y-2">
                        {assetCategories.map(cat => (
                            <CategoryToggle
                                key={cat.id}
                                category={cat}
                                isVisible={visibility[cat.id]}
                                onToggle={toggleCategory}
                                brandColor={brandColor}
                            />
                        ))}
                    </div>
                </div>

                {/* Execution / Deliverable Categories */}
                <div>
                    <SectionHeader
                        title="Executions"
                        subtitle={`${visibleDeliverableCount} visible in sidebar`}
                    />
                    <div className="space-y-2">
                        {deliverableCategories.map(cat => (
                            <CategoryToggle
                                key={cat.id}
                                category={cat}
                                isVisible={visibility[cat.id]}
                                onToggle={toggleCategory}
                                brandColor={brandColor}
                            />
                        ))}
                    </div>
                </div>
            </div>

            {/* Custom Category Suggestions */}
            <div className="mt-8">
                <SectionHeader
                    title="Need something else?"
                    subtitle="Suggest a folder for your industry — you can always create custom folders later."
                />
                <div className="space-y-2 max-w-md">
                    {customSuggestions.map((val, idx) => (
                        <input
                            key={idx}
                            type="text"
                            value={val}
                            onChange={e => handleSuggestionChange(idx, e.target.value)}
                            placeholder={idx === 0 ? 'e.g. Merchandise, Signage, UGC…' : 'Another folder…'}
                            className="w-full rounded-xl bg-white/[0.04] border border-white/[0.08] px-4 py-2.5 text-sm text-white/80 placeholder-white/20 focus:outline-none focus:border-white/20 transition-colors"
                        />
                    ))}
                    {customSuggestions.length < 3 && (
                        <button
                            type="button"
                            onClick={handleAddSuggestionField}
                            className="flex items-center gap-1.5 text-xs text-white/30 hover:text-white/50 transition-colors mt-1"
                        >
                            <PlusCircleIcon className="h-3.5 w-3.5" />
                            Add another
                        </button>
                    )}
                </div>
                <p className="mt-3 text-xs text-white/20">
                    You can create, rename, and manage folders and their filters from Manage → Folders & filters.
                </p>
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
                <button
                    type="button"
                    onClick={handleSave}
                    disabled={saving}
                    className="px-6 py-2.5 rounded-xl text-sm font-semibold text-white transition-all duration-300 hover:brightness-110 disabled:opacity-40 disabled:cursor-not-allowed"
                    style={{
                        background: `linear-gradient(135deg, ${brandColor}, ${brandColor}dd)`,
                        boxShadow: `0 4px 16px ${brandColor}30`,
                    }}
                >
                    {saving ? 'Saving…' : 'Continue'}
                </button>
                {onSkip && (
                    <button
                        type="button"
                        onClick={onSkip}
                        className="text-sm text-white/30 hover:text-white/50 transition-colors"
                    >
                        Skip — use defaults
                    </button>
                )}
            </div>
        </motion.div>
    )
}
