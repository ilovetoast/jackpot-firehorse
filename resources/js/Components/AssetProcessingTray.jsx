import { useState, useEffect, useRef } from 'react'
import { usePage, router } from '@inertiajs/react'
import {
    ArrowPathIcon,
    XCircleIcon,
    ChevronUpIcon,
    ChevronDownIcon,
    XMarkIcon,
} from '@heroicons/react/24/outline'

/**
 * AssetProcessingTray Component
 * 
 * Google Drive-style floating tray that shows assets currently processing thumbnails.
 * Automatically tracks assets where thumbnail_status !== 'completed'.
 * 
 * Features:
 * - Expandable/minimizable (remembers state in sessionStorage)
 * - Auto-dismisses when all assets complete
 * - Shows processing status for each asset
 * - Handles failure states gracefully
 */
export default function AssetProcessingTray() {
    const { processing_assets = [] } = usePage().props
    const [isExpanded, setIsExpanded] = useState(() => {
        // Restore minimized state from sessionStorage
        if (typeof window !== 'undefined') {
            const stored = sessionStorage.getItem('asset_processing_tray_minimized')
            return stored !== 'true'
        }
        return true
    })
    const [isDismissed, setIsDismissed] = useState(false)
    const autoDismissTimerRef = useRef(null)

    // Filter to only show assets that are actually processing (not completed)
    // Include assets where thumbnail_status is null (legacy assets) or not 'completed'
    const processingAssets = processing_assets.filter(
        (asset) => !asset.thumbnail_status || asset.thumbnail_status !== 'completed'
    )

    // Auto-dismiss when all assets complete
    useEffect(() => {
        if (processingAssets.length === 0 && !isDismissed) {
            // Wait 2 seconds before auto-dismissing to show completion briefly
            autoDismissTimerRef.current = setTimeout(() => {
                setIsDismissed(true)
            }, 2000)

            return () => {
                if (autoDismissTimerRef.current) {
                    clearTimeout(autoDismissTimerRef.current)
                }
            }
        } else if (processingAssets.length > 0) {
            // Cancel auto-dismiss if new assets start processing
            if (autoDismissTimerRef.current) {
                clearTimeout(autoDismissTimerRef.current)
            }
            setIsDismissed(false)
        }
    }, [processingAssets.length, isDismissed])

    // Cleanup timer on unmount
    useEffect(() => {
        return () => {
            if (autoDismissTimerRef.current) {
                clearTimeout(autoDismissTimerRef.current)
            }
        }
    }, [])

    // Don't render if dismissed or no processing assets
    if (isDismissed || processingAssets.length === 0) {
        return null
    }

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
                    <button
                        onClick={handleDismiss}
                        className="text-gray-400 hover:text-gray-500 focus:outline-none"
                        aria-label="Dismiss tray"
                    >
                        <XMarkIcon className="h-5 w-5" />
                    </button>
                </div>

                {/* Expanded content */}
                {isExpanded && (
                    <div className="max-h-96 overflow-y-auto">
                        {processingAssets.length === 0 ? (
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