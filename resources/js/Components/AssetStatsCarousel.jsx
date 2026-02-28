/**
 * Asset Stats Carousel Component
 * 
 * Combined component for "Most Viewed" and "Most Downloaded" assets with tabs.
 * Features:
 * - Tab navigation between Most Viewed (default) and Most Downloaded
 * - Fixed grid layout (no scrollbar) using the same visual style
 * - Items fill width, center item is largest
 * - Responsive item count (9 on large, 7 on medium, 5 on small, 3 on mobile)
 * - Cover container for photography, contain for others
 * - Hover effects with info overlay
 */

import { useState } from 'react'
import { Link } from '@inertiajs/react'
import { EyeIcon, CloudArrowDownIcon, FireIcon } from '@heroicons/react/24/outline'
import ThumbnailPreview from './ThumbnailPreview'

export default function AssetStatsCarousel({ 
    mostViewedAssets = [], 
    mostDownloadedAssets = [],
    mostTrendingAssets = [],
    maxItems = 7, // Reduced from 9 to fit wider tiles
    viewAllLink = '/app/assets'
}) {
    const [activeTab, setActiveTab] = useState('viewed') // 'viewed' | 'downloaded' | 'trending'
    
    // Responsive item count: 7 on large, 5 on medium, 3 on small, 3 on mobile (reduced for wider tiles)
    const maxDisplayItems = Math.min(maxItems, 7)
    
    // Get current assets based on active tab
    const currentAssets = activeTab === 'viewed' ? mostViewedAssets : activeTab === 'downloaded' ? mostDownloadedAssets : mostTrendingAssets
    
    // Sort by views/downloads/trending_score (descending) and take top items
    const sortedAssets = [...currentAssets].sort((a, b) => {
        if (activeTab === 'viewed') {
            const aViews = a.view_count || 0
            const bViews = b.view_count || 0
            return bViews - aViews
        } else if (activeTab === 'downloaded') {
            const aDownloads = a.download_count || 0
            const bDownloads = b.download_count || 0
            return bDownloads - aDownloads
        } else {
            const aScore = a.trending_score ?? 0
            const bScore = b.trending_score ?? 0
            return bScore - aScore
        }
    })
    
    // Order: center-most is highest views/downloads, then cascade left to right alternating
    const reorderForCenterCascade = (items) => {
        if (items.length <= 1) return items
        
        const ordered = new Array(items.length)
        const centerIndex = Math.floor(items.length / 2)
        
        // Center gets highest (index 0 in sorted array)
        ordered[centerIndex] = items[0]
        
        // Then alternate left and right for remaining items
        let sortedIndex = 1
        for (let offset = 1; offset <= centerIndex && sortedIndex < items.length; offset++) {
            // Left side (centerIndex - offset)
            if (centerIndex - offset >= 0 && sortedIndex < items.length) {
                ordered[centerIndex - offset] = items[sortedIndex++]
            }
            // Right side (centerIndex + offset)
            if (centerIndex + offset < items.length && sortedIndex < items.length) {
                ordered[centerIndex + offset] = items[sortedIndex++]
            }
        }
        
        return ordered.filter(item => item !== undefined)
    }
    
    const displayAssets = reorderForCenterCascade(sortedAssets.slice(0, maxDisplayItems))
    const [hoveredIndex, setHoveredIndex] = useState(null)
    
    // Determine if asset is photography based on category
    const isPhotography = (asset) => {
        const categoryName = asset.category?.name || asset.category_name || ''
        return categoryName.toLowerCase().includes('photography') || categoryName.toLowerCase().includes('photo')
    }
    
    // Get background color based on asset type/category
    const getBackgroundColor = (asset) => {
        const categoryName = asset.category?.name || asset.category_name || ''
        const categoryLower = categoryName.toLowerCase()
        
        if (categoryLower.includes('logo')) {
            return 'bg-indigo-50'
        } else if (categoryLower.includes('graphic')) {
            return 'bg-purple-50'
        } else if (categoryLower.includes('video')) {
            return 'bg-red-50'
        } else {
            return 'bg-gray-50'
        }
    }
    
    // Dynamic sizing based on hover - landscape orientation (wider than tall, Netflix-style)
    const getCardSize = (index) => {
        const totalItems = displayAssets.length
        if (totalItems === 0) return { height: 200, width: 360 }
        
        const focalIndex = hoveredIndex !== null ? hoveredIndex : Math.floor((totalItems - 1) / 2)
        const distanceFromFocal = Math.abs(index - focalIndex)
        
        // Landscape proportions: wider than tall (Netflix-style, ~16:9 or 3:2 ratio)
        const baseWidth = 360  // Landscape: width > height
        const baseHeight = 200
        const minWidth = 240
        const minHeight = 140
        
        // Decrease size based on distance from focal point
        const widthDecrease = Math.min(distanceFromFocal * 20, baseWidth - minWidth)
        const heightDecrease = Math.min(distanceFromFocal * 12, baseHeight - minHeight)
        
        return {
            width: Math.max(baseWidth - widthDecrease, minWidth),
            height: Math.max(baseHeight - heightDecrease, minHeight),
        }
    }
    
    // Overlap calculation - adjusted for landscape tiles
    const getOverlapAmount = (index) => {
        if (index === 0) return 0
        const { width } = getCardSize(index)
        return -(width * 0.15) // 15% overlap for landscape tiles
    }
    
    if (mostViewedAssets.length === 0 && mostDownloadedAssets.length === 0 && mostTrendingAssets.length === 0) {
        return (
            <div className="bg-white">
                <div className="px-6 py-6 sm:px-8 sm:py-7">
                    <div className="flex items-center justify-center mb-4">
                        <div className="flex items-center gap-2 border-b border-gray-200">
                            <button
                                type="button"
                                className="px-4 py-2 text-sm font-medium text-gray-500 border-b-2 border-transparent hover:text-gray-700"
                            >
                                <EyeIcon className="h-5 w-5 inline mr-2" />
                                Most Viewed
                            </button>
                            <button
                                type="button"
                                className="px-4 py-2 text-sm font-medium text-gray-500 border-b-2 border-transparent hover:text-gray-700"
                            >
                                <CloudArrowDownIcon className="h-5 w-5 inline mr-2" />
                                Most Downloaded
                            </button>
                            <button
                                type="button"
                                className="px-4 py-2 text-sm font-medium text-gray-500 border-b-2 border-transparent hover:text-gray-700"
                            >
                                <FireIcon className="h-5 w-5 inline mr-2" />
                                Trending
                            </button>
                        </div>
                    </div>
                    <div className="text-center py-8">
                        <EyeIcon className="mx-auto h-8 w-8 text-gray-400" />
                        <p className="mt-2 text-sm text-gray-500">No items yet</p>
                    </div>
                </div>
            </div>
        )
    }
    
    return (
        <div className="bg-white overflow-hidden">
            <div className="px-6 py-6 sm:px-8 sm:py-7 overflow-hidden">
                {/* Header with Tabs */}
                <div className="flex items-center justify-center mb-6 relative">
                    <div className="flex items-center gap-2 border-b border-gray-200">
                        <button
                            type="button"
                            onClick={() => setActiveTab('viewed')}
                            className={`px-4 py-2 text-sm font-medium border-b-2 transition-colors flex items-center gap-2 ${
                                activeTab === 'viewed'
                                    ? 'border-indigo-600 text-indigo-600'
                                    : 'border-transparent text-gray-500 hover:text-gray-700'
                            }`}
                        >
                            <EyeIcon className="h-5 w-5" />
                            Most Viewed
                        </button>
                        <button
                            type="button"
                            onClick={() => setActiveTab('downloaded')}
                            className={`px-4 py-2 text-sm font-medium border-b-2 transition-colors flex items-center gap-2 ${
                                activeTab === 'downloaded'
                                    ? 'border-indigo-600 text-indigo-600'
                                    : 'border-transparent text-gray-500 hover:text-gray-700'
                            }`}
                        >
                            <CloudArrowDownIcon className="h-5 w-5" />
                            Most Downloaded
                        </button>
                        <button
                            type="button"
                            onClick={() => setActiveTab('trending')}
                            className={`px-4 py-2 text-sm font-medium border-b-2 transition-colors flex items-center gap-2 ${
                                activeTab === 'trending'
                                    ? 'border-indigo-600 text-indigo-600'
                                    : 'border-transparent text-gray-500 hover:text-gray-700'
                            }`}
                        >
                            <FireIcon className="h-5 w-5" />
                            Trending
                        </button>
                    </div>
                    {displayAssets.length > 0 && (
                        <Link
                            href={viewAllLink}
                            className="absolute right-0 text-sm text-indigo-600 hover:text-indigo-900 font-medium"
                        >
                            View All
                        </Link>
                    )}
                </div>
                
                {/* Asset Grid */}
                {displayAssets.length > 0 ? (
                    <div className="overflow-hidden">
                        <div className="flex items-center justify-center relative mx-auto" style={{ 
                            maxWidth: '100%',
                            paddingLeft: '2rem', 
                            paddingRight: '2rem', 
                            minHeight: '220px',
                            width: '100%'
                        }}>
                            {displayAssets.map((asset, index) => {
                                const { height, width } = getCardSize(index)
                                const overlapAmount = getOverlapAmount(index)
                                const isHovered = hoveredIndex === index
                                const hoverScale = isHovered ? 1.05 : 1 // Reduced scale to prevent disappearing
                                const isPhoto = isPhotography(asset)
                                const focalIndex = hoveredIndex !== null ? hoveredIndex : Math.floor((displayAssets.length - 1) / 2)
                                const distanceFromFocal = Math.abs(index - focalIndex)
                                
                                return (
                                    <Link
                                        key={asset.id}
                                        href={`/app/assets?asset=${asset.id}`}
                                        className="relative transition-all duration-300 ease-out flex-shrink-0"
                                        style={{
                                            height: `${height}px`,
                                            width: `${width}px`,
                                            marginLeft: index === 0 ? '0' : `${overlapAmount}px`,
                                            transform: `scale(${hoverScale})`,
                                            transformOrigin: 'center center',
                                            zIndex: isHovered ? 50 : 30 - distanceFromFocal, // Higher z-index range to prevent disappearing
                                            opacity: 1, // Ensure tiles are always visible
                                            maxWidth: '100%', // Prevent overflow
                                        }}
                                        onMouseEnter={() => setHoveredIndex(index)}
                                        onMouseLeave={() => setHoveredIndex(null)}
                                    >
                                        <div
                                            className={`rounded-lg overflow-hidden shadow-lg ${getBackgroundColor(asset)}`}
                                            style={{
                                                height: '100%',
                                                width: '100%',
                                            }}
                                        >
                                            <div className="relative w-full h-full">
                                                <ThumbnailPreview
                                                    asset={asset}
                                                    alt={asset.title || asset.original_filename || 'Asset'}
                                                    className={`w-full h-full ${
                                                        isPhoto ? 'object-cover' : 'object-contain'
                                                    }`}
                                                    size="medium"
                                                />
                                                
                                                {/* Hover Gradient - Softer, longer transition */}
                                                <div 
                                                    className={`absolute bottom-0 left-0 right-0 transition-opacity duration-300 ${
                                                        isHovered ? 'opacity-100' : 'opacity-0'
                                                    }`}
                                                    style={{
                                                        height: '40%',
                                                        background: 'linear-gradient(to top, rgba(0, 0, 0, 0.5) 0%, rgba(0, 0, 0, 0.3) 30%, rgba(0, 0, 0, 0.15) 60%, rgba(0, 0, 0, 0.05) 85%, transparent 100%)',
                                                        zIndex: 10,
                                                    }}
                                                />
                                                {/* Hover Content */}
                                                {isHovered && (
                                                    <div className="absolute bottom-0 left-0 right-0 flex items-end p-3 transition-opacity z-20">
                                                        <div className="text-white w-full">
                                                            <p className="text-sm font-medium truncate">
                                                                {asset.title || asset.original_filename || 'Untitled'}
                                                            </p>
                                                            <div className="flex items-center gap-2 mt-1">
                                                                {activeTab === 'viewed' ? (
                                                                    <>
                                                                        <EyeIcon className="h-4 w-4" />
                                                                        <span className="text-xs">
                                                                            {asset.view_count || 0} {asset.view_count === 1 ? 'view' : 'views'}
                                                                        </span>
                                                                    </>
                                                                ) : activeTab === 'downloaded' ? (
                                                                    <>
                                                                        <CloudArrowDownIcon className="h-4 w-4" />
                                                                        <span className="text-xs">
                                                                            {asset.download_count || 0} {asset.download_count === 1 ? 'download' : 'downloads'}
                                                                        </span>
                                                                    </>
                                                                ) : (
                                                                    <>
                                                                        <FireIcon className="h-4 w-4" />
                                                                        <span className="text-xs">Trending</span>
                                                                    </>
                                                                )}
                                                            </div>
                                                        </div>
                                                    </div>
                                                )}
                                            </div>
                                        </div>
                                    </Link>
                                )
                            })}
                        </div>
                    </div>
                ) : (
                    <div className="text-center py-8">
                        {activeTab === 'viewed' ? (
                            <EyeIcon className="mx-auto h-8 w-8 text-gray-400" />
                        ) : activeTab === 'downloaded' ? (
                            <CloudArrowDownIcon className="mx-auto h-8 w-8 text-gray-400" />
                        ) : (
                            <FireIcon className="mx-auto h-8 w-8 text-gray-400" />
                        )}
                        <p className="mt-2 text-sm text-gray-500">
                            {activeTab === 'viewed' ? 'No views yet' : activeTab === 'downloaded' ? 'No downloads yet' : 'No trending activity yet'}
                        </p>
                    </div>
                )}
            </div>
        </div>
    )
}
