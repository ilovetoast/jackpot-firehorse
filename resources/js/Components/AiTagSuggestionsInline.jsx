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

import { useState, useEffect, useRef, useCallback } from 'react'
import { SparklesIcon, CheckIcon, XMarkIcon } from '@heroicons/react/24/outline'
import { usePermission } from '../hooks/usePermission'
import { usePage } from '@inertiajs/react'

// Cache positive results only — avoids locking in an empty response while AI jobs are still running.
const tagSuggestionsCache = new Map() // assetId -> { suggestions, timestamp }

export default function AiTagSuggestionsInline({
    assetId,
    uploadedByUserId = null,
    analysisStatus = null,
    primaryColor,
    drawerInsightGroup = false,
}) {
    const { auth } = usePage().props
    const brandColor = primaryColor || auth?.activeBrand?.primary_color || '#6366f1'
    const brandColorTint = brandColor.startsWith('#') ? `${brandColor}18` : `#${brandColor}18`

    const [suggestions, setSuggestions] = useState([])
    const [loading, setLoading] = useState(true)
    const [fetchNonce, setFetchNonce] = useState(0)
    const prevAnalysisStatusRef = useRef(null)

    useEffect(() => {
        prevAnalysisStatusRef.current = analysisStatus
    }, [assetId])

    const { can } = usePermission()
    const brandRole = (auth?.brand_role || '').toLowerCase()
    const isContributorBrandRole = brandRole === 'contributor'
    const isViewerBrandRole = brandRole === 'viewer'
    const isOwnUpload =
        uploadedByUserId != null &&
        auth?.user?.id != null &&
        String(uploadedByUserId) === String(auth.user.id)
    const canView = isViewerBrandRole
        ? false
        : isContributorBrandRole
            ? isOwnUpload && can('metadata.edit_post_upload')
            : can('metadata.suggestions.view') || (isOwnUpload && can('metadata.edit_post_upload'))
    const canApply = isViewerBrandRole
        ? false
        : isContributorBrandRole
            ? isOwnUpload && can('metadata.edit_post_upload')
            : can('metadata.suggestions.apply') || (isOwnUpload && can('metadata.edit_post_upload'))
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

    const bumpFetch = useCallback(() => {
        if (assetId) {
            tagSuggestionsCache.delete(assetId)
        }
        setFetchNonce((n) => n + 1)
    }, [assetId])

    // When pipeline finishes, refetch so we do not keep a pre-AI empty result.
    useEffect(() => {
        const prev = prevAnalysisStatusRef.current
        prevAnalysisStatusRef.current = analysisStatus
        if (!assetId || !canView) {
            return
        }
        if (prev !== 'complete' && analysisStatus === 'complete') {
            bumpFetch()
        }
    }, [assetId, canView, analysisStatus, bumpFetch])

    useEffect(() => {
        const onMetadataUpdated = () => bumpFetch()
        window.addEventListener('metadata-updated', onMetadataUpdated)
        return () => window.removeEventListener('metadata-updated', onMetadataUpdated)
    }, [bumpFetch])

    // Fetch AI tag suggestions (cached when non-empty for 5 minutes)
    useEffect(() => {
        if (!assetId || !canView) {
            setLoading(false)
            return
        }

        const cached = tagSuggestionsCache.get(assetId)
        const cacheValid =
            cached &&
            cached.suggestions?.length > 0 &&
            Date.now() - cached.timestamp < 5 * 60 * 1000

        if (cacheValid) {
            setSuggestions(cached.suggestions)
            setLoading(false)
            return
        }

        let cancelled = false
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
                if (cancelled) return
                const fetchedSuggestions = data.suggestions || []
                setSuggestions(fetchedSuggestions)
                setLoading(false)
                if (fetchedSuggestions.length > 0) {
                    tagSuggestionsCache.set(assetId, {
                        suggestions: fetchedSuggestions,
                        timestamp: Date.now(),
                    })
                }
            })
            .catch((err) => {
                if (cancelled) return
                console.error('[AiTagSuggestionsInline] Failed to fetch tag suggestions', err)
                setLoading(false)
            })

        return () => {
            cancelled = true
        }
    }, [assetId, canView, fetchNonce])

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

    const wrapOuter = (inner) =>
        drawerInsightGroup ? (
            <div className="rounded-md border border-gray-200 bg-white p-3 shadow-sm">{inner}</div>
        ) : (
            <div className="px-4 py-3 border-t border-gray-200">{inner}</div>
        )

    if (loading) {
        return wrapOuter(<div className="text-sm text-gray-500">Loading AI tag suggestions...</div>)
    }

    if (suggestions.length === 0) {
        return null // Hide if no suggestions
    }

    const body = (
        <>
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
        </>
    )

    return wrapOuter(body)
}
