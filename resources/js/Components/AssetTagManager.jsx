/**
 * AssetTagManager Component
 * 
 * Phase J.2.3: Unified tag management combining input and display
 * 
 * Features:
 * - Combines TagInput and TagList in a cohesive interface
 * - Shows existing tags with ✕ removal
 * - Input for adding new tags with autocomplete
 * - Source attribution (manual vs AI)
 * - Real-time updates between components
 * - Permission-based visibility
 */

import { useState, useEffect, useRef } from 'react'
import { TagIcon } from '@heroicons/react/24/outline'
import TagInputUnified from './TagInputUnified'
import TagListUnified from './TagListUnified'
import { usePermission } from '../hooks/usePermission'

export default function AssetTagManager({ 
    asset,
    className = "",
    showTitle = true,
    showInput = true,
    maxDisplayTags = null,
    compact = false,
    inline = false,
    detailed = false,
    primaryColor = null,
    /** 'dark' = light text for embedded lightbox details column */
    variant = 'default',
    /** View-only: no add/remove (e.g. lightbox read-only column) */
    readOnly = false,
}) {
    const isDark = variant === 'dark'
    const [refreshTrigger, setRefreshTrigger] = useState(0)
    const [tagCount, setTagCount] = useState(0)
    const prevAnalysisStatusRef = useRef(null)

    useEffect(() => {
        prevAnalysisStatusRef.current = asset?.analysis_status ?? null
    }, [asset?.id])

    useEffect(() => {
        if (!asset?.id) {
            return
        }
        const st = asset?.analysis_status
        const prev = prevAnalysisStatusRef.current
        if (prev !== 'complete' && st === 'complete') {
            setRefreshTrigger((t) => t + 1)
        }
        prevAnalysisStatusRef.current = st
    }, [asset?.id, asset?.analysis_status])
    
    // Permission check - align with existing asset permissions
    // If user can see the asset drawer, they can see tags. Use same pattern as metadata.
    const canView = true // Tags are viewable if asset is viewable (drawer already shown)
    const { can } = usePermission()
    const canAddTags = can('assets.tags.create')
    const canRemoveTags = can('assets.tags.delete')
    const effectiveShowInput = readOnly ? false : showInput
    const effectiveShowRemove = readOnly ? false : canRemoveTags

    // Handle tag added
    const handleTagAdded = (newTag) => {
        setRefreshTrigger(prev => prev + 1)
        if (typeof window !== 'undefined') window.dispatchEvent(new CustomEvent('tags-updated'))
    }

    // Handle tag removed
    const handleTagRemoved = (removedTag) => {
        setRefreshTrigger(prev => prev + 1)
        if (typeof window !== 'undefined') window.dispatchEvent(new CustomEvent('tags-updated'))
    }

    // Handle tags loaded (just track count to avoid state loops)
    const handleTagsLoaded = (loadedTags) => {
        setTagCount(loadedTags?.length || 0)
    }

    if (!canView || !asset?.id) {
        return null
    }

    return (
        <div className={`${className}`}>
            {inline ? (
                /* Inline layout: (icon) Tags: ___(input)___ */
                <>
                    <div className="flex items-center gap-2 mb-3">
                        <TagIcon className="h-4 w-4 text-gray-500" />
                        <span className="text-sm font-medium text-gray-700">Tags:</span>
                        {effectiveShowInput && canAddTags && (
                            <div className="flex-1">
                                <TagInputUnified
                                    mode="asset"
                                    assetId={asset.id}
                                    onTagAdded={handleTagAdded}
                                    placeholder="Add a tag..."
                                    className="w-full"
                                    compact={compact}
                                />
                            </div>
                        )}
                        {tagCount > 0 && (
                            <span className="text-gray-500 text-xs">
                                ({tagCount})
                            </span>
                        )}
                    </div>
                    
                    {/* Existing tags below */}
                    <TagListUnified
                        mode="full"
                        assetId={asset.id}
                        onTagRemoved={handleTagRemoved}
                        onTagsLoaded={handleTagsLoaded}
                        refreshTrigger={refreshTrigger}
                        className=""
                        showRemoveButtons={effectiveShowRemove}
                        maxTags={maxDisplayTags}
                        compact={compact}
                        detailed={detailed}
                        primaryColor={primaryColor}
                    />

                    {/* Permission message if can't add tags */}
                    {effectiveShowInput && !canAddTags && canView && (
                        <div className="text-gray-500 italic text-xs mt-2">
                            You don't have permission to add tags
                        </div>
                    )}
                </>
            ) : (
                /* Standard vertical layout */
                <>
                    {/* Header */}
                    {showTitle && (
                        <div className="flex items-center gap-2 mb-3">
                            <TagIcon className={`h-4 w-4 ${isDark ? 'text-neutral-500' : 'text-gray-500'}`} />
                            <h5 className={`font-semibold tracking-tight ${isDark ? 'text-sm text-neutral-100' : compact ? 'text-xs' : 'text-xs text-gray-700'}`}>
                                Tags
                            </h5>
                            {tagCount > 0 && (
                                <span className={`text-xs ${isDark ? 'text-neutral-500' : 'text-gray-500'}`}>
                                    ({tagCount})
                                </span>
                            )}
                        </div>
                    )}

                    {/* Existing tags */}
                    <TagListUnified
                        mode="full"
                        assetId={asset.id}
                        onTagRemoved={handleTagRemoved}
                        onTagsLoaded={handleTagsLoaded}
                        refreshTrigger={refreshTrigger}
                        className="mb-3"
                        showRemoveButtons={effectiveShowRemove}
                        maxTags={maxDisplayTags}
                        compact={compact}
                        detailed={detailed}
                        primaryColor={primaryColor}
                        variant={variant}
                    />

                    {/* Add new tag input */}
                    {effectiveShowInput && canAddTags && (
                        <TagInputUnified
                            mode="asset"
                            assetId={asset.id}
                            onTagAdded={handleTagAdded}
                            placeholder="Add a tag..."
                            className="w-full"
                            compact={compact}
                        />
                    )}

                    {/* Permission message if can't add tags */}
                    {effectiveShowInput && !canAddTags && canView && (
                        <div className={`italic ${compact ? 'text-xs' : 'text-sm'} ${isDark ? 'text-neutral-500' : 'text-gray-500'}`}>
                            You don't have permission to add tags
                        </div>
                    )}
                </>
            )}
        </div>
    )
}