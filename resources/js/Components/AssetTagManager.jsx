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

import { useState, useEffect } from 'react'
import { TagIcon } from '@heroicons/react/24/outline'
import TagInput from './TagInput'
import TagList from './TagList'
import { usePermission } from '../hooks/usePermission'

export default function AssetTagManager({ 
    asset,
    className = "",
    showTitle = true,
    showInput = true,
    maxDisplayTags = null,
    compact = false
}) {
    const [refreshTrigger, setRefreshTrigger] = useState(0)
    const [tags, setTags] = useState([])
    
    const { hasPermission: canView } = usePermission('assets.view')
    const { hasPermission: canAddTags } = usePermission('assets.tags.create')
    const { hasPermission: canRemoveTags } = usePermission('assets.tags.delete')

    // Handle tag added
    const handleTagAdded = (newTag) => {
        // Add to local state for immediate UI update
        setTags(prev => [...prev, newTag])
        
        // Trigger refresh to ensure consistency
        setRefreshTrigger(prev => prev + 1)
    }

    // Handle tag removed
    const handleTagRemoved = (removedTag) => {
        // Remove from local state for immediate UI update
        setTags(prev => prev.filter(tag => tag.id !== removedTag.id))
        
        // Trigger refresh to ensure consistency
        setRefreshTrigger(prev => prev + 1)
    }

    // Handle tags loaded
    const handleTagsLoaded = (loadedTags) => {
        setTags(loadedTags)
    }

    if (!canView || !asset?.id) {
        return null
    }

    return (
        <div className={`${className}`}>
            {/* Header */}
            {showTitle && (
                <div className="flex items-center gap-2 mb-3">
                    <TagIcon className="h-5 w-5 text-gray-500" />
                    <h3 className={`font-semibold text-gray-900 ${compact ? 'text-sm' : 'text-base'}`}>
                        Tags
                    </h3>
                    {tags.length > 0 && (
                        <span className={`text-gray-500 ${compact ? 'text-xs' : 'text-sm'}`}>
                            ({tags.length})
                        </span>
                    )}
                </div>
            )}

            {/* Existing tags */}
            <TagList
                assetId={asset.id}
                onTagRemoved={handleTagRemoved}
                onTagsLoaded={handleTagsLoaded}
                refreshTrigger={refreshTrigger}
                className="mb-3"
                showRemoveButtons={canRemoveTags}
                maxTags={maxDisplayTags}
            />

            {/* Add new tag input */}
            {showInput && canAddTags && (
                <TagInput
                    assetId={asset.id}
                    onTagAdded={handleTagAdded}
                    placeholder="Add a tag..."
                    className="w-full"
                />
            )}

            {/* Permission message if can't add tags */}
            {showInput && !canAddTags && canView && (
                <div className={`text-gray-500 italic ${compact ? 'text-xs' : 'text-sm'}`}>
                    You don't have permission to add tags
                </div>
            )}
        </div>
    )
}