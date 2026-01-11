import { router } from '@inertiajs/react'
import { useState } from 'react'
import {
    CheckCircleIcon,
    XCircleIcon,
    ArrowPathIcon,
    ArrowsRightLeftIcon,
} from '@heroicons/react/24/outline'
import ConfirmDialog from '../ConfirmDialog'

export default function TicketActionsToolbar({ ticket, permissions, onConvert }) {
    const [processing, setProcessing] = useState(false)
    const [action, setAction] = useState(null)
    const [showResolveConfirm, setShowResolveConfirm] = useState(false)
    const [showCloseConfirm, setShowCloseConfirm] = useState(false)
    const [showReopenConfirm, setShowReopenConfirm] = useState(false)

    const handleResolveClick = () => {
        setShowResolveConfirm(true)
    }

    const confirmResolve = () => {
        setShowResolveConfirm(false)
        setProcessing(true)
        setAction('resolve')
        router.put(`/app/admin/support/tickets/${ticket.id}/resolve`, {}, {
            preserveScroll: true,
            onFinish: () => {
                setProcessing(false)
                setAction(null)
            },
        })
    }

    const handleCloseClick = () => {
        setShowCloseConfirm(true)
    }

    const confirmClose = () => {
        setShowCloseConfirm(false)
        setProcessing(true)
        setAction('close')
        router.put(`/app/admin/support/tickets/${ticket.id}/close`, {}, {
            preserveScroll: true,
            onFinish: () => {
                setProcessing(false)
                setAction(null)
            },
        })
    }

    const handleReopenClick = () => {
        setShowReopenConfirm(true)
    }

    const confirmReopen = () => {
        setShowReopenConfirm(false)
        setProcessing(true)
        setAction('reopen')
        router.put(`/app/admin/support/tickets/${ticket.id}/reopen`, {}, {
            preserveScroll: true,
            onFinish: () => {
                setProcessing(false)
                setAction(null)
            },
        })
    }

    // Determine which actions are available based on current status
    const isResolved = ticket.status === 'resolved'
    const isClosed = ticket.status === 'closed'
    const isFinalState = isResolved || isClosed
    const canResolve = !isFinalState && permissions?.canAssign
    const canClose = !isClosed && permissions?.canAssign
    const canReopen = isFinalState && permissions?.canAssign
    const canConvert = permissions?.canConvert && ticket.type === 'tenant'

    // Don't render if no actions are available
    if (!canResolve && !canClose && !canReopen && !canConvert) {
        return null
    }

    return (
        <div className="bg-white shadow-sm ring-1 ring-gray-200 rounded-lg overflow-hidden mb-6">
            <div className="px-6 py-4 border-b border-gray-200">
                <h3 className="text-lg font-semibold text-gray-900">Actions</h3>
            </div>
            <div className="px-6 py-4">
                <div className="flex flex-wrap items-center gap-3">
                    {canResolve && (
                        <button
                            onClick={handleResolveClick}
                            disabled={processing}
                            className="inline-flex items-center gap-2 rounded-md bg-green-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-green-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-green-600 disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            <CheckCircleIcon className="h-5 w-5" />
                            {processing && action === 'resolve' ? 'Resolving...' : 'Resolve'}
                        </button>
                    )}
                    {canClose && (
                        <button
                            onClick={handleCloseClick}
                            disabled={processing}
                            className="inline-flex items-center gap-2 rounded-md bg-gray-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-gray-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gray-600 disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            <XCircleIcon className="h-5 w-5" />
                            {processing && action === 'close' ? 'Closing...' : 'Close'}
                        </button>
                    )}
                    {canReopen && (
                        <button
                            onClick={handleReopenClick}
                            disabled={processing}
                            className="inline-flex items-center gap-2 rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            <ArrowPathIcon className="h-5 w-5" />
                            {processing && action === 'reopen' ? 'Reopening...' : 'Reopen'}
                        </button>
                    )}
                    {canConvert && (
                        <button
                            onClick={onConvert}
                            disabled={processing}
                            className="inline-flex items-center gap-2 rounded-md bg-purple-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-purple-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-purple-600 disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            <ArrowsRightLeftIcon className="h-5 w-5" />
                            Convert to Internal
                        </button>
                    )}
                </div>
                <p className="mt-3 text-xs text-gray-500">
                    {isFinalState 
                        ? 'This ticket is in a final state. Use Reopen to change the status back to open.'
                        : 'Resolve marks the ticket as completed and records resolution time for SLA tracking. Close marks the ticket as permanently closed.'}
                </p>
            </div>

            {/* Confirmation Dialogs */}
            <ConfirmDialog
                open={showResolveConfirm}
                onClose={() => setShowResolveConfirm(false)}
                onConfirm={confirmResolve}
                title="Resolve Ticket"
                message="Mark this ticket as resolved? The resolution time will be recorded for SLA tracking."
                variant="info"
                confirmText="Resolve"
                loading={processing && action === 'resolve'}
            />
            <ConfirmDialog
                open={showCloseConfirm}
                onClose={() => setShowCloseConfirm(false)}
                onConfirm={confirmClose}
                title="Close Ticket"
                message="Close this ticket? Closed tickets are in a final state and typically cannot be reopened without admin intervention."
                variant="warning"
                confirmText="Close"
                loading={processing && action === 'close'}
            />
            <ConfirmDialog
                open={showReopenConfirm}
                onClose={() => setShowReopenConfirm(false)}
                onConfirm={confirmReopen}
                title="Reopen Ticket"
                message="Reopen this ticket? This will change the status back to open."
                variant="info"
                confirmText="Reopen"
                loading={processing && action === 'reopen'}
            />
        </div>
    )
}
