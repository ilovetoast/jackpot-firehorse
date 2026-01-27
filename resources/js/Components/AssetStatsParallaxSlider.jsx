/**
 * Asset Stats Parallax Slider Component
 * 
 * Full-width parallax slider for "Most Viewed" and "Most Downloaded" assets.
 * Based on Codrops tutorial: https://tympanus.net/codrops/2019/08/20/react-slider-with-parallax-hover-effects/
 * CodePen: https://codepen.io/hexagoncircle/full/jgGxKR
 * 
 * Features:
 * - GSAP animations for smooth parallax effects
 * - Images extend full width outside grid
 * - Fixed 5 images per page
 * - Center image full opacity, others reduced
 * - Navigation arrows at bottom
 * - Tab navigation between Most Viewed and Most Downloaded
 */

import { useState, useEffect, useRef, useCallback } from 'react'
import { Link } from '@inertiajs/react'
import { EyeIcon, CloudArrowDownIcon, ChevronLeftIcon, ChevronRightIcon } from '@heroicons/react/24/outline'
import ThumbnailPreview from './ThumbnailPreview'

export default function AssetStatsParallaxSlider({ 
    mostViewedAssets = [], 
    mostDownloadedAssets = [],
    viewAllLink = '/app/assets'
}) {
    const [activeTab, setActiveTab] = useState('viewed')
    const [currentIndex, setCurrentIndex] = useState(0)
    const sliderWrapperRef = useRef(null)
    
    // Get current assets based on active tab
    const currentAssets = activeTab === 'viewed' ? mostViewedAssets : mostDownloadedAssets
    
    // Sort by views/downloads (descending)
    const sortedAssets = [...currentAssets].sort((a, b) => {
        if (activeTab === 'viewed') {
            return (b.view_count || 0) - (a.view_count || 0)
        } else {
            return (b.download_count || 0) - (a.download_count || 0)
        }
    })
    
    // Show 5 tiles at once, with center tile active
    const tilesPerView = 5
    const centerIndex = Math.floor(tilesPerView / 2) // Index 2 is center (0,1,2,3,4)
    
    // Calculate which item should be in the center
    // Start with first item in center, then move forward
    const centerItemIndex = currentIndex
    
    // Calculate the range of items to display (5 tiles centered around centerItemIndex)
    const startIndex = Math.max(0, centerItemIndex - centerIndex)
    const endIndex = Math.min(sortedAssets.length, startIndex + tilesPerView)
    const visibleItems = sortedAssets.slice(startIndex, endIndex)
    
    // Pad with nulls if we don't have enough items
    const paddingStart = Math.max(0, centerIndex - centerItemIndex)
    const paddingEnd = Math.max(0, tilesPerView - visibleItems.length - paddingStart)
    
    // Build display items array safely
    const displayItems = []
    // Add padding at start
    for (let i = 0; i < paddingStart; i++) {
        displayItems.push(null)
    }
    // Add visible items
    displayItems.push(...visibleItems)
    // Add padding at end
    for (let i = 0; i < paddingEnd; i++) {
        displayItems.push(null)
    }
    
    // Ensure we have exactly tilesPerView items
    const finalDisplayItems = displayItems.slice(0, tilesPerView)
    while (finalDisplayItems.length < tilesPerView) {
        finalDisplayItems.push(null)
    }
    
    // Reset to first item (in center) when tab changes
    useEffect(() => {
        setCurrentIndex(0)
    }, [activeTab])
    
    // Navigation handlers - move by one item, but keep center position
    const handlePrevious = () => {
        setCurrentIndex(prev => Math.max(0, prev - 1))
    }
    
    const handleNext = () => {
        setCurrentIndex(prev => Math.min(sortedAssets.length - 1, prev + 1))
    }
    
    // No transform needed - items are positioned statically, center item is always in center
    const sliderTransform = 0
    
    if (sortedAssets.length === 0) {
        return (
            <div className="w-full bg-white py-12">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                    <div className="flex items-center justify-center mb-6">
                        <div className="flex items-center gap-2 border-b border-gray-200">
                            <button
                                type="button"
                                className="px-4 py-2 text-sm font-medium text-gray-500 border-b-2 border-transparent hover:text-gray-700"
                            >
                                <EyeIcon className="h-4 w-4 inline mr-2" />
                                Most Viewed
                            </button>
                            <button
                                type="button"
                                className="px-4 py-2 text-sm font-medium text-gray-500 border-b-2 border-transparent hover:text-gray-700"
                            >
                                <CloudArrowDownIcon className="h-4 w-4 inline mr-2" />
                                Most Downloaded
                            </button>
                        </div>
                    </div>
                    <div className="text-center py-8">
                        <p className="text-sm text-gray-500">No items yet</p>
                    </div>
                </div>
            </div>
        )
    }
    
    return (
        <div className="w-screen bg-white py-12 overflow-hidden" style={{ marginLeft: 'calc(-50vw + 50%)', marginRight: 'calc(-50vw + 50%)' }}>
            {/* Header with Tabs - Centered */}
            <div className="w-full max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mb-8">
                <div className="flex items-center justify-center">
                    <div className="flex items-center gap-2 border-b border-gray-200">
                        <button
                            type="button"
                            onClick={() => setActiveTab('viewed')}
                            className={`px-4 py-2 text-xs font-medium border-b-2 transition-colors flex items-center gap-2 ${
                                activeTab === 'viewed'
                                    ? 'border-indigo-600 text-indigo-600'
                                    : 'border-transparent text-gray-500 hover:text-gray-700'
                            }`}
                        >
                            <EyeIcon className="h-4 w-4" />
                            <span className="hidden sm:inline">Most Viewed</span>
                        </button>
                        <button
                            type="button"
                            onClick={() => setActiveTab('downloaded')}
                            className={`px-4 py-2 text-xs font-medium border-b-2 transition-colors flex items-center gap-2 ${
                                activeTab === 'downloaded'
                                    ? 'border-indigo-600 text-indigo-600'
                                    : 'border-transparent text-gray-500 hover:text-gray-700'
                            }`}
                        >
                            <CloudArrowDownIcon className="h-4 w-4" />
                            <span className="hidden sm:inline">Most Downloaded</span>
                        </button>
                    </div>
                </div>
            </div>
            
            {/* Slider Container - Full width, breaks out of grid */}
            <div className="relative overflow-visible w-screen" style={{ minHeight: '600px' }}>
                {/* Slider Wrapper - 5 tiles across screen */}
                <div 
                    ref={sliderWrapperRef}
                    className="relative overflow-visible"
                    style={{
                        display: 'flex',
                        width: '100%',
                        justifyContent: 'center',
                        alignItems: 'center',
                        gap: '1.5rem',
                        paddingLeft: '2rem',
                        paddingRight: '2rem',
                    }}
                >
                        {/* Render 5 tiles - center one is active */}
                        {displayItems.map((asset, displayIndex) => {
                            const isCenter = displayIndex === centerIndex
                            const actualIndex = startIndex + displayIndex - paddingStart
                            const isActive = asset && actualIndex === centerItemIndex
                            
                            if (!asset) {
                                // Empty placeholder for padding
                                return (
                                    <div
                                        key={`placeholder-${displayIndex}`}
                                        className="flex-shrink-0"
                                        style={{
                                            width: '18%',
                                            height: '500px',
                                        }}
                                    />
                                )
                            }
                            
                            return (
                                <Slide
                                    key={asset.id}
                                    asset={asset}
                                    activeTab={activeTab}
                                    isCurrent={isActive}
                                    isCenter={isCenter}
                                    index={actualIndex}
                                />
                            )
                        })}
                    </div>
                    
                {/* Navigation Buttons at Bottom */}
                {sortedAssets.length > 1 && (
                    <div className="flex items-center justify-center gap-4 mt-8">
                        <button
                            type="button"
                            onClick={handlePrevious}
                            disabled={currentIndex === 0}
                            className="p-3 rounded-full bg-white hover:bg-gray-50 shadow-lg border border-gray-200 disabled:opacity-50 disabled:cursor-not-allowed transition-all"
                            aria-label="Previous"
                        >
                            <ChevronLeftIcon className="h-6 w-6 text-gray-700" />
                        </button>
                        <button
                            type="button"
                            onClick={handleNext}
                            disabled={currentIndex >= sortedAssets.length - 1}
                            className="p-3 rounded-full bg-white hover:bg-gray-50 shadow-lg border border-gray-200 disabled:opacity-50 disabled:cursor-not-allowed transition-all"
                            aria-label="Next"
                        >
                            <ChevronRightIcon className="h-6 w-6 text-gray-700" />
                        </button>
                    </div>
                )}
            </div>
        </div>
    )
}

// Slide Component with Parallax Effect
function Slide({ asset, activeTab, isCurrent, isCenter, index }) {
    const slideRef = useRef(null)
    const imageWrapperRef = useRef(null)
    const imageRef = useRef(null)
    const contentRef = useRef(null)
    const [mousePosition, setMousePosition] = useState({ x: 0, y: 0 })
    const [isHovered, setIsHovered] = useState(false)
    
    // Set CSS variables via useEffect
    useEffect(() => {
        if (slideRef.current) {
            slideRef.current.style.setProperty('--x', `${mousePosition.x}px`)
            slideRef.current.style.setProperty('--y', `${mousePosition.y}px`)
        }
    }, [mousePosition.x, mousePosition.y])
    
    // Enable GPU acceleration on mount
    useEffect(() => {
        if (slideRef.current) {
            slideRef.current.style.willChange = 'transform'
            slideRef.current.style.transform = 'translateZ(0)' // Force GPU layer
        }
        if (imageWrapperRef.current) {
            imageWrapperRef.current.style.willChange = 'transform'
            imageWrapperRef.current.style.transform = 'translateZ(0)'
        }
        if (imageRef.current) {
            imageRef.current.style.willChange = 'transform'
            imageRef.current.style.transform = 'translateZ(0)'
        }
        if (contentRef.current) {
            contentRef.current.style.willChange = 'transform'
            contentRef.current.style.transform = 'translateZ(0)'
        }
        
        return () => {
            // Cleanup will-change on unmount
            if (slideRef.current) slideRef.current.style.willChange = 'auto'
            if (imageWrapperRef.current) imageWrapperRef.current.style.willChange = 'auto'
            if (imageRef.current) imageRef.current.style.willChange = 'auto'
            if (contentRef.current) contentRef.current.style.willChange = 'auto'
        }
    }, [])
    
    // Parallax animations on hover - use requestAnimationFrame for smooth updates
    useEffect(() => {
        if (!isCurrent || !isHovered) {
            // Reset animations with transition
            const resetTransform = () => {
                if (slideRef.current) {
                    slideRef.current.style.transition = 'transform 0.3s cubic-bezier(0.4, 0, 0.2, 1)'
                    slideRef.current.style.transform = 'translate3d(0px, 0px, 0px) scale(1)'
                }
                if (imageWrapperRef.current) {
                    imageWrapperRef.current.style.transition = 'transform 0.3s cubic-bezier(0.4, 0, 0.2, 1)'
                    imageWrapperRef.current.style.transform = 'translate3d(0px, 0px, 0px) scale(1)'
                }
                if (imageRef.current) {
                    imageRef.current.style.transition = 'transform 0.3s cubic-bezier(0.4, 0, 0.2, 1)'
                    imageRef.current.style.transform = 'translate3d(0px, 0px, 0px)'
                }
                if (contentRef.current) {
                    contentRef.current.style.transition = 'transform 0.5s cubic-bezier(0.4, 0, 0.2, 1)'
                    contentRef.current.style.transform = 'translate3d(0px, 0px, 0px)'
                }
            }
            resetTransform()
            return
        }
        
        // Animate on hover with parallax effect - no transition during mouse move for smoothness
        const updateTransform = () => {
            const x = mousePosition.x / 15  // Container movement (subtle)
            const y = mousePosition.y / 15
            const xImage = mousePosition.x / 10  // Image parallax (more pronounced)
            const yImage = mousePosition.y / 10
            const xContent = mousePosition.x / 40  // Content moves opposite (subtle)
            const yContent = mousePosition.y / 40
            
            // Remove transition during active mouse movement for smoothness
            // Scale up the entire tile on hover
            if (slideRef.current) {
                slideRef.current.style.transition = 'none' // No transition during mouse move
                slideRef.current.style.transform = `translate3d(${x}px, ${y}px, 0px) scale(1.05)`
            }
            
            // Image wrapper also scales
            if (imageWrapperRef.current) {
                imageWrapperRef.current.style.transition = 'none'
                imageWrapperRef.current.style.transform = 'translate3d(0px, 0px, 0px) scale(1.02)'
            }
            
            // Image moves with parallax (more pronounced movement)
            if (imageRef.current) {
                imageRef.current.style.transition = 'none'
                imageRef.current.style.transform = `translate3d(${xImage}px, ${yImage}px, 0px)`
            }
            
            // Content moves opposite direction (subtle)
            if (contentRef.current) {
                contentRef.current.style.transition = 'none'
                contentRef.current.style.transform = `translate3d(${-xContent}px, ${-yContent}px, 0px)`
            }
        }
        
        // Use requestAnimationFrame for smooth updates
        const rafId = requestAnimationFrame(updateTransform)
        return () => cancelAnimationFrame(rafId)
    }, [mousePosition, isHovered, isCurrent])
    
    const handleMouseMove = useCallback((e) => {
        if (!slideRef.current || !isCurrent) return
        
        // Get dimensions of slide element (same approach as example)
        const rect = slideRef.current.getBoundingClientRect()
        
        // Set x and y values relative to center of slide element
        const x = e.clientX - (rect.left + Math.floor(rect.width / 2))
        const y = e.clientY - (rect.top + Math.floor(rect.height / 2))
        
        setMousePosition({ x, y })
    }, [isCurrent])
    
    const handleMouseLeave = useCallback(() => {
        // Reset x and y values when no longer hovering (same as example)
        setMousePosition({ x: 0, y: 0 })
        setIsHovered(false)
        
        // Also reset CSS variables
        if (slideRef.current) {
            slideRef.current.style.setProperty('--x', '0')
            slideRef.current.style.setProperty('--y', '0')
        }
    }, [])
    
    const handleMouseEnter = useCallback(() => {
        setIsHovered(true)
    }, [])
    
    // Opacity: center full, others reduced, but full opacity on hover
    const baseOpacity = isCenter && isCurrent ? 1 : 0.6
    const opacity = isHovered && isCurrent ? 1 : baseOpacity
    
    return (
        <Link
            ref={slideRef}
            href={`/app/assets?asset=${asset.id}`}
            className={`slide relative group ${isCurrent ? 'slide--current' : ''}`}
            style={{
                pointerEvents: isCurrent ? 'auto' : 'none',
                height: '500px',
                width: isCenter ? '22%' : '18%', // Center tile slightly larger
                flexShrink: 0,
                opacity: opacity,
                transition: isHovered && isCurrent 
                    ? 'opacity 0.3s ease-out, width 0.3s ease-out' 
                    : 'opacity 0.3s ease-out, transform 0.3s cubic-bezier(0.4, 0, 0.2, 1), width 0.3s ease-out',
                transform: 'translate3d(0px, 0px, 0px) scale(1)', // Initial scale with GPU acceleration
            }}
            onMouseMove={handleMouseMove}
            onMouseEnter={handleMouseEnter}
            onMouseLeave={handleMouseLeave}
        >
            <div 
                ref={imageWrapperRef}
                className="slide__image-wrapper relative w-full h-full overflow-hidden rounded-lg"
                style={{
                    transformOrigin: 'center center',
                }}
            >
                <div 
                    ref={imageRef}
                    className="slide__image absolute inset-0"
                    style={{
                        transformOrigin: 'center center',
                    }}
                >
                    <ThumbnailPreview
                        asset={asset}
                        alt={asset.title || asset.original_filename || 'Asset'}
                        className="w-full h-full"
                        size="large"
                        forceObjectFit="cover"
                    />
                </div>
                
                {/* Content */}
                <div 
                    ref={contentRef}
                    className="slide__content absolute bottom-0 left-0 right-0 p-4"
                >
                    <h3 className="text-xs font-medium text-white mb-1 truncate">
                        {asset.title || asset.original_filename || 'Untitled'}
                    </h3>
                    <div className="flex items-center gap-2 text-xs text-white/90">
                        {activeTab === 'viewed' ? (
                            <>
                                <EyeIcon className="h-3 w-3" />
                                <span>{asset.view_count || 0} {asset.view_count === 1 ? 'view' : 'views'}</span>
                            </>
                        ) : (
                            <>
                                <CloudArrowDownIcon className="h-3 w-3" />
                                <span>{asset.download_count || 0} {asset.download_count === 1 ? 'download' : 'downloads'}</span>
                            </>
                        )}
                    </div>
                </div>
            </div>
        </Link>
    )
}
