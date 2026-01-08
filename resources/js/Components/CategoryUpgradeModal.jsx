import { useState, useEffect } from 'react'
import { router } from '@inertiajs/react'
import { XMarkIcon, ArrowPathIcon } from '@heroicons/react/24/outline'

export default function CategoryUpgradeModal({ category, brandId, isOpen, onClose, onSuccess, open, setOpen, onUpgradeSuccess }) {
    // Support both old and new prop names
    const modalOpen = open !== undefined ? open : isOpen
    const setModalOpen = setOpen || onClose
    const handleSuccess = onUpgradeSuccess || onSuccess
    
    // Get CSRF token from meta tag
    const getCsrfToken = () => {
        return document.querySelector('meta[name="csrf-token"]')?.content || ''
    }
    
    const [preview, setPreview] = useState(null)
    const [loading, setLoading] = useState(false)
    const [previewLoading, setPreviewLoading] = useState(true)
    const [approvedFields, setApprovedFields] = useState([])
    const [error, setError] = useState(null)

    useEffect(() => {
        if (modalOpen && category?.id) {
            loadPreview()
        } else {
            // Reset when modal closes
            setPreview(null)
            setApprovedFields([])
            setError(null)
            setPreviewLoading(true)
        }
    }, [modalOpen, category?.id])

    const loadPreview = async () => {
        if (!brandId || !category?.id) {
            setError('Brand ID and category ID are required')
            setPreviewLoading(false)
            return
        }
        
        setPreviewLoading(true)
        setError(null)
        try {
            const response = await fetch(`/app/brands/${brandId}/categories/${category.id}/upgrade/preview`, {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            })
            
            const contentType = response.headers.get('content-type')
            if (!contentType || !contentType.includes('application/json')) {
                const text = await response.text()
                throw new Error(`Server returned ${response.status}: ${text.substring(0, 100)}`)
            }
            
            if (!response.ok) {
                const errorData = await response.json().catch(() => ({ error: `HTTP ${response.status}` }))
                throw new Error(errorData.error || 'Failed to load upgrade preview')
            }
            
            const data = await response.json()
            
            if (data.error) {
                throw new Error(data.error)
            }
            
            setPreview(data)
            // Auto-approve fields that haven't been customized
            const autoApproved = Object.keys(data.fields || {}).filter(
                field => !data.fields[field].customized
            )
            setApprovedFields(autoApproved)
        } catch (err) {
            setError(err.message || 'Failed to load upgrade preview. Please try again.')
        } finally {
            setPreviewLoading(false)
        }
    }

    const handleFieldToggle = (fieldName) => {
        setApprovedFields(prev => {
            if (prev.includes(fieldName)) {
                return prev.filter(f => f !== fieldName)
            } else {
                return [...prev, fieldName]
            }
        })
    }

            const handleApply = async () => {
                // Allow upgrade even if no fields are selected - this will just update the version number

        setLoading(true)
        setError(null)

        if (!brandId || !category?.id) {
            setError('Brand ID and category ID are required')
            setLoading(false)
            return
        }
        
        try {
            const url = brandId 
                ? `/app/brands/${brandId}/categories/${category.id}/upgrade`
                : `/app/categories/${category.id}/upgrade`
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
                body: JSON.stringify({
                    approved_fields: approvedFields,
                }),
            })

            const contentType = response.headers.get('content-type')
            if (!contentType || !contentType.includes('application/json')) {
                const text = await response.text()
                console.error('Non-JSON response:', text.substring(0, 200))
                throw new Error(`Server returned ${response.status}. Please check the console for details.`)
            }

            const data = await response.json()
            
            if (!response.ok || data.error) {
                console.error('Upgrade error:', data)
                throw new Error(data.error || `HTTP ${response.status}: Failed to apply upgrade`)
            }

            if (handleSuccess) {
                handleSuccess()
            }
        } catch (err) {
            setError(err.message || 'Failed to apply upgrade. Please try again.')
        } finally {
            setLoading(false)
        }
    }

    if (!modalOpen) return null

    const getFieldLabel = (field) => {
        const labels = {
            name: 'Name',
            icon: 'Icon',
            is_private: 'Private',
            is_hidden: 'Hidden',
        }
        return labels[field] || field
    }

    const formatValue = (field, value) => {
        if (field === 'is_private' || field === 'is_hidden') {
            return value ? 'Yes' : 'No'
        }
        return value || '(empty)'
    }

    return (
        <div className="fixed inset-0 z-50 overflow-y-auto">
            <div className="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                <div className="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" onClick={() => setModalOpen(false)} />

                <div className="relative transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-3xl sm:p-6">
                    <div className="absolute right-0 top-0 hidden pr-4 pt-4 sm:block">
                        <button
                            type="button"
                            className="rounded-md bg-white text-gray-400 hover:text-gray-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                            onClick={() => setModalOpen(false)}
                        >
                            <span className="sr-only">Close</span>
                            <XMarkIcon className="h-6 w-6" aria-hidden="true" />
                        </button>
                    </div>

                    <div className="sm:flex sm:items-start">
                        <div className="mx-auto flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-full bg-amber-100 sm:mx-0 sm:h-10 sm:w-10">
                            <ArrowPathIcon className="h-6 w-6 text-amber-600" aria-hidden="true" />
                        </div>
                        <div className="mt-3 text-center sm:ml-4 sm:mt-0 sm:text-left w-full">
                            <h3 className="text-base font-semibold leading-6 text-gray-900">
                                Review Category Update
                            </h3>
                            <div className="mt-2">
                                <p className="text-sm text-gray-500">
                                    A new version of the system category template is available. Review the changes below and select which fields to update.
                                </p>
                            </div>

                            {previewLoading ? (
                                <div className="mt-4 text-center py-8">
                                    <div className="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600"></div>
                                    <p className="mt-2 text-sm text-gray-500">Loading preview...</p>
                                </div>
                            ) : error && !preview ? (
                                <div className="mt-4 rounded-md bg-red-50 p-4">
                                    <p className="text-sm text-red-800">{error}</p>
                                </div>
                            ) : preview ? (
                                <div className="mt-4 space-y-4">
                                    {/* Version Info */}
                                    <div className="rounded-md bg-blue-50 p-4">
                                        <div className="flex items-center justify-between">
                                            <div>
                                                <p className="text-sm font-medium text-blue-800">
                                                    Version {preview.current_version || 1} → Version {preview.latest_version}
                                                </p>
                                                {preview.change_summary && (
                                                    <p className="mt-1 text-sm text-blue-700">{preview.change_summary}</p>
                                                )}
                                            </div>
                                        </div>
                                    </div>

                                    {/* Fields Comparison */}
                                    {Object.keys(preview.fields || {}).length > 0 ? (
                                        <div className="overflow-hidden shadow ring-1 ring-black ring-opacity-5 sm:rounded-md">
                                            <table className="min-w-full divide-y divide-gray-300">
                                                <thead className="bg-gray-50">
                                                    <tr>
                                                        <th scope="col" className="py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-gray-900 sm:pl-6">
                                                            Field
                                                        </th>
                                                        <th scope="col" className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">
                                                            Current
                                                        </th>
                                                        <th scope="col" className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">
                                                            New
                                                        </th>
                                                        <th scope="col" className="px-3 py-3.5 text-center text-sm font-semibold text-gray-900">
                                                            Update
                                                        </th>
                                                    </tr>
                                                </thead>
                                                <tbody className="divide-y divide-gray-200 bg-white">
                                                    {Object.entries(preview.fields || {}).map(([field, data]) => (
                                                        <tr key={field} className={data.customized ? 'bg-amber-50' : ''}>
                                                            <td className="whitespace-nowrap py-4 pl-4 pr-3 text-sm font-medium text-gray-900 sm:pl-6">
                                                                {getFieldLabel(field)}
                                                                {data.customized && (
                                                                    <span className="ml-2 inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium bg-amber-100 text-amber-800">
                                                                        Customized
                                                                    </span>
                                                                )}
                                                            </td>
                                                            <td className="whitespace-nowrap px-3 py-4 text-sm text-gray-500">
                                                                {formatValue(field, data.current)}
                                                            </td>
                                                            <td className={`whitespace-nowrap px-3 py-4 text-sm ${data.current !== data.new ? 'text-indigo-600 font-medium' : 'text-gray-500'}`}>
                                                                {formatValue(field, data.new)}
                                                            </td>
                                                            <td className="whitespace-nowrap px-3 py-4 text-center text-sm">
                                                                <input
                                                                    type="checkbox"
                                                                    checked={approvedFields.includes(field)}
                                                                    onChange={() => handleFieldToggle(field)}
                                                                    className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                                                />
                                                            </td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </div>
                                    ) : (
                                        <div className="rounded-md bg-blue-50 p-4">
                                            <p className="text-sm text-blue-700">
                                                No field changes detected. The version number will be updated to match the latest system template.
                                            </p>
                                        </div>
                                    )}

                                    {error && (
                                        <div className="rounded-md bg-red-50 p-4">
                                            <p className="text-sm text-red-800">{error}</p>
                                        </div>
                                    )}
                                </div>
                            ) : null}
                        </div>
                    </div>

                    <div className="mt-5 sm:mt-4 sm:flex sm:flex-row-reverse">
                        <button
                            type="button"
                            onClick={handleApply}
                            disabled={loading || previewLoading}
                            className="inline-flex w-full justify-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 sm:ml-3 sm:w-auto disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            {loading ? 'Applying...' : 'Apply Update'}
                        </button>
                        <button
                            type="button"
                            onClick={() => setModalOpen(false)}
                            disabled={loading}
                            className="mt-3 inline-flex w-full justify-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 sm:mt-0 sm:w-auto disabled:opacity-50"
                        >
                            Cancel
                        </button>
                    </div>
                </div>
            </div>
        </div>
    )
}
