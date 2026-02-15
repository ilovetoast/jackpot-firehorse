import { useEffect } from 'react'
import { DocumentDuplicateIcon, XMarkIcon } from '@heroicons/react/24/outline'

/**
 * Focused modal for copying visibility settings from another category.
 * Select source category + Copy button. On Copy, opens parent's confirm flow.
 */
export default function CopyFromCategoryModal({
    isOpen,
    onClose,
    categoriesForBrand,
    selectedCategoryId,
    copyFromSourceId,
    setCopyFromSourceId,
    onCopyClick,
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

    if (!isOpen) return null

    const sourceOptions = categoriesForBrand.filter(c => c.id !== selectedCategoryId)

    const handleCopy = () => {
        if (copyFromSourceId) {
            onCopyClick()
            onClose()
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
                        <h3 className="text-base font-semibold text-gray-900">Copy from another category</h3>
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
                        Copy visibility settings from a category to this one. This will overwrite current settings.
                    </p>
                    <div className="space-y-4">
                        <div>
                            <label htmlFor="copy-source-category" className="block text-sm font-medium text-gray-700 mb-1">
                                Source category
                            </label>
                            <select
                                id="copy-source-category"
                                value={copyFromSourceId ?? ''}
                                onChange={(e) => setCopyFromSourceId(e.target.value ? parseInt(e.target.value, 10) : null)}
                                className="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                                disabled={loading}
                            >
                                <option value="">Select category…</option>
                                {sourceOptions.map(c => (
                                    <option key={c.id} value={c.id}>{c.name}</option>
                                ))}
                            </select>
                        </div>
                        <div className="flex justify-end gap-2 pt-2">
                            <button
                                type="button"
                                onClick={onClose}
                                disabled={loading}
                                className="rounded-md px-3 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 disabled:opacity-50"
                            >
                                Cancel
                            </button>
                            <button
                                type="button"
                                onClick={handleCopy}
                                disabled={!copyFromSourceId || loading}
                                className="inline-flex items-center gap-1.5 rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                <DocumentDuplicateIcon className="h-4 w-4" />
                                {loading ? 'Copying…' : 'Copy'}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    )
}
