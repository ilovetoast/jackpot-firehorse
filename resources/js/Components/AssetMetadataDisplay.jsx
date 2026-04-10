/**
 * Asset Metadata Display Component
 *
 * Phase 2 – Step 6: Displays metadata read-only with edit actions.
 * Uses WidgetResolver for centralized widget rendering logic.
 */

import { useState, useEffect } from 'react'
import MetadataAnalysisRunningBanner from './MetadataAnalysisRunningBanner'
import { PencilIcon, LockClosedIcon, ArrowPathIcon, CheckIcon, XMarkIcon, RectangleStackIcon, GlobeAltIcon } from '@heroicons/react/24/outline'
import { usePage } from '@inertiajs/react'
import AssetMetadataEditModal from './AssetMetadataEditModal'
import DominantColorsSwatches from './DominantColorsSwatches'
import StarRating from './StarRating'
import { resolve, isExcludedFromGenericLoop, isDominantColorsSwatches, CONTEXT, WIDGET } from '../utils/widgetResolver'

/** Parse fetch body as JSON; avoids SyntaxError when server returns HTML error pages. */
async function parseJsonResponse(res) {
    const text = await res.text()
    if (!res.ok) {
        const err = new Error(`HTTP ${res.status}`)
        err.status = res.status
        err.bodySnippet = text.slice(0, 400)
        throw err
    }
    if (!text || !text.trim()) {
        return {}
    }
    try {
        return JSON.parse(text)
    } catch (e) {
        const err = new Error('Response was not valid JSON')
        err.cause = e
        err.bodySnippet = text.slice(0, 400)
        throw err
    }
}

/** Field keys rendered last (after Collection + auto/system fields) — legal / rights row near AI in drawer. */
const PINNED_BOTTOM_METADATA_KEYS = ['photo_type', 'usage_rights', 'expiration_date']
const PINNED_BOTTOM_METADATA_SET = new Set(PINNED_BOTTOM_METADATA_KEYS)

/** After pinned legal row — inline rating / starred toggles at bottom of drawer metadata. */
const DRAWER_QUICK_EDIT_LAST_KEYS = ['quality_rating', 'starred']
const DRAWER_QUICK_EDIT_LAST_SET = new Set(DRAWER_QUICK_EDIT_LAST_KEYS)

function metadataFieldKeyLo(f) {
    return String(f?.key || f?.field_key || '').toLowerCase()
}

export default function AssetMetadataDisplay({
    assetId,
    onPendingCountChange,
    collectionDisplay = null,
    primaryColor,
    suppressAnalysisRunningBanner = false,
    /** When true: values only — no edit buttons, rating changes, or collection edit (e.g. drawer quick view). */
    readOnly = false,
    /** Manage asset workspace: no pencil buttons — tap/click row to open edit (except inline toggle/rating). */
    workspaceMode = false,
    /** Fires when pipeline health / analysis status updates (parent may render the analysis banner in Revue). */
    onAnalysisPipelineStateChange = null,
    /** After a successful inline toggle save (e.g. Starred). Parent can sync grid / toast. */
    onToggleFieldSaved = null,
}) {
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

    const applyEditablePayload = (data) => {
        setFields(data.fields || [])
        const count = data.pending_metadata_count || 0
        setPendingMetadataCount(count)
        setMetadataHealth(data.metadata_health ?? null)
        setAnalysisStatus(data.analysis_status ?? 'uploading')
        setThumbnailStatus(data.thumbnail_status ?? 'pending')
        if (onPendingCountChange) {
            onPendingCountChange(count)
        }
    }

    // Fetch editable metadata (silent = true skips loading state, used for polling)
    const fetchMetadata = (silent = false) => {
        if (!assetId) return

        if (!silent) setLoading(true)
        fetch(`/app/assets/${assetId}/metadata/editable`, {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
            },
            credentials: 'same-origin',
        })
            .then((res) => parseJsonResponse(res))
            .then((data) => {
                applyEditablePayload(data)
                setLoading(false)
            })
            .catch((err) => {
                console.error('[AssetMetadataDisplay] Failed to fetch metadata', err.message, err.bodySnippet || err.cause || '')
                setLoading(false)
            })
    }

    useEffect(() => {
        fetchMetadata()
    }, [assetId])

    useEffect(() => {
        if (typeof onAnalysisPipelineStateChange !== 'function') {
            return
        }
        onAnalysisPipelineStateChange({
            metadataHealth,
            analysisStatus,
            thumbnailStatus,
        })
    }, [metadataHealth, analysisStatus, thumbnailStatus, onAnalysisPipelineStateChange])

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
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                },
                credentials: 'same-origin',
            })
                .then((res) => parseJsonResponse(res))
                .then((data) => {
                    applyEditablePayload(data)
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
                    Accept: 'application/json',
                },
                credentials: 'same-origin',
            })
            const data = await parseJsonResponse(res)
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
                            headers: {
                                Accept: 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                            },
                            credentials: 'same-origin',
                        })
                            .then((r) => parseJsonResponse(r))
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
            <div>
                {!suppressAnalysisRunningBanner && (
                    <MetadataAnalysisRunningBanner
                        metadataHealth={metadataHealth}
                        analysisStatus={analysisStatus}
                        thumbnailStatus={thumbnailStatus}
                        className="mb-4"
                    />
                )}
                {fields.length === 0 ? (
                    <div className="text-sm text-gray-500 italic">No editable metadata fields available</div>
                ) : (
                    <dl className="space-y-2 md:space-y-3">
                        {(() => {
                            const filtered = fields.filter(field => !isExcludedFromGenericLoop(field))
                            const isAuto = (f) => f.readonly || f.population_mode === 'automatic'
                            const isPinnedBottom = (f) => PINNED_BOTTOM_METADATA_SET.has(metadataFieldKeyLo(f))
                            const isQuickEditLast = (f) =>
                                DRAWER_QUICK_EDIT_LAST_SET.has(metadataFieldKeyLo(f))
                            const nonAutoFields = filtered.filter(f => !isAuto(f))
                            const nonAutoMain = nonAutoFields.filter(
                                (f) => !isPinnedBottom(f) && !isQuickEditLast(f),
                            )
                            const nonAutoQuickEditLast = nonAutoFields
                                .filter((f) => !isPinnedBottom(f) && isQuickEditLast(f))
                                .sort(
                                    (a, b) =>
                                        DRAWER_QUICK_EDIT_LAST_KEYS.indexOf(metadataFieldKeyLo(a)) -
                                        DRAWER_QUICK_EDIT_LAST_KEYS.indexOf(metadataFieldKeyLo(b)),
                                )
                            const nonAutoPinned = nonAutoFields
                                .filter((f) => isPinnedBottom(f))
                                .sort(
                                    (a, b) =>
                                        PINNED_BOTTOM_METADATA_KEYS.indexOf(metadataFieldKeyLo(a)) -
                                        PINNED_BOTTOM_METADATA_KEYS.indexOf(metadataFieldKeyLo(b)),
                                )
                            const autoFields = filtered.filter(f => isAuto(f)).sort((a, b) => 0)
                            // Order: user-managed fields (except pinned trio), Collection, system auto fields, then photo_type / usage_rights / expiration_date
                            const collectionElement =
                                collectionDisplay &&
                                (collectionDisplay.inlineContent ||
                                    Array.isArray(collectionDisplay.collections)) ? (
                                    <div
                                        key="collection-field"
                                        className={`flex flex-col md:flex-row ${
                                            collectionDisplay.inlineContent
                                                ? 'md:items-center'
                                                : 'md:items-start'
                                        } md:justify-between gap-1 md:gap-4 md:flex-nowrap ${
                                            workspaceMode &&
                                            typeof collectionDisplay.onEdit === 'function' &&
                                            !collectionDisplay.inlineContent
                                                ? 'cursor-pointer rounded-lg -mx-2 px-2 py-1.5 transition-colors hover:bg-gray-50'
                                                : ''
                                        }`}
                                        onClick={
                                            workspaceMode &&
                                            typeof collectionDisplay.onEdit === 'function' &&
                                            !collectionDisplay.inlineContent
                                                ? (e) => {
                                                      if (e.target.closest('button, a, input, select, [role="checkbox"]'))
                                                          return
                                                      collectionDisplay.onEdit()
                                                  }
                                                : undefined
                                        }
                                        role={
                                            workspaceMode &&
                                            typeof collectionDisplay.onEdit === 'function' &&
                                            !collectionDisplay.inlineContent
                                                ? 'button'
                                                : undefined
                                        }
                                        tabIndex={
                                            workspaceMode &&
                                            typeof collectionDisplay.onEdit === 'function' &&
                                            !collectionDisplay.inlineContent
                                                ? 0
                                                : undefined
                                        }
                                        onKeyDown={
                                            workspaceMode &&
                                            typeof collectionDisplay.onEdit === 'function' &&
                                            !collectionDisplay.inlineContent
                                                ? (e) => {
                                                      if (e.key === 'Enter' || e.key === ' ') {
                                                          e.preventDefault()
                                                          collectionDisplay.onEdit()
                                                      }
                                                  }
                                                : undefined
                                        }
                                    >
                                        <div className="flex flex-col md:flex-row md:items-start md:gap-4 md:flex-1 md:min-w-0 md:flex-wrap">
                                            <dt className="text-sm text-gray-500 mb-1 md:mb-0 md:w-32 md:flex-shrink-0 flex items-center md:items-start">
                                                <span className="flex items-center flex-wrap gap-1 md:gap-1.5">
                                                    Collection
                                                </span>
                                            </dt>
                                            <dd className="text-sm font-semibold text-gray-900 md:flex-1 md:min-w-0 break-words w-full min-w-0">
                                                {collectionDisplay.inlineContent ? (
                                                    collectionDisplay.inlineContent
                                                ) : collectionDisplay.loading ? (
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
                                        {!readOnly &&
                                            !collectionDisplay.inlineContent &&
                                            collectionDisplay.showEditButton !== false &&
                                            typeof collectionDisplay.onEdit === 'function' &&
                                            !workspaceMode && (
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
                            const isToggle = widget === WIDGET.TOGGLE
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
                            if (!displayValue && field.current_value !== null && field.current_value !== undefined && !dominantColorsArray && !isRating && !isToggle) {
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
                            const shouldShow =
                                displayValue ||
                                dominantColorsArray ||
                                isRating ||
                                isToggle ||
                                (!isAutoField && !field.readonly) ||
                                isSystemField
                            
                                if (!shouldShow) {
                                    return []
                                }

                            const fieldOpensEditModal =
                                !isRating &&
                                !(
                                    isToggle &&
                                    !readOnly &&
                                    field.can_edit !== false &&
                                    field.is_user_editable !== false
                                ) &&
                                !(field.readonly || field.population_mode === 'automatic') &&
                                !readOnly &&
                                field.can_edit !== false &&
                                field.is_user_editable !== false
                            
                            const fieldElement = (
                                <div 
                                    key={field.metadata_field_id} 
                                    className={`flex flex-col md:flex-row md:items-start md:justify-between gap-1 md:gap-4 md:flex-nowrap ${
                                        workspaceMode && fieldOpensEditModal
                                            ? 'group cursor-pointer rounded-lg border border-transparent -mx-2 px-2 py-1.5 transition-colors hover:border-gray-200 hover:bg-gray-50 focus-visible:border-indigo-300 focus-visible:bg-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-200'
                                            : ''
                                    }`}
                                    role={workspaceMode && fieldOpensEditModal ? 'button' : undefined}
                                    tabIndex={workspaceMode && fieldOpensEditModal ? 0 : undefined}
                                    aria-label={
                                        workspaceMode && fieldOpensEditModal
                                            ? `Edit ${field.display_label || 'field'}`
                                            : undefined
                                    }
                                    onClick={
                                        workspaceMode && fieldOpensEditModal
                                            ? (e) => {
                                                  if (e.target.closest('button, a, input, select, textarea, [role="checkbox"]'))
                                                      return
                                                  setEditingFieldId(field.metadata_field_id)
                                                  setEditingField(field)
                                              }
                                            : undefined
                                    }
                                    onKeyDown={
                                        workspaceMode && fieldOpensEditModal
                                            ? (e) => {
                                                  if (e.key === 'Enter' || e.key === ' ') {
                                                      e.preventDefault()
                                                      setEditingFieldId(field.metadata_field_id)
                                                      setEditingField(field)
                                                  }
                                              }
                                            : undefined
                                    }
                                >
                                    <div className="flex flex-col md:flex-row md:items-start md:gap-4 md:flex-1 md:min-w-0 md:flex-wrap">
                                        {/* Mobile: label above, Desktop: fixed-width label column */}
                                        <dt className="text-sm text-gray-500 mb-1 md:mb-0 md:w-32 md:flex-shrink-0 flex items-center md:items-start">
                                            <span className="flex items-center flex-wrap gap-1 md:gap-1.5">
                                                {field.display_label}
                                            </span>
                                        </dt>
                                        {/* Show the value if there is one; system fields show label even when empty; workspace editable rows show a hint when empty */}
                                        {(displayValue ||
                                            dominantColorsArray ||
                                            isRating ||
                                            isToggle ||
                                            isSystemField ||
                                            (workspaceMode && fieldOpensEditModal)) ? (
                                            <dd className="text-sm font-semibold text-gray-900 md:flex-1 md:min-w-0 break-words">
                                                {isToggle &&
                                                !readOnly &&
                                                field.can_edit !== false &&
                                                field.is_user_editable !== false ? (
                                                    <label className="inline-flex shrink-0 cursor-pointer items-center gap-2">
                                                        <input
                                                            type="checkbox"
                                                            className="peer sr-only"
                                                            checked={
                                                                field.current_value === true ||
                                                                field.current_value === 'true'
                                                            }
                                                            onChange={async (e) => {
                                                                const newValue = e.target.checked
                                                                try {
                                                                    const response = await fetch(
                                                                        `/app/assets/${assetId}/metadata/edit`,
                                                                        {
                                                                            method: 'POST',
                                                                            headers: {
                                                                                'Content-Type': 'application/json',
                                                                                'X-CSRF-TOKEN':
                                                                                    document.querySelector(
                                                                                        'meta[name="csrf-token"]',
                                                                                    )?.content,
                                                                            },
                                                                            credentials: 'same-origin',
                                                                            body: JSON.stringify({
                                                                                metadata_field_id:
                                                                                    field.metadata_field_id,
                                                                                value: newValue,
                                                                            }),
                                                                        },
                                                                    )
                                                                    if (!response.ok) {
                                                                        const data = await response.json()
                                                                        throw new Error(
                                                                            data.message || 'Failed to save',
                                                                        )
                                                                    }
                                                                    setFields((prevFields) =>
                                                                        prevFields.map((f) =>
                                                                            f.metadata_field_id ===
                                                                            field.metadata_field_id
                                                                                ? { ...f, current_value: newValue }
                                                                                : f,
                                                                        ),
                                                                    )
                                                                    if (typeof onToggleFieldSaved === 'function') {
                                                                        onToggleFieldSaved({
                                                                            assetId,
                                                                            fieldKey:
                                                                                field.key ||
                                                                                field.field_key ||
                                                                                '',
                                                                            value: newValue,
                                                                        })
                                                                    }
                                                                } catch (err) {
                                                                    console.error(
                                                                        '[AssetMetadataDisplay] Failed to save toggle',
                                                                        err,
                                                                    )
                                                                }
                                                            }}
                                                        />
                                                        <div
                                                            className="relative box-border h-6 w-12 min-w-12 max-w-12 shrink-0 rounded-full bg-gray-200 after:pointer-events-none after:absolute after:left-[2px] after:top-1/2 after:z-0 after:h-5 after:w-5 after:-translate-y-1/2 after:rounded-full after:border after:border-gray-300 after:bg-white after:shadow-sm after:transition-transform after:duration-200 peer-checked:after:translate-x-6 peer-checked:after:-translate-y-1/2 peer-focus-visible:outline peer-focus-visible:ring-4 peer-focus-visible:ring-offset-1"
                                                            style={{
                                                                ['--tw-ring-color']: brandPrimary,
                                                                ...(field.current_value === true ||
                                                                field.current_value === 'true'
                                                                    ? { backgroundColor: brandPrimary }
                                                                    : {}),
                                                            }}
                                                            aria-hidden
                                                        />
                                                    </label>
                                                ) : isToggle ? (
                                                    <span className="font-semibold">{displayValue}</span>
                                                ) : isRating ? (
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
                                                        editable={
                                                            !readOnly &&
                                                            field.can_edit !== false &&
                                                            (field.key === 'quality_rating' ||
                                                                (!field.readonly && field.population_mode !== 'automatic'))
                                                        }
                                                        maxStars={5}
                                                        size="md"
                                                    />
                                                ) : dominantColorsArray ? (
                                                    <DominantColorsSwatches dominantColors={dominantColorsArray} />
                                                ) : displayValue != null && displayValue !== '' ? (
                                                    displayValue
                                                ) : isSystemField ? (
                                                    <span className="text-gray-400 italic">—</span>
                                                ) : workspaceMode && fieldOpensEditModal ? (
                                                    <span className="font-normal text-gray-400 italic">
                                                        Click to set…
                                                    </span>
                                                ) : null}
                                            </dd>
                                        ) : null}
                                    </div>
                                    {/* Show "Auto" badge where edit button would be for readonly/automatic fields */}
                                    {/* Rating + toggle: inline controls — no Edit button */}
                                    {/* Only show edit button if user has edit permission (can_edit/is_user_editable) */}
                                    {isRating || (isToggle && !readOnly && field.can_edit !== false && field.is_user_editable !== false) ? null : (field.readonly || field.population_mode === 'automatic') ? (
                                        <div className="self-start md:self-auto ml-auto md:ml-0 flex-shrink-0 inline-flex items-center gap-1 text-xs text-gray-500">
                                            <LockClosedIcon className="h-3 w-3" />
                                            <span className="italic">Auto</span>
                                        </div>
                                    ) : !readOnly && field.can_edit !== false && field.is_user_editable !== false ? (
                                        workspaceMode && fieldOpensEditModal ? (
                                            <div
                                                className="pointer-events-none self-start md:self-center ml-auto flex-shrink-0 select-none inline-flex items-center gap-1.5 rounded-md border border-gray-200 bg-white px-2 py-1 text-xs font-medium text-gray-600 shadow-sm group-hover:border-indigo-200 group-hover:text-gray-800"
                                                aria-hidden="true"
                                            >
                                                <PencilIcon
                                                    className="h-3.5 w-3.5 shrink-0"
                                                    style={{ color: brandPrimary }}
                                                />
                                                <span>{fieldHasValue ? 'Edit' : 'Set'}</span>
                                            </div>
                                        ) : workspaceMode ? null : (
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
                                        )
                                    ) : null}
                                </div>
                            )
                            
                                return [fieldElement]
                            }

                            const nonAutoMainElements = nonAutoMain.flatMap(renderField)
                            const autoElements = autoFields.flatMap(renderField)
                            const nonAutoPinnedElements = nonAutoPinned.flatMap(renderField)
                            const nonAutoQuickEditLastElements = nonAutoQuickEditLast.flatMap(renderField)
                            return [
                                ...nonAutoMainElements,
                                ...(collectionElement ? [collectionElement] : []),
                                ...autoElements,
                                ...nonAutoPinnedElements,
                                ...nonAutoQuickEditLastElements,
                            ]
                        })()}
                    </dl>
                )}
            </div>

            {/* Edit Modal: pass brand primary so toggle widgets use it (display_widget=toggle / starred) */}
            {editingField && !readOnly && (
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
