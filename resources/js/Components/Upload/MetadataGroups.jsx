/**
 * Metadata Groups Component
 *
 * Phase 2 – Step 2: Container component for rendering metadata field groups.
 *
 * Renders all metadata groups from the upload schema.
 * Handles empty state gracefully.
 */

import { useRef } from 'react'
import MetadataGroup from './MetadataGroup'
import { validateMetadata } from '../../utils/metadataValidation'

/**
 * MetadataGroups - Container for metadata field groups
 *
 * @param {Object} props
 * @param {Array} props.groups - Array of metadata groups from schema
 * @param {Object} props.values - Current metadata values keyed by field key
 * @param {Function} props.onChange - Callback when any field value changes (fieldKey, value)
 * @param {boolean} [props.disabled] - Whether fields are disabled
 * @param {boolean} [props.showErrors] - Whether to show validation errors
 * @param {Function} [props.onValidationAttempt] - Callback when validation is attempted
 */
export default function MetadataGroups({ 
    groups = [], 
    values = {}, 
    onChange, 
    disabled = false,
    showErrors = false,
    onValidationAttempt = null
}) {
    const groupRefs = useRef({})

    // Handle empty state
    if (!groups || groups.length === 0) {
        return (
            <div className="rounded-md bg-gray-50 border border-gray-200 p-4">
                <p className="text-sm text-gray-600 text-center">
                    No metadata required for this upload.
                </p>
            </div>
        )
    }

    return (
        <div className="space-y-6">
            {groups.map((group) => {
                const groupErrors = validateMetadata([group], values)
                const hasErrors = Object.keys(groupErrors).length > 0

                // Scroll to first group with errors when validation is attempted
                if (showErrors && hasErrors && onValidationAttempt) {
                    // Call onValidationAttempt once when errors are shown
                    setTimeout(() => {
                        const firstErrorGroup = groupRefs.current[group.key]
                        if (firstErrorGroup) {
                            firstErrorGroup.scrollIntoView({ behavior: 'smooth', block: 'center' })
                        }
                    }, 100)
                }

                return (
                    <div
                        key={group.key}
                        ref={(el) => {
                            if (el) {
                                groupRefs.current[group.key] = el
                            }
                        }}
                    >
                        <MetadataGroup
                            group={group}
                            values={values}
                            onChange={onChange}
                            disabled={disabled}
                            showErrors={showErrors}
                            autoExpand={showErrors && hasErrors}
                        />
                    </div>
                )
            })}
        </div>
    )
}
