/**
 * Asset Metadata Display Component
 *
 * Phase 2 – Step 6: Displays metadata read-only with edit actions.
 * Uses WidgetResolver for centralized widget rendering logic.
 */

import { useState, useEffect } from 'react'
import { PencilIcon, LockClosedIcon, ArrowPathIcon, CheckIcon, XMarkIcon, RectangleStackIcon, GlobeAltIcon } from '@heroicons/react/24/outline'
import { usePage } from '@inertiajs/react'
import AssetMetadataEditModal from './AssetMetadataEditModal'
import DominantColorsSwatches from './DominantColorsSwatches'
import StarRating from './StarRating'
import { resolve, isExcludedFromGenericLoop, isDominantColorsSwatches, CONTEXT, WIDGET } from '../utils/widgetResolver'

export default function AssetMetadataDisplay({ assetId, onPendingCountChange, collectionDisplay = null, primaryColor, suppressAnalysisRunningBanner = false }) {
    const { auth } = usePage().props
    const brandPrimary = primaryColor || auth?.activeBrand?.primary_color || '#6366f1'
    const badgeBg = brandPrimary.startsWith('#') ? `${brandPrimary}18` : `#${brandPrimary}18`
    const [fields, setFields] = useState([])
    const [loading, setLoading] = useState(true)
    const [editingFieldId, setEditingFieldId] = useState(null)
    const [editingField, setEditingField] = useState(null)
    const [overridingFieldId, setOverridingFieldId] = useState(null)
    const [revertingFieldId, setRevertingFieldId] = useState(null)
    const [pendingMetadataCount, setPendingMetadataCount] = useState(0)
    const [metadataHealth, setMetadataHealth] = useState(null)
    const [analysisStatus, setAnalysisStatus] = useState('uploading')
    const [thumbnailStatus, setThumbnailStatus] = useState('pending')
    const [reanalyzeLoading, setReanalyzeLoading] = useState(false)

    // Step 1: Removed inline approval handlers - approval actions consolidated in Pending Metadata section

    // Fetch editable metadata (silent = true skips loading state, used for polling)
    const fetchMetadata = (silent = false) => {
        if (!assetId) return

        if (!silent) setLoading(true)
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
                setMetadataHealth(data.metadata_health ?? null)
                setAnalysisStatus(data.analysis_status ?? 'uploading')
                setThumbnailStatus(data.thumbnail_status ?? 'pending')
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
                    setMetadataHealth(data.metadata_health ?? null)
                    setAnalysisStatus(data.analysis_status ?? 'uploading')
                    setThumbnailStatus(data.thumbnail_status ?? 'pending')
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

        // dominant_colors: return null, handled by DominantColorsSwatches in render
        if (isDominantColorsSwatches(field, CONTEXT.DISPLAY)) {
            return null
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

    const handleReanalyze = async () => {
        if (!assetId || reanalyzeLoading) return
        setReanalyzeLoading(true)
        try {
            const res = await fetch(`/app/assets/${assetId}/reanalyze`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                    'Accept': 'application/json',
                },
                credentials: 'same-origin',
            })
            const data = await res.json()
            if (data.status === 'queued') {
                fetchMetadata()
                // Poll until pipeline finishes (analysis complete or metadata_health.is_complete) or 60s
                let attempts = 0
                const poll = () => {
                    if (attempts >= 30) {
                        setReanalyzeLoading(false)
                        return
                    }
                    attempts++
                    setTimeout(() => {
                        fetch(`/app/assets/${assetId}/metadata/editable`, {
                            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content },
                            credentials: 'same-origin',
                        })
                            .then((r) => r.json())
                            .then((d) => {
                                setMetadataHealth(d.metadata_health ?? null)
                                setAnalysisStatus(d.analysis_status ?? 'uploading')
                                setThumbnailStatus(d.thumbnail_status ?? 'pending')
                                const done = (d.analysis_status ?? '') === 'complete' || (d.metadata_health?.is_complete === true)
                                if (done || attempts >= 30) setReanalyzeLoading(false)
                                else poll()
                            })
                            .catch(() => {
                                if (attempts >= 30) setReanalyzeLoading(false)
                                else poll()
                            })
                    }, 2000)
                }
                poll()
            } else {
                setReanalyzeLoading(false)
            }
        } catch {
            setReanalyzeLoading(false)
        }
    }

    // Always show the Metadata section content, even if no fields (for consistency)
    return (
        <>
            {/* Local diagnostics only — never shipped in production builds (Vite sets import.meta.env.PROD). */}
            {!import.meta.env.PROD && (
                <div className="mb-3 rounded border border-amber-300 bg-amber-50/80 p-3 font-mono text-xs text-amber-900">
                    <div className="font-semibold mb-1">Pipeline state (dev)</div>
                    <pre className="whitespace-pre-wrap break-all">
                        analysis_status: {analysisStatus}
                        thumbnail_status: {thumbnailStatus}
                        metadata_health: {metadataHealth ? JSON.stringify(metadataHealth) : 'null'}
                    </pre>
                </div>
            )}
            <div>
                {!suppressAnalysisRunningBanner && !metadataHealth?.is_complete && metadataHealth && analysisStatus !== 'complete' && (
                    <div className="bg-amber-50 border border-amber-200 rounded-md p-4 mb-4">
                        {thumbnailStatus === 'completed' && metadataHealth?.visual_metadata_ready === false ? (
                            <>
                                <div className="font-medium text-amber-800">
                                    Visual metadata invalid
                                </div>
                                <div className="text-sm text-amber-700 mt-1">
                                    Thumbnail exists but dimensions or metadata are missing or invalid. Re-run analysis or contact support.
                                </div>
                            </>
                        ) : (
                            <>
                                <div className="font-medium text-amber-800">
                                    System analysis still running
                                </div>
                                <div className="text-sm text-amber-700 mt-1">
                                    Dominant colors, embeddings, or thumbnails may not have completed. Re-run analysis will be available once the pipeline finishes.
                                </div>
                            </>
                        )}
                    </div>
                )}
                {fields.length === 0 ? (
                    <div className="text-sm text-gray-500 italic">No editable metadata fields available</div>
                ) : (
                    <dl className="space-y-2 md:space-y-3">
                        {(() => {
                            const filtered = fields.filter(field => !isExcludedFromGenericLoop(field))
                            const isAuto = (f) => f.readonly || f.population_mode === 'automatic'
                            const nonAutoFields = filtered.filter(f => !isAuto(f)).sort((a, b) => 0)
                            const autoFields = filtered.filter(f => isAuto(f)).sort((a, b) => 0)
                            // Order: user-managed fields first, then Collection, then system automated fields last
                            const collectionElement = (collectionDisplay && Array.isArray(collectionDisplay.collections)) ? (
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
                                                            className="inline-flex items-center gap-1 px-2 py-1 rounded-md text-xs font-medium"
                                                            style={{ backgroundColor: badgeBg, color: brandPrimary }}
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
                                    {collectionDisplay.showEditButton !== false && typeof collectionDisplay.onEdit === 'function' && (
                                        <div className="self-start md:self-auto ml-auto md:ml-0 flex-shrink-0 flex items-center gap-2">
                                            <button
                                                type="button"
                                                onClick={collectionDisplay.onEdit}
                                                className="inline-flex items-center gap-1 px-2 py-1 text-xs font-medium rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 hover:opacity-90"
                                                style={{ color: brandPrimary, ['--tw-ring-color']: brandPrimary }}
                                            >
                                                <PencilIcon className="h-3 w-3" />
                                                {collectionDisplay.collections.length > 0 ? 'Edit' : 'Add'}
                                            </button>
                                        </div>
                                    )}
                                </div>
                            ) : null

                            const renderField = (field) => {
                                const fieldHasValue = hasValue(field.current_value)
                                const widget = resolve(field, CONTEXT.DISPLAY)
                            const isRating = widget === WIDGET.RATING
                            const isDominantColors = widget === WIDGET.DOMINANT_COLORS
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
                            // 3. They are not automatic/readonly (editable fields show even without values)
                            // 4. They are system fields (always visible in details view, even when empty or still calculating)
                            const systemFieldKeys = ['dominant_colors', 'dominant_hue_group', 'orientation', 'resolution_class']
                            const isSystemField = systemFieldKeys.includes(field.key || field.field_key)
                            const shouldShow = displayValue || dominantColorsArray || isRating || (!isAutoField && !field.readonly) || isSystemField
                            
                                if (!shouldShow) {
                                    return []
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
                                        {/* Show the value if there is one; system fields show label even when empty */}
                                        {(displayValue || dominantColorsArray || isRating || isSystemField) ? (
                                            <dd className="text-sm font-semibold text-gray-900 md:flex-1 md:min-w-0 break-words">
                                                {/* Rating: inline control. Starred/other booleans with display_widget=toggle use Edit → modal (brand-colored toggle). */}
                                                {isRating ? (
                                                    <StarRating
                                                        value={field.current_value}
                                                        primaryColor={brandPrimary}
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
                                                        editable={field.can_edit !== false && (field.key === 'quality_rating' || (!field.readonly && field.population_mode !== 'automatic'))}
                                                        maxStars={5}
                                                        size="md"
                                                    />
                                                ) : dominantColorsArray ? (
                                                    <DominantColorsSwatches dominantColors={dominantColorsArray} />
                                                ) : displayValue != null && displayValue !== '' ? (
                                                    displayValue
                                                ) : isSystemField ? (
                                                    <span className="text-gray-400 italic">—</span>
                                                ) : null}
                                            </dd>
                                        ) : null}
                                    </div>
                                    {/* Show "Auto" badge where edit button would be for readonly/automatic fields */}
                                    {/* For rating only, no edit button - inline control; starred uses Edit → modal */}
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
                                                className="inline-flex items-center gap-1 px-2 py-1 text-xs font-medium rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 hover:opacity-90"
                                                style={{ color: brandPrimary, ['--tw-ring-color']: brandPrimary }}
                                            >
                                                <PencilIcon className="h-3 w-3" />
                                                {fieldHasValue ? 'Edit' : 'Add'}
                                            </button>
                                        </div>
                                    ) : null}
                                </div>
                            )
                            
                                return [fieldElement]
                            }

                            const nonAutoElements = nonAutoFields.flatMap(renderField)
                            const autoElements = autoFields.flatMap(renderField)
                            return [...nonAutoElements, ...(collectionElement ? [collectionElement] : []), ...autoElements]
                        })()}
                    </dl>
                )}
            </div>

            {/* Edit Modal: pass brand primary so toggle widgets use it (display_widget=toggle / starred) */}
            {editingField && (
                <AssetMetadataEditModal
                    assetId={assetId}
                    field={editingField}
                    primaryColor={brandPrimary}
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
