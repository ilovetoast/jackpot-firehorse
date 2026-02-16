/**
 * Asset Metadata Display Component
 *
 * Phase 2 – Step 6: Displays metadata read-only with edit actions.
 * Uses WidgetResolver for centralized widget rendering logic.
 */

import { useState, useEffect } from 'react'
import { PencilIcon, LockClosedIcon, ArrowPathIcon, CheckIcon, XMarkIcon, RectangleStackIcon, GlobeAltIcon, TagIcon, ArrowPathRoundedSquareIcon } from '@heroicons/react/24/outline'
import { usePage } from '@inertiajs/react'
import AssetMetadataEditModal from './AssetMetadataEditModal'
import DominantColorsSwatches from './DominantColorsSwatches'
import StarRating from './StarRating'
import { resolve, isExcludedFromGenericLoop, isDominantColorsSwatches, CONTEXT, WIDGET } from '../utils/widgetResolver'

export default function AssetMetadataDisplay({ assetId, onPendingCountChange, collectionDisplay = null, primaryColor }) {
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
    const [assetTags, setAssetTags] = useState([])
    const [tagsLoading, setTagsLoading] = useState(false)
    const [complianceScore, setComplianceScore] = useState(null)
    const [complianceBreakdown, setComplianceBreakdown] = useState(null)
    const [evaluationStatus, setEvaluationStatus] = useState('pending')
    const [complianceExpanded, setComplianceExpanded] = useState(false)
    const [brandDnaEnabled, setBrandDnaEnabled] = useState(false)
    const [rescoreLoading, setRescoreLoading] = useState(false)
    
    // Step 1: Removed inline approval handlers - approval actions consolidated in Pending Metadata section

    // Fetch tags for Tags row (all asset types including video)
    useEffect(() => {
        if (!assetId) {
            setAssetTags([])
            return
        }
        setTagsLoading(true)
        fetch(`/app/api/assets/${assetId}/tags`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((res) => res.ok ? res.json() : [])
            .then((data) => {
                const list = data?.tags ?? (Array.isArray(data) ? data : data?.data ?? [])
                setAssetTags(Array.isArray(list) ? list : [])
            })
            .catch(() => setAssetTags([]))
            .finally(() => setTagsLoading(false))
    }, [assetId])

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
                setComplianceScore(data.compliance_score ?? null)
                setComplianceBreakdown(data.compliance_breakdown ?? null)
                setEvaluationStatus(data.evaluation_status ?? 'pending')
                setBrandDnaEnabled(data.brand_dna_enabled ?? false)
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

    // Refetch tags when tags are updated (e.g. from Tag Manager below)
    useEffect(() => {
        const handleTagsUpdate = () => {
            if (!assetId) return
            fetch(`/app/api/assets/${assetId}/tags`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then((res) => (res.ok ? res.json() : { tags: [] }))
                .then((data) => setAssetTags(Array.isArray(data?.tags) ? data.tags : []))
                .catch(() => {})
        }
        window.addEventListener('tags-updated', handleTagsUpdate)
        return () => window.removeEventListener('tags-updated', handleTagsUpdate)
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
                    setComplianceScore(data.compliance_score ?? null)
                    setComplianceBreakdown(data.compliance_breakdown ?? null)
                    setEvaluationStatus(data.evaluation_status ?? 'pending')
                    setBrandDnaEnabled(data.brand_dna_enabled ?? false)
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

    // Compliance badge color: 90+ green, 70-89 amber, <70 red
    const getComplianceBadgeClass = (score) => {
        if (score >= 90) return 'bg-green-50 text-green-700 ring-green-600/20'
        if (score >= 70) return 'bg-amber-50 text-amber-700 ring-amber-600/20'
        return 'bg-red-50 text-red-700 ring-red-600/20'
    }

    // Always show the Metadata section content, even if no fields (for consistency)
    return (
        <>
            <div>
                {evaluationStatus === 'pending' && (
                    <div className="mb-3">
                        <p className="text-xs text-gray-500 italic">⏳ Evaluating...</p>
                        {brandDnaEnabled && (
                            <button
                                type="button"
                                onClick={async () => {
                                    if (!assetId || rescoreLoading) return
                                    setRescoreLoading(true)
                                    try {
                                        const res = await fetch(`/app/assets/${assetId}/rescore`, {
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
                                            setTimeout(() => window.dispatchEvent(new CustomEvent('metadata-updated')), 2000)
                                        }
                                    } finally {
                                        setRescoreLoading(false)
                                    }
                                }}
                                disabled={rescoreLoading}
                                className="mt-1 inline-flex items-center gap-1 text-xs text-indigo-600 hover:text-indigo-800 disabled:opacity-50"
                            >
                                <ArrowPathRoundedSquareIcon className="h-3 w-3" />
                                {rescoreLoading ? 'Recalculating…' : 'Recalculate Score'}
                            </button>
                        )}
                    </div>
                )}
                {evaluationStatus === 'not_applicable' && (
                    <div className="mb-3">
                        <p className="text-xs text-gray-500 italic">Brand compliance not configured.</p>
                        {brandDnaEnabled && (
                            <button
                                type="button"
                                onClick={async () => {
                                    if (!assetId || rescoreLoading) return
                                    setRescoreLoading(true)
                                    try {
                                        const res = await fetch(`/app/assets/${assetId}/rescore`, {
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
                                            setTimeout(() => window.dispatchEvent(new CustomEvent('metadata-updated')), 2000)
                                        }
                                    } finally {
                                        setRescoreLoading(false)
                                    }
                                }}
                                disabled={rescoreLoading}
                                className="mt-1 inline-flex items-center gap-1 text-xs text-indigo-600 hover:text-indigo-800 disabled:opacity-50"
                            >
                                <ArrowPathRoundedSquareIcon className="h-3 w-3" />
                                {rescoreLoading ? 'Recalculating…' : 'Recalculate Score'}
                            </button>
                        )}
                    </div>
                )}
                {evaluationStatus === 'incomplete' && (
                    <div className="mb-3">
                        <p className="text-xs text-amber-600 font-medium">⚠ Incomplete brand data.</p>
                        <p className="mt-0.5 text-[11px] text-gray-500">This asset is missing required metadata for evaluation.</p>
                        {brandDnaEnabled && (
                            <button
                                type="button"
                                onClick={async () => {
                                    if (!assetId || rescoreLoading) return
                                    setRescoreLoading(true)
                                    try {
                                        const res = await fetch(`/app/assets/${assetId}/rescore`, {
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
                                            setTimeout(() => window.dispatchEvent(new CustomEvent('metadata-updated')), 2000)
                                        }
                                    } finally {
                                        setRescoreLoading(false)
                                    }
                                }}
                                disabled={rescoreLoading}
                                className="mt-1 inline-flex items-center gap-1 text-xs text-indigo-600 hover:text-indigo-800 disabled:opacity-50"
                            >
                                <ArrowPathRoundedSquareIcon className="h-3 w-3" />
                                {rescoreLoading ? 'Recalculating…' : 'Recalculate Score'}
                            </button>
                        )}
                    </div>
                )}
                {evaluationStatus === 'evaluated' && complianceScore != null && (
                    <div className="mb-3">
                        <button
                            type="button"
                            onClick={() => setComplianceExpanded(!complianceExpanded)}
                            className={`inline-flex items-center gap-1.5 rounded-md px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset cursor-pointer hover:opacity-90 ${getComplianceBadgeClass(complianceScore)}`}
                        >
                            On-Brand Score: {complianceScore}%
                            <span className="text-[10px] opacity-75">{complianceExpanded ? '▼' : '▶'}</span>
                        </button>
                        <p className="mt-1 text-[11px] text-gray-500">
                            This score reflects how well this execution aligns with your active Brand DNA scoring rules.
                            {complianceBreakdown && (() => {
                                const evaluated = ['color', 'typography', 'tone', 'imagery'].filter((k) => complianceBreakdown[k]?.status === 'scored')
                                if (evaluated.length === 1) return ` Based on ${evaluated[0].charAt(0).toUpperCase() + evaluated[0].slice(1)} only.`
                                if (evaluated.length > 1) return ` Based on ${evaluated.map((k) => k.charAt(0).toUpperCase() + k.slice(1)).join(', ')}.`
                                return ''
                            })()}
                        </p>
                        {complianceExpanded && complianceBreakdown && (
                            <div className="mt-2 rounded-lg border border-gray-200 bg-gray-50/80 p-3 text-xs space-y-2">
                                {['color', 'typography', 'tone', 'imagery'].map((key) => {
                                    const item = complianceBreakdown[key]
                                    if (!item) return null
                                    const score = item.score ?? item
                                    const label = key.charAt(0).toUpperCase() + key.slice(1)
                                    return (
                                        <div key={key}>
                                            <span className="font-medium text-gray-700">{label}: {score}%</span>
                                            {item.reason && <p className="mt-0.5 text-gray-600">{item.reason}</p>}
                                        </div>
                                    )
                                })}
                                {brandDnaEnabled && (
                                    <div className="pt-2 border-t border-gray-200">
                                        <button
                                            type="button"
                                            onClick={async () => {
                                                if (!assetId || rescoreLoading) return
                                                setRescoreLoading(true)
                                                try {
                                                    const res = await fetch(`/app/assets/${assetId}/rescore`, {
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
                                                        setTimeout(() => window.dispatchEvent(new CustomEvent('metadata-updated')), 2000)
                                                    }
                                                } finally {
                                                    setRescoreLoading(false)
                                                }
                                            }}
                                            disabled={rescoreLoading}
                                            className="inline-flex items-center gap-1 text-indigo-600 hover:text-indigo-800 disabled:opacity-50"
                                        >
                                            <ArrowPathRoundedSquareIcon className="h-3.5 w-3.5" />
                                            {rescoreLoading ? 'Recalculating…' : 'Recalculate Score'}
                                        </button>
                                    </div>
                                )}
                            </div>
                        )}
                    </div>
                )}
                {fields.length === 0 ? (
                    <div className="text-sm text-gray-500 italic">No editable metadata fields available</div>
                ) : (
                    <dl className="space-y-2 md:space-y-3">
                        {fields.filter(field => !isExcludedFromGenericLoop(field)).sort((a, b) => {
                            // Sort: non-auto fields first, auto fields last
                            const aIsAuto = a.readonly || a.population_mode === 'automatic'
                            const bIsAuto = b.readonly || b.population_mode === 'automatic'
                            
                            if (aIsAuto && !bIsAuto) return 1  // a is auto, b is not - a goes after b
                            if (!aIsAuto && bIsAuto) return -1 // a is not auto, b is - a goes before b
                            return 0 // Both same type, maintain original order
                        }).flatMap((field) => {
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
                                                {/* Rating: inline control. Starred/other booleans with display_widget=toggle use Edit → modal (brand-colored toggle). */}
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
                                                        editable={field.can_edit !== false && (field.key === 'quality_rating' || (!field.readonly && field.population_mode !== 'automatic'))}
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
                        }).concat(
                            // C9.2: Show Collections whenever collectionDisplay is provided (all asset types including video)
                            (collectionDisplay && Array.isArray(collectionDisplay.collections)) ? [
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
                            ] : []
                        ).concat(
                            // Tags row (from API; all asset types including video)
                            [
                                <div
                                    key="tags-field"
                                    className="flex flex-col md:flex-row md:items-start md:justify-between gap-1 md:gap-4 md:flex-nowrap"
                                >
                                    <div className="flex flex-col md:flex-row md:items-start md:gap-4 md:flex-1 md:min-w-0 md:flex-wrap">
                                        <dt className="text-sm text-gray-500 mb-1 md:mb-0 md:w-32 md:flex-shrink-0 flex items-center md:items-start">
                                            <span className="flex items-center flex-wrap gap-1 md:gap-1.5">
                                                <TagIcon className="h-4 w-4" aria-hidden="true" />
                                                Tags
                                            </span>
                                        </dt>
                                        <dd className="text-sm font-semibold text-gray-900 md:flex-1 md:min-w-0 break-words">
                                            {tagsLoading ? (
                                                <span className="text-gray-400">Loading…</span>
                                            ) : assetTags.length > 0 ? (
                                                <div className="flex items-center gap-2 flex-wrap">
                                                    {assetTags.map((t) => (
                                                        <span
                                                            key={t.id ?? t.tag}
                                                            className="inline-flex items-center gap-1 px-2 py-1 rounded-md text-xs font-medium bg-gray-100 text-gray-800"
                                                        >
                                                            {typeof t === 'string' ? t : (t.tag ?? t.name ?? String(t))}
                                                        </span>
                                                    ))}
                                                </div>
                                            ) : (
                                                <span className="text-gray-400">No tags</span>
                                            )}
                                        </dd>
                                    </div>
                                </div>
                            ]
                        )}
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
