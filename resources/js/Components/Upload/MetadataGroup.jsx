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
export default function MetadataGroup({ group, values = {}, onChange, disabled = false, showErrors = false, autoExpand = false, collectionProps = null }) {
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

    // Starred is rendered as a toggle on the uploader section — exclude from metadata groups
    // Collection is rendered inside General group when group_key is general (seeder)
    const fieldsToRender = (group.fields || []).filter((f) => f.key !== 'starred')

    // Handle empty groups
    if (fieldsToRender.length === 0) {
        return null
    }

    return (
        <div>
            {/* Flat collapsible header — chevron only, small text, no card */}
            <button
                type="button"
                onClick={() => setIsExpanded(!isExpanded)}
                className="w-full flex items-center justify-between text-left py-1.5 text-xs font-medium text-gray-700 hover:text-gray-900"
                aria-expanded={isExpanded}
                aria-controls={`metadata-group-${group.key}`}
            >
                <span>{group.label}</span>
                {isExpanded ? (
                    <ChevronUpIcon className="h-4 w-4 text-gray-400 flex-shrink-0" />
                ) : (
                    <ChevronDownIcon className="h-4 w-4 text-gray-400 flex-shrink-0" />
                )}
            </button>
            <div className="border-b border-gray-200 mb-2" aria-hidden />

            {/* Group Fields */}
            {isExpanded && (
                <div
                    id={`metadata-group-${group.key}`}
                    className="pb-3"
                >
                    <div className="flex flex-col gap-3 [&_.flex-1]:max-w-sm">
                        {fieldsToRender.map((field) => (
                            <div key={field.key}>
                                <MetadataFieldInput
                                    field={field}
                                    value={field.key === 'collection' && collectionProps ? collectionProps.selectedIds : values[field.key]}
                                    onChange={field.key === 'collection' && collectionProps ? collectionProps.onChange : (value) => onChange(field.key, value)}
                                    disabled={disabled}
                                    showError={showErrors && !!groupErrors[field.key]}
                                    isUploadContext={true}
                                    collectionProps={field.key === 'collection' ? collectionProps : undefined}
                                />
                            </div>
                        ))}
                    </div>
                </div>
            )}
        </div>
    )
}
