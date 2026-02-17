/**
 * AI Tag Suggestions Inline Component
 *
 * Displays AI-generated tag suggestions inline in the asset drawer.
 * Tags are stored in asset_tag_candidates table (not approved data).
 *
 * Features:
 * - Section labeled "AI Suggested Tags"
 * - Tags shown with confidence scores
 * - Approve/Reject buttons (no confirmation modals)
 * - Styled to match Metadata Candidate Review (brand colors)
 */

import { useState, useEffect, useRef } from 'react'
import { SparklesIcon, CheckIcon, XMarkIcon } from '@heroicons/react/24/outline'
import { usePermission } from '../hooks/usePermission'
import { usePage } from '@inertiajs/react'

// Global cache to prevent fetching suggestions for the same asset multiple times
const tagSuggestionsCache = new Map() // assetId -> { suggestions, timestamp }

export default function AiTagSuggestionsInline({ assetId, primaryColor }) {
    const { auth } = usePage().props
    const brandColor = primaryColor || auth?.activeBrand?.primary_color || '#6366f1'
    const brandColorTint = brandColor.startsWith('#') ? `${brandColor}18` : `#${brandColor}18`

    const [suggestions, setSuggestions] = useState([])
    const [loading, setLoading] = useState(true)
    const hasFetchedSuggestions = useRef(false) // Prevent multiple fetches per component instance
    
    const { can } = usePermission()
    const canView = can('metadata.suggestions.view')
    const canApply = can('metadata.suggestions.apply')
    const [processing, setProcessing] = useState(new Set())

    // Handle accept (create tag in asset_tags) — no confirmation modal
    const handleAccept = async (candidateId, tag) => {
        if (processing.has(candidateId) || !canApply) return
        setProcessing((prev) => new Set(prev).add(candidateId))
        try {
            const res = await fetch(`/app/assets/${assetId}/tags/suggestions/${candidateId}/accept`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content },
                credentials: 'same-origin',
            })
            if (!res.ok) {
                const data = await res.json()
                if (res.status === 403 && data.limit_type === 'tags_per_asset') {
                    throw new Error(`${data.message}\n\nUpgrade your plan to add more tags per asset.`)
                }
                throw new Error(data.message || 'Failed to accept tag')
            }
            setSuggestions((prev) => {
                const updated = prev.filter((s) => s.id !== candidateId)
                if (tagSuggestionsCache.has(assetId)) tagSuggestionsCache.set(assetId, { suggestions: updated, timestamp: Date.now() })
                return updated
            })
            window.dispatchEvent(new CustomEvent('metadata-updated'))
        } catch (err) {
            console.error('[AiTagSuggestionsInline] Failed to accept tag', err)
            alert(err.message || 'Failed to accept tag')
        } finally {
            setProcessing((prev) => { const next = new Set(prev); next.delete(candidateId); return next })
        }
    }

    // Handle dismiss — no confirmation modal, immediate
    const handleDismiss = async (candidateId) => {
        if (processing.has(candidateId)) return
        setProcessing((prev) => new Set(prev).add(candidateId))
        try {
            const res = await fetch(`/app/assets/${assetId}/tags/suggestions/${candidateId}/dismiss`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content },
                credentials: 'same-origin',
            })
            if (!res.ok) {
                const data = await res.json()
                throw new Error(data.message || 'Failed to dismiss tag')
            }
            setSuggestions((prev) => {
                const updated = prev.filter((s) => s.id !== candidateId)
                if (tagSuggestionsCache.has(assetId)) tagSuggestionsCache.set(assetId, { suggestions: updated, timestamp: Date.now() })
                return updated
            })
        } catch (err) {
            console.error('[AiTagSuggestionsInline] Failed to dismiss tag', err)
            alert(err.message || 'Failed to dismiss tag')
        } finally {
            setProcessing((prev) => { const next = new Set(prev); next.delete(candidateId); return next })
        }
    }

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
            <div className="px-4 py-4 border-t border-gray-200">
                <div className="text-sm text-gray-500">Loading AI tag suggestions...</div>
            </div>
        )
    }

    if (suggestions.length === 0) {
        return null // Hide if no suggestions
    }

    return (
        <div className="px-4 py-3 border-t border-gray-200">
            <div className="flex items-center gap-1.5 mb-2">
                <SparklesIcon className="h-3.5 w-3.5 flex-shrink-0" style={{ color: brandColor }} />
                <h3 className="text-xs font-semibold text-gray-900">AI Suggested Tags</h3>
            </div>

            <div className="flex flex-wrap gap-1.5">
                {suggestions.map((suggestion) => {
                    const isProcessing = processing.has(suggestion.id)
                    return (
                        <div
                            key={suggestion.id}
                            className="inline-flex items-center gap-2 rounded-md px-2 py-1 border"
                            style={{ borderColor: `${brandColor}40`, backgroundColor: brandColorTint }}
                        >
                            <span className="text-xs font-medium text-gray-900">
                                {suggestion.tag}
                            </span>
                            {suggestion.confidence && (
                                <div
                                    className={`h-1.5 w-6 rounded-full ${getConfidenceColor(suggestion.confidence)}`}
                                    title={`Confidence: ${Math.round(suggestion.confidence * 100)}%`}
                                />
                            )}
                            {(suggestion.can_apply && canApply) || suggestion.can_dismiss ? (
                                <div className="flex items-center gap-0.5 ml-0.5">
                                    {suggestion.can_apply && canApply && (
                                        <button
                                            type="button"
                                            onClick={() => handleAccept(suggestion.id, suggestion.tag)}
                                            disabled={isProcessing}
                                            className="p-0.5 text-green-600 hover:text-green-700 hover:bg-green-100 rounded disabled:opacity-50 disabled:cursor-not-allowed"
                                            title="Accept tag"
                                        >
                                            <CheckIcon className="h-3 w-3" />
                                        </button>
                                    )}
                                    {suggestion.can_dismiss && (
                                        <button
                                            type="button"
                                            onClick={() => handleDismiss(suggestion.id)}
                                            disabled={isProcessing}
                                            className="p-0.5 text-gray-600 hover:text-gray-700 hover:bg-gray-100 rounded disabled:opacity-50 disabled:cursor-not-allowed"
                                            title="Dismiss tag"
                                        >
                                            <XMarkIcon className="h-3 w-3" />
                                        </button>
                                    )}
                                </div>
                            ) : null}
                        </div>
                    )
                })}
            </div>
        </div>
    )
}
