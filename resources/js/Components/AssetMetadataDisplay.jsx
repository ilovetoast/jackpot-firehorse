/**
 * Asset Metadata Display Component
 *
 * Phase 2 – Step 6: Displays metadata read-only with edit actions.
 */

import { useState, useEffect } from 'react'
import { PencilIcon } from '@heroicons/react/24/outline'
import AssetMetadataEditModal from './AssetMetadataEditModal'

export default function AssetMetadataDisplay({ assetId }) {
    const [fields, setFields] = useState([])
    const [loading, setLoading] = useState(true)
    const [editingFieldId, setEditingFieldId] = useState(null)
    const [editingField, setEditingField] = useState(null)

    // Fetch editable metadata
    const fetchMetadata = () => {
        if (!assetId) return

        setLoading(true)
        fetch(`/app/assets/${assetId}/metadata/editable`, {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
            },
            credentials: 'same-origin',
        })
            .then((res) => res.json())
            .then((data) => {
                setFields(data.fields || [])
                setLoading(false)
            })
            .catch((err) => {
                console.error('[AssetMetadataDisplay] Failed to fetch metadata', err)
                setLoading(false)
            })
    }

    useEffect(() => {
        fetchMetadata()
    }, [assetId])

    // Phase 8: Listen for metadata updates (from approval actions)
    useEffect(() => {
        const handleUpdate = () => {
            fetchMetadata()
        }
        window.addEventListener('metadata-updated', handleUpdate)
        return () => {
            window.removeEventListener('metadata-updated', handleUpdate)
        }
    }, [assetId])

    // Refresh after edit
    const handleEditComplete = () => {
        setEditingFieldId(null)
        setEditingField(null)
        // Refetch metadata
        if (assetId) {
            fetch(`/app/assets/${assetId}/metadata/editable`, {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                },
                credentials: 'same-origin',
            })
                .then((res) => res.json())
                .then((data) => {
                    setFields(data.fields || [])
                })
                .catch((err) => {
                    console.error('[AssetMetadataDisplay] Failed to refresh metadata', err)
                })
        }
    }

    // Format value for display
    const formatValue = (field, value) => {
        if (value === null || value === undefined || value === '') {
            return <span className="text-gray-400 italic">Not set</span>
        }

        if (field.type === 'multiselect' && Array.isArray(value)) {
            return value.join(', ')
        }

        if (field.type === 'boolean') {
            return value ? 'Yes' : 'No'
        }

        if (field.type === 'date') {
            try {
                const date = new Date(value)
                return date.toLocaleDateString()
            } catch (e) {
                return value
            }
        }

        return String(value)
    }

    if (loading) {
        return (
            <div className="px-6 py-4 border-t border-gray-200">
                <div className="text-sm text-gray-500">Loading metadata...</div>
            </div>
        )
    }

    // Always show the Metadata section header, even if no fields (for consistency)
    return (
        <>
            <div className="px-6 py-4 border-t border-gray-200">
                <h3 className="text-sm font-semibold text-gray-900 mb-4">Metadata</h3>
                {fields.length === 0 ? (
                    <div className="text-sm text-gray-500 italic">No editable metadata fields available</div>
                ) : (
                    <dl className="space-y-4">
                        {fields.map((field) => (
                            <div key={field.metadata_field_id} className="flex items-start justify-between">
                                <div className="flex-1">
                                    <dt className="text-sm font-medium text-gray-700 mb-1 flex items-center">
                                        {field.display_label}
                                        {field.has_pending && (
                                            <span
                                                className="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-yellow-100 text-yellow-800"
                                                title="This field has pending changes awaiting approval"
                                            >
                                                Pending
                                            </span>
                                        )}
                                    </dt>
                                    <dd className="text-sm text-gray-900">
                                        {formatValue(field, field.current_value)}
                                    </dd>
                                </div>
                                <button
                                    type="button"
                                    onClick={() => {
                                        setEditingFieldId(field.metadata_field_id)
                                        setEditingField(field)
                                    }}
                                    className="ml-4 flex-shrink-0 inline-flex items-center gap-1 px-2 py-1 text-xs font-medium text-indigo-600 hover:text-indigo-700 hover:bg-indigo-50 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                                >
                                    <PencilIcon className="h-3 w-3" />
                                    Edit
                                </button>
                            </div>
                        ))}
                    </dl>
                )}
            </div>

            {/* Edit Modal */}
            {editingField && (
                <AssetMetadataEditModal
                    assetId={assetId}
                    field={editingField}
                    onClose={() => {
                        setEditingFieldId(null)
                        setEditingField(null)
                    }}
                    onSave={handleEditComplete}
                />
            )}
        </>
    )
}
