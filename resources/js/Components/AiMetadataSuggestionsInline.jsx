/**
 * AI Metadata Suggestions Inline Component
 *
 * Displays AI-generated metadata suggestions inline in the asset metadata drawer.
 * Suggestions are stored in asset.metadata['_ai_suggestions'] (ephemeral format).
 *
 * Features:
 * - Section labeled "Suggested by AI"
 * - Suggestions shown inline per field
 * - Actions: Accept, Dismiss
 * - Permission-based visibility and actions
 */

import { useState, useEffect, useRef } from 'react'
import { SparklesIcon, CheckIcon, XMarkIcon } from '@heroicons/react/24/outline'
import { usePermission } from '../hooks/usePermission'

// Global guard to prevent multiple simultaneous usage checks across all component instances
let globalUsageCheckInProgress = false

// Global cache to prevent fetching suggestions for the same asset multiple times
const suggestionsCache = new Map() // assetId -> { suggestions, timestamp }

export default function AiMetadataSuggestionsInline({ assetId }) {
    const [suggestions, setSuggestions] = useState([])
    const [loading, setLoading] = useState(true)
    const [processing, setProcessing] = useState(new Set())
    const [suggestionsPaused, setSuggestionsPaused] = useState(false)
    const hasCheckedUsage = useRef(false) // Prevent multiple checks per component instance
    const hasFetchedSuggestions = useRef(false) // Prevent multiple fetches per component instance
    
    const { can } = usePermission()
    const canView = can('metadata.suggestions.view')
    const canViewAiUsage = can('ai.usage.view')

    // Check AI usage status to see if suggestions are paused (only once, only if user has BOTH permissions)
    // IMPORTANT: Only check if user has BOTH permissions to avoid 404 errors
    useEffect(() => {
        // CRITICAL: Skip entirely if user doesn't have permission to view AI usage
        // This prevents 404 errors from spamming the console
        // Check permissions FIRST before any other logic - if either is false, never make the API call
        if (!canViewAiUsage || !canView) {
            // User can't view usage or suggestions, so skip the check entirely - no API call ever
            // Mark as checked to prevent future attempts
            hasCheckedUsage.current = true
            return
        }

        // Early exit: Already checked for this component instance
        if (hasCheckedUsage.current) {
            return
        }

        // Early exit: Global check already in progress (prevents multiple simultaneous calls)
        if (globalUsageCheckInProgress) {
            return
        }

        // Mark as checked immediately to prevent re-runs
        hasCheckedUsage.current = true
        globalUsageCheckInProgress = true

        // Try to fetch AI usage status to check if suggestions are paused
        fetch('/app/api/companies/ai-usage', {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
            },
            credentials: 'same-origin',
        })
            .then((res) => {
                globalUsageCheckInProgress = false
                if (!res.ok) {
                    // If we can't access usage data, that's okay - proceed normally
                    // Don't log 404s - they're expected when user lacks permission
                    return null
                }
                return res.json()
            })
            .then((data) => {
                if (data && data.status && data.status.suggestions) {
                    const suggestionsStatus = data.status.suggestions
                    // Check if suggestions are disabled or cap exceeded
                    if (suggestionsStatus.is_disabled || suggestionsStatus.is_exceeded) {
                        setSuggestionsPaused(true)
                    }
                }
            })
            .catch(() => {
                globalUsageCheckInProgress = false
                // Silently fail - if we can't check usage, just proceed normally
                // No error logging to prevent console spam
            })
    }, [canView, canViewAiUsage])

    // Fetch AI suggestions (only once per asset, with caching)
    useEffect(() => {
        // Reset fetch flag when asset changes
        hasFetchedSuggestions.current = false
        
        if (!assetId) {
            setLoading(false)
            return
        }

        // Check cache first (valid for 5 minutes)
        const cached = suggestionsCache.get(assetId)
        const cacheValid = cached && (Date.now() - cached.timestamp < 5 * 60 * 1000)
        
        if (cacheValid) {
            setSuggestions(cached.suggestions)
            setLoading(false)
            hasFetchedSuggestions.current = true
            return
        }

        hasFetchedSuggestions.current = true
        setLoading(true)
        
        fetch(`/app/assets/${assetId}/metadata/suggestions`, {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
            },
            credentials: 'same-origin',
        })
            .then((res) => {
                if (!res.ok) {
                    throw new Error('Failed to fetch suggestions')
                }
                return res.json()
            })
            .then((data) => {
                const fetchedSuggestions = data.suggestions || []
                setSuggestions(fetchedSuggestions)
                setLoading(false)
                
                // Cache the result
                suggestionsCache.set(assetId, {
                    suggestions: fetchedSuggestions,
                    timestamp: Date.now()
                })
            })
            .catch((err) => {
                console.error('[AiMetadataSuggestionsInline] Failed to fetch suggestions', err)
                setLoading(false)
                hasFetchedSuggestions.current = false // Allow retry on error
            })
    }, [assetId])

    // Handle accept (write to metadata)
    const handleAccept = async (fieldKey) => {
        if (processing.has(fieldKey)) return

        setProcessing((prev) => new Set(prev).add(fieldKey))

        try {
            const response = await fetch(
                `/app/assets/${assetId}/metadata/suggestions/${encodeURIComponent(fieldKey)}/accept`,
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
                throw new Error(data.message || 'Failed to accept suggestion')
            }

            // Remove suggestion from list
            setSuggestions((prev) => {
                const updated = prev.filter((s) => s.field_key !== fieldKey)
                // Update cache
                if (suggestionsCache.has(assetId)) {
                    suggestionsCache.set(assetId, {
                        suggestions: updated,
                        timestamp: Date.now()
                    })
                }
                return updated
            })

            // Trigger metadata update event for other components
            window.dispatchEvent(new CustomEvent('metadata-updated'))
        } catch (error) {
            console.error('[AiMetadataSuggestionsInline] Failed to accept', error)
            alert(error.message || 'Failed to accept suggestion')
        } finally {
            setProcessing((prev) => {
                const next = new Set(prev)
                next.delete(fieldKey)
                return next
            })
        }
    }

    // Handle dismiss (remove from suggestions)
    const handleDismiss = async (fieldKey) => {
        if (processing.has(fieldKey)) return

        if (!confirm('Are you sure you want to dismiss this suggestion?')) {
            return
        }

        setProcessing((prev) => new Set(prev).add(fieldKey))

        try {
            const response = await fetch(
                `/app/assets/${assetId}/metadata/suggestions/${encodeURIComponent(fieldKey)}/dismiss`,
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
                throw new Error(data.message || 'Failed to dismiss suggestion')
            }

            // Remove suggestion from list
            setSuggestions((prev) => {
                const updated = prev.filter((s) => s.field_key !== fieldKey)
                // Update cache
                if (suggestionsCache.has(assetId)) {
                    suggestionsCache.set(assetId, {
                        suggestions: updated,
                        timestamp: Date.now()
                    })
                }
                return updated
            })
        } catch (error) {
            console.error('[AiMetadataSuggestionsInline] Failed to dismiss', error)
            alert(error.message || 'Failed to dismiss suggestion')
        } finally {
            setProcessing((prev) => {
                const next = new Set(prev)
                next.delete(fieldKey)
                return next
            })
        }
    }

    // Format value for display
    const formatValue = (suggestion) => {
        const { value, type } = suggestion

        if (value === null || value === undefined || value === '') {
            return <span className="text-gray-400 italic">Not set</span>
        }

        if (type === 'multiselect' && Array.isArray(value)) {
            return value.join(', ')
        }

        if (type === 'boolean') {
            return value ? 'Yes' : 'No'
        }

        if (type === 'date') {
            try {
                const date = new Date(value)
                return date.toLocaleDateString()
            } catch {
                return value
            }
        }

        return String(value)
    }

    // Get confidence indicator color
    const getConfidenceColor = (confidence) => {
        if (!confidence) return 'bg-gray-200'
        if (confidence >= 0.9) return 'bg-green-500'
        if (confidence >= 0.8) return 'bg-yellow-500'
        return 'bg-orange-500'
    }

    if (loading) {
        return (
            <div className="px-6 py-4 border-t border-gray-200">
                <div className="text-sm text-gray-500">Loading AI suggestions...</div>
            </div>
        )
    }

    // If suggestions are paused due to cap, show notice instead
    if (suggestionsPaused) {
        return (
            <div className="px-6 py-4 border-t border-gray-200">
                <div className="rounded-md bg-gray-50 border border-gray-200 p-3">
                    <div className="flex items-center gap-2">
                        <SparklesIcon className="h-4 w-4 text-gray-400" />
                        <p className="text-sm text-gray-600">
                            AI suggestions paused until next month
                        </p>
                    </div>
                </div>
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
                <h3 className="text-sm font-semibold text-gray-900">Suggested by AI</h3>
                {/* TODO (Optional Enhancement): UI Copy Refinement
                    At some point, you may want to add trust-building microcopy like:
                    <span className="text-xs text-gray-500 ml-2">· You're always in control</span>
                    This reinforces user agency and builds trust in AI suggestions.
                */}
            </div>

            <div className="space-y-3">
                {suggestions.map((suggestion) => {
                    const isProcessing = processing.has(suggestion.field_key)

                    return (
                        <div
                            key={suggestion.field_key}
                            className="bg-indigo-50 border border-indigo-200 rounded-lg p-3"
                        >
                            <div className="flex items-start justify-between mb-2">
                                <div className="flex-1 min-w-0">
                                    <div className="text-sm font-medium text-gray-900 mb-1">
                                        {suggestion.display_label}
                                    </div>
                                    <div className="text-sm text-gray-600">
                                        {formatValue(suggestion)}
                                    </div>
                                </div>
                                {suggestion.confidence && (
                                    <div className="flex items-center gap-2 ml-4 flex-shrink-0">
                                        <div
                                            className={`h-2 w-12 rounded-full ${getConfidenceColor(
                                                suggestion.confidence
                                            )}`}
                                            title={`Confidence: ${Math.round(
                                                suggestion.confidence * 100
                                            )}%`}
                                        />
                                    </div>
                                )}
                            </div>

                            <div className="flex items-center gap-2">
                                {suggestion.can_apply && (
                                    <button
                                        type="button"
                                        onClick={() => handleAccept(suggestion.field_key)}
                                        disabled={isProcessing}
                                        className="inline-flex items-center gap-1.5 px-2.5 py-1.5 text-xs font-medium text-white bg-green-600 rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed"
                                    >
                                        <CheckIcon className="h-3.5 w-3.5" />
                                        Accept
                                    </button>
                                )}
                                {suggestion.can_dismiss && (
                                    <button
                                        type="button"
                                        onClick={() => handleDismiss(suggestion.field_key)}
                                        disabled={isProcessing}
                                        className="inline-flex items-center gap-1.5 px-2.5 py-1.5 text-xs font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed"
                                    >
                                        <XMarkIcon className="h-3.5 w-3.5" />
                                        Dismiss
                                    </button>
                                )}
                            </div>
                        </div>
                    )
                })}
            </div>
        </div>
    )
}
