import { useEffect } from 'react'
import { BookmarkIcon, EyeIcon, XMarkIcon } from '@heroicons/react/24/outline'

/**
 * Focused modal for applying a saved profile to the current category.
 * Select profile + Preview + Apply. Apply opens parent's confirm flow.
 */
export default function ApplyProfileModal({
    isOpen,
    onClose,
    profiles,
    applyProfileId,
    setApplyProfileId,
    fetchProfiles,
    onPreviewProfile,
    onApplyProfileClick,
    loading,
}) {
    useEffect(() => {
        if (!isOpen) return
        const handleEscape = (e) => {
            if (e.key === 'Escape' && !loading) onClose()
        }
        document.addEventListener('keydown', handleEscape)
        return () => document.removeEventListener('keydown', handleEscape)
    }, [isOpen, onClose, loading])

    useEffect(() => {
        if (isOpen) document.body.style.overflow = 'hidden'
        return () => { document.body.style.overflow = '' }
    }, [isOpen])

    useEffect(() => {
        if (isOpen) fetchProfiles()
    }, [isOpen, fetchProfiles])

    if (!isOpen) return null

    const handleApply = () => {
        if (applyProfileId) {
            onApplyProfileClick()
            onClose()
        }
    }

    const handlePreview = async () => {
        if (applyProfileId) {
            await onPreviewProfile()
            onClose() // Close modal so user sees preview banner in main content
        }
    }

    return (
        <div className="fixed inset-0 z-50 overflow-y-auto">
            <div className="flex min-h-full items-center justify-center p-4">
                <div
                    className="fixed inset-0 bg-gray-500/75"
                    onClick={() => !loading && onClose()}
                    aria-hidden="true"
                />
                <div className="relative bg-white rounded-lg shadow-xl w-full max-w-sm p-5">
                    <div className="flex items-center justify-between mb-4">
                        <h3 className="text-base font-semibold text-gray-900">Apply profile</h3>
                        <button
                            type="button"
                            onClick={onClose}
                            disabled={loading}
                            className="p-1 rounded text-gray-400 hover:text-gray-600 hover:bg-gray-100"
                            aria-label="Close"
                        >
                            <XMarkIcon className="w-5 h-5" />
                        </button>
                    </div>
                    <p className="text-sm text-gray-500 mb-4">
                        Apply a saved visibility profile to this category. This will overwrite current settings.
                    </p>
                    <div className="space-y-4">
                        <div>
                            <label htmlFor="apply-profile-select" className="block text-sm font-medium text-gray-700 mb-1">
                                Profile
                            </label>
                            <select
                                id="apply-profile-select"
                                value={applyProfileId ?? ''}
                                onChange={(e) => setApplyProfileId(e.target.value ? parseInt(e.target.value, 10) : null)}
                                className="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                                disabled={loading}
                            >
                                <option value="">Select profile…</option>
                                {profiles.map(p => (
                                    <option key={p.id} value={p.id}>
                                        {p.name}{p.category_slug ? ` (${p.category_slug})` : ''}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="flex flex-wrap gap-2 pt-2">
                            <button
                                type="button"
                                onClick={handlePreview}
                                disabled={!applyProfileId || loading}
                                className="inline-flex items-center gap-1.5 rounded-md bg-gray-100 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200 disabled:opacity-50 disabled:cursor-not-allowed"
                                title="Preview profile without saving"
                            >
                                <EyeIcon className="h-4 w-4" />
                                Preview
                            </button>
                            <button
                                type="button"
                                onClick={handleApply}
                                disabled={!applyProfileId || loading}
                                className="inline-flex items-center gap-1.5 rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed"
                                title="Apply selected profile"
                            >
                                <BookmarkIcon className="h-4 w-4" />
                                Apply
                            </button>
                            <button
                                type="button"
                                onClick={onClose}
                                disabled={loading}
                                className="rounded-md px-3 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 disabled:opacity-50"
                            >
                                Cancel
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    )
}
