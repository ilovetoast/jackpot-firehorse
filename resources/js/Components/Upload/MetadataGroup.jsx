/**
 * Metadata Group Component
 *
 * Phase 2 – Step 2: Renders a single metadata field group.
 * Phase 2 – Step 3: Adds warning indicators and auto-expansion for invalid groups.
 *
 * Groups are collapsible for mobile-friendly UX.
 */

import { useState, useEffect } from 'react'
import { ChevronDownIcon, ChevronUpIcon, ExclamationTriangleIcon } from '@heroicons/react/24/outline'
import MetadataFieldInput from './MetadataFieldInput'
import { validateMetadata } from '../../utils/metadataValidation'

/**
 * MetadataGroup - Renders a single metadata group
 *
 * @param {Object} props
 * @param {Object} props.group - Group object with key, label, and fields
 * @param {Object} props.values - Current metadata values keyed by field key
 * @param {Function} props.onChange - Callback when any field value changes (fieldKey, value)
 * @param {boolean} [props.disabled] - Whether fields are disabled
 * @param {boolean} [props.showErrors] - Whether to show validation errors
 * @param {boolean} [props.autoExpand] - Whether to auto-expand if group has errors
 */
export default function MetadataGroup({ group, values = {}, onChange, disabled = false, showErrors = false, autoExpand = false }) {
    const [isExpanded, setIsExpanded] = useState(true)

    // Check if this group has any validation errors
    const groupErrors = validateMetadata([group], values)
    const hasErrors = Object.keys(groupErrors).length > 0

    // Auto-expand if group has errors and autoExpand is true
    useEffect(() => {
        if (autoExpand && hasErrors && !isExpanded) {
            setIsExpanded(true)
        }
    }, [autoExpand, hasErrors, isExpanded])

    // C9.2: In upload form, collection is shown in dedicated Collections section only — exclude from metadata groups
    const fieldsToRender = (group.fields || []).filter((f) => f.key !== 'collection')

    // Handle empty groups
    if (fieldsToRender.length === 0) {
        return null
    }

    return (
        <div className="bg-white border border-gray-200 rounded-lg shadow-sm">
            {/* Group Header - Collapsible */}
            <button
                type="button"
                onClick={() => setIsExpanded(!isExpanded)}
                className="w-full px-4 py-3 flex items-center justify-between text-left hover:bg-gray-50 transition-colors"
                aria-expanded={isExpanded}
                aria-controls={`metadata-group-${group.key}`}
            >
                <h3 className="text-sm font-medium text-gray-900">
                    {group.label}
                </h3>
                {isExpanded ? (
                    <ChevronUpIcon className="h-5 w-5 text-gray-400" />
                ) : (
                    <ChevronDownIcon className="h-5 w-5 text-gray-400" />
                )}
            </button>

            {/* Group Fields */}
            {isExpanded && (
                <div
                    id={`metadata-group-${group.key}`}
                    className="px-4 py-4 border-t border-gray-200"
                >
                    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                        {fieldsToRender.map((field) => (
                            <MetadataFieldInput
                                key={field.key}
                                field={field}
                                value={values[field.key]}
                                onChange={(value) => onChange(field.key, value)}
                                disabled={disabled}
                                showError={showErrors && !!groupErrors[field.key]}
                                isUploadContext={true}
                            />
                        ))}
                    </div>
                </div>
            )}
        </div>
    )
}
