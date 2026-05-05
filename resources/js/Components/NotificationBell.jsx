import { useState, useEffect } from 'react'
import { usePage, router } from '@inertiajs/react'
import { BellIcon, SparklesIcon, ExclamationTriangleIcon, InformationCircleIcon, ArrowDownTrayIcon, DocumentMagnifyingGlassIcon, ChevronDownIcon, ChevronUpIcon } from '@heroicons/react/24/outline'
import { usePermission } from '../hooks/usePermission'
import { showWorkspaceSwitchingOverlay } from '../utils/workspaceSwitchOverlay'

/**
 * Phase AF-3: Notification Bell Component
 * 
 * Shows unread notification count and opens notification panel.
 * Also shows simple pending items (AI suggestions, metadata approvals).
 */
export default function NotificationBell({ textColor = '#000000' }) {
    const { pending_items, auth } = usePage().props
    const [notifications, setNotifications] = useState([])
    const [unreadCount, setUnreadCount] = useState(0)
    const [isOpen, setIsOpen] = useState(false)
    const [loading, setLoading] = useState(true)
    const [expandedIds, setExpandedIds] = useState(new Set())
    const { can } = usePermission()
    const canApprove = can('metadata.bypass_approval')
    const canViewSuggestions = can('metadata.suggestions.view')
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
            const response = await fetch('/app/api/notifications', {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            })
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`)
            }
            const data = await response.json()
            setNotifications(data.notifications || [])
            setUnreadCount(data.unread_count || 0)
        } catch (error) {
            console.error('Failed to load notifications:', error)
        } finally {
            setLoading(false)
        }
    }

    const handleMarkAsRead = async (notificationId) => {
        try {
            const res = await fetch(`/app/api/notifications/${notificationId}/read`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
                },
            })
            if (!res.ok) {
                throw new Error(`HTTP ${res.status}`)
            }
            loadNotifications()
        } catch (error) {
            console.error('Failed to mark notification as read:', error)
        }
    }

    const getGroupTitle = (type, count = 1) => {
        const suffix = count > 1 ? ` (${count})` : ''
        switch (type) {
            case 'asset.submitted':
                return `Assets submitted for approval${suffix}`
            case 'asset.approved':
                return `Assets approved${suffix}`
            case 'asset.rejected':
                return `Assets rejected${suffix}`
            case 'asset.resubmitted':
                return `Assets resubmitted for approval${suffix}`
            case 'download.ready':
                return `Download${count > 1 ? 's' : ''} ready${suffix}`
            case 'brand_research.ready':
                return `Brand research completed${suffix}`
            default:
                return `Notification${suffix}`
        }
    }

    const getNotificationMessage = (notification, item = null) => {
        const { type, data } = notification
        const d = item || data
        const assetName = d?.asset_name || 'an asset'
        const actorName = d?.actor_name || 'Someone'
        const downloadTitle = d?.download_title || 'Your download'
        const assetCount = d?.asset_count ?? 0

        switch (type) {
            case 'asset.submitted':
                return `${actorName} submitted "${assetName}" for approval`
            case 'asset.approved':
                return `"${assetName}" was approved`
            case 'asset.rejected':
                return `"${assetName}" was rejected`
            case 'asset.resubmitted':
                return `${actorName} resubmitted "${assetName}" for approval`
            case 'download.ready':
                if (isUuidFallbackTitle(downloadTitle, d?.download_id)) {
                    return assetCount > 0 ? `${assetCount} asset${assetCount === 1 ? '' : 's'} ready to download` : 'Download ready'
                }
                return `"${downloadTitle}" is ready to download`
            case 'brand_research.ready':
                return data?.title || 'Brand research is ready'
            default:
                return 'New notification'
        }
    }

    const isUuidFallbackTitle = (title, downloadId) => {
        if (!title || typeof title !== 'string') return false
        const uuidPattern = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i
        if (title === `Download ${downloadId}` && uuidPattern.test(String(downloadId))) return true
        if (uuidPattern.test(title)) return true
        if (/^Download [0-9a-f-]{36}$/i.test(title)) return true
        return false
    }

    const getContextLabel = (notification) => {
        const { data } = notification
        const tenantName = data?.tenant_name
        const brandName = data?.brand_name
        if (tenantName && brandName && tenantName !== brandName) return `${tenantName} · ${brandName}`
        if (tenantName) return tenantName
        if (brandName) return brandName
        return null
    }

    const handleNotificationClick = async (notification, item = null) => {
        const activeTenantId = auth?.activeCompany?.id
        const activeBrandId = auth?.activeBrand?.id
        const data = item || notification.data || {}
        const notifTenantId = data.tenant_id
        const notifBrandId = data.brand_id

        if (notification.is_unread && !item) {
            await handleMarkAsRead(notification.id)
        }

        const needsCompanySwitch = notifTenantId && (!activeTenantId || activeTenantId !== notifTenantId)
        const needsBrandSwitch = notifBrandId && activeBrandId && notifBrandId !== activeBrandId && activeTenantId === notifTenantId

        if (notification.type === 'brand_research.ready' && data.action_url) {
            setIsOpen(false)
            router.visit(data.action_url)
            return
        }
        if (notification.type === 'download.ready' && data.download_id) {
            setIsOpen(false)
            if (needsCompanySwitch) {
                showWorkspaceSwitchingOverlay('company')
                const form = document.createElement('form')
                form.method = 'POST'
                form.action = `/app/companies/${notifTenantId}/switch`
                form.innerHTML = `<input type="hidden" name="_token" value="${document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')}" /><input type="hidden" name="redirect" value="/app/downloads" />`
                document.body.appendChild(form)
                form.submit()
                return
            }
            if (needsBrandSwitch) {
                router.post(`/app/brands/${notifBrandId}/switch`, {}, {
                    preserveState: true,
                    preserveScroll: true,
                    onSuccess: () => {
                        router.reload({ only: ['auth'] })
                        router.visit('/app/downloads')
                    },
                })
                return
            }
            router.visit('/app/downloads')
            return
        }
        if (data.asset_id) {
            if (needsCompanySwitch && notifTenantId) {
                showWorkspaceSwitchingOverlay('company')
                const form = document.createElement('form')
                form.method = 'POST'
                form.action = `/app/companies/${notifTenantId}/switch`
                form.innerHTML = `<input type="hidden" name="_token" value="${document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')}" /><input type="hidden" name="redirect" value="/app/assets?asset=${data.asset_id}" />`
                document.body.appendChild(form)
                form.submit()
                return
            }
            if (needsBrandSwitch && notifBrandId) {
                router.post(`/app/brands/${notifBrandId}/switch`, {}, {
                    preserveState: true,
                    preserveScroll: true,
                    onSuccess: () => {
                        router.reload({ only: ['auth'] })
                        window.location.href = `/app/assets?asset=${data.asset_id}`
                    },
                })
                return
            }
            window.location.href = `/app/assets?asset=${data.asset_id}`
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
                        className="fixed inset-0 z-[190]"
                        aria-hidden="true"
                        onClick={() => setIsOpen(false)}
                    />
                    <div className="absolute right-0 z-[200] w-80 origin-top-right rounded-md bg-white shadow-lg ring-1 ring-black ring-opacity-5 focus:outline-none max-h-[calc(100vh-8rem)] overflow-y-auto" style={{ top: '25px' }}>
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
                                                const tagCount = pending_items.ai_tag_suggestions ?? 0
                                                const catCount = pending_items.ai_category_suggestions ?? 0
                                                const tab = tagCount >= catCount ? 'tags' : 'categories'
                                                router.visit(`/app/insights/review?tab=${tab}`)
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
                                                            {(pending_items.ai_tag_suggestions ?? 0) > 0 && (pending_items.ai_category_suggestions ?? 0) > 0
                                                                ? `${pending_items.ai_tag_suggestions} tags, ${pending_items.ai_category_suggestions} categories`
                                                                : 'Review in Analytics'}
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
                                                router.visit('/app')
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
                            
                            {/* Regular Notifications — grouped, collapsed/expandable */}
                            {loading ? (
                                <div className="px-4 py-3 text-sm text-gray-500">Loading...</div>
                            ) : notifications.length === 0 && (!pending_items || ((!canViewSuggestions || pending_items.ai_suggestions === 0) && metadataApprovalsCount === 0)) ? (
                                <div className="px-4 py-3 text-sm text-gray-500">No notifications</div>
                            ) : (
                                notifications.map((notification) => {
                                    const isDownloadReady = notification.type === 'download.ready'
                                    const isBrandResearchReady = notification.type === 'brand_research.ready'
                                    const Icon = isBrandResearchReady ? DocumentMagnifyingGlassIcon : isDownloadReady ? ArrowDownTrayIcon : null
                                    const count = notification.count ?? 1
                                    const brands = notification.brands || []
                                    const brandsLabel = brands.map((b) => `${b.name} (${b.count})`).join(', ')
                                    const latestAt = notification.latest_at || notification.created_at
                                    const isExpanded = expandedIds.has(notification.id)
                                    const items = notification.items || []
                                    const expandable = notification.expandable && items.length > 1

                                    return (
                                        <div
                                            key={notification.id}
                                            className={`${notification.is_unread ? 'bg-blue-50' : ''} border-b border-gray-100 last:border-b-0`}
                                        >
                                            <div className="flex items-start gap-2 px-4 py-3">
                                                {Icon && <Icon className="h-5 w-5 text-emerald-500 flex-shrink-0 mt-0.5" />}
                                                <div className="flex-1 min-w-0">
                                                    <div className="flex items-center justify-between gap-2">
                                                        <button
                                                            onClick={() => {
                                                                if (expandable) {
                                                                    if (!isExpanded) {
                                                                        setExpandedIds((prev) => new Set(prev).add(notification.id))
                                                                    } else {
                                                                        const target = items[0] || notification.data
                                                                        handleNotificationClick(notification, target)
                                                                    }
                                                                } else {
                                                                    const target = items[0] || notification.data
                                                                    handleNotificationClick(notification, target)
                                                                }
                                                            }}
                                                            className="text-left flex-1 min-w-0"
                                                        >
                                                            <p className="text-sm font-medium text-gray-900">
                                                                {getGroupTitle(notification.type, count)}
                                                            </p>
                                                            {brandsLabel && (
                                                                <p className="text-xs text-gray-600 mt-0.5 truncate">
                                                                    {brandsLabel}
                                                                </p>
                                                            )}
                                                            <p className="text-xs text-gray-400 mt-0.5">
                                                                Latest: {latestAt ? new Date(latestAt).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) : ''}
                                                            </p>
                                                        </button>
                                                        {expandable && (
                                                            <button
                                                                type="button"
                                                                onClick={(e) => {
                                                                    e.stopPropagation()
                                                                    setExpandedIds((prev) => {
                                                                        const next = new Set(prev)
                                                                        if (next.has(notification.id)) next.delete(notification.id)
                                                                        else next.add(notification.id)
                                                                        return next
                                                                    })
                                                                }}
                                                                className="p-1 rounded hover:bg-gray-200 text-gray-500"
                                                                aria-label={isExpanded ? 'Collapse' : 'Expand'}
                                                            >
                                                                {isExpanded ? (
                                                                    <ChevronUpIcon className="h-4 w-4" />
                                                                ) : (
                                                                    <ChevronDownIcon className="h-4 w-4" />
                                                                )}
                                                            </button>
                                                        )}
                                                        {notification.is_unread && (
                                                            <span className="h-2 w-2 rounded-full bg-blue-500 flex-shrink-0 mt-1.5" />
                                                        )}
                                                    </div>
                                                </div>
                                            </div>
                                            {isExpanded && expandable && items.length > 0 && (
                                                <div className="px-4 pb-3 pl-11 space-y-1">
                                                    {items.map((item, idx) => (
                                                        <button
                                                            key={idx}
                                                            onClick={() => handleNotificationClick(notification, item)}
                                                            className="w-full text-left text-xs text-gray-600 hover:text-gray-900 hover:bg-gray-50 rounded px-2 py-1.5 -mx-2"
                                                        >
                                                            {item.brand_name || 'Unknown'} • {item.created_at ? new Date(item.created_at).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) : ''}
                                                        </button>
                                                    ))}
                                                </div>
                                            )}
                                        </div>
                                    )
                                })
                            )}
                        </div>
                    </div>
                </>
            )}
        </div>
    )
}
