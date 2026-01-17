/**
 * Metadata Groups Component
 *
 * Phase 2 – Step 2: Container component for rendering metadata field groups.
 *
 * Renders all metadata groups from the upload schema.
 * Handles empty state gracefully.
 */

import MetadataGroup from './MetadataGroup'

/**
 * MetadataGroups - Container for metadata field groups
 *
 * @param {Object} props
 * @param {Array} props.groups - Array of metadata groups from schema
 * @param {Object} props.values - Current metadata values keyed by field key
 * @param {Function} props.onChange - Callback when any field value changes (fieldKey, value)
 * @param {boolean} [props.disabled] - Whether fields are disabled
 */
export default function MetadataGroups({ groups = [], values = {}, onChange, disabled = false }) {
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
