/**
 * TagListUnified Component
 * 
 * Phase J.2.8: Unified, reusable tag list component
 * 
 * Features:
 * - Display tags with optional removal functionality
 * - Source attribution (manual vs AI with subtle styling)
 * - Optimistic UI updates
 * - Configurable for different display contexts
 * - Keyboard accessible
 * 
 * Modes:
 * - 'full': Complete tag management (load from API, show all features)
 * - 'display': Display only (no API calls, show provided tags)
 * - 'compact': Minimal display (smaller size, fewer features)
 */

import { useState, useEffect } from 'react'
import { XMarkIcon } from '@heroicons/react/24/outline'

// Source styling configuration
const getTagStyle = (source) => {
    switch (source) {
        case 'manual':
            return {
                container: 'bg-gray-100 border-gray-300 text-gray-900',
                text: 'text-gray-900',
                button: 'text-gray-600 hover:text-gray-800 hover:bg-gray-200',
                tooltip: 'Manually added'
            }
        case 'ai':
            return {
                container: 'bg-indigo-50 border-indigo-200 text-indigo-900',
                text: 'text-indigo-900',
                button: 'text-indigo-600 hover:text-indigo-800 hover:bg-indigo-100',
                tooltip: 'AI suggested and accepted'
            }
        case 'ai:auto':
            return {
                container: 'bg-purple-50 border-purple-200 text-purple-900',
                text: 'text-purple-900',
                button: 'text-purple-600 hover:text-purple-800 hover:bg-purple-100',
                tooltip: 'Auto-applied by AI'
            }
        default:
            return {
                container: 'bg-gray-100 border-gray-300 text-gray-900',
                text: 'text-gray-900',
                button: 'text-gray-600 hover:text-gray-800 hover:bg-gray-200',
                tooltip: 'Unknown source'
            }
    }
}

export default function TagListUnified({ 
    // Mode and data
    mode = 'full', // 'full' | 'display' | 'compact'
    
    // Full mode props (loads from API)
    assetId = null,
    onTagRemoved = () => {},
    onTagsLoaded = () => {},
    refreshTrigger = null,
    
    // Display mode props (provided data)
    tags = [],
    
    // Common configuration
    className = "",
    showRemoveButtons = true,
    maxTags = null,
    
    // Compact mode options
    compact = false,
    inline = false,
    
    // Detailed mode options
    detailed = false
}) {
    const [loadedTags, setLoadedTags] = useState([])
    const [loading, setLoading] = useState(mode === 'full')
    const [removing, setRemoving] = useState(new Set())

    // Fetch tags for full mode
    const fetchTags = async () => {
        if (mode !== 'full' || !assetId) return

        try {
            const response = await fetch(`/app/api/assets/${assetId}/tags`, {
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                },
                credentials: 'same-origin',
            })

            if (response.ok) {
                const data = await response.json()
                setLoadedTags(data.tags || [])
                onTagsLoaded(data.tags || [])
            } else {
                console.error('[TagListUnified] Failed to fetch tags:', response.status)
            }
        } catch (error) {
            console.error('[TagListUnified] Failed to fetch tags:', error)
        } finally {
            setLoading(false)
        }
    }

    // Initial load and refresh on trigger (full mode only)
    useEffect(() => {
        if (mode === 'full' && assetId) {
            setLoading(true)
            fetchTags()
        }
    }, [assetId, refreshTrigger, mode])

    // Listen for metadata updates from other components (full mode only)
    useEffect(() => {
        if (mode !== 'full') return

        const handleMetadataUpdate = () => {
            fetchTags()
        }

        window.addEventListener('metadata-updated', handleMetadataUpdate)
        return () => window.removeEventListener('metadata-updated', handleMetadataUpdate)
    }, [mode])

    // Handle tag removal
    const removeTag = async (tagId, tagName) => {
        if (removing.has(tagId) || !showRemoveButtons) return

        // Show confirmation dialog
        const confirmed = window.confirm(`Are you sure you want to remove the tag "${tagName}"?`)
        if (!confirmed) return

        if (mode === 'full') {
            // API removal for full mode
            setRemoving(prev => new Set(prev).add(tagId))

            // Optimistic update
            const originalTags = [...loadedTags]
            setLoadedTags(prev => prev.filter(tag => tag.id !== tagId))

            try {
                const response = await fetch(`/app/api/assets/${assetId}/tags/${tagId}`, {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                    },
                    credentials: 'same-origin',
                })

                if (response.ok) {
                    onTagRemoved({ id: tagId, tag: tagName })
                    window.dispatchEvent(new CustomEvent('metadata-updated'))
                } else {
                    setLoadedTags(originalTags)
                    const errorData = await response.json()
                    alert(errorData.message || 'Failed to remove tag')
                }
            } catch (error) {
                setLoadedTags(originalTags)
                console.error('[TagListUnified] Failed to remove tag:', error)
                alert('Failed to remove tag. Please try again.')
            } finally {
                setRemoving(prev => {
                    const next = new Set(prev)
                    next.delete(tagId)
                    return next
                })
            }
        } else {
            // For display mode, just notify parent
            onTagRemoved({ id: tagId, tag: tagName })
        }
    }

    // Get display tags based on mode
    const displayTags = mode === 'full' ? loadedTags : tags
    const finalTags = maxTags ? displayTags.slice(0, maxTags) : displayTags
    const hiddenCount = maxTags && displayTags.length > maxTags ? displayTags.length - maxTags : 0

    // Loading state (full mode only)
    if (loading && mode === 'full') {
        return (
            <div className={className}>
                <div className={`text-gray-500 ${compact ? 'text-xs' : 'text-sm'}`}>
                    Loading tags...
                </div>
            </div>
        )
    }

    // Empty state
    if (finalTags.length === 0) {
        if (mode === 'compact') return null
        
        return (
            <div className={className}>
                <div className={`text-gray-500 ${compact ? 'text-xs' : 'text-sm'}`}>
                    No tags yet
                </div>
            </div>
        )
    }

    return (
        <div className={className}>
            {detailed ? (
                /* Detailed view - each tag on its own line with full metadata */
                <div className="space-y-2">
                    {finalTags.map((tag) => {
                        const style = getTagStyle(tag.source)
                        const isRemoving = removing.has(tag.id)
                        const tagKey = tag.id || `${tag.tag}-${tag.source}`

                        return (
                            <div
                                key={tagKey}
                                className={`flex items-center justify-between py-2 px-3 rounded-lg border ${
                                    style.container
                                } ${isRemoving ? 'opacity-50 pointer-events-none' : ''}`}
                            >
                                <div className="flex-1">
                                    <div className="flex items-center gap-3">
                                        <span className={`font-medium ${style.text} text-sm`}>
                                            {tag.tag.charAt(0).toUpperCase() + tag.tag.slice(1)}
                                        </span>
                                        
                                        {/* Source badge */}
                                        <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${
                                            tag.source === 'ai' ? 'bg-pink-100 text-pink-800' : 'bg-blue-100 text-blue-800'
                                        }`}>
                                            {tag.source === 'ai' ? (
                                                tag.confidence ? `AI (${Math.round(tag.confidence * 100)}%)` : 'AI'
                                            ) : 'Manual'}
                                        </span>
                                    </div>
                                    
                                    {/* Additional metadata */}
                                    <div className="mt-1 text-xs text-gray-500">
                                        {tag.source === 'ai' && tag.confidence && (
                                            <span>
                                                Confidence: {Math.round(tag.confidence * 100)}% 
                                                {tag.created_at && ` • Added ${new Date(tag.created_at).toLocaleDateString()}`}
                                            </span>
                                        )}
                                        {tag.source === 'manual' && tag.created_at && (
                                            <span>Added manually on {new Date(tag.created_at).toLocaleDateString()}</span>
                                        )}
                                    </div>
                                </div>

                                {/* Remove button */}
                                {showRemoveButtons && (
                                    <button
                                        type="button"
                                        onClick={() => removeTag(tag.id, tag.tag)}
                                        disabled={isRemoving}
                                        className={`ml-3 inline-flex items-center p-1 rounded focus:outline-none focus:ring-2 focus:ring-offset-1 focus:ring-gray-500 disabled:opacity-50 disabled:cursor-not-allowed ${style.button}`}
                                        title="Remove tag"
                                        aria-label={`Remove tag "${tag.tag}"`}
                                    >
                                        {isRemoving ? (
                                            <div className="animate-spin rounded-full h-3 w-3 border border-current border-t-transparent" />
                                        ) : (
                                            <XMarkIcon className="h-3 w-3" />
                                        )}
                                    </button>
                                )}
                            </div>
                        )
                    })}
                </div>
            ) : (
                /* Standard compact view - tags in a row */
                <div className={`flex flex-wrap gap-2 ${inline ? 'inline-flex' : ''}`}>
                    {finalTags.map((tag) => {
                        const style = getTagStyle(tag.source)
                        const isRemoving = removing.has(tag.id)
                        const tagKey = tag.id || `${tag.tag}-${tag.source}`

                        return (
                            <div
                                key={tagKey}
                                className={`inline-flex items-center gap-1 rounded-md border ${
                                    compact ? 'px-1.5 py-0.5 text-xs' : 'px-1.5 py-0.5 text-xs'
                                } ${style.container} ${
                                    isRemoving ? 'opacity-50 pointer-events-none' : ''
                                }`}
                                title={style.tooltip}
                            >
                                <span className={`font-medium ${style.text}`}>
                                    {tag.tag.charAt(0).toUpperCase() + tag.tag.slice(1)}
                                </span>
                                
                                {/* Confidence indicator only for AI tags that aren't accepted (manual) */}
                                {!compact && tag.confidence && tag.source === 'ai' && (
                                    <div
                                        className={`h-1.5 w-4 rounded-full ${
                                            tag.confidence >= 0.9 ? 'bg-green-500' :
                                            tag.confidence >= 0.8 ? 'bg-yellow-500' :
                                            'bg-orange-500'
                                        }`}
                                        title={`AI Confidence: ${Math.round(tag.confidence * 100)}%`}
                                    />
                                )}

                                {/* Remove button */}
                                {showRemoveButtons && (
                                    <button
                                        type="button"
                                        onClick={() => removeTag(tag.id, tag.tag)}
                                        disabled={isRemoving}
                                        className={`inline-flex items-center p-0.5 rounded focus:outline-none focus:ring-2 focus:ring-offset-1 focus:ring-gray-500 disabled:opacity-50 disabled:cursor-not-allowed ${style.button}`}
                                        title="Remove tag"
                                        aria-label={`Remove tag "${tag.tag}"`}
                                    >
                                        {isRemoving ? (
                                            <div className="animate-spin rounded-full h-2.5 w-2.5 border border-current border-t-transparent" />
                                        ) : (
                                            <XMarkIcon className="h-2.5 w-2.5" />
                                        )}
                                    </button>
                                )}
                            </div>
                        )
                    })}
                </div>
            )}

            {/* Show hidden count if there are more tags (only in standard view) */}
            {!detailed && hiddenCount > 0 && (
                <div className={`mt-2 text-gray-500 ${compact ? 'text-xs' : 'text-sm'}`}>
                    +{hiddenCount} more tag{hiddenCount !== 1 ? 's' : ''}
                </div>
            )}
        </div>
    )
}