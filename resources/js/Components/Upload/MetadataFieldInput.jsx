/**
 * Metadata Field Input Component
 *
 * Phase 2 – Step 2: Renders appropriate input based on field type.
 * Phase 2 – Step 3: Adds required field indicators and validation.
 * Phase J.2.8: Special handling for tags field using TagInputUnified.
 *
 * Supports all field types from the upload metadata schema.
 * Handles empty options gracefully.
 */

import { forwardRef, useCallback, useEffect, useMemo, useState } from 'react'
import { isFieldSatisfied } from '../../utils/metadataValidation'
import TagInputUnified from '../TagInputUnified'
import CollectionSelector from '../Collections/CollectionSelector'
import StarRating from '../StarRating'
import { usePage } from '@inertiajs/react'

/** Storage value for a new custom select/multiselect option (server accepts slug-like values). */
function slugifyMetadataOptionValue(label) {
    const raw = String(label).trim()
    if (!raw) {
        return `v_${Date.now()}`
    }
    const s = raw
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '')
    return s || `v_${Date.now()}`
}

/**
 * MetadataFieldInput - Renders metadata field input
 *
 * @param {Object} props
 * @param {Object} props.field - Field object from schema
 * @param {any} props.value - Current field value
 * @param {Function} props.onChange - Callback when value changes
 * @param {boolean} [props.disabled] - Whether field is disabled
 * @param {boolean} [props.showError] - Whether to show validation error
 * @param {'default' | 'modal'} [props.layout] - "modal" = stacked label, full width, tall list in quick edit modals
 */
const MetadataFieldInput = forwardRef(function MetadataFieldInput(
    {
        field,
        value,
        onChange,
        disabled = false,
        showError = false,
        isUploadContext = true,
        collectionProps = null,
        tagsPlaceholder = null,
        layout = 'default',
    },
    ref
) {
    const { auth, currentWorkspace } = usePage().props
    const [appendedOptions, setAppendedOptions] = useState([])
    const [addOptionOpen, setAddOptionOpen] = useState(false)
    const [addOptionLabel, setAddOptionLabel] = useState('')
    const [addOptionLoading, setAddOptionLoading] = useState(false)
    const [addOptionError, setAddOptionError] = useState(null)

    useEffect(() => {
        setAppendedOptions([])
        setAddOptionOpen(false)
        setAddOptionLabel('')
        setAddOptionError(null)
    }, [field?.metadata_field_id])

    const mergedOptions = useMemo(() => {
        const base = field?.options || []
        const seen = new Set(base.map((o) => o?.value).filter((v) => v != null && v !== ''))
        return [
            ...base,
            ...appendedOptions.filter((o) => o && o.value != null && o.value !== '' && !seen.has(o.value)),
        ]
    }, [field?.options, appendedOptions])

    const isRequired = field.is_required || false
    // UPLOAD CONTEXT FIX: During upload, all fields are editable (approval happens after upload)
    // For non-upload contexts (e.g., asset drawer), respect can_edit permission
    const canEdit = isUploadContext ? true : (field.can_edit !== undefined ? field.can_edit : true)
    const isDisabled = disabled || !canEdit
    const hasError = showError && isRequired && !isFieldSatisfied(field, value)
    const canAddOptionsOnTheFly =
        field?.can_add_options === true &&
        !!field?.metadata_field_id &&
        (field?.type === 'select' || field?.type === 'multiselect') &&
        !isDisabled

    const handleChange = (newValue) => {
        if (!isDisabled) {
            onChange(newValue)
        }
    }

    const submitNewOption = useCallback(async () => {
        const label = addOptionLabel.trim()
        if (!label || addOptionLoading || !field?.metadata_field_id) {
            return
        }
        setAddOptionLoading(true)
        setAddOptionError(null)
        const valueSlug = slugifyMetadataOptionValue(label)
        const url =
            typeof route === 'function'
                ? route('tenant.metadata.fields.values.add', { field: field.metadata_field_id })
                : `/app/tenant/metadata/fields/${field.metadata_field_id}/values`
        try {
            const res = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
                credentials: 'same-origin',
                body: JSON.stringify({ value: valueSlug, system_label: label }),
            })
            const data = await res.json().catch(() => ({}))
            if (!res.ok) {
                throw new Error(
                    (typeof data.error === 'string' && data.error) || data?.message || `Request failed (${res.status})`
                )
            }
            const newValue = data.option?.value ?? valueSlug
            const displayLabel = data.option?.display_label ?? label
            setAppendedOptions((prev) => [...prev, { value: newValue, display_label: displayLabel }])
            if (field.type === 'multiselect') {
                const cur = Array.isArray(value)
                    ? value
                    : value != null && value !== ''
                      ? [String(value)]
                      : []
                if (!isDisabled) {
                    onChange([...cur, newValue])
                }
            } else if (field.type === 'select' && !isDisabled) {
                onChange(newValue)
            }
            setAddOptionLabel('')
            setAddOptionOpen(false)
        } catch (e) {
            setAddOptionError(e?.message || 'Failed to add value')
        } finally {
            setAddOptionLoading(false)
        }
    }, [addOptionLabel, addOptionLoading, field, isDisabled, onChange, value])

    const renderOptionAddRow = (className = '') => {
        if (!canAddOptionsOnTheFly) {
            return null
        }
        return (
            <div
                className={`rounded-md border border-dashed border-slate-200 bg-slate-50/80 px-2 py-2 text-left ${className}`.trim()}
            >
                {!addOptionOpen ? (
                    <button
                        type="button"
                        onClick={() => {
                            setAddOptionOpen(true)
                            setAddOptionError(null)
                        }}
                        className="text-xs font-medium text-indigo-600 hover:text-indigo-500"
                    >
                        + Add new value
                    </button>
                ) : (
                    <div className="flex flex-col gap-2 sm:flex-row sm:items-end">
                        <div className="min-w-0 flex-1">
                            <label htmlFor={`add-opt-${field.metadata_field_id}`} className="sr-only">
                                New {field.display_label} option
                            </label>
                            <input
                                id={`add-opt-${field.metadata_field_id}`}
                                type="text"
                                value={addOptionLabel}
                                onChange={(e) => setAddOptionLabel(e.target.value)}
                                onKeyDown={(e) => {
                                    if (e.key === 'Enter') {
                                        e.preventDefault()
                                        submitNewOption()
                                    }
                                }}
                                placeholder="New option label"
                                disabled={addOptionLoading}
                                className="block w-full rounded border border-slate-200 bg-white px-2 py-1.5 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                            />
                        </div>
                        <div className="flex shrink-0 gap-2">
                            <button
                                type="button"
                                onClick={submitNewOption}
                                disabled={addOptionLoading || !addOptionLabel.trim()}
                                className="rounded bg-indigo-600 px-2.5 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {addOptionLoading ? 'Adding…' : 'Add'}
                            </button>
                            <button
                                type="button"
                                onClick={() => {
                                    setAddOptionOpen(false)
                                    setAddOptionLabel('')
                                    setAddOptionError(null)
                                }}
                                disabled={addOptionLoading}
                                className="rounded border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50"
                            >
                                Cancel
                            </button>
                        </div>
                    </div>
                )}
                {addOptionError ? <p className="mt-1 text-xs text-red-600">{addOptionError}</p> : null}
            </div>
        )
    }

    // C9.2: Collections field — render inside General group when collectionProps provided (uploader)
    if (field.key === 'collection' && collectionProps) {
        const { collections, collectionsLoading, selectedIds, onChange: onCollectionChange, showCreateButton, onCreateClick } = collectionProps
        return (
            <div className="flex items-start gap-2 min-w-0">
                <label className="flex-shrink-0 w-56 text-sm font-medium text-gray-700 pt-0.5">
                    {field.display_label}
                    {isRequired && <span className="text-red-500 ml-0.5">*</span>}
                </label>
                <div className="flex-1 min-w-0">
                    {collectionsLoading ? (
                        <span className="text-sm text-gray-500">Loading…</span>
                    ) : (
                        <CollectionSelector
                            collections={collections || []}
                            selectedIds={selectedIds || []}
                            onChange={onCollectionChange}
                            disabled={isDisabled}
                            placeholder="Select"
                            showCreateButton={showCreateButton === true}
                            onCreateClick={onCreateClick}
                        />
                    )}
                </div>
                {hasError && <span className="flex-shrink-0 text-xs text-red-600">Required</span>}
            </div>
        )
    }

    // Phase J.2.8: Special handling for tags field
    if (field.key === 'tags') {
        const tenantId = auth?.activeCompany?.id ?? currentWorkspace?.id ?? null

        return (
            <div className="flex items-start gap-2 min-w-0">
                <label className="flex-shrink-0 w-56 text-sm font-medium text-gray-700 pt-0.5">
                    {field.display_label}
                    {isRequired && <span className="text-red-500 ml-0.5">*</span>}
                </label>
                <div className="flex-1 min-w-0">
                    <TagInputUnified
                        ref={ref}
                        mode="upload"
                        value={Array.isArray(value) ? value : []}
                        onChange={handleChange}
                        tenantId={tenantId}
                        placeholder={tagsPlaceholder || 'Add tags...'}
                        showTitle={false}
                        title={field.display_label}
                        showCounter={true}
                        maxTags={10}
                        compact={false}
                        className="w-full"
                        ariaLabel={`${field.display_label} input`}
                        suggestedTags={
                            Array.isArray(field.suggested_tags)
                                ? field.suggested_tags
                                : Array.isArray(field.suggested_values)
                                  ? field.suggested_values
                                  : null
                        }
                    />
                </div>
                {hasError && <span className="flex-shrink-0 text-xs text-red-600">Required</span>}
            </div>
        )
    }

    // Render based on field type
    switch (field.type) {
        case 'text':
            return (
                <div className="flex items-center gap-2 min-w-0">
                    <label htmlFor={field.key} className="flex-shrink-0 w-56 text-sm font-medium text-gray-700">
                        {field.display_label}
                        {isRequired && <span className="text-red-500 ml-0.5">*</span>}
                    </label>
                    <input
                        type="text"
                        id={field.key}
                        name={field.key}
                        value={value || ''}
                        onChange={(e) => handleChange(e.target.value)}
                        disabled={isDisabled}
                        title={!canEdit ? "You don't have permission to edit this field" : undefined}
                        className={`flex-1 min-w-0 rounded border shadow-sm focus:ring-indigo-500 text-sm ${
                            hasError
                                ? 'border-red-300 focus:border-red-500 focus:ring-red-500'
                                : 'border-gray-300 focus:border-indigo-500 focus:ring-indigo-500'
                        } ${disabled ? 'bg-gray-50 text-gray-500 cursor-not-allowed opacity-60' : ''}`}
                    />
                    {hasError && (
                        <span className="flex-shrink-0 text-xs text-red-600">Required</span>
                    )}
                </div>
            )

        case 'number':
            return (
                <div className="flex items-center gap-2 min-w-0">
                    <label htmlFor={field.key} className="flex-shrink-0 w-56 text-sm font-medium text-gray-700">
                        {field.display_label}
                        {isRequired && <span className="text-red-500 ml-0.5">*</span>}
                    </label>
                    <input
                        type="number"
                        id={field.key}
                        name={field.key}
                        value={value || ''}
                        onChange={(e) => handleChange(e.target.value === '' ? null : Number(e.target.value))}
                        disabled={isDisabled}
                        title={!canEdit ? "You don't have permission to edit this field" : undefined}
                        className={`flex-1 min-w-0 rounded border shadow-sm focus:ring-indigo-500 text-sm ${
                            hasError ? 'border-red-300 focus:border-red-500 focus:ring-red-500' : 'border-gray-300 focus:border-indigo-500 focus:ring-indigo-500'
                        } ${disabled ? 'bg-gray-50 text-gray-500 cursor-not-allowed opacity-60' : ''}`}
                    />
                    {hasError && <span className="flex-shrink-0 text-xs text-red-600">Required</span>}
                </div>
            )

        case 'boolean':
            // display_widget=toggle: same layout as filters/edit (stored in DB for consistency everywhere)
            if (field.display_widget === 'toggle') {
                return (
                    <div className="flex items-center gap-2 min-w-0">
                        <label className="flex-shrink-0 w-56 text-sm font-medium text-gray-700">
                            {field.display_label}
                            {isRequired && <span className="text-red-500 ml-0.5">*</span>}
                        </label>
                        <div className="relative inline-flex items-center flex-shrink-0">
                            <input
                                type="checkbox"
                                checked={value === true || value === 'true'}
                                onChange={(e) => handleChange(e.target.checked)}
                                disabled={isDisabled}
                                className="sr-only peer"
                            />
                            <div className="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-indigo-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600 disabled:opacity-50 disabled:cursor-not-allowed" />
                        </div>
                        {hasError && <span className="flex-shrink-0 text-xs text-red-600">Required</span>}
                    </div>
                )
            }
            return (
                <div className="flex items-center gap-2 min-w-0">
                    <label className="flex-shrink-0 w-56 text-sm font-medium text-gray-700">
                        {field.display_label}
                        {isRequired && <span className="text-red-500 ml-0.5">*</span>}
                    </label>
                    <input
                        type="checkbox"
                        checked={value === true || value === 'true'}
                        onChange={(e) => handleChange(e.target.checked)}
                        disabled={isDisabled}
                        title={!canEdit ? "You don't have permission to edit this field" : undefined}
                        className={`h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded ${hasError ? 'border-red-300' : ''} ${isDisabled ? 'cursor-not-allowed opacity-60' : ''}`}
                    />
                    {hasError && <span className="flex-shrink-0 text-xs text-red-600">Required</span>}
                </div>
            )

        case 'date':
            return (
                <div className="flex items-center gap-2 min-w-0">
                    <label htmlFor={field.key} className="flex-shrink-0 w-56 text-sm font-medium text-gray-700">
                        {field.display_label}
                        {isRequired && <span className="text-red-500 ml-0.5">*</span>}
                    </label>
                    <input
                        type="date"
                        id={field.key}
                        name={field.key}
                        value={value || ''}
                        onChange={(e) => handleChange(e.target.value)}
                        disabled={isDisabled}
                        title={!canEdit ? "You don't have permission to edit this field" : undefined}
                        className={`flex-1 min-w-0 rounded border shadow-sm focus:ring-indigo-500 text-sm ${
                            hasError ? 'border-red-300 focus:border-red-500 focus:ring-red-500' : 'border-gray-300 focus:border-indigo-500 focus:ring-indigo-500'
                        } ${disabled ? 'bg-gray-50 text-gray-500 cursor-not-allowed opacity-60' : ''}`}
                    />
                    {hasError && <span className="flex-shrink-0 text-xs text-red-600">Required</span>}
                </div>
            )

        case 'select':
            // Handle empty options (unless user may add values on the fly)
            if (!mergedOptions.length && !canAddOptionsOnTheFly) {
                return (
                    <div>
                        <label htmlFor={field.key} className="block text-sm font-medium text-gray-700 mb-1">
                            {field.display_label}
                        </label>
                        <select
                            id={field.key}
                            name={field.key}
                            disabled
                            className="block w-full rounded-md border-gray-300 shadow-sm bg-gray-50 text-gray-500 cursor-not-allowed opacity-60 sm:text-sm"
                        >
                            <option>No options available</option>
                        </select>
                        <p className="mt-1 text-xs text-gray-500">
                            No options are available for this field.
                        </p>
                    </div>
                )
            }

            return (
                <div className="min-w-0 space-y-2">
                    <div className="flex items-center gap-2 min-w-0">
                        <label htmlFor={field.key} className="flex-shrink-0 w-56 text-sm font-medium text-gray-700">
                            {field.display_label}
                            {isRequired && <span className="text-red-500 ml-0.5">*</span>}
                        </label>
                        <div className="min-w-0 flex-1">
                            <select
                                id={field.key}
                                name={field.key}
                                value={value || ''}
                                onChange={(e) => handleChange(e.target.value || null)}
                                disabled={isDisabled}
                                title={!canEdit ? "You don't have permission to edit this field" : undefined}
                                className={`w-full min-w-0 rounded border shadow-sm focus:ring-indigo-500 text-sm text-gray-900 bg-white ${
                                    hasError
                                        ? 'border-red-300 focus:border-red-500 focus:ring-red-500'
                                        : 'border-gray-300 focus:border-indigo-500 focus:ring-indigo-500'
                                } ${isDisabled ? 'bg-gray-50 text-gray-500 cursor-not-allowed opacity-60' : ''}`}
                            >
                                <option value="">{mergedOptions.length ? 'Select' : 'Add a value below'}</option>
                                {mergedOptions.map((option) => (
                                    <option key={option.value} value={option.value}>
                                        {option.display_label}
                                    </option>
                                ))}
                            </select>
                        </div>
                        {hasError && <span className="flex-shrink-0 text-xs text-red-600">Required</span>}
                    </div>
                    {renderOptionAddRow()}
                </div>
            )

        case 'multiselect': {
            const isModalList = layout === 'modal'
            if (!mergedOptions.length) {
                if (canAddOptionsOnTheFly) {
                    return (
                        <div
                            className={
                                isModalList
                                    ? 'flex w-full min-w-0 flex-col gap-2'
                                    : 'flex min-w-0 flex-col gap-2 sm:flex-row sm:items-start sm:gap-4'
                            }
                        >
                            <label
                                className={
                                    isModalList
                                        ? 'text-sm font-medium text-gray-700'
                                        : 'shrink-0 text-sm font-medium text-gray-700 sm:w-56 sm:pt-0.5'
                                }
                            >
                                {field.display_label}
                                {isRequired && <span className="text-red-500 ml-0.5">*</span>}
                            </label>
                            <div className="min-w-0 flex-1 space-y-2">
                                <p className="text-xs text-gray-500">No values yet. Add the first one below, then select it.</p>
                                {renderOptionAddRow()}
                            </div>
                        </div>
                    )
                }
                return (
                    <div
                        className={
                            isModalList
                                ? 'flex w-full min-w-0 flex-col gap-1.5'
                                : 'flex min-w-0 items-center gap-2'
                        }
                    >
                        <label
                            className={
                                isModalList
                                    ? 'text-sm font-medium text-gray-700'
                                    : 'w-56 flex-shrink-0 text-sm font-medium text-gray-700'
                            }
                        >
                            {field.display_label}
                        </label>
                        <span className="text-xs text-gray-500">No options</span>
                    </div>
                )
            }
            const currentValues = Array.isArray(value)
                ? value
                : value != null && value !== ''
                  ? [String(value)]
                  : []
            const listClassName = isModalList
                ? 'w-full max-h-[min(32rem,55vh)] divide-y divide-gray-100 overflow-y-auto rounded-lg border border-gray-200 bg-gradient-to-b from-white to-slate-50/80 shadow-sm'
                : 'max-h-64 divide-y divide-gray-100 overflow-y-auto rounded-lg border border-gray-200 bg-gradient-to-b from-white to-slate-50/80 shadow-sm'
            if (isModalList) {
                return (
                    <div className="flex w-full min-w-0 flex-col gap-2">
                        <label className="shrink-0 text-sm font-medium text-gray-700">
                            {field.display_label}
                            {isRequired && <span className="text-red-500 ml-0.5">*</span>}
                        </label>
                        <div className="w-full min-w-0">
                            <ul className={listClassName} aria-label={field.display_label}>
                                {mergedOptions.map((option) => {
                                    const isSelected = currentValues.includes(option.value)
                                    return (
                                        <li key={option.value}>
                                            <label
                                                className={`flex cursor-pointer items-start gap-3 px-3 py-2.5 transition-colors ${
                                                    isSelected ? 'bg-indigo-50/60' : 'hover:bg-white/90'
                                                } ${isDisabled ? 'cursor-not-allowed opacity-60' : ''}`}
                                            >
                                                <input
                                                    type="checkbox"
                                                    checked={isSelected}
                                                    onChange={(e) => {
                                                        const newValues = e.target.checked
                                                            ? [...currentValues, option.value]
                                                            : currentValues.filter((v) => v !== option.value)
                                                        handleChange(newValues)
                                                    }}
                                                    disabled={isDisabled}
                                                    title={!canEdit ? "You don't have permission to edit this field" : undefined}
                                                    className={`mt-0.5 h-4 w-4 shrink-0 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 ${
                                                        hasError ? 'border-red-300' : ''
                                                    } ${isDisabled ? 'cursor-not-allowed' : ''}`}
                                                />
                                                <span className="min-w-0 flex-1 text-sm leading-snug text-gray-800">
                                                    {option.display_label}
                                                </span>
                                            </label>
                                        </li>
                                    )
                                })}
                            </ul>
                        </div>
                        {renderOptionAddRow()}
                        {hasError && <p className="text-xs text-red-600">Required</p>}
                    </div>
                )
            }
            return (
                <div className="flex min-w-0 flex-col gap-3 sm:flex-row sm:items-start sm:gap-4">
                    <label className="shrink-0 text-sm font-medium text-gray-700 sm:w-56 sm:pt-0.5">
                        {field.display_label}
                        {isRequired && <span className="text-red-500 ml-0.5">*</span>}
                    </label>
                    <div className="min-w-0 flex-1 space-y-1.5">
                        <ul
                            className={listClassName}
                            aria-label={field.display_label}
                        >
                            {mergedOptions.map((option) => {
                                const isSelected = currentValues.includes(option.value)
                                return (
                                    <li key={option.value}>
                                        <label
                                            className={`flex cursor-pointer items-start gap-3 px-3 py-2.5 transition-colors sm:py-2 ${
                                                isSelected ? 'bg-indigo-50/60' : 'hover:bg-white/90'
                                            } ${isDisabled ? 'cursor-not-allowed opacity-60' : ''}`}
                                        >
                                            <input
                                                type="checkbox"
                                                checked={isSelected}
                                                onChange={(e) => {
                                                    const newValues = e.target.checked
                                                        ? [...currentValues, option.value]
                                                        : currentValues.filter((v) => v !== option.value)
                                                    handleChange(newValues)
                                                }}
                                                disabled={isDisabled}
                                                title={!canEdit ? "You don't have permission to edit this field" : undefined}
                                                className={`mt-0.5 h-4 w-4 shrink-0 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 ${
                                                    hasError ? 'border-red-300' : ''
                                                } ${isDisabled ? 'cursor-not-allowed' : ''}`}
                                            />
                                            <span className="min-w-0 flex-1 text-sm leading-snug text-gray-800">
                                                {option.display_label}
                                            </span>
                                        </label>
                                    </li>
                                )
                            })}
                        </ul>
                        {renderOptionAddRow()}
                        {hasError && <p className="text-xs text-red-600">Required</p>}
                    </div>
                </div>
            )
        }

        case 'rating':
            return (
                <div className="flex items-center gap-2 min-w-0">
                    <label className="flex-shrink-0 w-56 text-sm font-medium text-gray-700">
                        {field.display_label}
                        {isRequired && <span className="text-red-500 ml-0.5">*</span>}
                    </label>
                    <div className="flex-1 min-w-0">
                    <StarRating
                        value={value || 0}
                        onChange={handleChange}
                        editable={!isDisabled}
                        maxStars={5}
                        size="md"
                    />
                    </div>
                    {hasError && <span className="flex-shrink-0 text-xs text-red-600">Required</span>}
                </div>
            )

        default:
            // Unknown type - fail safe (render nothing)
            return null
    }
})

export default MetadataFieldInput
