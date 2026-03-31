/**
 * Metadata Groups Component
 *
 * Phase 2 – Step 2: Container component for rendering metadata field groups.
 *
 * Renders all metadata groups from the upload schema.
 * Handles empty state gracefully.
 * 
 * UX-1: Shows helper message when metadata approval is enabled for contributors.
 */

import { useRef } from 'react'
import { usePage } from '@inertiajs/react'
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
 * @param {string[]} [props.defaultCollapsedGroupKeys] - Group keys (case-insensitive) that start collapsed on upload
 */
export default function MetadataGroups({ 
    groups = [], 
    values = {}, 
    onChange, 
    disabled = false,
    showErrors = false,
    onValidationAttempt = null,
    collectionProps = null,
    tagFieldInputRef = null,
    defaultCollapsedGroupKeys = null,
}) {
    const { auth } = usePage().props
    const groupRefs = useRef({})

    // UX-1: Check if we should show metadata approval helper message
    // Show when: metadata approval is enabled AND user does not have bypass permission
    const metadataApprovalEnabled = auth?.metadata_approval_features?.metadata_approval_enabled === true
    const hasBypassPermission = (auth?.effective_permissions || []).includes('metadata.bypass_approval')
    const showApprovalMessage = metadataApprovalEnabled && !hasBypassPermission

    const collapsedKeySet =
        defaultCollapsedGroupKeys?.length > 0
            ? new Set(defaultCollapsedGroupKeys.map((k) => String(k).toLowerCase()))
            : null

    /** Remount groups when default-collapse set changes (e.g. Photography vs other category) */
    const collapseModeKey =
        defaultCollapsedGroupKeys?.length > 0
            ? [...defaultCollapsedGroupKeys].sort().join('|')
            : 'none'

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
        <div className="space-y-3">
            {/* UX-1: Helper message for contributors when metadata approval is enabled */}
            {showApprovalMessage && (
                <div className="rounded-md bg-gray-50 border border-gray-200 px-3 py-2">
                    <p className="text-xs text-gray-600">
                        Metadata entered here will be reviewed before publishing.
                    </p>
                </div>
            )}
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

                const defaultExpanded = collapsedKeySet
                    ? !collapsedKeySet.has(String(group.key || '').toLowerCase())
                    : true

                return (
                    <div
                        key={`${group.key}__${collapseModeKey}`}
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
                            defaultExpanded={defaultExpanded}
                            collectionProps={collectionProps}
                            tagFieldInputRef={tagFieldInputRef}
                        />
                    </div>
                )
            })}
        </div>
    )
}
