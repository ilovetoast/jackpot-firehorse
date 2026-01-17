/**
 * AI Metadata Suggestions Component
 *
 * Phase 2 – Step 5.5: Displays AI-generated metadata suggestions and allows
 * users to accept, edit, or reject them.
 */

import { useState, useEffect } from 'react'
import {
    SparklesIcon,
    CheckIcon,
    XMarkIcon,
    PencilIcon,
} from '@heroicons/react/24/outline'
import MetadataFieldInput from './Upload/MetadataFieldInput'

export default function AiMetadataSuggestions({ assetId }) {
    const [suggestions, setSuggestions] = useState([])
    const [loading, setLoading] = useState(true)
    const [editingFieldId, setEditingFieldId] = useState(null)
    const [editedValue, setEditedValue] = useState(null)
    const [processing, setProcessing] = useState(new Set())

    // Fetch AI suggestions
    useEffect(() => {
        if (!assetId) return

        setLoading(true)
        fetch(`/app/assets/${assetId}/metadata/ai-suggestions`, {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
            },
            credentials: 'same-origin',
        })
            .then((res) => res.json())
            .then((data) => {
                setSuggestions(data.suggestions || [])
                setLoading(false)
            })
            .catch((err) => {
                console.error('[AiMetadataSuggestions] Failed to fetch suggestions', err)
                setLoading(false)
            })
    }, [assetId])

    // Handle approve (accept as-is)
    const handleApprove = async (fieldId, suggestionId) => {
        if (processing.has(suggestionId)) return

        setProcessing((prev) => new Set(prev).add(suggestionId))

        try {
            const response = await fetch(
                `/app/assets/${assetId}/metadata/ai-suggestions/${suggestionId}/approve`,
                {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                    },
                    credentials: 'same-origin',
                }
            )

            if (!response.ok) {
                const data = await response.json()
                throw new Error(data.message || 'Failed to approve suggestion')
            }

            // Remove suggestion from list
            setSuggestions((prev) =>
                prev.filter((s) => s.metadata_field_id !== fieldId)
            )
        } catch (error) {
            console.error('[AiMetadataSuggestions] Failed to approve', error)
            alert(error.message || 'Failed to approve suggestion')
        } finally {
            setProcessing((prev) => {
                const next = new Set(prev)
                next.delete(suggestionId)
                return next
            })
        }
    }

    // Handle edit & accept
    const handleEditAndAccept = async (fieldId, suggestionId, field) => {
        if (processing.has(suggestionId)) return

        // Validate edited value
        if (editedValue === null || editedValue === undefined) {
            alert('Please enter a value')
            return
        }

        // For multiselect, ensure at least one value
        if (field.type === 'multiselect' && (!Array.isArray(editedValue) || editedValue.length === 0)) {
            alert('Please select at least one option')
            return
        }

        setProcessing((prev) => new Set(prev).add(suggestionId))

        try {
            const response = await fetch(
                `/app/assets/${assetId}/metadata/ai-suggestions/${suggestionId}/edit-accept`,
                {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ value: editedValue }),
                }
            )

            if (!response.ok) {
                const data = await response.json()
                throw new Error(data.message || 'Failed to accept edited suggestion')
            }

            // Remove suggestion from list
            setSuggestions((prev) =>
                prev.filter((s) => s.metadata_field_id !== fieldId)
            )
            setEditingFieldId(null)
            setEditedValue(null)
        } catch (error) {
            console.error('[AiMetadataSuggestions] Failed to edit and accept', error)
            alert(error.message || 'Failed to accept edited suggestion')
        } finally {
            setProcessing((prev) => {
                const next = new Set(prev)
                next.delete(suggestionId)
                return next
            })
        }
    }

    // Handle reject
    const handleReject = async (fieldId, suggestionId) => {
        if (processing.has(suggestionId)) return

        if (!confirm('Are you sure you want to reject this suggestion?')) {
            return
        }

        setProcessing((prev) => new Set(prev).add(suggestionId))

        try {
            const response = await fetch(
                `/app/assets/${assetId}/metadata/ai-suggestions/${suggestionId}/reject`,
                {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                    },
                    credentials: 'same-origin',
                }
            )

            if (!response.ok) {
                const data = await response.json()
                throw new Error(data.message || 'Failed to reject suggestion')
            }

            // Remove suggestion from list
            setSuggestions((prev) =>
                prev.filter((s) => s.metadata_field_id !== fieldId)
            )
        } catch (error) {
            console.error('[AiMetadataSuggestions] Failed to reject', error)
            alert(error.message || 'Failed to reject suggestion')
        } finally {
            setProcessing((prev) => {
                const next = new Set(prev)
                next.delete(suggestionId)
                return next
            })
        }
    }

    // Get confidence indicator color
    const getConfidenceColor = (confidence) => {
        if (!confidence) return 'bg-gray-200'
        if (confidence >= 0.8) return 'bg-green-500'
        if (confidence >= 0.6) return 'bg-yellow-500'
        return 'bg-orange-500'
    }

    if (loading) {
        return (
            <div className="px-6 py-4">
                <div className="text-sm text-gray-500">Loading AI suggestions...</div>
            </div>
        )
    }

    if (suggestions.length === 0) {
        return null // Hide if no suggestions
    }

    return (
        <div className="px-6 py-4 border-t border-gray-200">
            <div className="flex items-center gap-2 mb-4">
                <SparklesIcon className="h-5 w-5 text-indigo-500" />
                <h3 className="text-sm font-semibold text-gray-900">AI Suggestions</h3>
            </div>

            <div className="space-y-4">
                {suggestions.map((suggestion) => {
                    const fieldId = suggestion.metadata_field_id
                    const isEditing = editingFieldId === fieldId
                    const firstSuggestion = suggestion.suggestions[0]
                    const suggestionId = firstSuggestion.id
                    const isProcessing = processing.has(suggestionId)

                    // Build field object for MetadataFieldInput
                    const field = {
                        field_id: suggestion.metadata_field_id,
                        key: suggestion.field_key,
                        display_label: suggestion.display_label,
                        type: suggestion.type,
                        options: suggestion.options || [],
                        is_required: false,
                    }

                    // Get suggested value (handle multi-value)
                    const suggestedValue =
                        suggestion.type === 'multiselect'
                            ? suggestion.suggestions.map((s) => s.value)
                            : firstSuggestion.value

                    return (
                        <div
                            key={fieldId}
                            className="bg-indigo-50 border border-indigo-200 rounded-lg p-4"
                        >
                            <div className="flex items-start justify-between mb-3">
                                <div className="flex-1">
                                    <div className="text-sm font-medium text-gray-900 mb-1">
                                        {suggestion.display_label}
                                    </div>
                                    {!isEditing && (
                                        <div className="text-sm text-gray-600">
                                            {suggestion.type === 'multiselect' ? (
                                                <span>
                                                    {suggestion.suggestions
                                                        .map((s) => s.value)
                                                        .join(', ')}
                                                </span>
                                            ) : (
                                                <span>{firstSuggestion.value}</span>
                                            )}
                                        </div>
                                    )}
                                </div>
                                {firstSuggestion.confidence && (
                                    <div className="flex items-center gap-2 ml-4">
                                        <div
                                            className={`h-2 w-12 rounded-full ${getConfidenceColor(
                                                firstSuggestion.confidence
                                            )}`}
                                            title={`Confidence: ${Math.round(
                                                firstSuggestion.confidence * 100
                                            )}%`}
                                        />
                                    </div>
                                )}
                            </div>

                            {isEditing ? (
                                <div className="space-y-3">
                                    <MetadataFieldInput
                                        field={field}
                                        value={editedValue !== null ? editedValue : suggestedValue}
                                        onChange={(value) => setEditedValue(value)}
                                        disabled={isProcessing}
                                        showError={false}
                                    />
                                    <div className="flex items-center gap-2">
                                        <button
                                            type="button"
                                            onClick={() =>
                                                handleEditAndAccept(
                                                    fieldId,
                                                    suggestionId,
                                                    field
                                                )
                                            }
                                            disabled={isProcessing}
                                            className="inline-flex items-center gap-2 px-3 py-1.5 text-sm font-medium text-white bg-indigo-600 rounded-md hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed"
                                        >
                                            <CheckIcon className="h-4 w-4" />
                                            Accept
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => {
                                                setEditingFieldId(null)
                                                setEditedValue(null)
                                            }}
                                            disabled={isProcessing}
                                            className="inline-flex items-center gap-2 px-3 py-1.5 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed"
                                        >
                                            Cancel
                                        </button>
                                    </div>
                                </div>
                            ) : (
                                <div className="flex items-center gap-2">
                                    <button
                                        type="button"
                                        onClick={() => handleApprove(fieldId, suggestionId)}
                                        disabled={isProcessing || !canEdit}
                                        title={!canEdit ? "You don't have permission to edit this field" : undefined}
                                        className="inline-flex items-center gap-2 px-3 py-1.5 text-sm font-medium text-white bg-green-600 rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed"
                                    >
                                        <CheckIcon className="h-4 w-4" />
                                        Accept
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setEditingFieldId(fieldId)
                                            setEditedValue(suggestedValue)
                                        }}
                                        disabled={isProcessing || !canEdit}
                                        title={!canEdit ? "You don't have permission to edit this field" : undefined}
                                        className="inline-flex items-center gap-2 px-3 py-1.5 text-sm font-medium text-indigo-700 bg-indigo-100 rounded-md hover:bg-indigo-200 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed"
                                    >
                                        <PencilIcon className="h-4 w-4" />
                                        Edit
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => handleReject(fieldId, suggestionId)}
                                        disabled={isProcessing}
                                        className="inline-flex items-center gap-2 px-3 py-1.5 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed"
                                    >
                                        <XMarkIcon className="h-4 w-4" />
                                        Reject
                                    </button>
                                </div>
                            )}
                        </div>
                    )
                })}
            </div>
        </div>
    )
}
