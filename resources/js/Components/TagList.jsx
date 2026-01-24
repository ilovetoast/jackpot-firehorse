/**
 * TagList Component
 * 
 * Phase J.2.3: Display and manage asset tags with removal functionality
 * 
 * Features:
 * - Shows all asset tags (manual, AI suggested, auto-applied)
 * - ✕ icon for instant removal
 * - Source attribution (subtle styling)
 * - Optimistic UI updates
 * - Keyboard accessible
 * - Auto-applied tags removable without feeling sticky
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

export default function TagList({ 
    assetId, 
    onTagRemoved = () => {},
    onTagsLoaded = () => {},
    refreshTrigger = null, // External trigger to refresh tags
    className = "",
    showRemoveButtons = true,
    maxTags = null // Optional limit for display
}) {
    const [tags, setTags] = useState([])
    const [loading, setLoading] = useState(true)
    const [removing, setRemoving] = useState(new Set())

    // Fetch tags
    const fetchTags = async () => {
        try {
            const response = await fetch(`/api/assets/${assetId}/tags`, {
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                },
                credentials: 'same-origin',
            })

            if (response.ok) {
                const data = await response.json()
                setTags(data.tags || [])
                onTagsLoaded(data.tags || [])
            } else {
                console.error('[TagList] Failed to fetch tags:', response.status)
            }
        } catch (error) {
            console.error('[TagList] Failed to fetch tags:', error)
        } finally {
            setLoading(false)
        }
    }

    // Initial load and refresh on trigger
    useEffect(() => {
        if (assetId) {
            setLoading(true)
            fetchTags()
        }
    }, [assetId, refreshTrigger])

    // Listen for metadata updates from other components
    useEffect(() => {
        const handleMetadataUpdate = () => {
            fetchTags()
        }

        window.addEventListener('metadata-updated', handleMetadataUpdate)
        return () => window.removeEventListener('metadata-updated', handleMetadataUpdate)
    }, [])

    // Handle tag removal
    const removeTag = async (tagId, tagName) => {
        if (removing.has(tagId)) return

        setRemoving(prev => new Set(prev).add(tagId))

        // Optimistic update - remove from UI immediately
        const originalTags = [...tags]
        setTags(prev => prev.filter(tag => tag.id !== tagId))

        try {
            const response = await fetch(`/api/assets/${assetId}/tags/${tagId}`, {
                method: 'DELETE',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                },
                credentials: 'same-origin',
            })

            if (response.ok) {
                // Success - notify parent
                onTagRemoved({ id: tagId, tag: tagName })
                
                // Trigger metadata update event for other components
                window.dispatchEvent(new CustomEvent('metadata-updated'))
            } else {
                // Failed - revert optimistic update
                setTags(originalTags)
                const errorData = await response.json()
                alert(errorData.message || 'Failed to remove tag')
            }
        } catch (error) {
            // Failed - revert optimistic update
            setTags(originalTags)
            console.error('[TagList] Failed to remove tag:', error)
            alert('Failed to remove tag. Please try again.')
        } finally {
            setRemoving(prev => {
                const next = new Set(prev)
                next.delete(tagId)
                return next
            })
        }
    }

    if (loading) {
        return (
            <div className={`${className}`}>
                <div className="text-sm text-gray-500">Loading tags...</div>
            </div>
        )
    }

    if (tags.length === 0) {
        return (
            <div className={`${className}`}>
                <div className="text-sm text-gray-500">No tags yet</div>
            </div>
        )
    }

    // Apply max tags limit if specified
    const displayTags = maxTags ? tags.slice(0, maxTags) : tags
    const hiddenCount = maxTags && tags.length > maxTags ? tags.length - maxTags : 0

    return (
        <div className={`${className}`}>
            <div className="flex flex-wrap gap-2">
                {displayTags.map((tag) => {
                    const style = getTagStyle(tag.source)
                    const isRemoving = removing.has(tag.id)

                    return (
                        <div
                            key={tag.id || `${tag.tag}-${tag.source}`}
                            className={`inline-flex items-center gap-2 rounded-md px-3 py-1.5 border text-sm ${style.container} ${
                                isRemoving ? 'opacity-50 pointer-events-none' : ''
                            }`}
                            title={style.tooltip}
                        >
                            <span className={`font-medium ${style.text}`}>
                                {tag.tag}
                            </span>
                            
                            {/* Confidence indicator for AI tags */}
                            {tag.confidence && (
                                <div
                                    className={`h-2 w-6 rounded-full ${
                                        tag.confidence >= 0.9 ? 'bg-green-500' :
                                        tag.confidence >= 0.8 ? 'bg-yellow-500' :
                                        'bg-orange-500'
                                    }`}
                                    title={`Confidence: ${Math.round(tag.confidence * 100)}%`}
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

            {/* Show hidden count if there are more tags */}
            {hiddenCount > 0 && (
                <div className="mt-2 text-xs text-gray-500">
                    +{hiddenCount} more tag{hiddenCount !== 1 ? 's' : ''}
                </div>
            )}
        </div>
    )
}