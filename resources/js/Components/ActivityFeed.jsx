import { useState } from 'react'
import Avatar from './Avatar'
import { CheckCircleIcon } from '@heroicons/react/24/solid'

/**
 * Reusable Activity Feed Component
 * 
 * Displays a timeline of activities for any subject (user, company, brand, asset, etc.)
 * Designed to match Tailwind Plus activity feed style with custom branding
 * 
 * @param {Object} props
 * @param {Array} props.activities - Array of activity objects
 * @param {Function} props.onAddComment - Callback when user adds a comment (optional)
 * @param {Object} props.currentUser - Current user object for comment input
 * @param {string} props.primaryColor - Primary color for branding (default: indigo)
 * @param {string} props.secondaryColor - Secondary color for branding (default: purple)
 * @param {string} props.accentColor - Accent color for branding (default: pink)
 */
export default function ActivityFeed({ 
    activities = [], 
    onAddComment = null,
    currentUser = null,
    primaryColor = '#6366f1',
    secondaryColor = '#8b5cf6',
    accentColor = '#ec4899'
}) {
    const [commentText, setCommentText] = useState('')
    const [isSubmitting, setIsSubmitting] = useState(false)

    const handleSubmitComment = async (e) => {
        e.preventDefault()
        if (!commentText.trim() || !onAddComment || isSubmitting) return

        setIsSubmitting(true)
        try {
            await onAddComment(commentText.trim())
            setCommentText('')
        } catch (error) {
            console.error('Error adding comment:', error)
        } finally {
            setIsSubmitting(false)
        }
    }

    // Get activity icon/indicator based on event type
    const getActivityIndicator = (activity) => {
        // Check if this is a completion/success event
        const successEvents = ['paid', 'completed', 'approved', 'accepted']
        const isSuccess = successEvents.some(event => 
            activity.event_type?.toLowerCase().includes(event) ||
            activity.description?.toLowerCase().includes(event)
        )

        if (isSuccess) {
            return (
                <div 
                    className="flex-shrink-0 h-8 w-8 rounded-full flex items-center justify-center relative z-10"
                    style={{ backgroundColor: accentColor }}
                >
                    <CheckCircleIcon className="h-5 w-5 text-white" />
                </div>
            )
        }

        // Check if this is a comment
        if (activity.event_type?.toLowerCase().includes('comment') || 
            activity.description?.toLowerCase().includes('commented') ||
            activity.metadata?.comment) {
            if (activity.actor && (activity.actor.avatar_url || (activity.actor.name && activity.actor.name !== 'System'))) {
                return (
                    <div className="flex-shrink-0 relative z-10">
                        <Avatar
                            avatarUrl={activity.actor?.avatar_url}
                            firstName={activity.actor?.name?.split(' ')[0] || ''}
                            lastName={activity.actor?.name?.split(' ')[1] || ''}
                            email={activity.actor?.email}
                            size="md"
                        />
                    </div>
                )
            }
        }

        // Default: grey dot
        return (
            <div className="flex-shrink-0 h-2 w-2 rounded-full bg-gray-400 mt-3 relative z-10"></div>
        )
    }

    // Check if activity is a comment
    const isComment = (activity) => {
        return activity.event_type?.toLowerCase().includes('comment') ||
               activity.description?.toLowerCase().includes('commented') ||
               activity.metadata?.comment ||
               activity.metadata?.has_comment
    }

    // Format activity description into human-readable language
    const formatActivityDescription = (activity) => {
        const eventType = activity.event_type || ''
        const metadata = activity.metadata || {}
        const tenant = activity.tenant
        const brand = activity.brand
        
        // Helper to get subject name
        const getSubjectName = () => {
            if (tenant) return tenant
            if (brand) return brand
            if (metadata.subject_name) return metadata.subject_name
            if (activity.subject?.name) return activity.subject.name
            return null
        }

        const subjectName = getSubjectName()
        
        // Map event types to human-readable descriptions
        const descriptions = {
            'tenant.updated': subjectName ? `${subjectName} was updated` : 'Company was updated',
            'tenant.created': subjectName ? `${subjectName} was created` : 'Company was created',
            'tenant.deleted': subjectName ? `${subjectName} was deleted` : 'Company was deleted',
            'brand.created': subjectName ? `${subjectName} was created` : 'Brand was created',
            'brand.updated': subjectName ? `${subjectName} was updated` : 'Brand was updated',
            'brand.deleted': subjectName ? `${subjectName} was deleted` : 'Brand was deleted',
            'user.created': 'User account was created',
            'user.updated': metadata.action === 'suspended' ? 'Account was suspended' :
                           metadata.action === 'unsuspended' ? 'Account was unsuspended' :
                           'User account was updated',
            'user.deleted': 'User account was deleted',
            'user.invited': 'User was invited',
            'user.added_to_company': 'User was added to company',
            'user.removed_from_company': 'User was removed from company',
            'user.added_to_brand': metadata.role ? `Added to brand as ${metadata.role}` : 'User was added to brand',
            'user.removed_from_brand': 'User was removed from brand',
            'user.role_updated': metadata.old_role && metadata.new_role 
                ? `Role changed from ${metadata.old_role} to ${metadata.new_role}`
                : 'Role was updated',
            'category.created': subjectName ? `${subjectName} category was created` : 'Category was created',
            'category.updated': subjectName ? `${subjectName} category was updated` : 'Category was updated',
            'category.deleted': subjectName ? `${subjectName} category was deleted` : 'Category was deleted',
            'plan.updated': metadata.old_plan && metadata.new_plan 
                ? `Plan changed from ${metadata.old_plan} to ${metadata.new_plan}`
                : 'Plan was updated',
        }

        // Return formatted description or fallback to existing description/event_type
        return descriptions[eventType] || activity.description || eventType
            .split('.')
            .map(word => word.charAt(0).toUpperCase() + word.slice(1))
            .join(' ')
    }

    return (
        <div className="bg-white rounded-lg shadow-sm ring-1 ring-gray-200 overflow-hidden">
            {/* Header - White background aligned with timeline dots */}
            <div className="px-6 py-4 border-b border-gray-200 bg-white">
                <div className="flex items-center">
                    {/* Spacer to align with timeline dots (left-4 = 16px) */}
                    <div className="flex-shrink-0 w-4"></div>
                    <h3 className="text-lg font-semibold text-gray-900">Activity</h3>
                </div>
            </div>

            {/* Activity Timeline */}
            <div className="px-6 py-4 max-h-[600px] overflow-y-auto">
                <div className="relative">
                    {/* Timeline line - positioned at left-4 (16px) to align with dots */}
                    <div className="absolute left-4 top-0 bottom-0 w-0.5 bg-gray-200"></div>
                    
                    {/* Activities */}
                    <div className="space-y-6">
                        {activities && activities.length > 0 ? (
                            activities.map((activity, index) => {
                                const isCommentActivity = isComment(activity)
                                
                                return (
                                    <div key={activity.id || index} className="relative flex items-start gap-4">
                                        {/* Timeline indicator - positioned at left-4 (16px) */}
                                        <div className="flex-shrink-0 relative z-10" style={{ marginTop: isCommentActivity ? '0' : '8px', marginLeft: '0' }}>
                                            {getActivityIndicator(activity)}
                                        </div>
                                        
                                        {/* Activity content */}
                                        <div className="flex-1 min-w-0 pb-6">
                                            {isCommentActivity ? (
                                                // Comment style
                                                <div className="bg-gray-50 rounded-lg p-4 border border-gray-200">
                                                    <div className="flex items-center gap-2 mb-2">
                                                        <span className="text-sm font-semibold text-gray-900">
                                                            {activity.actor?.name || 'System'}
                                                        </span>
                                                        <span className="text-xs text-gray-500">commented</span>
                                                    </div>
                                                    <p className="text-sm text-gray-700 whitespace-pre-wrap">
                                                        {activity.metadata?.comment || activity.description || 'No comment text'}
                                                    </p>
                                                    <div className="mt-2 flex items-center justify-between">
                                                        <span className="text-xs text-gray-500">{activity.created_at}</span>
                                                        {activity.tenant && (
                                                            <span className="text-xs text-gray-400">{activity.tenant}</span>
                                                        )}
                                                    </div>
                                                </div>
                                            ) : (
                                                // Regular activity style
                                                <div>
                                                    <div className="flex items-center gap-2 flex-wrap">
                                                        <span className="text-sm font-semibold text-gray-900">
                                                            {activity.actor?.name || 'System'}
                                                        </span>
                                                        <span className="text-sm text-gray-600">
                                                            {activity.description || formatActivityDescription(activity)}
                                                        </span>
                                                    </div>
                                                    <div className="mt-1 flex items-center gap-3 flex-wrap text-xs text-gray-500">
                                                        <span>{activity.created_at || activity.created_at_human}</span>
                                                        {activity.tenant && activity.tenant !== (activity.brand ? activity.brand : null) && (
                                                            <>
                                                                <span>•</span>
                                                                <span>{activity.tenant}</span>
                                                            </>
                                                        )}
                                                        {activity.brand && (
                                                            <>
                                                                <span>•</span>
                                                                <span>{activity.brand}</span>
                                                            </>
                                                        )}
                                                    </div>
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                )
                            })
                        ) : (
                            <div className="text-center py-8 text-sm text-gray-500">
                                No activity to display
                            </div>
                        )}
                    </div>
                </div>
            </div>

            {/* Comment Input Section (if enabled) */}
            {onAddComment && currentUser && (
                <div className="border-t border-gray-200 px-6 py-4 bg-gray-50">
                    <form onSubmit={handleSubmitComment} className="flex items-start gap-3">
                        <div className="flex-shrink-0">
                            <Avatar
                                avatarUrl={currentUser.avatar_url}
                                firstName={currentUser.first_name}
                                lastName={currentUser.last_name}
                                email={currentUser.email}
                                size="md"
                            />
                        </div>
                        <div className="flex-1">
                            <textarea
                                value={commentText}
                                onChange={(e) => setCommentText(e.target.value)}
                                placeholder="Add your comment..."
                                rows={3}
                                className="block w-full rounded-md border-0 py-2 px-3 text-sm text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 resize-none"
                            />
                            <div className="mt-2 flex items-center justify-between">
                                <div className="flex items-center gap-3">
                                    {/* Attachment button */}
                                    <button
                                        type="button"
                                        className="text-gray-400 hover:text-gray-600"
                                        title="Attach file"
                                    >
                                        <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M18.375 12.739l-7.693 7.693a4.5 4.5 0 01-6.364-6.364l10.94-10.94A3 3 0 1119.5 7.372L8.552 18.32m.009-.01l-.01.01m5.699-9.941l-7.81 7.81a1.5 1.5 0 002.112 2.13" />
                                        </svg>
                                    </button>
                                    {/* Emoji button */}
                                    <button
                                        type="button"
                                        className="text-gray-400 hover:text-gray-600"
                                        title="Add emoji"
                                    >
                                        <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M15.182 15.182a4.5 4.5 0 01-6.364 0M21 12a9 9 0 11-18 0 9 9 0 0118 0zM9.75 9.75c0 .414-.168.75-.375.75S9 10.164 9 9.75 9.168 9 9.375 9s.375.336.375.75zm-.375 0h.008v.015h-.008V9.75zm5.625 0c0 .414-.168.75-.375.75s-.375-.336-.375-.75.168-.75.375-.75.375.336.375.75zm-.375 0h.008v.015h-.008V9.75z" />
                                        </svg>
                                    </button>
                                </div>
                                <button
                                    type="submit"
                                    disabled={!commentText.trim() || isSubmitting}
                                    className="rounded-md px-4 py-2 text-sm font-semibold text-white shadow-sm hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 disabled:opacity-50 disabled:cursor-not-allowed transition-opacity"
                                    style={{
                                        backgroundColor: primaryColor,
                                    }}
                                    onMouseEnter={(e) => e.currentTarget.style.backgroundColor = secondaryColor}
                                    onMouseLeave={(e) => e.currentTarget.style.backgroundColor = primaryColor}
                                >
                                    {isSubmitting ? 'Posting...' : 'Comment'}
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            )}
        </div>
    )
}
