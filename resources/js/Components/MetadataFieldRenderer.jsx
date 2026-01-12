/**
 * Phase 3.3 Metadata Field Renderer Component
 * 
 * Renders appropriate input component based on metadata field type.
 * Supports all field types defined in MetadataField interface.
 * 
 * @module MetadataFieldRenderer
 */

import { useState, useEffect } from 'react';

/**
 * MetadataFieldRenderer - Renders metadata field input
 * 
 * @param {Object} props
 * @param {MetadataField} props.field - Metadata field definition
 * @param {any} props.value - Current field value
 * @param {Function} props.onChange - Callback when value changes
 * @param {boolean} [props.hasError] - Whether field has validation error
 */
export default function MetadataFieldRenderer({ field, value, onChange, hasError = false, disabled = false }) {
    const [multiselectValues, setMultiselectValues] = useState(() => {
        // Initialize multiselect from value or empty array
        if (field.type === 'multiselect') {
            return Array.isArray(value) ? value : [];
        }
        return [];
    });

    // Sync multiselect state with value prop
    useEffect(() => {
        if (field.type === 'multiselect' && Array.isArray(value)) {
            setMultiselectValues(value);
        }
    }, [field.type, value]);

    /**
     * Handle input change based on field type
     */
    const handleChange = (newValue) => {
        if (field.type === 'multiselect') {
            // Multiselect handles its own state
            setMultiselectValues(newValue);
            onChange(newValue);
        } else {
            onChange(newValue);
        }
    };

    // Render based on field type
    switch (field.type) {
        case 'text':
            return (
                <div>
                    <label htmlFor={field.key} className="block text-sm font-medium text-gray-700 mb-1">
                        {field.label}
                        {field.required && <span className="text-red-500 ml-1">*</span>}
                    </label>
                    <input
                        type="text"
                        id={field.key}
                        value={value || ''}
                        onChange={(e) => handleChange(e.target.value)}
                        disabled={disabled}
                        className={`block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm ${
                            hasError ? 'border-red-300' : ''
                        } ${disabled ? 'bg-gray-50 text-gray-500 cursor-not-allowed opacity-60' : ''}`}
                        placeholder={field.defaultValue ? String(field.defaultValue) : ''}
                    />
                    {hasError && (
                        <p className="mt-1 text-xs text-red-600">This field is required</p>
                    )}
                </div>
            );

        case 'textarea':
            return (
                <div>
                    <label htmlFor={field.key} className="block text-sm font-medium text-gray-700 mb-1">
                        {field.label}
                        {field.required && <span className="text-red-500 ml-1">*</span>}
                    </label>
                    <textarea
                        id={field.key}
                        rows={4}
                        value={value || ''}
                        onChange={(e) => handleChange(e.target.value)}
                        disabled={disabled}
                        className={`block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm ${
                            hasError ? 'border-red-300' : ''
                        } ${disabled ? 'bg-gray-50 text-gray-500 cursor-not-allowed opacity-60' : ''}`}
                        placeholder={field.defaultValue ? String(field.defaultValue) : ''}
                    />
                    {hasError && (
                        <p className="mt-1 text-xs text-red-600">This field is required</p>
                    )}
                </div>
            );

        case 'select':
            return (
                <div>
                    <label htmlFor={field.key} className="block text-sm font-medium text-gray-700 mb-1">
                        {field.label}
                        {field.required && <span className="text-red-500 ml-1">*</span>}
                    </label>
                    <select
                        id={field.key}
                        value={value || ''}
                        onChange={(e) => handleChange(e.target.value)}
                        disabled={disabled}
                        className={`block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm ${
                            hasError ? 'border-red-300' : ''
                        } ${disabled ? 'bg-gray-50 text-gray-500 cursor-not-allowed opacity-60' : ''}`}
                    >
                        <option value="">Select {field.label.toLowerCase()}</option>
                        {field.options?.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </select>
                    {hasError && (
                        <p className="mt-1 text-xs text-red-600">This field is required</p>
                    )}
                </div>
            );

        case 'multiselect':
            return (
                <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">
                        {field.label}
                        {field.required && <span className="text-red-500 ml-1">*</span>}
                    </label>
                    <div className="space-y-2">
                        {field.options?.map((option) => {
                            const isSelected = multiselectValues.includes(option.value);
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
                                                ? [...multiselectValues, option.value]
                                                : multiselectValues.filter(v => v !== option.value);
                                            handleChange(newValues);
                                        }}
                                        disabled={disabled}
                                        className={`h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded ${
                                            disabled ? 'cursor-not-allowed opacity-60' : ''
                                        }`}
                                    />
                                    <span className="ml-2 text-sm text-gray-700">
                                        {option.label}
                                    </span>
                                </label>
                            );
                        })}
                    </div>
                    {hasError && (
                        <p className="mt-1 text-xs text-red-600">At least one option must be selected</p>
                    )}
                </div>
            );

        case 'number':
            return (
                <div>
                    <label htmlFor={field.key} className="block text-sm font-medium text-gray-700 mb-1">
                        {field.label}
                        {field.required && <span className="text-red-500 ml-1">*</span>}
                    </label>
                    <input
                        type="number"
                        id={field.key}
                        value={value || ''}
                        onChange={(e) => handleChange(e.target.value === '' ? null : Number(e.target.value))}
                        disabled={disabled}
                        className={`block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm ${
                            hasError ? 'border-red-300' : ''
                        } ${disabled ? 'bg-gray-50 text-gray-500 cursor-not-allowed opacity-60' : ''}`}
                        placeholder={field.defaultValue ? String(field.defaultValue) : ''}
                    />
                    {hasError && (
                        <p className="mt-1 text-xs text-red-600">This field is required</p>
                    )}
                </div>
            );

        case 'date':
            return (
                <div>
                    <label htmlFor={field.key} className="block text-sm font-medium text-gray-700 mb-1">
                        {field.label}
                        {field.required && <span className="text-red-500 ml-1">*</span>}
                    </label>
                    <input
                        type="date"
                        id={field.key}
                        value={value || ''}
                        onChange={(e) => handleChange(e.target.value)}
                        disabled={disabled}
                        className={`block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm ${
                            hasError ? 'border-red-300' : ''
                        } ${disabled ? 'bg-gray-50 text-gray-500 cursor-not-allowed opacity-60' : ''}`}
                    />
                    {hasError && (
                        <p className="mt-1 text-xs text-red-600">This field is required</p>
                    )}
                </div>
            );

        case 'boolean':
            return (
                <div>
                    <label className="flex items-center">
                        <input
                            type="checkbox"
                            checked={value === true || value === 'true'}
                            onChange={(e) => handleChange(e.target.checked)}
                            disabled={disabled}
                            className={`h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded ${
                                disabled ? 'cursor-not-allowed opacity-60' : ''
                            }`}
                        />
                        <span className="ml-2 text-sm font-medium text-gray-700">
                            {field.label}
                            {field.required && <span className="text-red-500 ml-1">*</span>}
                        </span>
                    </label>
                    {hasError && (
                        <p className="mt-1 text-xs text-red-600 ml-6">This field is required</p>
                    )}
                </div>
            );

        default:
            return (
                <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">
                        {field.label}
                        {field.required && <span className="text-red-500 ml-1">*</span>}
                    </label>
                    <p className="text-xs text-gray-500">
                        Unsupported field type: {field.type}
                    </p>
                </div>
            );
    }
}
