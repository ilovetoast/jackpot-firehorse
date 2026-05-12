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
 * @param {Function} props.onVideoPreviewRetry - Callback for video preview retry
 */
import { CheckCircleIcon, XCircleIcon, ArrowPathIcon } from '@heroicons/react/24/outline'
import { Activity, AlertCircle, Slash, RefreshCw, History } from 'lucide-react'
import { formatBytesHuman } from '../utils/formatBytesHuman'

/**
 * Site staff (drawer passes audioAiAudience="operator"): show reason codes + errors for Audio AI skips.
 * End users see a generic line from {@link AssetTimeline} instead.
 */
function formatAudioAiSkippedOperator(metadata) {
    const r = metadata?.reason != null ? String(metadata.reason) : 'unknown'
    const err = metadata?.error ? ` — ${String(metadata.error).slice(0, 220)}` : ''
    const hints = {
        no_provider: 'Set config `assets.audio_ai.provider` (default whisper) or remove an empty ASSET_AUDIO_AI_PROVIDER override.',
        no_api_key: 'Set `OPENAI_API_KEY` (Whisper uses the same key via config).',
        unknown_provider: 'Provider key is not registered in AudioAiAnalysisService::resolveProvider.',
        agent_disabled: 'Re-enable the audio_insights agent in Admin → AI → Agents.',
        plan_limit_exceeded: 'Workspace AI credits exhausted or AI disabled for tenant.',
        ai_disabled: 'AI disabled for this workspace.',
        budget_exceeded: 'Per-asset Whisper budget cap exceeded (config assets.audio_ai.whisper.budget_cents_per_asset).',
        duration_exceeded: 'Clip longer than assets.audio_ai.whisper.max_duration_seconds.',
    }
    const hint = hints[r] ? ` ${hints[r]}` : ''
    return `Audio AI skipped (operator): ${r}${err}.${hint}`
}

export default function AssetTimeline({
    events = [],
    loading = false,
    onThumbnailRetry = null,
    thumbnailRetryCount = 0,
    onVideoPreviewRetry = null,
    variant = 'default',
    /** `'operator'` = site staff: show Audio AI skip/fail reason codes; default = generic copy for library users */
    audioAiAudience = 'default',
}) {
    const isDark = variant === 'dark'
    // Format event type to human-readable description
    const formatEventType = (eventType, metadata = {}) => {
        if (eventType === 'asset.audio_ai.skipped') {
            if (audioAiAudience === 'operator') {
                return formatAudioAiSkippedOperator(metadata)
            }
            return 'Unable to run AI analysis on this audio right now.'
        }
        if (eventType === 'asset.audio_ai.failed') {
            if (audioAiAudience === 'operator') {
                const r = metadata?.reason != null ? String(metadata.reason) : 'unknown'
                const err = metadata?.error ? ` — ${String(metadata.error).slice(0, 220)}` : ''
                return `Audio AI failed (operator): ${r}${err}.`
            }
            return 'Unable to complete AI analysis on this audio.'
        }

        const eventMap = {
            'asset.upload.finalized': 'Upload finalized',
            'asset.thumbnail.started': metadata?.triggered_by === 'user_manual_request' 
                ? 'Thumbnail generation started (manually triggered)'
                : 'Thumbnail generation started',
            'asset.thumbnail.completed': 'Thumbnail generation completed',
            'asset.thumbnail.failed': 'Thumbnail generation failed',
            'asset.thumbnail.skipped': 'Thumbnail generation skipped (unsupported format)',
            'asset.thumbnail.retry_requested': 'Thumbnail generation retry requested',
            'asset.video_preview.started': 'Video preview generation started',
            'asset.video_preview.completed': 'Video preview generation completed',
            'asset.video_preview.failed': 'Video preview generation failed',
            'asset.video_preview.skipped': 'Video preview generation skipped',
            // Audio web-playback derivative — the 128 kbps MP3 we transcode for
            // the browser. `skipped` is informational (small MP3 sources don't
            // need a derivative) so we name the reason rather than just "skipped".
            'asset.audio_web_playback.started': 'Web playback MP3 transcode started',
            'asset.audio_web_playback.completed': metadata?.bitrate_kbps
                ? `Web playback MP3 generated (${metadata.bitrate_kbps} kbps${metadata?.output_size_bytes ? `, ${formatBytesHuman(metadata.output_size_bytes)}` : ''})`
                : 'Web playback MP3 generated',
            'asset.audio_web_playback.skipped': metadata?.reason === 'mp3_under_threshold'
                ? 'Web playback MP3 not needed — original MP3 streams directly'
                : metadata?.reason === 'small_browser_compatible'
                  ? 'Web playback MP3 not needed — original is browser-friendly'
                  : metadata?.reason === 'disabled_by_config'
                    ? 'Web playback MP3 disabled by config'
                    : 'Web playback MP3 generation skipped',
            'asset.audio_web_playback.failed': metadata?.reason
                ? `Web playback MP3 generation failed (${metadata.reason})`
                : 'Web playback MP3 generation failed',
            // Audio AI insights (Whisper transcript + mood + summary).
            'asset.audio_ai.started': 'Audio AI analysis started',
            'asset.audio_ai.completed': metadata?.credits_charged
                ? `Audio AI analysis completed (${metadata.credits_charged} credit${metadata.credits_charged === 1 ? '' : 's'}${metadata?.detected_language ? `, ${metadata.detected_language}` : ''})`
                : 'Audio AI analysis completed',
            // asset.audio_ai.skipped / .failed: handled at top of formatEventType (operator vs library user)
            'asset.audio_ai.skipped': 'Audio AI analysis skipped',
            'asset.audio_ai.failed': 'Audio AI analysis failed',
            'asset.promoted': 'Asset promoted',
            'asset.ready': 'Asset ready',
            'asset.ai_tagging.completed': `AI tagging completed${metadata?.tag_count ? ` (${metadata.tag_count} tags)` : ''}`,
            'asset.ai_tagging.regenerated': metadata?.pipeline === 'audio_insights'
                ? 'Audio AI analysis re-run requested'
                : metadata?.pipeline === 'vision_tags'
                  ? 'AI tagging re-run requested (vision)'
                  : 'AI tagging re-run requested',
            'asset.ai_metadata.generated': `AI metadata generated`,
            'asset.ai_metadata.failed': `AI metadata generation failed`,
            'asset.ai_video_insights.completed': `Video AI insights completed`,
            'asset.ai_video_insights.failed': `Video AI insights failed`,
            'asset.ai_suggestions.generated': `AI suggestions generated${metadata?.suggestions_count ? ` (${metadata.suggestions_count} suggestions)` : ''}`,
            'asset.ai_suggestions.failed': `AI suggestions failed`,
            'asset.ai_suggestion.accepted': metadata?.field_label 
                ? `AI suggestion accepted: ${metadata.field_label}`
                : 'AI suggestion accepted',
            'asset.ai_suggestion.dismissed': metadata?.field_label
                ? `AI suggestion dismissed: ${metadata.field_label}`
                : 'AI suggestion dismissed',
            // Note: 'asset.system_tagging.completed' and 'asset.metadata.populated' are no longer logged
            // System metadata is tracked via 'asset.system_metadata.generated' from ComputedMetadataJob
            'asset.system_metadata.generated': `System metadata generated${metadata?.fields_count ? ` (${metadata.fields_count} fields)` : ''}`,
            'asset.system_metadata.regenerated': `System metadata regenerated${metadata?.fields_count ? ` (${metadata.fields_count} fields)` : ''}`,
            'asset.color_analysis.completed': `Color analysis completed${metadata?.buckets_count ? ` (${metadata.buckets_count} colors)` : ''}`,
            'asset.brand_compliance.requested': 'Brand alignment analysis started',
            'asset.brand_compliance.evaluated': metadata?.overall_score != null
                ? `Brand alignment score: ${metadata.overall_score}%`
                : 'Brand alignment evaluated',
            'asset.brand_compliance.incomplete': 'Brand alignment incomplete — missing required metadata',
            'asset.brand_compliance.not_applicable': 'Brand alignment not configured for this brand',
            'asset.brand_compliance.file_type_unsupported': 'Brand alignment not available for this file type',
            // Version lifecycle: when a new version becomes the active one
            'asset.version.created': metadata?.version_number != null
                ? `Version switched over to v${metadata.version_number}`
                : 'Version switched over',
            'asset.version.restored': metadata?.version_number != null
                ? `Version switched over to v${metadata.version_number} (restored)`
                : 'Version switched over (restored)',
            'asset.replaced': 'File replaced',
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
        if (eventType.includes('video_preview.started')) {
            return allEvents.some(e => 
                e.event_type === 'asset.video_preview.completed' || 
                e.event_type === 'asset.video_preview.failed' ||
                e.event_type === 'asset.video_preview.skipped'
            )
        }
        if (eventType === 'asset.audio_web_playback.started') {
            return allEvents.some(e =>
                e.event_type === 'asset.audio_web_playback.completed' ||
                e.event_type === 'asset.audio_web_playback.failed' ||
                e.event_type === 'asset.audio_web_playback.skipped'
            )
        }
        if (eventType === 'asset.audio_ai.started') {
            return allEvents.some(e =>
                e.event_type === 'asset.audio_ai.completed' ||
                e.event_type === 'asset.audio_ai.failed' ||
                e.event_type === 'asset.audio_ai.skipped'
            )
        }
        if (eventType === 'asset.brand_compliance.requested') {
            return allEvents.some(e => 
                e.event_type === 'asset.brand_compliance.evaluated' ||
                e.event_type === 'asset.brand_compliance.incomplete' ||
                e.event_type === 'asset.brand_compliance.not_applicable' ||
                e.event_type === 'asset.brand_compliance.file_type_unsupported'
            )
        }
        return false
    }

    const getEventIcon = (eventType, allEvents = []) => {
        // Version switched over events (version lifecycle)
        if (eventType === 'asset.version.created' || eventType === 'asset.version.restored' || eventType === 'asset.replaced') {
            return {
                icon: History,
                color: 'text-indigo-500',
                bgColor: 'bg-indigo-50',
            }
        }

        // Brand compliance events (Lucide icons)
        if (eventType === 'asset.brand_compliance.evaluated') {
            return { icon: Activity, color: 'text-green-500', bgColor: 'bg-green-50' }
        }
        if (eventType === 'asset.brand_compliance.incomplete') {
            return { icon: AlertCircle, color: 'text-amber-500', bgColor: 'bg-amber-50' }
        }
        if (eventType === 'asset.brand_compliance.not_applicable') {
            return { icon: Slash, color: 'text-gray-500', bgColor: 'bg-gray-50' }
        }
        if (eventType === 'asset.brand_compliance.file_type_unsupported') {
            return { icon: Slash, color: 'text-gray-500', bgColor: 'bg-gray-50' }
        }
        if (eventType === 'asset.brand_compliance.requested') {
            const hasCompleted = hasCompletionEvent(eventType, allEvents)
            return {
                icon: RefreshCw,
                color: 'text-blue-500',
                bgColor: 'bg-blue-50',
                animated: !hasCompleted,
            }
        }

        // AI failures should show red X icon
        if (eventType.includes('failed') || 
            eventType === 'asset.ai_metadata.failed' || 
            eventType === 'asset.ai_suggestions.failed') {
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

    /** Lightbox / dark panels: replace light icon wells (bg-blue-50 etc.) with neutral */
    const mapIconForDarkSurface = (cfg) => {
        if (!isDark) return cfg
        const c = cfg.color || ''
        const iconColor =
            c.includes('red') ? 'text-red-400' :
            c.includes('green') ? 'text-emerald-400' :
            c.includes('amber') ? 'text-amber-400' :
            c.includes('blue') || c.includes('indigo') ? 'text-neutral-300' :
            'text-neutral-200'
        return {
            ...cfg,
            bgColor: 'bg-neutral-800',
            color: iconColor,
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
            <div className="text-center py-4">
                <ArrowPathIcon className={`h-5 w-5 mx-auto animate-spin ${isDark ? 'text-neutral-500' : 'text-gray-400'}`} />
                <p className={`mt-2 text-xs ${isDark ? 'text-neutral-500' : 'text-gray-500'}`}>Loading timeline...</p>
            </div>
        )
    }

    if (!events || events.length === 0) {
        return (
            <div className={`text-center py-4 text-xs ${isDark ? 'text-neutral-500' : 'text-gray-500'}`}>
                No activity yet
            </div>
        )
    }

    return (
        <div className="relative">
                {/* Timeline line */}
                <div className={`absolute left-3 top-0 bottom-0 w-0.5 ${isDark ? 'bg-neutral-700' : 'bg-gray-200'}`}></div>
                
                {/* Events */}
                <div className="space-y-4">
                    {events.map((event, index) => {
                        const eventConfig = mapIconForDarkSurface(getEventIcon(event.event_type, events))
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
                                            <p className={`text-xs font-medium ${isDark ? 'text-neutral-100' : 'text-gray-900'}`}>
                                                {formatEventType(event.event_type, event.metadata)}
                                            </p>
                                            {event.metadata && Object.keys(event.metadata).length > 0 && (
                                                <div className={`mt-1 text-xs ${isDark ? 'text-neutral-400' : 'text-gray-500'}`}>
                                                    {/* Show error for non-AI events */}
                                                    {event.metadata.error && 
                                                     event.event_type !== 'asset.ai_metadata.failed' && 
                                                     event.event_type !== 'asset.ai_suggestions.failed' && (
                                                        <p className="text-red-600">{event.metadata.error}</p>
                                                    )}
                                                    {/* Show user-friendly error for AI failures */}
                                                    {(event.event_type === 'asset.ai_metadata.failed' || 
                                                      event.event_type === 'asset.ai_suggestions.failed') && 
                                                     event.metadata.error && (
                                                        <p className="text-xs text-red-600 mt-1">
                                                            {event.metadata.error_type === 'quota_exceeded' 
                                                                ? 'API quota exceeded. Check billing settings.'
                                                                : event.metadata.error}
                                                        </p>
                                                    )}
                                                    {event.metadata.reason && event.event_type === 'asset.thumbnail.skipped' && (
                                                        <p className="text-blue-600">
                                                            {event.metadata.reason === 'unsupported_file_type' 
                                                                ? 'Unsupported file type' 
                                                                : event.metadata.reason}
                                                        </p>
                                                    )}
                                                    {/* Show error details for video preview failures */}
                                                    {event.event_type === 'asset.video_preview.failed' && event.metadata.error && (
                                                        <p className="text-xs text-red-600 mt-1">
                                                            {event.metadata.error}
                                                        </p>
                                                    )}
                                                    {/* Show reason for video preview skipped */}
                                                    {event.event_type === 'asset.video_preview.skipped' && event.metadata.reason && (
                                                        <p className="text-blue-600">
                                                            {event.metadata.reason === 'not_a_video' 
                                                                ? 'Not a video file' 
                                                                : event.metadata.reason === 'already_generated'
                                                                ? 'Preview already generated'
                                                                : event.metadata.reason}
                                                        </p>
                                                    )}
                                                    {/* Show manual trigger indicator for thumbnail started events */}
                                                    {event.metadata.triggered_by === 'user_manual_request' && event.event_type === 'asset.thumbnail.started' && (
                                                        <p className={`italic ${isDark ? 'text-neutral-400' : 'text-indigo-600'}`}>
                                                            Manually triggered by user
                                                        </p>
                                                    )}
                                                    {/* Show style indicators for thumbnail completed events (hide when styles is empty) */}
                                                    {event.metadata.styles?.length > 0 && event.event_type === 'asset.thumbnail.completed' && (
                                                        <div className="mt-1.5 flex items-center gap-2">
                                                            <span className={`text-xs ${isDark ? 'text-neutral-500' : 'text-gray-500'}`}>Styles:</span>
                                                            <div className="flex items-center gap-1.5">
                                                                {event.metadata.styles.map((style) => (
                                                                    <span
                                                                        key={style}
                                                                        className="inline-flex items-center gap-1 text-xs"
                                                                        title={`${style.charAt(0).toUpperCase() + style.slice(1)} thumbnail generated`}
                                                                    >
                                                                        {/* Style indicator dot */}
                                                                        <span className={`inline-block h-2 w-2 rounded-full ${
                                                                            isDark
                                                                                ? style === 'preview'
                                                                                    ? 'bg-neutral-500'
                                                                                    : style === 'thumb'
                                                                                      ? 'bg-neutral-400'
                                                                                      : style === 'medium'
                                                                                        ? 'bg-neutral-600'
                                                                                        : style === 'large'
                                                                                          ? 'bg-neutral-500'
                                                                                          : 'bg-neutral-600'
                                                                                : style === 'preview'
                                                                                  ? 'bg-blue-400'
                                                                                  : style === 'thumb'
                                                                                    ? 'bg-green-500'
                                                                                    : style === 'medium'
                                                                                      ? 'bg-yellow-500'
                                                                                      : style === 'large'
                                                                                        ? 'bg-purple-500'
                                                                                        : 'bg-gray-400'
                                                                        }`} />
                                                                        <span className={`capitalize ${isDark ? 'text-neutral-300' : 'text-gray-600'}`}>{style}</span>
                                                                    </span>
                                                                ))}
                                                            </div>
                                                        </div>
                                                    )}
                                                    {/* Show text for other events that mention styles */}
                                                    {event.metadata.styles?.length > 0 && event.event_type !== 'asset.thumbnail.completed' && (
                                                        <p className={`text-xs ${isDark ? 'text-neutral-400' : 'text-gray-500'}`}>Styles: {event.metadata.styles.join(', ')}</p>
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
                                            {/* Retry affordance for failed video preview events */}
                                            {event.event_type === 'asset.video_preview.failed' && onVideoPreviewRetry && (
                                                <div className="mt-2">
                                                    <button
                                                        type="button"
                                                        onClick={(e) => {
                                                            e.stopPropagation()
                                                            onVideoPreviewRetry()
                                                        }}
                                                        className="inline-flex items-center gap-1.5 rounded-md bg-green-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2"
                                                    >
                                                        <ArrowPathIcon className="h-3.5 w-3.5" />
                                                        Retry video preview generation
                                                    </button>
                                                </div>
                                            )}
                                        </div>
                                        <span className={`flex-shrink-0 text-xs whitespace-nowrap ${isDark ? 'text-neutral-500' : 'text-gray-400'}`}>
                                            {formatRelativeTime(event.created_at)}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        )
                    })}
                </div>
            </div>
    )
}