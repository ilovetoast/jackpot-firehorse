/**
 * Asset Metadata Display Component
 *
 * Phase 2 – Step 6: Displays metadata read-only with edit actions.
 */

import { useState, useEffect } from 'react'
import { PencilIcon, LockClosedIcon, ArrowPathIcon } from '@heroicons/react/24/outline'
import AssetMetadataEditModal from './AssetMetadataEditModal'
import DominantColorsSwatches from './DominantColorsSwatches'

export default function AssetMetadataDisplay({ assetId }) {
    const [fields, setFields] = useState([])
    const [loading, setLoading] = useState(true)
    const [editingFieldId, setEditingFieldId] = useState(null)
    const [editingField, setEditingField] = useState(null)
    const [overridingFieldId, setOverridingFieldId] = useState(null)
    const [revertingFieldId, setRevertingFieldId] = useState(null)

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

    // Check if field has a value
    const hasValue = (value) => {
        return value !== null && value !== undefined && value !== ''
    }

    // Get label for a value from options
    const getLabelForValue = (options, value) => {
        if (!options || !Array.isArray(options)) {
            return null
        }
        
        const option = options.find(opt => opt.value === value || opt.value === String(value))
        return option?.display_label || null
    }

    // Format value for display (with label lookup for select/multiselect)
    const formatValue = (field, value) => {
        if (!hasValue(value)) {
            return null // Return null instead of "Not set" text
        }

        if (field.type === 'multiselect' && Array.isArray(value)) {
            // Look up labels for each value
            const labels = value.map(v => {
                const label = getLabelForValue(field.options || [], v)
                return label || String(v)
            })
            return labels.join(', ')
        }

        if (field.type === 'select') {
            // Look up label for the value
            const label = getLabelForValue(field.options || [], value)
            return label || String(value)
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
            <div className="text-sm text-gray-500">Loading metadata...</div>
        )
    }

    // Always show the Metadata section content, even if no fields (for consistency)
    return (
        <>
            <div>
                {fields.length === 0 ? (
                    <div className="text-sm text-gray-500 italic">No editable metadata fields available</div>
                ) : (
                    <dl className="space-y-2">
                        {fields.filter(field => field.key !== 'tags' && field.field_key !== 'tags').map((field) => {
                            const fieldHasValue = hasValue(field.current_value)
                            const formattedValue = formatValue(field, field.current_value)
                            
                            return (
                                <div key={field.metadata_field_id} className="flex items-start justify-between">
                                    <div className="flex-1">
                                        <dt className="text-sm text-gray-500 mb-1 flex items-center">
                                            {field.display_label}
                                            {field.has_pending && (
                                                <span
                                                    className="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-yellow-100 text-yellow-800"
                                                    title="This field has pending changes awaiting approval"
                                                >
                                                    Pending
                                                </span>
                                            )}
                                            {/* Phase B2: Show readonly indicator for automatic fields */}
                                            {(field.readonly || field.population_mode === 'automatic') && (
                                                <span
                                                    className="ml-2 inline-flex items-center gap-1 text-xs text-gray-500"
                                                    title="This field is automatically populated and cannot be edited"
                                                >
                                                    <LockClosedIcon className="h-3 w-3" />
                                                    <span className="italic">Auto</span>
                                                </span>
                                            )}
                                        </dt>
                                        {/* Only show the value if there is one */}
                                        {formattedValue && (
                                            <dd className="text-sm font-semibold text-gray-900">
                                                {formattedValue}
                                            </dd>
                                        )}
                                    </div>
                                    {/* Phase B2: Hide edit button for readonly fields */}
                                    {!(field.readonly || field.population_mode === 'automatic') && (
                                        <button
                                            type="button"
                                            onClick={() => {
                                                setEditingFieldId(field.metadata_field_id)
                                                setEditingField(field)
                                            }}
                                            className="ml-4 flex-shrink-0 inline-flex items-center gap-1 px-2 py-1 text-xs font-medium text-indigo-600 hover:text-indigo-700 hover:bg-indigo-50 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                                        >
                                            <PencilIcon className="h-3 w-3" />
                                            {fieldHasValue ? 'Edit' : 'Add'}
                                        </button>
                                    )}
                                </div>
                            )
                        })}
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
