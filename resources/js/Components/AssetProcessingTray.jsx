import { useState, useEffect, useRef } from 'react'
import { usePage, router } from '@inertiajs/react'
import {
    ArrowPathIcon,
    XCircleIcon,
    ChevronUpIcon,
    ChevronDownIcon,
    XMarkIcon,
    SparklesIcon,
} from '@heroicons/react/24/outline'
import { updateFilterDebug } from '../utils/assetFilterDebug'

/**
 * AssetProcessingTray Component
 * 
 * Backend-driven processing indicator that shows assets currently processing.
 * 
 * CRITICAL RULES:
 * - NO optimistic UI tracking - all state comes from backend
 * - Polls /app/assets/processing endpoint (authoritative source)
 * - Automatically clears when backend reports zero active jobs
 * - Never persists across reloads unless backend confirms jobs exist
 * - Includes TTL safety (stale jobs >10 minutes are auto-cleared)
 * 
 * Features:
 * - Expandable/minimizable (remembers state in sessionStorage)
 * - Auto-dismisses when all assets complete
 * - Shows processing status for each asset
 * - Handles failure states gracefully
 * - Defensive logging for debugging
 */
export default function AssetProcessingTray() {
    const { auth } = usePage().props
    
    // Only run on authenticated routes
    const isAuthenticated = auth?.user !== null && auth?.user !== undefined
    
    // Don't run on admin routes - check current URL
    const currentPath = typeof window !== 'undefined' ? window.location.pathname : ''
    const isAdminRoute = currentPath.startsWith('/app/admin')
    
    const [processingAssets, setProcessingAssets] = useState([])
    const [isExpanded, setIsExpanded] = useState(() => {
        // Restore minimized state from sessionStorage
        if (typeof window !== 'undefined') {
            const stored = sessionStorage.getItem('asset_processing_tray_minimized')
            return stored !== 'true'
        }
        return true
    })
    const [isDismissed, setIsDismissed] = useState(false)
    const [isLoading, setIsLoading] = useState(true)
    const autoDismissTimerRef = useRef(null)
    const pollIntervalRef = useRef(null)
    const lastFetchRef = useRef(null)

    // Poll backend endpoint for active processing jobs
    const fetchActiveJobs = async () => {
        try {
            const response = await window.axios.get('/app/assets/processing')
            const { active_jobs = [], stale_count = 0, fetched_at } = response.data

            // Log when items are added/removed
            const previousCount = processingAssets.length
            const currentCount = active_jobs.length

            updateFilterDebug({
                processingTray: {
                    activeJobs: currentCount,
                    staleCount: stale_count,
                    fetchedAt: fetched_at,
                },
            })

            // Update state with backend truth
            setProcessingAssets(active_jobs)
            lastFetchRef.current = fetched_at
            setIsLoading(false)

            // Auto-dismiss when backend reports zero active jobs
            if (active_jobs.length === 0 && !isDismissed) {
                // Wait 2 seconds before auto-dismissing to show completion briefly
                if (autoDismissTimerRef.current) {
                    clearTimeout(autoDismissTimerRef.current)
                }
                autoDismissTimerRef.current = setTimeout(() => {
                    updateFilterDebug({ processingTray: { autoDismiss: true, activeJobs: 0 } })
                    setIsDismissed(true)
                }, 2000)
            } else if (active_jobs.length > 0) {
                // Cancel auto-dismiss if new assets start processing
                if (autoDismissTimerRef.current) {
                    clearTimeout(autoDismissTimerRef.current)
                }
                setIsDismissed(false)
            }
        } catch (error) {
            console.error('[AssetProcessingTray] Error fetching active jobs', {
                error: error.message,
                response: error.response?.data,
            })
            setIsLoading(false)
            // On error, clear processing assets (fail-safe)
            setProcessingAssets([])
        }
    }

    // Initial fetch and polling setup (only when authenticated and not on admin routes)
    useEffect(() => {
        // Don't poll if not authenticated or on admin routes
        if (!isAuthenticated || isAdminRoute) {
            setIsLoading(false)
            return
        }

        // Initial fetch immediately
        fetchActiveJobs()

        // Poll every 5 seconds when component is mounted
        pollIntervalRef.current = setInterval(() => {
            fetchActiveJobs()
        }, 5000)

        return () => {
            if (pollIntervalRef.current) {
                clearInterval(pollIntervalRef.current)
            }
            if (autoDismissTimerRef.current) {
                clearTimeout(autoDismissTimerRef.current)
            }
        }
    }, [isAuthenticated, isAdminRoute]) // Only run when authentication state or route changes

    // Cleanup timers on unmount
    useEffect(() => {
        return () => {
            if (pollIntervalRef.current) {
                clearInterval(pollIntervalRef.current)
            }
            if (autoDismissTimerRef.current) {
                clearTimeout(autoDismissTimerRef.current)
            }
        }
    }, [])

    const handleToggleExpand = () => {
        const newExpanded = !isExpanded
        setIsExpanded(newExpanded)
        // Remember minimized state in sessionStorage
        if (typeof window !== 'undefined') {
            sessionStorage.setItem('asset_processing_tray_minimized', (!newExpanded).toString())
        }
    }

    const handleDismiss = () => {
        setIsDismissed(true)
        // Clear session storage on manual dismiss
        if (typeof window !== 'undefined') {
            sessionStorage.removeItem('asset_processing_tray_minimized')
        }
    }

    // Get status label and icon for an asset
    const getAssetStatus = (asset) => {
        const thumbnailStatus = asset.thumbnail_status || 'pending'
        const assetStatus = asset.status || 'pending'

        if (thumbnailStatus === 'failed') {
            return {
                label: 'Thumbnail failed',
                icon: XCircleIcon,
                iconColor: 'text-red-500',
                bgColor: 'bg-red-50',
                textColor: 'text-red-700',
            }
        }

        if (thumbnailStatus === 'processing') {
            return {
                label: 'Processing thumbnails...',
                icon: ArrowPathIcon,
                iconColor: 'text-blue-500',
                bgColor: 'bg-blue-50',
                textColor: 'text-blue-700',
                animated: true,
            }
        }

        if (assetStatus === 'completed' || assetStatus === 'thumbnail_generated') {
            return {
                label: 'Finalizing asset...',
                icon: ArrowPathIcon,
                iconColor: 'text-amber-500',
                bgColor: 'bg-amber-50',
                textColor: 'text-amber-700',
                animated: true,
            }
        }

        // Default: pending
        return {
            label: 'Processing thumbnails...',
            icon: ArrowPathIcon,
            iconColor: 'text-gray-500',
            bgColor: 'bg-gray-50',
            textColor: 'text-gray-700',
            animated: true,
        }
    }

    // Truncate title for display
    const truncateTitle = (title, maxLength = 40) => {
        if (!title || title.length <= maxLength) return title
        return title.substring(0, maxLength) + '...'
    }

    // Detect if any processing assets were uploaded by OTHER users (not current user)
    const currentUserId = auth?.user?.id
    const hasOtherUsersAssets = processingAssets.some(
        (asset) => asset.user_id && asset.user_id !== currentUserId
    )

    // Staleness flag: Track if asset grid is stale (has other users' processing assets)
    // This flag is set when hasOtherUsersAssets becomes true, and cleared on refresh/navigation
    const [hasStaleAssetGrid, setHasStaleAssetGrid] = useState(() => {
        // Initialize from window-level state (persists across component remounts)
        if (typeof window !== 'undefined' && window.__assetGridStaleness) {
            return window.__assetGridStaleness.hasStaleAssetGrid || false
        }
        return false
    })

    // Update window-level state when staleness changes
    useEffect(() => {
        if (typeof window !== 'undefined') {
            if (!window.__assetGridStaleness) {
                window.__assetGridStaleness = { hasStaleAssetGrid: false }
            }
            window.__assetGridStaleness.hasStaleAssetGrid = hasStaleAssetGrid
            
            // Dispatch custom event for other components to listen
            window.dispatchEvent(new CustomEvent('assetGridStalenessChanged', {
                detail: { hasStaleAssetGrid }
            }))
        }
    }, [hasStaleAssetGrid])

    // Set staleness flag when other users' assets start processing
    useEffect(() => {
        if (hasOtherUsersAssets && !hasStaleAssetGrid) {
            setHasStaleAssetGrid(true)
        }
    }, [hasOtherUsersAssets, hasStaleAssetGrid])

    // Handle refresh button click - soft reload asset grid and clear staleness
    const handleRefreshGrid = () => {
        // Clear staleness flag immediately (optimistic)
        setHasStaleAssetGrid(false)
        if (typeof window !== 'undefined' && window.__assetGridStaleness) {
            window.__assetGridStaleness.hasStaleAssetGrid = false
            window.dispatchEvent(new CustomEvent('assetGridStalenessChanged', {
                detail: { hasStaleAssetGrid: false }
            }))
        }
        
        // Check if we're on the assets page
        const currentPath = typeof window !== 'undefined' ? window.location.pathname : ''
        const isAssetsPage = currentPath.startsWith('/app/assets') || currentPath.startsWith('/app/categories')
        
        if (isAssetsPage) {
            // Soft reload only the assets data (preserves scroll, filters, etc.)
            router.reload({ 
                only: ['assets'],
                onSuccess: () => {
                    // Ensure staleness is cleared after reload completes
                    setHasStaleAssetGrid(false)
                }
            })
        } else {
            // If not on assets page, do a full page reload to navigate to assets
            router.visit('/app/assets')
        }
    }

    // Don't render on unauthenticated routes or admin routes
    if (!isAuthenticated || isAdminRoute) {
        return null
    }

    // Don't render if dismissed or no processing assets
    // CRITICAL: Only show if backend confirms active jobs exist
    if (isDismissed || processingAssets.length === 0) {
        return null
    }

    return (
        <div className="fixed bottom-4 right-4 z-50">
            <div className="bg-white rounded-lg shadow-lg border border-gray-200 overflow-hidden">
                {/* Header - Always visible */}
                <div className="flex items-center justify-between px-4 py-3 bg-gray-50 border-b border-gray-200">
                    <div className="flex items-center gap-2">
                        <button
                            onClick={handleToggleExpand}
                            className="flex items-center gap-2 text-sm font-medium text-gray-700 hover:text-gray-900"
                        >
                            {isExpanded ? (
                                <ChevronDownIcon className="h-5 w-5" />
                            ) : (
                                <ChevronUpIcon className="h-5 w-5" />
                            )}
                            <span>Processing</span>
                            {processingAssets.length > 0 && (
                                <span className="inline-flex items-center justify-center w-5 h-5 text-xs font-semibold text-white bg-indigo-600 rounded-full">
                                    {processingAssets.length}
                                </span>
                            )}
                        </button>
                    </div>
                    <div className="flex items-center gap-2">
                        {/* Refresh button - only show when other users' assets are processing */}
                        {hasOtherUsersAssets && (
                            <button
                                onClick={handleRefreshGrid}
                                className="text-gray-400 hover:text-indigo-600 focus:outline-none transition-colors"
                                aria-label="New assets are processing — refresh grid"
                                title="New assets are processing — refresh grid"
                            >
                                <SparklesIcon className="h-5 w-5" />
                            </button>
                        )}
                        <button
                            onClick={handleDismiss}
                            className="text-gray-400 hover:text-gray-500 focus:outline-none"
                            aria-label="Dismiss tray"
                        >
                            <XMarkIcon className="h-5 w-5" />
                        </button>
                    </div>
                </div>

                {/* Expanded content */}
                {isExpanded && (
                    <div className="max-h-96 overflow-y-auto">
                        {isLoading ? (
                            <div className="px-4 py-8 text-center text-sm text-gray-500">
                                Loading...
                            </div>
                        ) : processingAssets.length === 0 ? (
                            <div className="px-4 py-8 text-center text-sm text-gray-500">
                                All assets are ready
                            </div>
                        ) : (
                            <div className="divide-y divide-gray-200">
                                {processingAssets.map((asset) => {
                                    const status = getAssetStatus(asset)
                                    const StatusIcon = status.icon
                                    const isAnimated = status.animated

                                    return (
                                        <div
                                            key={asset.id}
                                            className={`px-4 py-3 ${status.bgColor} hover:opacity-90 transition-opacity`}
                                        >
                                            <div className="flex items-start gap-3">
                                                {/* Status icon */}
                                                <div className="flex-shrink-0 mt-0.5">
                                                    <StatusIcon
                                                        className={`h-5 w-5 ${status.iconColor} ${
                                                            isAnimated ? 'animate-spin' : ''
                                                        }`}
                                                    />
                                                </div>

                                                {/* Asset info */}
                                                <div className="flex-1 min-w-0">
                                                    <p
                                                        className={`text-sm font-medium ${status.textColor} truncate`}
                                                        title={asset.title}
                                                    >
                                                        {truncateTitle(asset.title)}
                                                    </p>
                                                    <p className="text-xs text-gray-500 mt-0.5">
                                                        {status.label}
                                                    </p>
                                                    {asset.thumbnail_error && (
                                                        <p
                                                            className="text-xs text-red-600 mt-1"
                                                            title={asset.thumbnail_error}
                                                        >
                                                            Preview unavailable
                                                        </p>
                                                    )}
                                                </div>
                                            </div>
                                        </div>
                                    )
                                })}
                            </div>
                        )}
                    </div>
                )}

                {/* Minimized state - show count badge */}
                {!isExpanded && processingAssets.length > 0 && (
                    <div className="px-4 py-2">
                        <div className="flex items-center justify-center">
                            <span className="text-xs text-gray-500">
                                {processingAssets.length}{' '}
                                {processingAssets.length === 1 ? 'asset' : 'assets'} processing
                            </span>
                        </div>
                    </div>
                )}
            </div>
        </div>
    )
}
