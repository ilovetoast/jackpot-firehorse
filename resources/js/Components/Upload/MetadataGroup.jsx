/**
 * Metadata Group Component
 *
 * Phase 2 – Step 2: Renders a single metadata field group.
 * Phase 2 – Step 3: Adds warning indicators and auto-expansion for invalid groups.
 *
 * Upload context: collapsible card header (aligned with bulk Video AI / Admin sections).
 */

import { useState, useEffect } from 'react'
import { ChevronDownIcon, ChevronUpIcon, ExclamationTriangleIcon } from '@heroicons/react/24/outline'
import MetadataFieldInput from './MetadataFieldInput'
import { validateMetadata } from '../../utils/metadataValidation'

/**
 * @param {string} groupKey
 * @param {string} label
 * @returns {{ card: string, bar: string, badge: string | null, badgeText: string | null, title: string, helper: string | null }}
 */
function uploadSectionPresentation(groupKey, label) {
    const k = String(groupKey || '').toLowerCase()
    const safeLabel = label || 'Metadata'

    if (k === 'creative') {
        return {
            card: 'rounded-xl border border-violet-200/90 bg-violet-50/35 shadow-sm',
            bar: 'border-violet-100/90',
            badge: 'inline-flex items-center rounded bg-violet-600 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-white',
            badgeText: 'Creative',
            title: 'Graphic type & scenes',
            helper: 'Applies to every file in this upload batch.',
        }
    }
    if (k === 'general') {
        return {
            card: 'rounded-xl border border-gray-200 bg-gray-50/60 shadow-sm',
            bar: 'border-gray-200/80',
            badge: 'inline-flex items-center rounded bg-indigo-600 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-white',
            badgeText: 'General',
            title: 'Tags & collection',
            helper: 'Shared metadata for discovery and organization.',
        }
    }
    if (k === 'rights' || k === 'legal') {
        return {
            card: 'rounded-xl border border-amber-200/80 bg-amber-50/40 shadow-sm',
            bar: 'border-amber-100/80',
            badge: 'inline-flex items-center rounded bg-amber-700 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-white',
            badgeText: 'Rights',
            title: safeLabel,
            helper: null,
        }
    }
    return {
        card: 'rounded-xl border border-gray-200 bg-white shadow-sm',
        bar: 'border-gray-100',
        badge: null,
        badgeText: null,
        title: safeLabel,
        helper: null,
    }
}

/**
 * MetadataGroup - Renders a single metadata field group
 *
 * @param {Object} props
 * @param {Object} props.group - Group object with key, label, and fields
 * @param {Object} props.values - Current metadata values keyed by field key
 * @param {Function} props.onChange - Callback when any field value changes (fieldKey, value)
 * @param {boolean} [props.disabled] - Whether fields are disabled
 * @param {boolean} [props.showErrors] - Whether to show validation errors
 * @param {boolean} [props.autoExpand] - Whether to auto-expand if group has errors
 * @param {boolean} [props.defaultExpanded] - Initial expanded state (default true)
 */
export default function MetadataGroup({
    group,
    values = {},
    onChange,
    disabled = false,
    showErrors = false,
    autoExpand = false,
    defaultExpanded = true,
    collectionProps = null,
    tagFieldInputRef = null,
}) {
    const [isExpanded, setIsExpanded] = useState(defaultExpanded)

    const groupErrors = validateMetadata([group], values)
    const hasErrors = Object.keys(groupErrors).length > 0

    useEffect(() => {
        if (autoExpand && hasErrors && !isExpanded) {
            setIsExpanded(true)
        }
    }, [autoExpand, hasErrors, isExpanded])

    const fieldsToRender = (group.fields || []).filter((f) => f.key !== 'starred')

    if (fieldsToRender.length === 0) {
        return null
    }

    const chrome = uploadSectionPresentation(group.key, group.label)

    return (
        <div className={chrome.card}>
            <div className="p-3.5">
                <button
                    type="button"
                    onClick={() => setIsExpanded(!isExpanded)}
                    className="w-full rounded-md text-left focus-visible:outline focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-1"
                    aria-expanded={isExpanded}
                    aria-controls={`metadata-group-${group.key}`}
                >
                    <div className={`flex items-start justify-between gap-2 border-b pb-2 ${chrome.bar}`}>
                        <div className="min-w-0 flex-1 pr-1">
                            <div className="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                                {chrome.badge && chrome.badgeText ? (
                                    <span className={chrome.badge}>{chrome.badgeText}</span>
                                ) : null}
                                <h3 className="text-xs font-semibold text-gray-900">{chrome.title}</h3>
                                {hasErrors ? (
                                    <span className="inline-flex shrink-0" title="This group has validation issues">
                                        <ExclamationTriangleIcon className="h-3.5 w-3.5 text-amber-600" aria-hidden />
                                    </span>
                                ) : null}
                            </div>
                            {chrome.helper ? (
                                <p className="mt-1.5 text-[11px] leading-snug text-gray-600">{chrome.helper}</p>
                            ) : null}
                        </div>
                        {isExpanded ? (
                            <ChevronUpIcon className="mt-0.5 h-4 w-4 shrink-0 text-gray-400" aria-hidden />
                        ) : (
                            <ChevronDownIcon className="mt-0.5 h-4 w-4 shrink-0 text-gray-400" aria-hidden />
                        )}
                    </div>
                </button>

                {isExpanded && (
                    <div id={`metadata-group-${group.key}`} className="min-w-0 pt-3">
                        <div className="flex min-w-0 flex-col gap-3">
                            {fieldsToRender.map((field) => (
                                <div key={field.key} className="min-w-0">
                                    <MetadataFieldInput
                                        ref={field.key === 'tags' ? tagFieldInputRef : undefined}
                                        field={field}
                                        value={
                                            field.key === 'collection' && collectionProps
                                                ? collectionProps.selectedIds
                                                : values[field.key]
                                        }
                                        onChange={
                                            field.key === 'collection' && collectionProps
                                                ? collectionProps.onChange
                                                : (value) => onChange(field.key, value)
                                        }
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
        </div>
    )
}
