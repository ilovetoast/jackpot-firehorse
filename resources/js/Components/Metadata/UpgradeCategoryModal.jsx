import { useState, useEffect } from 'react'
import { ArrowPathIcon, XMarkIcon } from '@heroicons/react/24/outline'

/**
 * Focused modal for upgrading a system category to the latest version.
 * Shows current vs new version and confirms the upgrade action.
 */
export default function UpgradeCategoryModal({
    isOpen,
    onClose,
    category,
    brandId,
    onConfirm,
    loading,
}) {
    const [preview, setPreview] = useState(null)
    const [previewLoading, setPreviewLoading] = useState(false)
    const [previewError, setPreviewError] = useState(null)

    useEffect(() => {
        if (!isOpen || !category?.id || !brandId) return

        setPreview(null)
        setPreviewError(null)
        setPreviewLoading(true)

        fetch(`/app/brands/${brandId}/categories/${category.id}/upgrade/preview`, {
            headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((r) => r.json())
            .then((data) => {
                if (data.error) {
                    setPreviewError(data.error)
                } else {
                    setPreview(data)
                }
            })
            .catch(() => setPreviewError('Failed to load upgrade preview.'))
            .finally(() => setPreviewLoading(false))
    }, [isOpen, category?.id, brandId])

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

    const handleConfirm = () => {
        onConfirm() // Parent handles close on success
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
                        <h3 className="text-base font-semibold text-gray-900">Upgrade Category</h3>
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

                    {previewLoading && (
                        <div className="flex items-center gap-2 py-6 text-sm text-gray-500">
                            <ArrowPathIcon className="w-5 h-5 animate-spin" />
                            Loading upgrade details…
                        </div>
                    )}

                    {previewError && (
                        <div className="rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">
                            {previewError}
                        </div>
                    )}

                    {preview && !previewError && (
                        <>
                            <p className="text-base font-medium text-gray-900 mb-1">
                                Upgrade to Version {preview.latest_version ?? '—'}?
                            </p>
                            <p className="text-sm text-gray-500 mb-6">
                                Your customizations will be preserved.
                            </p>
                            <div className="flex justify-end gap-2">
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
                                    onClick={handleConfirm}
                                    disabled={loading}
                                    className="inline-flex items-center gap-1.5 rounded-md bg-amber-600 px-3 py-2 text-sm font-medium text-white hover:bg-amber-500 disabled:opacity-50 disabled:cursor-not-allowed"
                                >
                                    {loading ? (
                                        <>
                                            <ArrowPathIcon className="h-4 w-4 animate-spin" />
                                            Upgrading…
                                        </>
                                    ) : (
                                        'Upgrade'
                                    )}
                                </button>
                            </div>
                        </>
                    )}
                </div>
            </div>
        </div>
    )
}
