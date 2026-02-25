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

import { isFieldSatisfied } from '../../utils/metadataValidation'
import TagInputUnified from '../TagInputUnified'
import CollectionSelector from '../Collections/CollectionSelector'
import StarRating from '../StarRating'
import { usePage } from '@inertiajs/react'

/**
 * MetadataFieldInput - Renders metadata field input
 *
 * @param {Object} props
 * @param {Object} props.field - Field object from schema
 * @param {any} props.value - Current field value
 * @param {Function} props.onChange - Callback when value changes
 * @param {boolean} [props.disabled] - Whether field is disabled
 * @param {boolean} [props.showError] - Whether to show validation error
 */
export default function MetadataFieldInput({ field, value, onChange, disabled = false, showError = false, isUploadContext = true, collectionProps = null }) {
    const isRequired = field.is_required || false
    // UPLOAD CONTEXT FIX: During upload, all fields are editable (approval happens after upload)
    // For non-upload contexts (e.g., asset drawer), respect can_edit permission
    const canEdit = isUploadContext ? true : (field.can_edit !== undefined ? field.can_edit : true)
    const isDisabled = disabled || !canEdit
    const hasError = showError && isRequired && !isFieldSatisfied(field, value)
    const handleChange = (newValue) => {
        if (!isDisabled) {
            onChange(newValue)
        }
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
        const { tenant } = usePage().props
        const tenantId = tenant?.id
        
        return (
            <div className="flex items-start gap-2 min-w-0">
                <label className="flex-shrink-0 w-56 text-sm font-medium text-gray-700 pt-0.5">
                    {field.display_label}
                    {isRequired && <span className="text-red-500 ml-0.5">*</span>}
                </label>
                <div className="flex-1 min-w-0">
                    <TagInputUnified
                        mode="upload"
                        value={Array.isArray(value) ? value : []}
                        onChange={handleChange}
                        tenantId={tenantId}
                        placeholder="Add tags..."
                        showTitle={false}
                        title={field.display_label}
                        showCounter={true}
                        maxTags={10}
                        compact={false}
                        className="w-full"
                        ariaLabel={`${field.display_label} input`}
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
            // Handle empty options
            if (!field.options || field.options.length === 0) {
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
                <div className="flex items-center gap-2 min-w-0">
                    <label htmlFor={field.key} className="flex-shrink-0 w-56 text-sm font-medium text-gray-700">
                        {field.display_label}
                        {isRequired && <span className="text-red-500 ml-0.5">*</span>}
                    </label>
                    <select
                        id={field.key}
                        name={field.key}
                        value={value || ''}
                        onChange={(e) => handleChange(e.target.value)}
                        disabled={isDisabled}
                        title={!canEdit ? "You don't have permission to edit this field" : undefined}
                        className={`flex-1 min-w-0 rounded border shadow-sm focus:ring-indigo-500 text-sm text-gray-900 bg-white ${
                            hasError ? 'border-red-300 focus:border-red-500 focus:ring-red-500' : 'border-gray-300 focus:border-indigo-500 focus:ring-indigo-500'
                        } ${isDisabled ? 'bg-gray-50 text-gray-500 cursor-not-allowed opacity-60' : ''}`}
                    >
                        <option value="">Select</option>
                        {field.options.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.display_label}
                            </option>
                        ))}
                    </select>
                    {hasError && <span className="flex-shrink-0 text-xs text-red-600">Required</span>}
                </div>
            )

        case 'multiselect':
            if (!field.options || field.options.length === 0) {
                return (
                    <div className="flex items-center gap-2 min-w-0">
                        <label className="flex-shrink-0 w-56 text-sm font-medium text-gray-700">{field.display_label}</label>
                        <span className="text-xs text-gray-500">No options</span>
                    </div>
                )
            }
            const currentValues = Array.isArray(value) ? value : []
            return (
                <div className="flex items-start gap-2 min-w-0">
                    <label className="flex-shrink-0 w-56 text-sm font-medium text-gray-700 pt-0.5">
                        {field.display_label}
                        {isRequired && <span className="text-red-500 ml-0.5">*</span>}
                    </label>
                    <div className="flex-1 min-w-0 flex flex-wrap gap-x-3 gap-y-1">
                        {field.options.map((option) => {
                            const isSelected = currentValues.includes(option.value)
                            return (
                                <label key={option.value} className="flex items-center">
                                    <input
                                        type="checkbox"
                                        checked={isSelected}
                                        onChange={(e) => {
                                            const newValues = e.target.checked
                                                ? [...currentValues, option.value]
                                                : currentValues.filter(v => v !== option.value)
                                            handleChange(newValues)
                                        }}
                                        disabled={isDisabled}
                                        title={!canEdit ? "You don't have permission to edit this field" : undefined}
                                        className={`h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded ${hasError ? 'border-red-300' : ''} ${isDisabled ? 'cursor-not-allowed opacity-60' : ''}`}
                                    />
                                    <span className="ml-1.5 text-sm text-gray-700">{option.display_label}</span>
                                </label>
                            )
                        })}
                    </div>
                    {hasError && <span className="flex-shrink-0 text-xs text-red-600">Required</span>}
                </div>
            )

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
}
