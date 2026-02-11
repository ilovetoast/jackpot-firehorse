/**
 * AssetCard Component
 * 
 * Displays a single asset in the grid view.
 * Shows thumbnail preview, title, and file type badge.
 * 
 * @param {Object} props
 * @param {Object} props.asset - Asset object with title, thumbnail_url, file_extension, etc.
 * @param {Function} props.onClick - Optional click handler to open asset detail drawer
 * @param {boolean} props.showInfo - Whether to show asset info (title, file type)
 * @param {boolean} props.isSelected - Whether this asset is currently selected
 * @param {string} props.primaryColor - Brand primary color for selected highlight
 */
import { useMemo, useState, useEffect, useRef } from 'react'
import { usePage } from '@inertiajs/react'
import { EyeSlashIcon } from '@heroicons/react/24/outline'
import { StarIcon } from '@heroicons/react/24/solid'
import ThumbnailPreview from './ThumbnailPreview'
import { getThumbnailVersion, getThumbnailState } from '../utils/thumbnailUtils'

export default function AssetCard({ asset, onClick = null, showInfo = true, isSelected = false, primaryColor = '#6366f1', isBulkSelected = false, onBulkSelect = null, isInBucket = false, onBucketToggle = null, isPendingApprovalMode = false, isPendingPublicationFilter = false, onAssetApproved = null }) {
    const { auth } = usePage().props
    // Extract file extension from original_filename, file_extension, or mime_type
    const getFileExtension = () => {
        // First try explicit file_extension field
        if (asset.file_extension && asset.file_extension.trim()) {
            return asset.file_extension.toUpperCase()
        }
        
        // Then try to extract from original_filename
        if (asset.original_filename) {
            const parts = asset.original_filename.split('.')
            if (parts.length > 1) {
                const ext = parts[parts.length - 1].trim()
                if (ext) {
                    return ext.toUpperCase()
                }
            }
        }
        
        // Fallback: derive from mime_type
        if (asset.mime_type) {
            // Extract from mime types like "image/jpeg" -> "JPEG", "application/pdf" -> "PDF"
            const mimeParts = asset.mime_type.split('/')
            if (mimeParts.length === 2) {
                const mimeSubtype = mimeParts[1].toLowerCase()
                // Map common mime types to extensions
                const mimeToExt = {
                    'jpeg': 'JPG',
                    'jpg': 'JPG',
                    'png': 'PNG',
                    'gif': 'GIF',
                    'webp': 'WEBP',
                    'svg+xml': 'SVG',
                    'tiff': 'TIF',
                    'bmp': 'BMP',
                    'pdf': 'PDF',
                    'zip': 'ZIP',
                    'x-zip-compressed': 'ZIP',
                    'mpeg': 'MPG',
                    'mp4': 'MP4',
                    'quicktime': 'MOV',
                    'x-msvideo': 'AVI',
                    'vnd.adobe.photoshop': 'PSD',
                    'vnd.adobe.illustrator': 'AI',
                }
                if (mimeToExt[mimeSubtype]) {
                    return mimeToExt[mimeSubtype]
                }
                // Fallback: use mime subtype uppercase
                return mimeSubtype.split('+')[0].toUpperCase()
            }
        }
        
        // Last resort: return generic "FILE" (should rarely happen)
        return 'FILE'
    }
    
    const fileExtension = getFileExtension()
    
    // Determine if asset is an image based on mime_type or extension
    const imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'tiff', 'tif']
    const extLower = fileExtension.toLowerCase()
    const isImage = asset.mime_type?.startsWith('image/') || imageExtensions.includes(extLower)
    
    // Phase V-1: Detect if asset is a video
    const videoExtensions = ['mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v']
    const isVideo = Boolean(asset?.mime_type?.startsWith('video/') || videoExtensions.includes(extLower))
    
    // Phase V-1: Hover preview state (desktop only)
    const [isHovering, setIsHovering] = useState(false)
    // Phase D1: Card hover for bucket checkbox visibility
    const [isCardHovering, setIsCardHovering] = useState(false)
    const [previewLoaded, setPreviewLoaded] = useState(false)
    const videoPreviewRef = useRef(null)
    const isMobile = typeof window !== 'undefined' ? window.innerWidth < 768 : false
    
    // Phase 3.1: Derive stable thumbnail version signal
    // This ensures memoized components re-render when thumbnail availability changes
    // after background reconciliation. Recompute only when asset id or thumbnailVersion changes.
    const thumbnailVersion = useMemo(() => getThumbnailVersion(asset), [
        asset?.id,
        asset?.thumbnail_url,
        asset?.thumbnail_status?.value || asset?.thumbnail_status,
        asset?.updated_at,
    ])
    
    // Phase 3.1: Get thumbnail state for processing badge
    // Recompute only when asset id or thumbnailVersion changes (same as thumbnailVersion memo)
    const thumbnailState = useMemo(() => getThumbnailState(asset), [
        asset?.id,
        thumbnailVersion,
    ])
    
    // Phase 3.1E: Processing badge shows only when thumbnail state is 'PENDING'
    // This ensures badge disappears when thumbnail becomes available after reconciliation
    const isProcessing = thumbnailState.state === 'PENDING'
    
    // Phase 3.1E: Detect meaningful state transitions for thumbnail animation
    // Track previous state to detect transitions from non-AVAILABLE → AVAILABLE
    // Animation should ONLY trigger on meaningful state changes (e.g., after background reconciliation)
    // NEVER animate on initial render - prevents UI jank
    // Smart poll authority: only polling/reconciliation may promote to AVAILABLE
    const [shouldAnimateThumbnail, setShouldAnimateThumbnail] = useState(false)
    const prevThumbnailStateRef = useRef(null)
    
    useEffect(() => {
        const prevState = prevThumbnailStateRef.current
        const currentState = thumbnailState.state
        
        // Phase 3.1E: Detect transition from non-AVAILABLE → AVAILABLE (meaningful state change)
        // This happens when background reconciliation detects thumbnail completion
        // Log when polling promotes thumbnail to AVAILABLE
        if (prevState !== null && prevState !== 'AVAILABLE' && currentState === 'AVAILABLE') {
            console.log('[ThumbnailPoll] Polling promoted thumbnail to AVAILABLE', {
                assetId: asset.id,
                prevState,
                currentState,
                thumbnailUrl: thumbnailState.thumbnailUrl,
            })
            setShouldAnimateThumbnail(true)
            // Reset after animation completes (handled by ThumbnailPreview)
        } else {
            // No meaningful transition - don't animate
            setShouldAnimateThumbnail(false)
        }
        
        prevThumbnailStateRef.current = currentState
    }, [thumbnailState.state, asset?.id, thumbnailState.thumbnailUrl])
    
    // Get appropriate icon for non-image files
    const getFileIcon = () => {
        if (extLower === 'pdf') {
            return (
                <svg className="h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                </svg>
            )
        } else if (['psd', 'psb', 'ai', 'eps', 'sketch'].includes(extLower)) {
            return (
                <svg className="h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h11.25c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25zM6.75 12h.008v.008H6.75V12zm0 3h.008v.008H6.75V15zm0 3h.008v.008H6.75V18z" />
                </svg>
            )
        } else {
            // Generic document icon
            return (
                <svg className="h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                </svg>
            )
        }
    }
    
    // UX invariant: Click on card always opens drawer. Only the checkbox adds/removes from download bucket.
    const handleClick = (e) => {
        if (onClick) {
            onClick(asset, e)
        }
    }

    // Convert hex color to RGB for shadow opacity
    const hexToRgb = (hex) => {
        const result = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex)
        return result ? {
            r: parseInt(result[1], 16),
            g: parseInt(result[2], 16),
            b: parseInt(result[3], 16)
        } : { r: 99, g: 102, b: 241 } // Default indigo-500
    }
    
    const rgb = hexToRgb(primaryColor)
    const shadowStyle = isSelected ? {
        borderColor: primaryColor,
        boxShadow: `0 10px 15px -3px rgba(${rgb.r}, ${rgb.g}, ${rgb.b}, 0.3), 0 4px 6px -2px rgba(${rgb.r}, ${rgb.g}, ${rgb.b}, 0.2), 0 0 0 1px ${primaryColor}`,
    } : {}
    
    return (
        <div
            onClick={handleClick}
            onMouseEnter={() => setIsCardHovering(true)}
            onMouseLeave={() => setIsCardHovering(false)}
            draggable={false}
            onDragStart={(e) => e.preventDefault()}
            className={`group relative bg-white rounded-lg border overflow-hidden transition-all duration-200 cursor-pointer ${
                isSelected 
                    ? 'border-2' 
                    : 'border-gray-200 hover:border-gray-300 shadow-md hover:shadow-lg'
            }`}
            style={{
                ...shadowStyle,
                '--primary-color': primaryColor,
            }}
        >
            {/* Phase 3.1: Thumbnail container - fixed aspect ratio (4:3) */}
            {/* Use ThumbnailPreview component for consistent state machine and fade-in */}
            {/* FORBIDDEN: Never use green placeholders. ThumbnailPreview handles all states with FileTypeIcon fallback. */}
            <div 
                className="aspect-[4/3] bg-gray-50 relative overflow-hidden"
                onMouseEnter={() => !isMobile && isVideo && setIsHovering(true)}
                onMouseLeave={() => {
                    setIsHovering(false)
                    // Pause and unload preview on mouse leave
                    if (videoPreviewRef.current) {
                        videoPreviewRef.current.pause()
                        videoPreviewRef.current.currentTime = 0
                    }
                }}
            >
                {/* Phase V-1: Video hover preview (desktop only, lazy load) */}
                {isVideo && isHovering && asset.video_preview_url && !isMobile && (
                    <video
                        ref={videoPreviewRef}
                        src={asset.video_preview_url}
                        className="absolute inset-0 w-full h-full object-cover z-10"
                        autoPlay
                        muted
                        loop
                        playsInline
                        onLoadedData={() => setPreviewLoaded(true)}
                        style={{ opacity: previewLoaded ? 1 : 0, transition: 'opacity 0.2s' }}
                    />
                )}
                
                {/* Phase V-1: Use ThumbnailPreview for videos (same as drawer) */}
                {/* ThumbnailPreview handles route-based URLs (final_thumbnail_url) which work correctly */}
                {/* The drawer uses ThumbnailPreview for videos, so we do the same in the grid */}
                <ThumbnailPreview
                    asset={asset}
                    alt={asset.title || asset.original_filename || (isVideo ? 'Video' : 'Asset')}
                    className={`w-full h-full ${isHovering && isVideo && asset.video_preview_url && !isMobile ? 'opacity-0' : 'opacity-100'} transition-opacity duration-200`}
                    retryCount={0}
                    onRetry={null}
                    size="lg"
                    thumbnailVersion={thumbnailVersion}
                    shouldAnimateThumbnail={shouldAnimateThumbnail}
                />
                
                {/* Phase V-1: Play icon overlay for videos */}
                {isVideo && (
                    <div className="absolute inset-0 flex items-center justify-center z-20 pointer-events-none">
                        <div className="bg-black/40 backdrop-blur-sm rounded-full p-3">
                            <svg className="h-8 w-8 text-white" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M8 5v14l11-7z" />
                            </svg>
                        </div>
                    </div>
                )}
                
                {/* Phase 2 – Step 7: Bulk selection checkbox */}
                {onBulkSelect && (
                    <div className="absolute top-2 left-2 z-10">
                        <input
                            type="checkbox"
                            checked={isBulkSelected}
                            onChange={(e) => {
                                e.stopPropagation()
                                onBulkSelect()
                            }}
                            onClick={(e) => e.stopPropagation()}
                            className="h-5 w-5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 cursor-pointer bg-white shadow-sm"
                        />
                    </div>
                )}

                {/* Phase D1: Download bucket checkbox. Only affordance for add/remove; visible on hover or when selected. */}
                {!onBulkSelect && onBucketToggle && (
                    <div className={`absolute top-2 left-2 z-10 transition-opacity ${isCardHovering || isInBucket ? 'opacity-100' : 'opacity-0'}`}>
                        <input
                            type="checkbox"
                            checked={isInBucket}
                            onChange={() => {}}
                            onClick={(e) => {
                                e.stopPropagation()
                                onBucketToggle()
                            }}
                            className="h-5 w-5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 cursor-pointer bg-white shadow-sm"
                            aria-label={isInBucket ? 'Remove from download' : 'Add to download'}
                        />
                    </div>
                )}

                {/* File type badge overlay - top right - Conditionally hidden based on showInfo prop */}
                {showInfo && (
                    <div className="absolute top-2 right-2 flex flex-col gap-1 items-end">
                        <div className="inline-flex items-center gap-1.5">
                            <span className="inline-flex items-center rounded-md bg-black/60 backdrop-blur-sm px-2 py-1 text-xs font-medium text-white uppercase tracking-wide">
                                {fileExtension}
                            </span>
                            {/* Starred: gold for visibility on varied image backgrounds (brand primary was too dark) */}
                            {asset.starred === true && (
                                <StarIcon className="h-3.5 w-3.5 text-amber-400 drop-shadow-[0_1px_2px_rgba(0,0,0,0.8)]" aria-label="Starred" />
                            )}
                            {/* Subtle unpublished icon */}
                            {/* CANONICAL RULE: Published vs Unpublished is determined ONLY by is_published */}
                            {/* Use is_published boolean from API - do not infer from approval, lifecycle enums, or fallbacks */}
                            {!asset.archived_at && asset.is_published === false && (
                                <EyeSlashIcon className="h-3 w-3 text-white/70 drop-shadow-[0_1px_2px_rgba(0,0,0,0.8)]" aria-label="Unpublished" />
                            )}
                        </div>
                        
                        {/* Labels removed for clean grid view */}
                    </div>
                )}
            </div>
            
            {/* Title section - Conditionally hidden based on showInfo prop */}
            {showInfo && (
                <div className="p-3 border-t border-gray-100">
                    <h3 
                        className="text-sm font-medium text-gray-900 truncate transition-colors duration-200 group-hover:text-[var(--primary-color)]"
                    >
                        {asset.title || asset.original_filename || 'Untitled Asset'}
                    </h3>
                </div>
            )}
            
            {/* Phase L.6.2: Visual indicator for unpublished assets - user clicks asset to open drawer */}
            {isPendingApprovalMode && (
                <div className="absolute inset-0 bg-black/20 backdrop-blur-sm flex items-center justify-center z-10 pointer-events-none">
                    <div className="bg-yellow-100 border-2 border-yellow-400 rounded-lg px-3 py-2 shadow-lg">
                        <span className="text-xs font-medium text-yellow-800">Click to view & publish</span>
                    </div>
                </div>
            )}
        </div>
    )
}
