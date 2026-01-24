/**
 * AI Tag Suggestions Inline Component
 *
 * Displays AI-generated tag suggestions inline in the asset drawer.
 * Tags are stored in asset_tag_candidates table (not approved data).
 *
 * Features:
 * - Section labeled "AI Suggested Tags"
 * - Tags shown with confidence scores
 * - Actions: Accept, Dismiss
 * - Permission-based visibility and actions
 */

import { useState, useEffect, useRef } from 'react'
import { SparklesIcon, CheckIcon, XMarkIcon } from '@heroicons/react/24/outline'
import { usePermission } from '../hooks/usePermission'

// Global cache to prevent fetching suggestions for the same asset multiple times
const tagSuggestionsCache = new Map() // assetId -> { suggestions, timestamp }

export default function AiTagSuggestionsInline({ assetId }) {
    const [suggestions, setSuggestions] = useState([])
    const [loading, setLoading] = useState(true)
    const [processing, setProcessing] = useState(new Set())
    const [showConfirmDismiss, setShowConfirmDismiss] = useState(null) // {id, tag} for dismiss confirmation
    const hasFetchedSuggestions = useRef(false) // Prevent multiple fetches per component instance
    
    const { hasPermission: canView } = usePermission('metadata.suggestions.view')

    // Fetch AI tag suggestions (only once per asset, with caching)
    useEffect(() => {
        // Reset fetch flag when asset changes
        hasFetchedSuggestions.current = false
        
        if (!assetId || !canView) {
            setLoading(false)
            return
        }

        // Check cache first (valid for 5 minutes)
        const cached = tagSuggestionsCache.get(assetId)
        const cacheValid = cached && (Date.now() - cached.timestamp < 5 * 60 * 1000)
        
        if (cacheValid) {
            setSuggestions(cached.suggestions)
            setLoading(false)
            hasFetchedSuggestions.current = true
            return
        }

        hasFetchedSuggestions.current = true
        setLoading(true)
        
        fetch(`/app/assets/${assetId}/tags/suggestions`, {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
            },
            credentials: 'same-origin',
        })
            .then((res) => {
                if (!res.ok) {
                    throw new Error('Failed to fetch tag suggestions')
                }
                return res.json()
            })
            .then((data) => {
                const fetchedSuggestions = data.suggestions || []
                setSuggestions(fetchedSuggestions)
                setLoading(false)
                
                // Cache the result
                tagSuggestionsCache.set(assetId, {
                    suggestions: fetchedSuggestions,
                    timestamp: Date.now()
                })
            })
            .catch((err) => {
                console.error('[AiTagSuggestionsInline] Failed to fetch tag suggestions', err)
                setLoading(false)
                hasFetchedSuggestions.current = false // Allow retry on error
            })
    }, [assetId, canView])

    // Handle accept (create tag in asset_tags)
    const handleAccept = async (candidateId, tag) => {
        if (processing.has(candidateId)) return

        setProcessing((prev) => new Set(prev).add(candidateId))

        try {
            const response = await fetch(
                `/app/assets/${assetId}/tags/suggestions/${candidateId}/accept`,
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
                if (response.status === 403 && data.limit_type === 'tags_per_asset') {
                    // Plan limit exceeded - show detailed message
                    throw new Error(`${data.message}\n\nUpgrade your plan to add more tags per asset. Visit the billing page to see available plans.`)
                }
                throw new Error(data.message || 'Failed to accept tag')
            }

            // Remove suggestion from list
            setSuggestions((prev) => {
                const updated = prev.filter((s) => s.id !== candidateId)
                // Update cache
                if (tagSuggestionsCache.has(assetId)) {
                    tagSuggestionsCache.set(assetId, {
                        suggestions: updated,
                        timestamp: Date.now()
                    })
                }
                return updated
            })

            // Trigger metadata update event for other components
            window.dispatchEvent(new CustomEvent('metadata-updated'))
        } catch (error) {
            console.error('[AiTagSuggestionsInline] Failed to accept tag', error)
            alert(error.message || 'Failed to accept tag')
        } finally {
            setProcessing((prev) => {
                const next = new Set(prev)
                next.delete(candidateId)
                return next
            })
        }
    }

    // Handle dismiss (show confirmation modal)
    const handleDismiss = (candidateId, tag) => {
        if (processing.has(candidateId)) return
        setShowConfirmDismiss({ id: candidateId, tag: tag })
    }

    // Handle confirmed dismiss (mark candidate as dismissed)
    const handleConfirmedDismiss = async () => {
        if (!showConfirmDismiss) return
        
        const { id: candidateId, tag } = showConfirmDismiss
        setProcessing((prev) => new Set(prev).add(candidateId))
        setShowConfirmDismiss(null)

        try {
            const response = await fetch(
                `/app/assets/${assetId}/tags/suggestions/${candidateId}/dismiss`,
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
                throw new Error(data.message || 'Failed to dismiss tag')
            }

            // Remove suggestion from list
            setSuggestions((prev) => {
                const updated = prev.filter((s) => s.id !== candidateId)
                // Update cache
                if (tagSuggestionsCache.has(assetId)) {
                    tagSuggestionsCache.set(assetId, {
                        suggestions: updated,
                        timestamp: Date.now()
                    })
                }
                return updated
            })
        } catch (error) {
            console.error('[AiTagSuggestionsInline] Failed to dismiss tag', error)
            alert(error.message || 'Failed to dismiss tag')
        } finally {
            setProcessing((prev) => {
                const next = new Set(prev)
                next.delete(candidateId)
                return next
            })
        }
    }

    // Get confidence indicator color
    const getConfidenceColor = (confidence) => {
        if (!confidence) return 'bg-gray-200'
        if (confidence >= 0.9) return 'bg-green-500'
        if (confidence >= 0.8) return 'bg-yellow-500'
        return 'bg-orange-500'
    }

    if (!canView) {
        return null // Hide if user doesn't have permission
    }

    if (loading) {
        return (
            <div className="px-6 py-4 border-t border-gray-200">
                <div className="text-sm text-gray-500">Loading AI tag suggestions...</div>
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
                <h3 className="text-sm font-semibold text-gray-900">AI Suggested Tags</h3>
            </div>

            <div className="flex flex-wrap gap-2">
                {suggestions.map((suggestion) => {
                    const isProcessing = processing.has(suggestion.id)

                    return (
                        <div
                            key={suggestion.id}
                            className="inline-flex items-center gap-2 bg-indigo-50 border border-indigo-200 rounded-md px-3 py-1.5"
                        >
                            <span className="text-sm font-medium text-gray-900">
                                {suggestion.tag}
                            </span>
                            {suggestion.confidence && (
                                <div
                                    className={`h-2 w-8 rounded-full ${getConfidenceColor(
                                        suggestion.confidence
                                    )}`}
                                    title={`Confidence: ${Math.round(
                                        suggestion.confidence * 100
                                    )}%`}
                                />
                            )}
                            <div className="flex items-center gap-1 ml-1">
                                {suggestion.can_apply && (
                                    <button
                                        type="button"
                                        onClick={() => handleAccept(suggestion.id, suggestion.tag)}
                                        disabled={isProcessing}
                                        className="inline-flex items-center p-1 text-green-600 hover:text-green-700 hover:bg-green-100 rounded focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-1 disabled:opacity-50 disabled:cursor-not-allowed"
                                        title="Accept tag"
                                    >
                                        <CheckIcon className="h-4 w-4" />
                                    </button>
                                )}
                                {suggestion.can_dismiss && (
                                    <button
                                        type="button"
                                        onClick={() => handleDismiss(suggestion.id, suggestion.tag)}
                                        disabled={isProcessing}
                                        className="inline-flex items-center p-1 text-gray-600 hover:text-gray-700 hover:bg-gray-100 rounded focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-1 disabled:opacity-50 disabled:cursor-not-allowed"
                                        title="Dismiss tag"
                                    >
                                        <XMarkIcon className="h-4 w-4" />
                                    </button>
                                )}
                            </div>
                        </div>
                    )
                })}
            </div>

            {/* Confirm Dismiss Modal */}
            {showConfirmDismiss && (
                <ConfirmModal
                    title="Dismiss AI Tag Suggestion"
                    message={`Are you sure you want to dismiss the "${showConfirmDismiss.tag}" tag suggestion? This will permanently remove it from future suggestions.`}
                    confirmText="Dismiss"
                    confirmClass="bg-red-600 hover:bg-red-700"
                    onConfirm={handleConfirmedDismiss}
                    onCancel={() => setShowConfirmDismiss(null)}
                    processing={processing.has(showConfirmDismiss.id)}
                />
            )}
        </div>
    )
}

// Confirm Modal Component
function ConfirmModal({ title, message, confirmText, confirmClass, onConfirm, onCancel, processing }) {
    return (
        <>
            {/* Backdrop */}
            <div
                className="fixed inset-0 bg-black/50 z-50 transition-opacity"
                onClick={onCancel}
                aria-hidden="true"
            />

            {/* Modal */}
            <div
                className="fixed inset-0 z-50 flex items-center justify-center p-4"
                onClick={(e) => e.stopPropagation()}
            >
                <div className="bg-white rounded-lg shadow-xl max-w-md w-full">
                    <div className="p-6">
                        <h3 className="text-lg font-semibold text-gray-900 mb-2">{title}</h3>
                        <p className="text-sm text-gray-600 mb-6">{message}</p>

                        <div className="flex items-center justify-end gap-3">
                            <button
                                type="button"
                                onClick={onCancel}
                                disabled={processing}
                                className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-50"
                            >
                                Cancel
                            </button>
                            <button
                                type="button"
                                onClick={onConfirm}
                                disabled={processing}
                                className={`px-4 py-2 text-sm font-medium text-white rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:opacity-50 ${confirmClass}`}
                            >
                                {processing ? 'Processing...' : confirmText}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </>
    )
}
