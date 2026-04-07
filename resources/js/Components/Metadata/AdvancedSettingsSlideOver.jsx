import { useEffect, useState } from 'react'
import { ArrowPathIcon, BookmarkIcon, EyeIcon, Squares2X2Icon, XMarkIcon } from '@heroicons/react/24/outline'

/**
 * Advanced Settings slide-over for category metadata configuration (Reset + Profiles).
 *
 * SUNSET (2026): "Copy configuration from another category" and "Apply to other brands" were removed from the UI.
 * Backend remains for now: TenantMetadataRegistryController::copyCategoryFrom, getApplyToOtherBrandsTargets,
 * applyToOtherBrands (+ routes). Delete those endpoints when no longer needed.
 */
export default function AdvancedSettingsSlideOver({
    isOpen,
    onClose,
    categoriesForBrand: _categoriesForBrand,
    selectedCategoryId: _selectedCategoryId,
    copyFromSourceId: _copyFromSourceId,
    setCopyFromSourceId: _setCopyFromSourceId,
    onCopySettings: _onCopySettings,
    onReset,
    onSaveProfile,
    onApplyProfile,
    onPreviewProfile,
    onApplyToOtherBrands: _onApplyToOtherBrands,
    profiles,
    applyProfileId,
    setApplyProfileId,
    fetchProfiles,
    saveProfileName,
    setSaveProfileName,
    profileAvailableToAllBrands,
    setProfileAvailableToAllBrands,
    brands,
    loading,
    saveProfileLoading,
}) {
    const [slideIn, setSlideIn] = useState(false)

    useEffect(() => {
        if (isOpen) {
            setSlideIn(false)
            const raf = requestAnimationFrame(() => {
                requestAnimationFrame(() => setSlideIn(true))
            })
            fetchProfiles?.()
            return () => cancelAnimationFrame(raf)
        }
    }, [isOpen, fetchProfiles])

    useEffect(() => {
        if (isOpen) document.body.style.overflow = 'hidden'
        return () => { document.body.style.overflow = '' }
    }, [isOpen])

    useEffect(() => {
        if (!isOpen) return
        const handleEscape = (e) => {
            if (e.key === 'Escape' && !loading) onClose()
        }
        document.addEventListener('keydown', handleEscape)
        return () => document.removeEventListener('keydown', handleEscape)
    }, [isOpen, onClose, loading])

    if (!isOpen) return null

    const handleApply = () => {
        if (applyProfileId) onApplyProfile()
    }

    return (
        <>
            <div
                className="fixed inset-0 z-[75] bg-black/20 backdrop-blur-sm transition-opacity duration-300"
                onClick={onClose}
                aria-hidden="true"
            />
            <div
                className={`fixed inset-y-0 right-0 z-[80] w-full max-w-md bg-white shadow-xl flex flex-col rounded-l-lg transition-transform duration-300 ease-out ${
                    slideIn ? 'translate-x-0' : 'translate-x-full'
                }`}
                role="dialog"
                aria-modal="true"
                aria-labelledby="advanced-settings-title"
            >
                <div className="flex items-center justify-between px-6 py-4 border-b border-gray-200">
                    <h2 id="advanced-settings-title" className="text-lg font-semibold text-gray-900">
                        Advanced Settings
                    </h2>
                    <button
                        type="button"
                        onClick={onClose}
                        className="p-2 -m-2 rounded-lg text-gray-400 hover:text-gray-600 hover:bg-gray-100"
                        aria-label="Close"
                    >
                        <XMarkIcon className="h-5 w-5" />
                    </button>
                </div>

                <div className="flex-1 overflow-y-auto px-6 py-6 space-y-8">
                    {/* SUNSET: Copy Configuration UI removed — see file-level docblock. */}

                    {/* Section 1: Reset */}
                    <section>
                        <h3 className="text-sm font-semibold text-gray-900 mb-1">
                            Reset
                        </h3>
                        <p className="text-sm text-gray-600 mb-3">
                            Revert this category to its original system configuration.
                        </p>
                        <div className="space-y-2">
                            <button
                                type="button"
                                onClick={onReset}
                                disabled={loading}
                                className="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                <ArrowPathIcon className="h-4 w-4" />
                                Reset to System Default
                            </button>
                        </div>
                    </section>

                    <hr className="border-gray-200" />

                    {/* Section 2: Profiles */}
                    <section>
                        <h3 className="text-sm font-semibold text-gray-900 mb-1">
                            Profiles
                        </h3>
                        <p className="text-sm text-gray-600 mb-3">
                            Profiles allow you to reuse field visibility setups across categories.
                        </p>
                        <div className="space-y-6">
                            {/* Save current config as profile */}
                            <div>
                                <label htmlFor="profile-name" className="block text-sm font-medium text-gray-700 mb-1">
                                    Save current config as profile
                                </label>
                                <div className="flex gap-2">
                                    <input
                                        id="profile-name"
                                        type="text"
                                        value={saveProfileName}
                                        onChange={(e) => setSaveProfileName(e.target.value)}
                                        placeholder="e.g. Graphics Standard"
                                        className="flex-1 rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                                        disabled={saveProfileLoading}
                                        onKeyDown={(e) => e.key === 'Enter' && onSaveProfile()}
                                    />
                                    <button
                                        type="button"
                                        onClick={onSaveProfile}
                                        disabled={!saveProfileName?.trim() || saveProfileLoading}
                                        className="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed"
                                    >
                                        <BookmarkIcon className="h-4 w-4" />
                                        {saveProfileLoading ? 'Saving…' : 'Save'}
                                    </button>
                                </div>
                                {brands?.length > 1 && (
                                    <label className="mt-2 flex items-center gap-2 cursor-pointer">
                                        <input
                                            type="checkbox"
                                            checked={profileAvailableToAllBrands}
                                            onChange={(e) => setProfileAvailableToAllBrands(e.target.checked)}
                                            disabled={saveProfileLoading}
                                            className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                        />
                                        <span className="text-xs text-gray-600">Available to all brands</span>
                                    </label>
                                )}
                            </div>

                            {/* Apply profile to this category */}
                            <div>
                                <label htmlFor="apply-profile-select" className="block text-sm font-medium text-gray-700 mb-1">
                                    Apply profile to this category
                                </label>
                                <select
                                    id="apply-profile-select"
                                    value={applyProfileId ?? ''}
                                    onChange={(e) =>
                                        setApplyProfileId(e.target.value ? parseInt(e.target.value, 10) : null)
                                    }
                                    className="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 mb-2"
                                    disabled={loading}
                                >
                                    <option value="">Select profile…</option>
                                    {profiles?.map((p) => (
                                        <option key={p.id} value={p.id}>
                                            {p.name}
                                            {p.category_slug ? ` (${p.category_slug})` : ''}
                                        </option>
                                    ))}
                                </select>
                                <div className="flex gap-2">
                                    <button
                                        type="button"
                                        onClick={() => applyProfileId && onPreviewProfile()}
                                        disabled={!applyProfileId || loading}
                                        className="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
                                    >
                                        <EyeIcon className="h-4 w-4" />
                                        Preview profile
                                    </button>
                                    <button
                                        type="button"
                                        onClick={handleApply}
                                        disabled={!applyProfileId || loading}
                                        className="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed"
                                    >
                                        <Squares2X2Icon className="h-4 w-4" />
                                        Apply
                                    </button>
                                </div>
                            </div>
                        </div>
                    </section>

                    {/* SUNSET: "Apply to other brands" UI removed — see file-level docblock. */}
                </div>
            </div>
        </>
    )
}
