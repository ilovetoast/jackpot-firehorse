/**
 * Recently Viewed Grid Component
 * 
 * Fixed grid display for recently viewed assets.
 * Features:
 * - Fixed grid layout (no scrollbar)
 * - Items fill width, center item is largest
 * - Responsive item count (9 on large, 7 on medium, 5 on small, 3 on mobile)
 * - Cover container for photography, contain for others
 * - Hover effects with info overlay
 */

import { useState } from 'react'
import { Link } from '@inertiajs/react'
import { EyeIcon } from '@heroicons/react/24/outline'
import ThumbnailPreview from './ThumbnailPreview'

export default function RecentlyViewedCarousel({ assets = [], title = 'Recently Viewed', maxItems = 9, viewAllLink = '/app/assets' }) {
    // Responsive item count: 9 on large, 7 on medium, 5 on small, 3 on mobile
    // We'll use CSS to handle this, but limit to maxItems
    const maxDisplayItems = Math.min(maxItems, 9)
    
    // Sort by views (descending) and take top items
    const sortedAssets = [...assets].sort((a, b) => {
        const aViews = a.view_count || 0
        const bViews = b.view_count || 0
        return bViews - aViews
    })
    
    // Order: center-most is highest views, then cascade left to right alternating
    // Pattern: center (highest), left (2nd), right (3rd), left (4th), right (5th), etc.
    // For 9 items sorted [0=highest, 1, 2, 3, 4, 5, 6, 7, 8=lowest]:
    // Result: [4, 3, 5, 2, 6, 1, 7, 0, 8] (indices in display order)
    // Which maps to: [item0, item1, item2, item3, item4, item5, item6, item7, item8] (sorted by views)
    const reorderForCenterCascade = (items) => {
        if (items.length <= 1) return items
        
        const ordered = new Array(items.length)
        const centerIndex = Math.floor(items.length / 2)
        
        // Center gets highest views (index 0 in sorted array)
        ordered[centerIndex] = items[0]
        
        // Then alternate left and right for remaining items
        let sortedIndex = 1 // Start with 2nd highest views
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
        
        // Brand-specific colors based on category
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
    
    if (displayAssets.length === 0) {
        return (
            <div className="bg-white">
                <div className="px-6 py-6 sm:px-8 sm:py-7">
                    <div className="flex items-center justify-center mb-4">
                        <h3 className="text-base font-semibold leading-6 text-gray-900 flex items-center gap-2">
                            <EyeIcon className="h-5 w-5 text-gray-400" />
                            {title}
                        </h3>
                    </div>
                    <div className="text-center py-8">
                        <EyeIcon className="mx-auto h-8 w-8 text-gray-400" />
                        <p className="mt-2 text-sm text-gray-500">No items yet</p>
                    </div>
                </div>
            </div>
        )
    }
    
    // Calculate dynamic sizes based on hover state: hovered card is largest, cascade down from there
    // When hovering over a card, that card becomes the focal point
    // Cards to the left and right get progressively smaller based on distance from hovered card
    // Sizes are reduced to ensure all cards fit within container
    const getCardSize = (index) => {
        const totalItems = displayAssets.length
        
        // If no hover, use center as focal point (default state)
        const focalIndex = hoveredIndex !== null ? hoveredIndex : Math.floor((totalItems - 1) / 2)
        const distanceFromFocal = Math.abs(index - focalIndex)
        
        // Reduced base size for focal item (to ensure all cards fit)
        const baseHeight = 360 // Reduced from 400px
        const baseWidth = 240 // Reduced from 280px
        
        // Decrease by 20px height and 14px width for each step away from focal point
        const heightDecrease = distanceFromFocal * 20
        const widthDecrease = distanceFromFocal * 14
        
        const finalHeight = baseHeight - heightDecrease
        const finalWidth = baseWidth - widthDecrease
        
        // Minimum size (also reduced)
        const minHeight = 220 // Reduced from 240px
        const minWidth = 140 // Reduced from 160px
        
        return {
            height: `${Math.max(finalHeight, minHeight)}px`,
            width: `${Math.max(finalWidth, minWidth)}px`,
        }
    }
    
    // Calculate overlap amount (20-30% of card width)
    const getOverlapAmount = (index, cardWidth) => {
        if (index === 0) return 0
        // Overlap by ~25% of card width (negative margin)
        const widthValue = parseInt(cardWidth)
        return -(widthValue * 0.25)
    }
    
    return (
        <div className="bg-white overflow-visible w-full">
            <div className="px-6 py-6 sm:px-8 sm:py-7">
                <div className="flex items-center justify-center mb-6 relative">
                    <h3 className="text-base font-semibold leading-6 text-gray-900 flex items-center gap-2">
                        <EyeIcon className="h-5 w-5 text-gray-400" />
                        {title}
                    </h3>
                    {displayAssets.length > 0 && (
                        <Link
                            href={viewAllLink}
                            className="absolute right-0 text-sm text-indigo-600 hover:text-indigo-900 font-medium"
                        >
                            View All
                        </Link>
                    )}
                </div>
                
                {/* Fixed Container - overlapping cards, vertically centered, macOS dock effect */}
                <div className="relative overflow-visible w-full">
                    <div 
                        className="flex items-center justify-center relative"
                        style={{
                            paddingTop: '3rem',
                            paddingBottom: '3rem',
                            paddingLeft: '4rem', // Add left padding to prevent left cards from being cut off
                            paddingRight: '4rem', // Add right padding to prevent right cards from being cut off
                            minHeight: '500px', // Ensure enough vertical space
                        }}
                    >
                        {displayAssets.map((asset, index) => {
                            const isPhoto = isPhotography(asset)
                            const bgColor = getBackgroundColor(asset)
                            const isHovered = hoveredIndex === index
                            const cardSize = getCardSize(index)
                            const overlapAmount = getOverlapAmount(index, cardSize.width)
                            
                            // Responsive visibility: show more items as screen size increases
                            // Mobile (default): show first 3 (indices 0-2)
                            // sm: show first 5 (indices 0-4)
                            // md: show first 7 (indices 0-6)
                            // lg: show all 9 (indices 0-8)
                            let visibilityClass = ''
                            if (index >= 3 && index < 5) {
                                // Items 3-4: hidden on mobile, visible sm+
                                visibilityClass = 'hidden sm:flex'
                            } else if (index >= 5 && index < 7) {
                                // Items 5-6: hidden on mobile/sm, visible md+
                                visibilityClass = 'hidden md:flex'
                            } else if (index >= 7) {
                                // Items 7-8: hidden on mobile/sm/md, visible lg+
                                visibilityClass = 'hidden lg:flex'
                            }
                            
                            // macOS dock effect: scale up on hover
                            // The card size already changes based on hover (via getCardSize), so we just add a small scale boost on hover
                            const hoverScale = isHovered ? 1.1 : 1.0
                            
                            return (
                                <Link
                                    key={asset.id}
                                    href={`/app/assets?asset=${asset.id}`}
                                    className={`flex-shrink-0 relative rounded-xl overflow-hidden shadow-xl transition-all duration-300 cursor-pointer ${visibilityClass || 'flex'}`}
                                    style={{
                                        height: cardSize.height,
                                        width: cardSize.width,
                                        zIndex: isHovered ? 50 : displayAssets.length - index + 10,
                                        transform: `scale(${hoverScale})`,
                                        transformOrigin: 'center center',
                                        marginLeft: index === 0 ? '0' : `${overlapAmount}px`,
                                    }}
                                    onMouseEnter={() => setHoveredIndex(index)}
                                    onMouseLeave={() => setHoveredIndex(null)}
                                >
                                    {/* Image Container */}
                                    <div className={`absolute inset-0 ${isPhoto ? 'bg-black' : bgColor}`}>
                                        {isPhoto ? (
                                            // Photography: Cover container
                                            <ThumbnailPreview
                                                asset={asset}
                                                alt={asset.title || 'Asset'}
                                                className="w-full h-full object-cover"
                                                size="md"
                                            />
                                        ) : (
                                            // Other assets: Contain with background
                                            <div className="relative w-full h-full flex items-center justify-center">
                                                <ThumbnailPreview
                                                    asset={asset}
                                                    alt={asset.title || 'Asset'}
                                                    className="max-w-full max-h-full object-contain"
                                                    size="md"
                                                />
                                                {/* Optional: Blurred background overlay */}
                                                <div 
                                                    className="absolute inset-0 opacity-20 blur-xl -z-10"
                                                    style={{
                                                        backgroundImage: `url(${asset.thumbnail_url || asset.final_thumbnail_url || ''})`,
                                                        backgroundSize: 'cover',
                                                        backgroundPosition: 'center'
                                                    }}
                                                />
                                            </div>
                                        )}
                                    </div>
                                    
                                    {/* Hover Overlay with Info */}
                                    <div 
                                        className={`absolute inset-0 bg-gradient-to-t from-black/90 via-black/50 to-transparent transition-all duration-300 ${
                                            isHovered ? 'opacity-100' : 'opacity-0'
                                        }`}
                                    >
                                        <div className="absolute bottom-0 left-0 right-0 p-5 text-white">
                                            <h4 className="font-semibold text-base mb-2 line-clamp-2 drop-shadow-lg">
                                                {asset.title || asset.original_filename || 'Untitled'}
                                            </h4>
                                            <div className="flex items-center gap-3 text-xs text-white/90">
                                                {asset.view_count !== undefined && (
                                                    <div className="flex items-center gap-1.5">
                                                        <EyeIcon className="h-4 w-4" />
                                                        <span className="font-medium">{asset.view_count.toLocaleString()} {asset.view_count === 1 ? 'view' : 'views'}</span>
                                                    </div>
                                                )}
                                                {asset.category?.name && (
                                                    <span className="ml-auto px-3 py-1 bg-white/25 backdrop-blur-sm rounded-full font-medium border border-white/30">
                                                        {asset.category.name}
                                                    </span>
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                    
                                    {/* Subtle border for depth */}
                                    <div className="absolute inset-0 rounded-xl border border-white/20 pointer-events-none shadow-inner" />
                                </Link>
                            )
                        })}
                    </div>
                </div>
            </div>
        </div>
    )
}
