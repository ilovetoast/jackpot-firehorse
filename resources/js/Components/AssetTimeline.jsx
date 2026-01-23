/**
 * AssetTimeline Component
 * 
 * Compact timeline component for displaying asset lifecycle events.
 * Similar to ActivityFeed but smaller and optimized for asset detail drawer.
 * 
 * @param {Object} props
 * @param {Array} props.events - Array of activity event objects
 * @param {boolean} props.loading - Loading state
 * @param {Function} props.onThumbnailRetry - Callback for thumbnail retry (UI only, max 2 retries)
 * @param {number} props.thumbnailRetryCount - Current retry count
 */
import { CheckCircleIcon, XCircleIcon, ArrowPathIcon } from '@heroicons/react/24/outline'

export default function AssetTimeline({ events = [], loading = false, onThumbnailRetry = null, thumbnailRetryCount = 0 }) {
    // Format event type to human-readable description
    const formatEventType = (eventType, metadata = {}) => {
        const eventMap = {
            'asset.upload.finalized': 'Upload finalized',
            'asset.thumbnail.started': metadata?.triggered_by === 'user_manual_request' 
                ? 'Thumbnail generation started (manually triggered)'
                : 'Thumbnail generation started',
            'asset.thumbnail.completed': 'Thumbnail generation completed',
            'asset.thumbnail.failed': 'Thumbnail generation failed',
            'asset.thumbnail.skipped': 'Thumbnail generation skipped (unsupported format)',
            'asset.thumbnail.retry_requested': 'Thumbnail generation retry requested',
            'asset.promoted': 'Asset promoted',
            'asset.ready': 'Asset ready',
            'asset.ai_tagging.completed': `AI tagging completed${metadata?.tag_count ? ` (${metadata.tag_count} tags)` : ''}`,
            'asset.system_tagging.completed': `System tagging completed${metadata?.fields_count ? ` (${metadata.fields_count} fields)` : ''}`,
            'asset.ai_suggestions.generated': `AI suggestions generated${metadata?.suggestions_count ? ` (${metadata.suggestions_count} suggestions)` : ''}`,
            'asset.ai_suggestion.accepted': metadata?.field_label 
                ? `AI suggestion accepted: ${metadata.field_label}`
                : 'AI suggestion accepted',
            'asset.ai_suggestion.dismissed': metadata?.field_label
                ? `AI suggestion dismissed: ${metadata.field_label}`
                : 'AI suggestion dismissed',
            'asset.metadata.populated': `Metadata populated${metadata?.fields_count ? ` (${metadata.fields_count} fields)` : ''}`,
            'asset.color_analysis.completed': `Color analysis completed${metadata?.buckets_count ? ` (${metadata.buckets_count} colors)` : ''}`,
        }
        
        return eventMap[eventType] || eventType
            .split('.')
            .map(word => word.charAt(0).toUpperCase() + word.slice(1))
            .join(' ')
    }

    // Get icon and color for event type
    // Check if a completion event exists for 'started' events to stop animation
    const hasCompletionEvent = (eventType, allEvents) => {
        if (eventType.includes('thumbnail.started')) {
            return allEvents.some(e => 
                e.event_type === 'asset.thumbnail.completed' || 
                e.event_type === 'asset.thumbnail.failed' ||
                e.event_type === 'asset.thumbnail.skipped'
            )
        }
        // Add other started event types here if needed
        return false
    }

    const getEventIcon = (eventType, allEvents = []) => {
        if (eventType.includes('failed')) {
            return {
                icon: XCircleIcon,
                color: 'text-red-500',
                bgColor: 'bg-red-50',
            }
        }
        
        // Skipped events are informational (not error, not success)
        if (eventType.includes('skipped')) {
            return {
                icon: CheckCircleIcon,
                color: 'text-blue-500',
                bgColor: 'bg-blue-50',
            }
        }
        
        if (eventType.includes('completed') || eventType.includes('promoted') || eventType.includes('ready') || eventType.includes('finalized') || eventType.includes('generated') || eventType.includes('accepted') || eventType.includes('populated')) {
            return {
                icon: CheckCircleIcon,
                color: 'text-green-500',
                bgColor: 'bg-green-50',
            }
        }
        
        // Dismissed events are informational (not error, not success)
        if (eventType.includes('dismissed')) {
            return {
                icon: CheckCircleIcon,
                color: 'text-gray-500',
                bgColor: 'bg-gray-50',
            }
        }
        
        if (eventType.includes('started') || eventType.includes('processing')) {
            // Only animate if no completion event exists
            const hasCompleted = hasCompletionEvent(eventType, allEvents)
            return {
                icon: ArrowPathIcon,
                color: 'text-blue-500',
                bgColor: 'bg-blue-50',
                animated: !hasCompleted,
            }
        }
        
        // Default
        return {
            icon: CheckCircleIcon,
            color: 'text-gray-400',
            bgColor: 'bg-gray-50',
        }
    }

    // Format relative time
    const formatRelativeTime = (dateString) => {
        if (!dateString) return 'Unknown time'
        
        try {
            const date = new Date(dateString)
            const now = new Date()
            const diffMs = now - date
            const diffMins = Math.floor(diffMs / 60000)
            const diffHours = Math.floor(diffMs / 3600000)
            const diffDays = Math.floor(diffMs / 86400000)
            
            if (diffMins < 1) return 'Just now'
            if (diffMins < 60) return `${diffMins} minute${diffMins !== 1 ? 's' : ''} ago`
            if (diffHours < 24) return `${diffHours} hour${diffHours !== 1 ? 's' : ''} ago`
            if (diffDays < 7) return `${diffDays} day${diffDays !== 1 ? 's' : ''} ago`
            
            // For older dates, show formatted date
            return date.toLocaleDateString('en-US', {
                month: 'short',
                day: 'numeric',
                year: date.getFullYear() !== now.getFullYear() ? 'numeric' : undefined,
            })
        } catch (e) {
            return 'Unknown time'
        }
    }

    if (loading) {
        return (
            <div className="space-y-3">
                <h3 className="text-sm font-medium text-gray-900">Timeline</h3>
                <div className="text-center py-4">
                    <ArrowPathIcon className="h-5 w-5 text-gray-400 mx-auto animate-spin" />
                    <p className="mt-2 text-xs text-gray-500">Loading timeline...</p>
                </div>
            </div>
        )
    }

    if (!events || events.length === 0) {
        return (
            <div className="space-y-3 border-t border-gray-200 pt-6">
                <h3 className="text-sm font-medium text-gray-900">Timeline</h3>
                <div className="text-center py-4 text-xs text-gray-500">
                    No activity yet
                </div>
            </div>
        )
    }

    return (
        <div className="space-y-3 border-t border-gray-200 pt-6">
            <h3 className="text-sm font-medium text-gray-900">Timeline</h3>
            
            <div className="relative">
                {/* Timeline line */}
                <div className="absolute left-3 top-0 bottom-0 w-0.5 bg-gray-200"></div>
                
                {/* Events */}
                <div className="space-y-4">
                    {events.map((event, index) => {
                        const eventConfig = getEventIcon(event.event_type, events)
                        const EventIcon = eventConfig.icon
                        const isAnimated = eventConfig.animated
                        
                        return (
                            <div key={event.id || index} className="relative flex items-start gap-3">
                                {/* Timeline indicator */}
                                <div className={`flex-shrink-0 relative z-10 h-6 w-6 rounded-full flex items-center justify-center ${eventConfig.bgColor}`}>
                                    <EventIcon className={`h-4 w-4 ${eventConfig.color} ${isAnimated ? 'animate-spin' : ''}`} />
                                </div>
                                
                                {/* Event content */}
                                <div className="flex-1 min-w-0 pt-0.5">
                                    <div className="flex items-start justify-between gap-2">
                                        <div className="flex-1 min-w-0">
                                            <p className="text-xs font-medium text-gray-900">
                                                {formatEventType(event.event_type, event.metadata)}
                                            </p>
                                            {event.metadata && Object.keys(event.metadata).length > 0 && (
                                                <div className="mt-1 text-xs text-gray-500">
                                                    {event.metadata.error && (
                                                        <p className="text-red-600">{event.metadata.error}</p>
                                                    )}
                                                    {event.metadata.reason && event.event_type === 'asset.thumbnail.skipped' && (
                                                        <p className="text-blue-600">
                                                            {event.metadata.reason === 'unsupported_file_type' 
                                                                ? 'Unsupported file type' 
                                                                : event.metadata.reason}
                                                        </p>
                                                    )}
                                                    {/* Show manual trigger indicator for thumbnail started events */}
                                                    {event.metadata.triggered_by === 'user_manual_request' && event.event_type === 'asset.thumbnail.started' && (
                                                        <p className="text-indigo-600 italic">
                                                            Manually triggered by user
                                                        </p>
                                                    )}
                                                    {/* Show style indicators for thumbnail completed events */}
                                                    {event.metadata.styles && event.event_type === 'asset.thumbnail.completed' && (
                                                        <div className="mt-1.5 flex items-center gap-2">
                                                            <span className="text-xs text-gray-500">Styles:</span>
                                                            <div className="flex items-center gap-1.5">
                                                                {event.metadata.styles.map((style) => (
                                                                    <span
                                                                        key={style}
                                                                        className="inline-flex items-center gap-1 text-xs"
                                                                        title={`${style.charAt(0).toUpperCase() + style.slice(1)} thumbnail generated`}
                                                                    >
                                                                        {/* Style indicator dot */}
                                                                        <span className={`inline-block h-2 w-2 rounded-full ${
                                                                            style === 'preview' ? 'bg-blue-400' :
                                                                            style === 'thumb' ? 'bg-green-500' :
                                                                            style === 'medium' ? 'bg-yellow-500' :
                                                                            style === 'large' ? 'bg-purple-500' :
                                                                            'bg-gray-400'
                                                                        }`} />
                                                                        <span className="text-gray-600 capitalize">{style}</span>
                                                                    </span>
                                                                ))}
                                                            </div>
                                                        </div>
                                                    )}
                                                    {/* Show text for other events that mention styles */}
                                                    {event.metadata.styles && event.event_type !== 'asset.thumbnail.completed' && (
                                                        <p className="text-xs text-gray-500">Styles: {event.metadata.styles.join(', ')}</p>
                                                    )}
                                                    {/* Phase 3.1: Hide temp upload paths from timeline (internal-only detail, confusing for users) */}
                                                    {event.metadata.from && event.metadata.to && 
                                                     !event.metadata.from.includes('temp/uploads/') && 
                                                     !event.metadata.to.includes('temp/uploads/') && (
                                                        <p className="truncate">
                                                            {event.metadata.from} → {event.metadata.to}
                                                        </p>
                                                    )}
                                                </div>
                                            )}
                                            {/* Phase 3.0C: Retry affordance for failed thumbnail events (UI only, max 2 retries) */}
                                            {event.event_type === 'asset.thumbnail.failed' && onThumbnailRetry && thumbnailRetryCount < 2 && (
                                                <div className="mt-2">
                                                    <button
                                                        type="button"
                                                        onClick={(e) => {
                                                            e.stopPropagation()
                                                            onThumbnailRetry()
                                                        }}
                                                        className="inline-flex items-center gap-1.5 rounded-md bg-indigo-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                                                    >
                                                        <ArrowPathIcon className="h-3.5 w-3.5" />
                                                        Retry thumbnail generation
                                                    </button>
                                                </div>
                                            )}
                                        </div>
                                        <span className="flex-shrink-0 text-xs text-gray-400 whitespace-nowrap">
                                            {formatRelativeTime(event.created_at)}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        )
                    })}
                </div>
            </div>
        </div>
    )
}