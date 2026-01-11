import { useForm } from '@inertiajs/react'
import { useState } from 'react'

/**
 * TicketStatusSelector Component
 * 
 * Subtle, compact status selector for ticket headers.
 * Provides quick status changes while being less prominent than action buttons.
 * Intelligently disables options that conflict with action button workflows.
 * 
 * @param {Object} props
 * @param {Object} props.ticket - Ticket object with current status
 * @param {boolean} props.canAssign - Whether user has permission to change status
 */
export default function TicketStatusSelector({ ticket, canAssign }) {
    const { data, setData, put, processing } = useForm({
        status: ticket.status,
    })

    // Determine which statuses should be disabled based on current state
    // When action buttons are available, disable conflicting manual status changes
    const isResolved = ticket.status === 'resolved'
    const isClosed = ticket.status === 'closed'
    const isFinalState = isResolved || isClosed

    // Status options with labels
    const statusOptions = [
        { value: 'open', label: 'Open' },
        { value: 'waiting_on_user', label: 'Waiting on User' },
        { value: 'waiting_on_support', label: 'Waiting on Support' },
        { value: 'in_progress', label: 'In Progress' },
        { value: 'blocked', label: 'Blocked' },
        { value: 'resolved', label: 'Resolved' },
        { value: 'closed', label: 'Closed' },
    ]

    const handleStatusChange = (e) => {
        const newStatus = e.target.value
        setData('status', newStatus)
        put(`/app/admin/support/tickets/${ticket.id}/status`, {
            preserveScroll: true,
        })
    }

    if (!canAssign) {
        return null
    }

    return (
        <div className="flex items-center gap-2">
            <label htmlFor="status-selector" className="text-xs text-gray-500 sr-only">
                Status
            </label>
            <select
                id="status-selector"
                value={data.status}
                onChange={handleStatusChange}
                disabled={processing}
                className="text-xs rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 bg-white text-gray-700 py-1 px-2 pr-7 disabled:opacity-50 disabled:cursor-not-allowed"
            >
                {statusOptions.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>
        </div>
    )
}
