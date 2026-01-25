import { useState, useEffect } from 'react'
import { BellIcon } from '@heroicons/react/24/outline'

/**
 * Phase AF-3: Notification Bell Component
 * 
 * Shows unread notification count and opens notification panel.
 */
export default function NotificationBell({ textColor = '#000000' }) {
    const [notifications, setNotifications] = useState([])
    const [unreadCount, setUnreadCount] = useState(0)
    const [isOpen, setIsOpen] = useState(false)
    const [loading, setLoading] = useState(true)

    useEffect(() => {
        loadNotifications()
        
        // Refresh notifications every 30 seconds
        const interval = setInterval(loadNotifications, 30000)
        return () => clearInterval(interval)
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
        <div className="relative">
            <button
                type="button"
                onClick={() => setIsOpen(!isOpen)}
                className="relative p-2 text-gray-400 hover:text-gray-500 focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2 rounded-full"
                style={{ color: textColor === '#ffffff' ? 'rgba(255, 255, 255, 0.7)' : 'rgba(0, 0, 0, 0.7)' }}
            >
                <BellIcon className="h-6 w-6" />
                {unreadCount > 0 && (
                    <span className="absolute top-0 right-0 block h-2 w-2 rounded-full bg-red-500 ring-2 ring-white" />
                )}
                {unreadCount > 0 && (
                    <span className="absolute -top-1 -right-1 flex h-5 w-5 items-center justify-center rounded-full bg-red-500 text-xs font-bold text-white">
                        {unreadCount > 9 ? '9+' : unreadCount}
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
                            {loading ? (
                                <div className="px-4 py-3 text-sm text-gray-500">Loading...</div>
                            ) : notifications.length === 0 ? (
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
