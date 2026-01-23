/**
 * AssetDetailsModal Component
 * 
 * Modal for displaying all asset metadata fields (read-only) for testing/verification.
 * Shows preview, category, and all metadata fields including AI/automated ones.
 * 
 * @param {Object} props
 * @param {Object} props.asset - Asset object
 * @param {boolean} props.isOpen - Whether modal is open
 * @param {Function} props.onClose - Callback when modal should close
 */
import { useEffect, useState } from 'react'
import { XMarkIcon } from '@heroicons/react/24/outline'
import ThumbnailPreview from './ThumbnailPreview'
import DominantColorsSwatches from './DominantColorsSwatches'

export default function AssetDetailsModal({ asset, isOpen, onClose }) {
    const [metadata, setMetadata] = useState(null)
    const [loading, setLoading] = useState(false)
    const [error, setError] = useState(null)

    useEffect(() => {
        if (isOpen && asset?.id) {
            fetchMetadata()
        }
    }, [isOpen, asset?.id])

    const fetchMetadata = async () => {
        if (!asset?.id) return

        setLoading(true)
        setError(null)

        try {
            const response = await window.axios.get(`/app/assets/${asset.id}/metadata/all`)
            setMetadata(response.data)
        } catch (err) {
            console.error('Failed to fetch metadata:', err)
            setError(err.response?.data?.message || 'Failed to load metadata')
        } finally {
            setLoading(false)
        }
    }

    const formatValue = (value, type) => {
        if (value === null || value === undefined) {
            return <span className="text-gray-400 italic">Not set</span>
        }

        if (type === 'multiselect' && Array.isArray(value)) {
            if (value.length === 0) {
                return <span className="text-gray-400 italic">Not set</span>
            }
            return (
                <span className="text-gray-700">
                    {value.map((v, idx) => (
                        <span key={idx}>
                            {String(v)}
                            {idx < value.length - 1 && ', '}
                        </span>
                    ))}
                </span>
            )
        }

        if (type === 'boolean') {
            return (
                <span className={value ? 'text-green-700' : 'text-gray-600'}>
                    {value ? 'Yes' : 'No'}
                </span>
            )
        }

        if (type === 'date') {
            try {
                const date = new Date(value)
                return date.toLocaleDateString()
            } catch {
                return String(value)
            }
        }

        return <span className="text-gray-700">{String(value)}</span>
    }

    const getSourceBadge = (field) => {
        if (!field.metadata) return null

        const { source, producer, confidence, is_overridden } = field.metadata

        if (is_overridden) {
            return (
                <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-yellow-100 text-yellow-800">
                    Manual Override
                </span>
            )
        }

        if (source === 'user') {
            return (
                <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800">
                    User
                </span>
            )
        }

        if (source === 'automatic' || source === 'system') {
            return (
                <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-purple-100 text-purple-800">
                    {producer === 'system' ? 'System' : 'Automatic'}
                </span>
            )
        }

        if (source === 'ai') {
            return (
                <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-pink-100 text-pink-800">
                    AI {confidence ? `(${(confidence * 100).toFixed(0)}%)` : ''}
                </span>
            )
        }

        return null
    }

    if (!isOpen) return null

    return (
        <div className="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
            {/* Backdrop */}
            <div className="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" onClick={onClose} />

            {/* Modal */}
            <div className="flex min-h-full items-center justify-center p-4">
                <div className="relative transform overflow-hidden rounded-lg bg-white shadow-xl transition-all w-full max-w-4xl max-h-[90vh] flex flex-col">
                    {/* Header */}
                    <div className="sticky top-0 z-10 bg-white border-b border-gray-200 px-6 py-4 flex items-center justify-between">
                        <h3 id="modal-title" className="text-lg font-semibold text-gray-900">
                            Asset Details - {asset?.title || asset?.original_filename || 'Asset'}
                        </h3>
                        <button
                            type="button"
                            onClick={onClose}
                            className="rounded-md bg-white text-gray-400 hover:text-gray-500 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                        >
                            <span className="sr-only">Close</span>
                            <XMarkIcon className="h-6 w-6" aria-hidden="true" />
                        </button>
                    </div>

                    {/* Content */}
                    <div className="flex-1 overflow-y-auto px-6 py-6">
                        {loading && (
                            <div className="text-center py-8">
                                <div className="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600"></div>
                                <p className="mt-2 text-sm text-gray-500">Loading metadata...</p>
                            </div>
                        )}

                        {error && (
                            <div className="rounded-md bg-red-50 p-4 mb-4">
                                <p className="text-sm text-red-800">{error}</p>
                            </div>
                        )}

                        {!loading && !error && metadata && (
                            <div className="space-y-6">
                                {/* Preview */}
                                <div>
                                    <h4 className="text-sm font-medium text-gray-900 mb-3">Preview</h4>
                                    <div className="bg-gray-50 rounded-lg overflow-hidden border border-gray-200" style={{ aspectRatio: '16/9', minHeight: '200px' }}>
                                        {asset?.id && (
                                            <ThumbnailPreview
                                                asset={asset}
                                                alt={asset?.title || asset?.original_filename || 'Asset preview'}
                                                className="w-full h-full"
                                                size="lg"
                                            />
                                        )}
                                    </div>
                                </div>

                                {/* Category */}
                                {metadata.category && (
                                    <div>
                                        <h4 className="text-sm font-medium text-gray-900 mb-2">Category</h4>
                                        <p className="text-sm text-gray-700">{metadata.category.name}</p>
                                    </div>
                                )}

                                {/* Dominant Colors */}
                                {asset?.metadata?.dominant_colors && Array.isArray(asset.metadata.dominant_colors) && asset.metadata.dominant_colors.length > 0 && (
                                    <div>
                                        <h4 className="text-sm font-medium text-gray-900 mb-2">Dominant Colors</h4>
                                        <div className="text-sm text-gray-700">
                                            <DominantColorsSwatches dominantColors={asset.metadata.dominant_colors} />
                                        </div>
                                    </div>
                                )}

                                {/* Metadata Fields */}
                                <div>
                                    <h4 className="text-sm font-medium text-gray-900 mb-3">All Metadata Fields</h4>
                                    <div className="space-y-2">
                                        {metadata.fields && metadata.fields.length > 0 ? (
                                            metadata.fields.map((field) => {
                                                const typeLabel = field.type + 
                                                    (field.population_mode !== 'manual' ? ` (${field.population_mode})` : '') +
                                                    (field.readonly ? ' (read-only)' : '') +
                                                    (field.is_ai_related ? ' (AI-related)' : '');
                                                
                                                return (
                                                    <div
                                                        key={field.metadata_field_id}
                                                        className="flex items-start justify-between py-2 border-b border-gray-100 last:border-b-0"
                                                    >
                                                        <div className="flex-1 min-w-0">
                                                            <div className="text-sm text-gray-900">
                                                                <span className="font-medium">{field.display_label}</span>
                                                                <span className="text-gray-500 ml-1">({typeLabel})</span>
                                                                <span className="text-gray-400 mx-2">:</span>
                                                                <span className="text-gray-700">
                                                                    {formatValue(field.current_value, field.type)}
                                                                </span>
                                                            </div>
                                                            {field.metadata && (field.metadata.approved_at || field.metadata.confidence !== null) && (
                                                                <div className="mt-1 text-xs text-gray-400">
                                                                    {field.metadata.approved_at && (
                                                                        <span>Approved {new Date(field.metadata.approved_at).toLocaleDateString()}</span>
                                                                    )}
                                                                    {field.metadata.confidence !== null && (
                                                                        <span className={field.metadata.approved_at ? ' ml-2' : ''}>
                                                                            {field.metadata.confidence ? `${(field.metadata.confidence * 100).toFixed(0)}% confidence` : ''}
                                                                        </span>
                                                                    )}
                                                                </div>
                                                            )}
                                                        </div>
                                                        <div className="ml-3 flex-shrink-0">
                                                            {getSourceBadge(field)}
                                                        </div>
                                                    </div>
                                                );
                                            })
                                        ) : (
                                            <p className="text-sm text-gray-500">No metadata fields available</p>
                                        )}
                                    </div>
                                </div>
                            </div>
                        )}
                    </div>

                    {/* Footer */}
                    <div className="sticky bottom-0 bg-gray-50 border-t border-gray-200 px-6 py-4 flex justify-end">
                        <button
                            type="button"
                            onClick={onClose}
                            className="rounded-md bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                        >
                            Close
                        </button>
                    </div>
                </div>
            </div>
        </div>
    )
}
