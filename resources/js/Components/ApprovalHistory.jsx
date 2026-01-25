import { useState, useEffect } from 'react'
import { usePage } from '@inertiajs/react'
import { ClockIcon, CheckCircleIcon, XCircleIcon, ArrowPathIcon, ChatBubbleLeftRightIcon } from '@heroicons/react/24/outline'

/**
 * Phase AF-2: Approval History Component
 * 
 * Displays timeline of approval actions and comments.
 * Shows: Submitted, Rejected, Resubmitted, Approved, Comments
 */
export default function ApprovalHistory({ asset, brand }) {
    const { auth } = usePage().props
    const [comments, setComments] = useState([])
    const [loading, setLoading] = useState(false)
    const [error, setError] = useState(null)

    useEffect(() => {
        if (!asset?.id || !brand?.id) {
            return
        }

        setLoading(true)
        fetch(`/app/brands/${brand.id}/assets/${asset.id}/approval-history`)
            .then(res => {
                if (!res.ok) {
                    throw new Error('Failed to load approval history')
                }
                return res.json()
            })
            .then(data => {
                setComments(data.comments || [])
                setLoading(false)
            })
            .catch(err => {
                console.error('Failed to load approval history:', err)
                setError(err.message)
                setLoading(false)
            })
    }, [asset?.id, brand?.id])

    const getActionIcon = (action) => {
        switch (action) {
            case 'submitted':
                return <ClockIcon className="h-5 w-5 text-blue-500" />
            case 'approved':
                return <CheckCircleIcon className="h-5 w-5 text-green-500" />
            case 'rejected':
                return <XCircleIcon className="h-5 w-5 text-red-500" />
            case 'resubmitted':
                return <ArrowPathIcon className="h-5 w-5 text-yellow-500" />
            case 'comment':
                return <ChatBubbleLeftRightIcon className="h-5 w-5 text-gray-500" />
            default:
                return <ClockIcon className="h-5 w-5 text-gray-400" />
        }
    }

    const getActionColor = (action) => {
        switch (action) {
            case 'submitted':
                return 'bg-blue-50 text-blue-700 border-blue-200'
            case 'approved':
                return 'bg-green-50 text-green-700 border-green-200'
            case 'rejected':
                return 'bg-red-50 text-red-700 border-red-200'
            case 'resubmitted':
                return 'bg-yellow-50 text-yellow-700 border-yellow-200'
            case 'comment':
                return 'bg-gray-50 text-gray-700 border-gray-200'
            default:
                return 'bg-gray-50 text-gray-700 border-gray-200'
        }
    }

    const formatDate = (dateString) => {
        if (!dateString) return 'N/A'
        return new Date(dateString).toLocaleString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        })
    }

    if (loading) {
        return (
            <div className="text-sm text-gray-500 py-2">Loading approval history...</div>
        )
    }

    if (error) {
        return (
            <div className="text-sm text-red-600 py-2">Failed to load approval history: {error}</div>
        )
    }

    if (comments.length === 0) {
        return (
            <div className="text-sm text-gray-500 py-2">No approval history available.</div>
        )
    }

    return (
        <div className="space-y-3">
            {comments.map((comment, index) => (
                <div key={comment.id} className="flex gap-3">
                    <div className="flex-shrink-0 mt-1">
                        {getActionIcon(comment.action)}
                    </div>
                    <div className="flex-1 min-w-0">
                        <div className="flex items-center gap-2 mb-1">
                            <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium border ${getActionColor(comment.action)}`}>
                                {comment.action_label}
                            </span>
                            <span className="text-xs text-gray-500">
                                {formatDate(comment.created_at)}
                            </span>
                        </div>
                        {comment.user && (
                            <div className="text-xs text-gray-600 mb-1">
                                by {comment.user.name || comment.user.email || 'Unknown'}
                            </div>
                        )}
                        {comment.comment && (
                            <div className="text-sm text-gray-700 mt-1 p-2 bg-gray-50 rounded border border-gray-200">
                                {comment.comment}
                            </div>
                        )}
                    </div>
                </div>
            ))}
        </div>
    )
}
