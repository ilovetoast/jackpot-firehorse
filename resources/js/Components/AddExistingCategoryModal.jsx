/**
 * Add Existing Category modal — select from available system templates.
 */
import { useState, useEffect } from 'react'
import { XMarkIcon, FolderIcon } from '@heroicons/react/24/outline'
import { CategoryIcon } from '../Helpers/categoryIcons'

export default function AddExistingCategoryModal({
    isOpen,
    onClose,
    brandId,
    assetType = 'asset',
    onSuccess,
}) {
    const [templates, setTemplates] = useState([])
    const [loading, setLoading] = useState(false)
    const [submitting, setSubmitting] = useState(false)
    const [error, setError] = useState(null)
    const [entered, setEntered] = useState(false)

    useEffect(() => {
        if (isOpen && brandId) {
            setEntered(false)
            const t = requestAnimationFrame(() => requestAnimationFrame(() => setEntered(true)))
            setLoading(true)
            setError(null)
            fetch(`/app/api/brands/${brandId}/category-form-data`, { credentials: 'same-origin' })
                .then((r) => r.json())
                .then((data) => {
                    const list = data.available_system_templates || []
                    setTemplates(assetType ? list.filter((t) => t.asset_type === assetType) : list)
                })
                .catch(() => {
                    setError('Failed to load available categories.')
                    setTemplates([])
                })
                .finally(() => setLoading(false))
            return () => cancelAnimationFrame(t)
        }
    }, [isOpen, brandId, assetType])

    if (!isOpen) return null

    const getCsrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content || ''

    const handleAdd = async (template) => {
        setSubmitting(true)
        setError(null)
        try {
            const res = await fetch(`/app/brands/${brandId}/categories/add-system-template`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                    Accept: 'application/json',
                },
                credentials: 'same-origin',
                body: JSON.stringify({ system_category_id: template.system_category_id }),
            })
            const data = await res.json().catch(() => ({}))
            if (res.ok) {
                onSuccess?.(template)
                onClose()
                if (typeof window !== 'undefined' && window.toast) {
                    window.toast(`Added "${template.name}"`, 'success')
                }
            } else {
                setError(data.message || data.error || 'Failed to add category.')
            }
        } catch (e) {
            setError('Network error. Please try again.')
        } finally {
            setSubmitting(false)
        }
    }

    return (
        <div
            className="fixed inset-0 z-[100] flex items-start sm:items-center justify-center p-4 pt-8 sm:pt-4 bg-black/50 overflow-y-auto"
            onClick={onClose}
        >
            <div
                className="bg-white rounded-xl shadow-lg max-w-2xl w-full max-h-[90vh] overflow-y-auto transition-all duration-[180ms] ease-[cubic-bezier(0.16,1,0.3,1)]"
                style={{
                    opacity: entered ? 1 : 0,
                    transform: entered ? 'translateY(0)' : 'translateY(8px)',
                }}
                onClick={(e) => e.stopPropagation()}
            >
                <div className="flex items-center justify-between px-6 md:px-8 py-4 border-b border-gray-200">
                    <div className="min-w-0">
                        <h2 className="text-lg font-semibold text-gray-900 truncate">
                            Add Existing Category
                        </h2>
                        <p className="mt-0.5 text-sm text-gray-500">
                            System categories are pre-configured with metadata fields. Changes to field options apply across all brands using this category.
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        className="ml-2 p-1.5 rounded-lg text-gray-500 hover:bg-gray-100 shrink-0"
                        aria-label="Close"
                    >
                        <XMarkIcon className="w-5 h-5" />
                    </button>
                </div>

                <div className="px-6 md:px-8 py-6">
                    {error && (
                        <div className="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700 border border-red-100">
                            {error}
                        </div>
                    )}
                    {loading ? (
                        <div className="py-12 text-center text-sm text-gray-500">Loading…</div>
                    ) : templates.length === 0 ? (
                        <div className="py-12 text-center">
                            <p className="text-sm text-gray-500">No additional system categories available.</p>
                            <p className="mt-1 text-xs text-gray-400">All system categories may already be added to this brand.</p>
                        </div>
                    ) : (
                        <div className="space-y-2">
                            {templates.map((template) => (
                                <button
                                    key={template.system_category_id}
                                    type="button"
                                    onClick={() => handleAdd(template)}
                                    disabled={submitting}
                                    className="flex w-full items-center gap-3 p-3.5 text-left rounded-xl bg-white shadow-sm border border-gray-100 transition-all duration-150 ease-out hover:shadow-md hover:bg-gray-50/80 disabled:opacity-50 disabled:cursor-not-allowed"
                                >
                                    <span className="flex items-center justify-center w-8 h-8 rounded-lg shrink-0 bg-gray-100">
                                        <CategoryIcon
                                            iconId={template.icon || 'folder'}
                                            className="w-4 h-4 text-gray-600"
                                        />
                                    </span>
                                    <div className="flex-1 min-w-0">
                                        <span className="block text-sm font-medium text-gray-900">
                                            {template.name}
                                        </span>
                                        <span className="block text-xs text-gray-500 mt-0.5">
                                            {template.slug} (v{template.system_version})
                                            {template.is_private && ' • Private'}
                                        </span>
                                    </div>
                                    <span className="text-xs font-medium text-indigo-600 shrink-0">
                                        Add
                                    </span>
                                </button>
                            ))}
                        </div>
                    )}
                </div>
            </div>
        </div>
    )
}
