/**
 * Asset Metadata Display Component
 *
 * Phase 2 – Step 6: Displays metadata read-only with edit actions.
 */

import { useState, useEffect } from 'react'
import { PencilIcon, LockClosedIcon, ArrowPathIcon, CheckIcon, XMarkIcon, RectangleStackIcon, GlobeAltIcon } from '@heroicons/react/24/outline'
import { usePage } from '@inertiajs/react'
import AssetMetadataEditModal from './AssetMetadataEditModal'
import DominantColorsSwatches from './DominantColorsSwatches'
import StarRating from './StarRating'
import { usePermission } from '../hooks/usePermission'

export default function AssetMetadataDisplay({ assetId, onPendingCountChange, collectionDisplay = null }) {
    const { auth } = usePage().props
    const [fields, setFields] = useState([])
    const [loading, setLoading] = useState(true)
    const [editingFieldId, setEditingFieldId] = useState(null)
    const [editingField, setEditingField] = useState(null)
    const [overridingFieldId, setOverridingFieldId] = useState(null)
    const [revertingFieldId, setRevertingFieldId] = useState(null)
    const [pendingMetadataCount, setPendingMetadataCount] = useState(0)
    
    // Step 1: Removed inline approval handlers - approval actions consolidated in Pending Metadata section

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
                const count = data.pending_metadata_count || 0
                setPendingMetadataCount(count)
                if (onPendingCountChange) {
                    onPendingCountChange(count)
                }
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

    // Step 1: Removed inline approval/reject handlers - approval actions consolidated in Pending Metadata section

    // Check if field has a value
    const hasValue = (value) => {
        if (value === null || value === undefined) return false
        if (value === '') return false
        // For arrays, check if they have any elements
        if (Array.isArray(value)) return value.length > 0
        // For objects, check if they have any keys
        if (typeof value === 'object') return Object.keys(value).length > 0
        return true
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

        // Special handling for dominant_colors - return null, handled separately in render
        const isDominantColors = (field.key === 'dominant_colors' || field.field_key === 'dominant_colors')
        if (isDominantColors) {
            return null // Don't format, will be handled by dominantColorsArray check
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
            // Always return a string - use label if found, otherwise use the value itself
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
                    <dl className="space-y-2 md:space-y-3">
                        {fields.filter(field => {
                            // Exclude tags (handled separately)
                            if (field.key === 'tags' || field.field_key === 'tags') {
                                return false;
                            }
                            // Exclude dimensions (it's file info, not metadata, shown in file info area)
                            if (field.key === 'dimensions' || field.field_key === 'dimensions') {
                                return false;
                            }
                            // C9.1: Exclude collection field (handled separately in inline collections display)
                            if (field.key === 'collection' || field.field_key === 'collection') {
                                return false;
                            }
                            return true;
                        }).sort((a, b) => {
                            // Sort: non-auto fields first, auto fields last
                            const aIsAuto = a.readonly || a.population_mode === 'automatic'
                            const bIsAuto = b.readonly || b.population_mode === 'automatic'
                            
                            if (aIsAuto && !bIsAuto) return 1  // a is auto, b is not - a goes after b
                            if (!aIsAuto && bIsAuto) return -1 // a is not auto, b is - a goes before b
                            return 0 // Both same type, maintain original order
                        }).flatMap((field) => {
                            const fieldHasValue = hasValue(field.current_value)
                            const isDominantColors = (field.key === 'dominant_colors' || field.field_key === 'dominant_colors')
                            const isRating = field.type === 'rating' || field.key === 'quality_rating' || field.field_key === 'quality_rating'
                            
                            // For dominant_colors, check if we have a valid array
                            let dominantColorsArray = null
                            if (isDominantColors && field.current_value) {
                                if (Array.isArray(field.current_value) && field.current_value.length > 0) {
                                    dominantColorsArray = field.current_value
                                }
                            }
                            
                            const formattedValue = formatValue(field, field.current_value)
                            
                            // For automatic fields, if formattedValue is null but we have a value, use the raw value
                            const isAutoField = field.readonly || field.population_mode === 'automatic'
                            
                            // Always try to display the value if it exists
                            // For automatic fields, show the value even if formatting didn't work
                            let displayValue = formattedValue
                            
                            // If no formatted value but we have a raw value, try to format it
                            if (!displayValue && field.current_value !== null && field.current_value !== undefined && !dominantColorsArray && !isRating) {
                                const rawValue = field.current_value
                                
                                // Skip empty strings
                                if (rawValue === '') {
                                    displayValue = null
                                } else if (field.type === 'select' && field.options && field.options.length > 0) {
                                    // Try to find the label from options
                                    const label = getLabelForValue(field.options, rawValue)
                                    displayValue = label || String(rawValue)
                                } else if (Array.isArray(rawValue)) {
                                    // For arrays, join them
                                    displayValue = rawValue.map(v => String(v)).join(', ')
                                } else {
                                    // For other types, convert to string
                                    displayValue = String(rawValue)
                                }
                            }
                            
                            // Show fields if:
                            // 1. They have a value (displayValue or dominantColorsArray)
                            // 2. They are rating fields (so users can add ratings)
                            // 3. They are not automatic/readonly fields (automatic fields only show if they have values)
                            // For editable fields, show them even without values so users can add them
                            const shouldShow = displayValue || dominantColorsArray || isRating || (!isAutoField && !field.readonly)
                            
                            if (!shouldShow) {
                                return [];
                            }
                            
                            const fieldElement = (
                                <div 
                                    key={field.metadata_field_id} 
                                    className="flex flex-col md:flex-row md:items-start md:justify-between gap-1 md:gap-4 md:flex-nowrap"
                                >
                                    <div className="flex flex-col md:flex-row md:items-start md:gap-4 md:flex-1 md:min-w-0 md:flex-wrap">
                                        {/* Mobile: label above, Desktop: fixed-width label column */}
                                        <dt className="text-sm text-gray-500 mb-1 md:mb-0 md:w-32 md:flex-shrink-0 flex items-center md:items-start">
                                            <span className="flex items-center flex-wrap gap-1 md:gap-1.5">
                                                {field.display_label}
                                            </span>
                                        </dt>
                                        {/* Show the value if there is one, or nothing if no value */}
                                        {(displayValue || dominantColorsArray || isRating) ? (
                                            <dd className="text-sm font-semibold text-gray-900 md:flex-1 md:min-w-0 break-words">
                                                {/* Special handling for rating fields - show star rating with direct save */}
                                                {isRating ? (
                                                    <StarRating
                                                        value={field.current_value}
                                                        onChange={async (newValue) => {
                                                            // Save rating directly without modal
                                                            try {
                                                                const response = await fetch(`/app/assets/${assetId}/metadata/edit`, {
                                                                    method: 'POST',
                                                                    headers: {
                                                                        'Content-Type': 'application/json',
                                                                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                                                                    },
                                                                    credentials: 'same-origin',
                                                                    body: JSON.stringify({
                                                                        metadata_field_id: field.metadata_field_id,
                                                                        value: newValue,
                                                                    }),
                                                                })

                                                                if (!response.ok) {
                                                                    const data = await response.json()
                                                                    throw new Error(data.message || 'Failed to save rating')
                                                                }

                                                                // Update local state
                                                                setFields(prevFields => 
                                                                    prevFields.map(f => 
                                                                        f.metadata_field_id === field.metadata_field_id
                                                                            ? { ...f, current_value: newValue }
                                                                            : f
                                                                    )
                                                                )
                                                            } catch (err) {
                                                                console.error('[AssetMetadataDisplay] Failed to save rating', err)
                                                                // Optionally show error toast/notification
                                                            }
                                                        }}
                                                        editable={!field.readonly && field.population_mode !== 'automatic' && field.can_edit !== false}
                                                        maxStars={5}
                                                        size="md"
                                                    />
                                                ) : dominantColorsArray ? (
                                                    <DominantColorsSwatches dominantColors={dominantColorsArray} />
                                                ) : (
                                                    displayValue
                                                )}
                                            </dd>
                                        ) : null}
                                    </div>
                                    {/* Show "Auto" badge where edit button would be for readonly/automatic fields */}
                                    {/* For rating fields, don't show edit button - rating is clickable directly */}
                                    {/* Only show edit button if user has edit permission (can_edit/is_user_editable) */}
                                    {isRating ? null : (field.readonly || field.population_mode === 'automatic') ? (
                                        <div className="self-start md:self-auto ml-auto md:ml-0 flex-shrink-0 inline-flex items-center gap-1 text-xs text-gray-500">
                                            <LockClosedIcon className="h-3 w-3" />
                                            <span className="italic">Auto</span>
                                        </div>
                                    ) : (field.can_edit !== false && field.is_user_editable !== false) ? (
                                        <div className="self-start md:self-auto ml-auto md:ml-0 flex-shrink-0 flex items-center gap-2">
                                            {/* Step 1: Inline approval buttons removed - all approval actions consolidated in Pending Metadata section */}
                                            <button
                                                type="button"
                                                onClick={() => {
                                                    setEditingFieldId(field.metadata_field_id)
                                                    setEditingField(field)
                                                }}
                                                className="inline-flex items-center gap-1 px-2 py-1 text-xs font-medium text-indigo-600 hover:text-indigo-700 hover:bg-indigo-50 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                                            >
                                                <PencilIcon className="h-3 w-3" />
                                                {fieldHasValue ? 'Edit' : 'Add'}
                                            </button>
                                        </div>
                                    ) : null}
                                </div>
                            )
                            
                            return [fieldElement]
                        }).concat(
                            // C9.2: Show Collections whenever collectionDisplay is provided (not only after Scene Classification)
                            // Defensive: require collectionDisplay with collections array and onEdit callback
                            (collectionDisplay && Array.isArray(collectionDisplay.collections) && typeof collectionDisplay.onEdit === 'function') ? [
                                <div
                                    key="collection-field"
                                    className="flex flex-col md:flex-row md:items-start md:justify-between gap-1 md:gap-4 md:flex-nowrap"
                                >
                                    <div className="flex flex-col md:flex-row md:items-start md:gap-4 md:flex-1 md:min-w-0 md:flex-wrap">
                                        <dt className="text-sm text-gray-500 mb-1 md:mb-0 md:w-32 md:flex-shrink-0 flex items-center md:items-start">
                                            <span className="flex items-center flex-wrap gap-1 md:gap-1.5">
                                                Collection
                                            </span>
                                        </dt>
                                        <dd className="text-sm font-semibold text-gray-900 md:flex-1 md:min-w-0 break-words">
                                            {collectionDisplay.loading ? (
                                                <span className="text-gray-400">Loading…</span>
                                            ) : collectionDisplay.collections.length > 0 ? (
                                                <div className="flex items-center gap-2 flex-wrap">
                                                    {collectionDisplay.collections.map((c) => (
                                                        <span
                                                            key={c.id}
                                                            className="inline-flex items-center gap-1 px-2 py-1 rounded-md bg-indigo-50 text-indigo-700 text-xs font-medium"
                                                            title={c.is_public ? 'Public collection' : undefined}
                                                        >
                                                            <RectangleStackIcon className="h-3 w-3" aria-hidden="true" />
                                                            {c.name}
                                                            {c.is_public && (
                                                                <GlobeAltIcon className="h-3 w-3 opacity-80" aria-hidden="true" title="Public" />
                                                            )}
                                                        </span>
                                                    ))}
                                                </div>
                                            ) : (
                                                <span className="text-gray-400">No collections</span>
                                            )}
                                        </dd>
                                    </div>
                                    <div className="self-start md:self-auto ml-auto md:ml-0 flex-shrink-0 flex items-center gap-2">
                                        <button
                                            type="button"
                                            onClick={collectionDisplay.onEdit}
                                            className="inline-flex items-center gap-1 px-2 py-1 text-xs font-medium text-indigo-600 hover:text-indigo-700 hover:bg-indigo-50 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                                        >
                                            <PencilIcon className="h-3 w-3" />
                                            {collectionDisplay.collections.length > 0 ? 'Edit' : 'Add'}
                                        </button>
                                    </div>
                                </div>
                            ] : []
                        )}
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
