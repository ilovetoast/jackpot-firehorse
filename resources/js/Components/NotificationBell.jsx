import { useState, useEffect } from 'react'
import { usePage, router } from '@inertiajs/react'
import { BellIcon, SparklesIcon, CheckCircleIcon, ExclamationTriangleIcon, InformationCircleIcon } from '@heroicons/react/24/outline'
import { usePermission } from '../hooks/usePermission'

/**
 * Phase AF-3: Notification Bell Component
 * 
 * Shows unread notification count and opens notification panel.
 * Also shows simple pending items (AI suggestions, metadata approvals).
 */
export default function NotificationBell({ textColor = '#000000' }) {
    const { pending_items } = usePage().props
    const [notifications, setNotifications] = useState([])
    const [unreadCount, setUnreadCount] = useState(0)
    const [isOpen, setIsOpen] = useState(false)
    const [loading, setLoading] = useState(true)
    const { hasPermission: canApprove } = usePermission('metadata.bypass_approval')
    const { hasPermission: canViewSuggestions } = usePermission('metadata.suggestions.view')
    const [hasStaleAssetGrid, setHasStaleAssetGrid] = useState(() => {
        // Initialize from window-level state
        if (typeof window !== 'undefined' && window.__assetGridStaleness) {
            return window.__assetGridStaleness.hasStaleAssetGrid || false
        }
        return false
    })
    
    // Determine which metadata approval count to show
    // Approvers see all pending (actionable), contributors see their own (informational)
    const metadataApprovalsCount = canApprove 
        ? (pending_items?.metadata_approvals ?? 0)  // Approvers: all pending
        : (pending_items?.my_pending_metadata_approvals ?? 0)  // Contributors: their own

    useEffect(() => {
        loadNotifications()
        
        // Refresh notifications every 30 seconds
        const interval = setInterval(loadNotifications, 30000)
        return () => clearInterval(interval)
    }, [])
    
    // Listen for staleness flag changes
    useEffect(() => {
        const handleStalenessChange = (event) => {
            setHasStaleAssetGrid(event.detail.hasStaleAssetGrid)
        }
        
        // Initialize from window state
        if (typeof window !== 'undefined') {
            if (window.__assetGridStaleness) {
                setHasStaleAssetGrid(window.__assetGridStaleness.hasStaleAssetGrid || false)
            }
            
            window.addEventListener('assetGridStalenessChanged', handleStalenessChange)
        }
        
        return () => {
            if (typeof window !== 'undefined') {
                window.removeEventListener('assetGridStalenessChanged', handleStalenessChange)
            }
        }
    }, [])

    const loadNotifications = async () => {
        try {
            const response = await fetch('/app/api/notifications')
            const data = await response.json()
            setNotifications(data.notifications || [])
            setUnreadCount(data.unread_count || 0)
            setLoading(false)
        } catch (error) {
            console.error('Failed to load notifications:', error)
            setLoading(false)
        }
    }

    const handleMarkAsRead = async (notificationId) => {
        try {
            await fetch(`/app/api/notifications/${notificationId}/read`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
                },
            })
            loadNotifications()
        } catch (error) {
            console.error('Failed to mark notification as read:', error)
        }
    }

    const getNotificationMessage = (notification) => {
        const { type, data } = notification
        const assetName = data?.asset_name || 'an asset'
        const actorName = data?.actor_name || 'Someone'
        
        switch (type) {
            case 'asset.submitted':
                return `${actorName} submitted "${assetName}" for approval`
            case 'asset.approved':
                return `"${assetName}" was approved`
            case 'asset.rejected':
                return `"${assetName}" was rejected`
            case 'asset.resubmitted':
                return `${actorName} resubmitted "${assetName}" for approval`
            default:
                return 'New notification'
        }
    }

    const handleNotificationClick = async (notification) => {
        if (notification.is_unread) {
            await handleMarkAsRead(notification.id)
        }
        
        // Open asset drawer (would need to integrate with asset drawer system)
        // For now, just navigate to assets page
        if (notification.data?.asset_id) {
            window.location.href = `/app/assets?asset=${notification.data.asset_id}`
        }
        setIsOpen(false)
    }

    return (
        <div className="relative flex items-center gap-2">
            {/* Stale Asset Grid Indicator - shows when grid is stale */}
            {hasStaleAssetGrid && (
                <button
                    type="button"
                    onClick={() => {
                        // Navigate to assets page to refresh
                        router.visit('/app/assets')
                    }}
                    className="relative p-2 text-amber-500 hover:text-amber-600 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-2 rounded-full transition-colors"
                    title="Asset grid may be stale - click to refresh"
                    aria-label="Asset grid may be stale"
                >
                    <SparklesIcon className="h-6 w-6" />
                </button>
            )}
            
            <button
                type="button"
                onClick={() => setIsOpen(!isOpen)}
                className="relative p-2 text-gray-400 hover:text-gray-500 focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2 rounded-full"
                style={{ color: textColor === '#ffffff' ? 'rgba(255, 255, 255, 0.7)' : 'rgba(0, 0, 0, 0.7)' }}
            >
                <BellIcon className="h-6 w-6" />
                {/* Show badge if there are unread notifications OR pending items (only if user has permission) */}
                {(unreadCount > 0 || (pending_items && ((canViewSuggestions && pending_items.ai_suggestions > 0) || metadataApprovalsCount > 0))) && (
                    <span className="absolute top-0 right-0 block h-2 w-2 rounded-full bg-red-500 ring-2 ring-white" />
                )}
                {(unreadCount > 0 || (pending_items && ((canViewSuggestions && pending_items.ai_suggestions > 0) || metadataApprovalsCount > 0))) && (
                    <span className="absolute -top-1 -right-1 flex h-5 w-5 items-center justify-center rounded-full bg-red-500 text-xs font-bold text-white">
                        {(() => {
                            const totalPending = (canViewSuggestions ? (pending_items?.ai_suggestions || 0) : 0) + metadataApprovalsCount
                            const total = unreadCount + totalPending
                            return total > 9 ? '9+' : total
                        })()}
                    </span>
                )}
            </button>

            {isOpen && (
                <>
                    <div
                        className="fixed inset-0 z-10"
                        onClick={() => setIsOpen(false)}
                    />
                    <div className="absolute right-0 z-20 mt-2 w-80 origin-top-right rounded-md bg-white shadow-lg ring-1 ring-black ring-opacity-5 focus:outline-none max-h-96 overflow-y-auto">
                        <div className="px-4 py-3 border-b border-gray-200">
                            <h3 className="text-sm font-semibold text-gray-900">Notifications</h3>
                        </div>
                        <div className="py-1">
                            {/* Pending Items - Simple notifications */}
                            {pending_items && ((canViewSuggestions && pending_items.ai_suggestions > 0) || metadataApprovalsCount > 0) && (
                                <>
                                    {canViewSuggestions && pending_items.ai_suggestions > 0 && (
                                        <button
                                            onClick={() => {
                                                router.visit('/app/dashboard')
                                                setIsOpen(false)
                                            }}
                                            className="w-full text-left px-4 py-3 text-sm hover:bg-gray-50 bg-blue-50"
                                        >
                                            <div className="flex items-start justify-between">
                                                <div className="flex items-start gap-3 flex-1 min-w-0">
                                                    <SparklesIcon className="h-5 w-5 text-amber-500 flex-shrink-0 mt-0.5" />
                                                    <div className="flex-1 min-w-0">
                                                        <p className="text-sm font-medium text-gray-900">
                                                            {pending_items.ai_suggestions} pending AI {pending_items.ai_suggestions === 1 ? 'suggestion' : 'suggestions'}
                                                        </p>
                                                        <p className="text-xs text-gray-500 mt-0.5">
                                                            Review tags and metadata
                                                        </p>
                                                    </div>
                                                </div>
                                                <span className="ml-2 h-2 w-2 rounded-full bg-blue-500 flex-shrink-0 mt-1" />
                                            </div>
                                        </button>
                                    )}
                                    {metadataApprovalsCount > 0 && (
                                        <button
                                            onClick={() => {
                                                router.visit('/app/dashboard')
                                                setIsOpen(false)
                                            }}
                                            className={`w-full text-left px-4 py-3 text-sm ${
                                                canApprove 
                                                    ? 'hover:bg-amber-50 bg-amber-50'  // Approver: actionable warning style
                                                    : 'hover:bg-gray-50 bg-gray-50'    // Contributor: informational subtle style
                                            }`}
                                        >
                                            <div className="flex items-start justify-between">
                                                <div className="flex items-start gap-3 flex-1 min-w-0">
                                                    {canApprove ? (
                                                        <ExclamationTriangleIcon className="h-5 w-5 text-amber-600 flex-shrink-0 mt-0.5" />
                                                    ) : (
                                                        <InformationCircleIcon className="h-5 w-5 text-gray-500 flex-shrink-0 mt-0.5" />
                                                    )}
                                                    <div className="flex-1 min-w-0">
                                                        <p className="text-sm font-medium text-gray-900">
                                                            {canApprove ? (
                                                                <>
                                                                    {metadataApprovalsCount} pending metadata {metadataApprovalsCount === 1 ? 'approval' : 'approvals'}
                                                                </>
                                                            ) : (
                                                                <>
                                                                    {metadataApprovalsCount} of your metadata {metadataApprovalsCount === 1 ? 'field is' : 'fields are'} pending approval
                                                                </>
                                                            )}
                                                        </p>
                                                        <p className="text-xs text-gray-500 mt-0.5">
                                                            {canApprove ? (
                                                                'Review metadata fields'
                                                            ) : (
                                                                'An admin will review these shortly'
                                                            )}
                                                        </p>
                                                    </div>
                                                </div>
                                                <span className={`ml-2 h-2 w-2 rounded-full flex-shrink-0 mt-1 ${
                                                    canApprove ? 'bg-amber-500' : 'bg-gray-400'
                                                }`} />
                                            </div>
                                        </button>
                                    )}
                                    {(notifications.length > 0 || loading) && (
                                        <div className="border-t border-gray-200 my-1" />
                                    )}
                                </>
                            )}
                            
                            {/* Regular Notifications */}
                            {loading ? (
                                <div className="px-4 py-3 text-sm text-gray-500">Loading...</div>
                            ) : notifications.length === 0 && (!pending_items || ((!canViewSuggestions || pending_items.ai_suggestions === 0) && metadataApprovalsCount === 0)) ? (
                                <div className="px-4 py-3 text-sm text-gray-500">No notifications</div>
                            ) : (
                                notifications.map((notification) => (
                                    <button
                                        key={notification.id}
                                        onClick={() => handleNotificationClick(notification)}
                                        className={`w-full text-left px-4 py-3 text-sm hover:bg-gray-50 ${
                                            notification.is_unread ? 'bg-blue-50' : ''
                                        }`}
                                    >
                                        <div className="flex items-start justify-between">
                                            <div className="flex-1 min-w-0">
                                                <p className="text-sm text-gray-900">
                                                    {getNotificationMessage(notification)}
                                                </p>
                                                <p className="text-xs text-gray-500 mt-1">
                                                    {new Date(notification.created_at).toLocaleString()}
                                                </p>
                                            </div>
                                            {notification.is_unread && (
                                                <span className="ml-2 h-2 w-2 rounded-full bg-blue-500 flex-shrink-0 mt-1" />
                                            )}
                                        </div>
                                    </button>
                                ))
                            )}
                        </div>
                    </div>
                </>
            )}
        </div>
    )
}
