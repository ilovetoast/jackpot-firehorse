/**
 * Metadata Field Input Component
 *
 * Phase 2 – Step 2: Renders appropriate input based on field type.
 * Phase 2 – Step 3: Adds required field indicators and validation.
 *
 * Supports all field types from the upload metadata schema.
 * Handles empty options gracefully.
 */

import { isFieldSatisfied } from '../../utils/metadataValidation'

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
export default function MetadataFieldInput({ field, value, onChange, disabled = false, showError = false }) {
    const isRequired = field.is_required || false
    // Phase 4: Check can_edit permission (default to true for backward compatibility)
    const canEdit = field.can_edit !== undefined ? field.can_edit : true
    const isDisabled = disabled || !canEdit
    const hasError = showError && isRequired && !isFieldSatisfied(field, value)
    const handleChange = (newValue) => {
        if (!isDisabled) {
            onChange(newValue)
        }
    }

    // Render based on field type
    switch (field.type) {
        case 'text':
            return (
                <div>
                    <label htmlFor={field.key} className="block text-sm font-medium text-gray-700 mb-1">
                        {field.display_label}
                        {isRequired && <span className="text-red-500 ml-1">*</span>}
                        {isRequired && !hasError && (
                            <span className="ml-2 text-xs text-gray-500 font-normal">Required</span>
                        )}
                    </label>
                    <input
                        type="text"
                        id={field.key}
                        name={field.key}
                        value={value || ''}
                        onChange={(e) => handleChange(e.target.value)}
                        disabled={isDisabled}
                        title={!canEdit ? "You don't have permission to edit this field" : undefined}
                        className={`block w-full rounded-md shadow-sm focus:ring-indigo-500 sm:text-sm ${
                            hasError
                                ? 'border-red-300 focus:border-red-500 focus:ring-red-500'
                                : 'border-gray-300 focus:border-indigo-500 focus:ring-indigo-500'
                        } ${
                            disabled ? 'bg-gray-50 text-gray-500 cursor-not-allowed opacity-60' : ''
                        }`}
                    />
                    {hasError && (
                        <p className="mt-1 text-xs text-red-600">This field is required.</p>
                    )}
                </div>
            )

        case 'number':
            return (
                <div>
                    <label htmlFor={field.key} className="block text-sm font-medium text-gray-700 mb-1">
                        {field.display_label}
                        {isRequired && <span className="text-red-500 ml-1">*</span>}
                        {isRequired && !hasError && (
                            <span className="ml-2 text-xs text-gray-500 font-normal">Required</span>
                        )}
                    </label>
                    <input
                        type="number"
                        id={field.key}
                        name={field.key}
                        value={value || ''}
                        onChange={(e) => handleChange(e.target.value === '' ? null : Number(e.target.value))}
                        disabled={isDisabled}
                        title={!canEdit ? "You don't have permission to edit this field" : undefined}
                        className={`block w-full rounded-md shadow-sm focus:ring-indigo-500 sm:text-sm ${
                            hasError
                                ? 'border-red-300 focus:border-red-500 focus:ring-red-500'
                                : 'border-gray-300 focus:border-indigo-500 focus:ring-indigo-500'
                        } ${
                            disabled ? 'bg-gray-50 text-gray-500 cursor-not-allowed opacity-60' : ''
                        }`}
                    />
                    {hasError && (
                        <p className="mt-1 text-xs text-red-600">This field is required.</p>
                    )}
                </div>
            )

        case 'boolean':
            return (
                <div>
                    <label className="flex items-center">
                        <input
                            type="checkbox"
                            checked={value === true || value === 'true'}
                            onChange={(e) => handleChange(e.target.checked)}
                            disabled={isDisabled}
                        title={!canEdit ? "You don't have permission to edit this field" : undefined}
                            className={`h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded ${
                                hasError ? 'border-red-300' : ''
                            } ${
                                isDisabled ? 'cursor-not-allowed opacity-60' : ''
                            }`}
                        />
                        <span className="ml-2 text-sm font-medium text-gray-700">
                            {field.display_label}
                            {isRequired && <span className="text-red-500 ml-1">*</span>}
                            {isRequired && !hasError && (
                                <span className="ml-2 text-xs text-gray-500 font-normal">Required</span>
                            )}
                        </span>
                    </label>
                    {hasError && (
                        <p className="mt-1 text-xs text-red-600 ml-6">This field is required.</p>
                    )}
                </div>
            )

        case 'date':
            return (
                <div>
                    <label htmlFor={field.key} className="block text-sm font-medium text-gray-700 mb-1">
                        {field.display_label}
                        {isRequired && <span className="text-red-500 ml-1">*</span>}
                        {isRequired && !hasError && (
                            <span className="ml-2 text-xs text-gray-500 font-normal">Required</span>
                        )}
                    </label>
                    <input
                        type="date"
                        id={field.key}
                        name={field.key}
                        value={value || ''}
                        onChange={(e) => handleChange(e.target.value)}
                        disabled={isDisabled}
                        title={!canEdit ? "You don't have permission to edit this field" : undefined}
                        className={`block w-full rounded-md shadow-sm focus:ring-indigo-500 sm:text-sm ${
                            hasError
                                ? 'border-red-300 focus:border-red-500 focus:ring-red-500'
                                : 'border-gray-300 focus:border-indigo-500 focus:ring-indigo-500'
                        } ${
                            disabled ? 'bg-gray-50 text-gray-500 cursor-not-allowed opacity-60' : ''
                        }`}
                    />
                    {hasError && (
                        <p className="mt-1 text-xs text-red-600">This field is required.</p>
                    )}
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
                <div>
                    <label htmlFor={field.key} className="block text-sm font-medium text-gray-700 mb-1">
                        {field.display_label}
                        {isRequired && <span className="text-red-500 ml-1">*</span>}
                        {isRequired && !hasError && (
                            <span className="ml-2 text-xs text-gray-500 font-normal">Required</span>
                        )}
                    </label>
                    <select
                        id={field.key}
                        name={field.key}
                        value={value || ''}
                        onChange={(e) => handleChange(e.target.value)}
                        disabled={isDisabled}
                        title={!canEdit ? "You don't have permission to edit this field" : undefined}
                        className={`block w-full rounded-md shadow-sm focus:ring-indigo-500 sm:text-sm ${
                            hasError
                                ? 'border-red-300 focus:border-red-500 focus:ring-red-500'
                                : 'border-gray-300 focus:border-indigo-500 focus:ring-indigo-500'
                        } ${
                            disabled ? 'bg-gray-50 text-gray-500 cursor-not-allowed opacity-60' : ''
                        }`}
                    >
                        <option value="">Select {field.display_label.toLowerCase()}</option>
                        {field.options.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.display_label}
                            </option>
                        ))}
                    </select>
                    {hasError && (
                        <p className="mt-1 text-xs text-red-600">This field is required.</p>
                    )}
                </div>
            )

        case 'multiselect':
            // Handle empty options
            if (!field.options || field.options.length === 0) {
                return (
                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">
                            {field.display_label}
                        </label>
                        <div className="rounded-md border border-gray-300 bg-gray-50 p-3">
                            <p className="text-xs text-gray-500">
                                No options are available for this field.
                            </p>
                        </div>
                    </div>
                )
            }

            // Ensure value is an array
            const currentValues = Array.isArray(value) ? value : []

            return (
                <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">
                        {field.display_label}
                        {isRequired && <span className="text-red-500 ml-1">*</span>}
                        {isRequired && !hasError && (
                            <span className="ml-2 text-xs text-gray-500 font-normal">Required</span>
                        )}
                    </label>
                    <div className="space-y-2">
                        {field.options.map((option) => {
                            const isSelected = currentValues.includes(option.value)
                            return (
                                <label
                                    key={option.value}
                                    className="flex items-center"
                                >
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
                                        className={`h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded ${
                                            hasError ? 'border-red-300' : ''
                                        } ${
                                            isDisabled ? 'cursor-not-allowed opacity-60' : ''
                                        }`}
                                    />
                                    <span className="ml-2 text-sm text-gray-700">
                                        {option.display_label}
                                    </span>
                                </label>
                            )
                        })}
                    </div>
                    {hasError && (
                        <p className="mt-1 text-xs text-red-600">At least one option must be selected.</p>
                    )}
                </div>
            )

        default:
            // Unknown type - fail safe (render nothing)
            return null
    }
}
