/**
 * Pending Metadata List Component
 *
 * Phase 8: Displays pending metadata that requires approval.
 */

import { useState, useEffect } from 'react'
import { usePage } from '@inertiajs/react'
import {
    ClockIcon,
    CheckIcon,
    XMarkIcon,
    PencilIcon,
    ExclamationTriangleIcon,
} from '@heroicons/react/24/outline'
import MetadataFieldInput from './Upload/MetadataFieldInput'

export default function PendingMetadataList({ assetId }) {
    const { auth, tenant } = usePage().props
    const [pending, setPending] = useState([])
    const [loading, setLoading] = useState(true)
    const [editingMetadataId, setEditingMetadataId] = useState(null)
    const [editedValue, setEditedValue] = useState(null)
    const [processing, setProcessing] = useState(new Set())
    const [showConfirmApprove, setShowConfirmApprove] = useState(null)
    const [showConfirmReject, setShowConfirmReject] = useState(null)

    // Get user role to determine if they can approve
    // Use tenant_role from auth props (available in HandleInertiaRequests)
    const userRole = auth?.tenant_role || 'member'
    const canApprove = ['owner', 'admin', 'manager'].includes(userRole?.toLowerCase())

    // Fetch pending metadata
    useEffect(() => {
        if (!assetId) return

        setLoading(true)
        fetch(`/app/assets/${assetId}/metadata/pending`, {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
            },
            credentials: 'same-origin',
        })
            .then((res) => res.json())
            .then((data) => {
                setPending(data.pending || [])
                setLoading(false)
            })
            .catch((err) => {
                console.error('[PendingMetadataList] Failed to fetch pending metadata', err)
                setLoading(false)
            })
    }, [assetId])

    // Refresh pending metadata after actions
    const refreshPending = () => {
        if (!assetId) return
        fetch(`/app/assets/${assetId}/metadata/pending`, {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
            },
            credentials: 'same-origin',
        })
            .then((res) => res.json())
            .then((data) => {
                setPending(data.pending || [])
            })
            .catch((err) => {
                console.error('[PendingMetadataList] Failed to refresh pending metadata', err)
            })
    }

    // Handle approve
    const handleApprove = async (metadataId) => {
        if (processing.has(metadataId)) return

        setProcessing((prev) => new Set(prev).add(metadataId))
        setShowConfirmApprove(null)

        try {
            const response = await fetch(`/app/metadata/${metadataId}/approve`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                },
                credentials: 'same-origin',
            })

            if (!response.ok) {
                const data = await response.json()
                throw new Error(data.message || 'Failed to approve metadata')
            }

            // Refresh pending metadata and approved metadata
            refreshPending()
            // Trigger refresh of approved metadata display
            window.dispatchEvent(new CustomEvent('metadata-updated'))
        } catch (error) {
            console.error('[PendingMetadataList] Failed to approve', error)
            alert(error.message || 'Failed to approve metadata')
        } finally {
            setProcessing((prev) => {
                const next = new Set(prev)
                next.delete(metadataId)
                return next
            })
        }
    }

    // Handle edit & approve
    const handleEditAndApprove = async (metadataId, field) => {
        if (processing.has(metadataId)) return

        // Validate edited value
        if (editedValue === null || editedValue === undefined) {
            alert('Please enter a value')
            return
        }

        // For multiselect, ensure at least one value
        if (field.field_type === 'multiselect' && (!Array.isArray(editedValue) || editedValue.length === 0)) {
            alert('Please select at least one option')
            return
        }

        setProcessing((prev) => new Set(prev).add(metadataId))

        try {
            const response = await fetch(`/app/metadata/${metadataId}/edit-approve`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    value: editedValue,
                }),
            })

            if (!response.ok) {
                const data = await response.json()
                throw new Error(data.message || 'Failed to edit and approve metadata')
            }

            // Close edit modal
            setEditingMetadataId(null)
            setEditedValue(null)

            // Refresh pending metadata and approved metadata
            refreshPending()
            window.dispatchEvent(new CustomEvent('metadata-updated'))
        } catch (error) {
            console.error('[PendingMetadataList] Failed to edit and approve', error)
            alert(error.message || 'Failed to edit and approve metadata')
        } finally {
            setProcessing((prev) => {
                const next = new Set(prev)
                next.delete(metadataId)
                return next
            })
        }
    }

    // Handle reject
    const handleReject = async (metadataId) => {
        if (processing.has(metadataId)) return

        setProcessing((prev) => new Set(prev).add(metadataId))
        setShowConfirmReject(null)

        try {
            const response = await fetch(`/app/metadata/${metadataId}/reject`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                },
                credentials: 'same-origin',
            })

            if (!response.ok) {
                const data = await response.json()
                throw new Error(data.message || 'Failed to reject metadata')
            }

            // Refresh pending metadata
            refreshPending()
        } catch (error) {
            console.error('[PendingMetadataList] Failed to reject', error)
            alert(error.message || 'Failed to reject metadata')
        } finally {
            setProcessing((prev) => {
                const next = new Set(prev)
                next.delete(metadataId)
                return next
            })
        }
    }

    // Format value for display
    const formatValue = (fieldType, value) => {
        if (value === null || value === undefined || value === '') {
            return <span className="text-gray-400 italic">Not set</span>
        }

        if (fieldType === 'multiselect' && Array.isArray(value)) {
            return value.join(', ')
        }

        if (fieldType === 'boolean') {
            return value ? 'Yes' : 'No'
        }

        if (fieldType === 'date') {
            try {
                const date = new Date(value)
                return date.toLocaleDateString()
            } catch (e) {
                return value
            }
        }

        return String(value)
    }

    // Format time ago
    const formatTimeAgo = (timestamp) => {
        if (!timestamp) return 'Unknown time'
        const date = new Date(timestamp)
        const now = new Date()
        const diffMs = now - date
        const diffMins = Math.floor(diffMs / 60000)
        const diffHours = Math.floor(diffMs / 3600000)
        const diffDays = Math.floor(diffMs / 86400000)

        if (diffMins < 1) return 'Just now'
        if (diffMins < 60) return `${diffMins} minute${diffMins !== 1 ? 's' : ''} ago`
        if (diffHours < 24) return `${diffHours} hour${diffHours !== 1 ? 's' : ''} ago`
        return `${diffDays} day${diffDays !== 1 ? 's' : ''} ago`
    }

    if (loading) {
        return (
            <div className="px-6 py-4 border-t border-gray-200">
                <div className="text-sm text-gray-500">Loading pending metadata...</div>
            </div>
        )
    }

    if (pending.length === 0) {
        return null // Hide if no pending metadata
    }

    return (
        <>
            <div className="px-6 py-4 border-t border-gray-200">
                <h3 className="text-sm font-semibold text-gray-900 mb-4 flex items-center">
                    <ClockIcon className="h-4 w-4 mr-2 text-yellow-500" />
                    Pending Metadata
                </h3>
                <div className="space-y-4">
                    {pending.map((item) => (
                        <div key={item.field_id} className="border border-yellow-200 rounded-lg p-4 bg-yellow-50">
                            <div className="flex items-start justify-between mb-2">
                                <div className="flex-1">
                                    <dt className="text-sm font-medium text-gray-900 mb-1">
                                        {item.field_label}
                                    </dt>
                                    {item.values.map((valueItem, idx) => (
                                        <dd key={idx} className="text-sm text-gray-700 mb-2">
                                            <div className="flex items-center gap-2">
                                                <span>{formatValue(item.field_type, valueItem.value)}</span>
                                                <span
                                                    className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${
                                                        valueItem.source === 'ai'
                                                            ? 'bg-purple-100 text-purple-800'
                                                            : 'bg-blue-100 text-blue-800'
                                                    }`}
                                                >
                                                    {valueItem.source === 'ai' ? 'AI' : 'User'}
                                                </span>
                                                {valueItem.confidence && (
                                                    <span className="text-xs text-gray-500">
                                                        ({Math.round(valueItem.confidence * 100)}% confidence)
                                                    </span>
                                                )}
                                            </div>
                                            <div className="text-xs text-gray-500 mt-1">
                                                Proposed {formatTimeAgo(valueItem.created_at)}
                                            </div>
                                        </dd>
                                    ))}
                                </div>
                            </div>

                            {canApprove && item.can_approve ? (
                                <div className="flex items-center gap-2 mt-3">
                                    <button
                                        type="button"
                                        onClick={() => setShowConfirmApprove(item.values[0]?.id)}
                                        disabled={processing.has(item.values[0]?.id)}
                                        className="inline-flex items-center px-3 py-1.5 text-xs font-medium text-white bg-green-600 hover:bg-green-700 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed"
                                    >
                                        <CheckIcon className="h-3 w-3 mr-1" />
                                        Approve
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setEditingMetadataId(item.values[0]?.id)
                                            setEditedValue(item.values[0]?.value)
                                        }}
                                        disabled={processing.has(item.values[0]?.id)}
                                        className="inline-flex items-center px-3 py-1.5 text-xs font-medium text-indigo-600 bg-indigo-50 hover:bg-indigo-100 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed"
                                    >
                                        <PencilIcon className="h-3 w-3 mr-1" />
                                        Edit & Approve
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => setShowConfirmReject(item.values[0]?.id)}
                                        disabled={processing.has(item.values[0]?.id)}
                                        className="inline-flex items-center px-3 py-1.5 text-xs font-medium text-red-600 bg-red-50 hover:bg-red-100 rounded-md focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed"
                                    >
                                        <XMarkIcon className="h-3 w-3 mr-1" />
                                        Reject
                                    </button>
                                </div>
                            ) : (
                                <div className="mt-3 text-xs text-gray-500 flex items-center">
                                    <ExclamationTriangleIcon className="h-4 w-4 mr-1" />
                                    Awaiting approval
                                </div>
                            )}
                        </div>
                    ))}
                </div>
            </div>

            {/* Edit & Approve Modal */}
            {editingMetadataId && (() => {
                const item = pending.find((p) => p.values[0]?.id === editingMetadataId)
                if (!item) return null
                
                return (
                    <EditApproveModal
                        metadataId={editingMetadataId}
                        field={item}
                        value={editedValue}
                        onChange={setEditedValue}
                        onSave={() => {
                            handleEditAndApprove(editingMetadataId, item)
                        }}
                        onClose={() => {
                            setEditingMetadataId(null)
                            setEditedValue(null)
                        }}
                        processing={processing.has(editingMetadataId)}
                    />
                )
            })()}

            {/* Confirm Approve Modal */}
            {showConfirmApprove && (
                <ConfirmModal
                    title="Approve Metadata"
                    message="Are you sure you want to approve this metadata value? It will become active immediately."
                    confirmText="Approve"
                    confirmClass="bg-green-600 hover:bg-green-700"
                    onConfirm={() => handleApprove(showConfirmApprove)}
                    onCancel={() => setShowConfirmApprove(null)}
                    processing={processing.has(showConfirmApprove)}
                />
            )}

            {/* Confirm Reject Modal */}
            {showConfirmReject && (
                <ConfirmModal
                    title="Reject Metadata"
                    message="Are you sure you want to reject this metadata value? This action cannot be undone."
                    confirmText="Reject"
                    confirmClass="bg-red-600 hover:bg-red-700"
                    onConfirm={() => handleReject(showConfirmReject)}
                    onCancel={() => setShowConfirmReject(null)}
                    processing={processing.has(showConfirmReject)}
                />
            )}
        </>
    )
}

// Edit & Approve Modal Component
function EditApproveModal({ metadataId, field, value, onChange, onSave, onClose, processing }) {
    return (
        <>
            {/* Backdrop */}
            <div
                className="fixed inset-0 bg-black/50 z-50 transition-opacity"
                onClick={onClose}
                aria-hidden="true"
            />

            {/* Modal */}
            <div
                className="fixed inset-0 z-50 flex items-center justify-center p-4"
                onClick={(e) => e.stopPropagation()}
            >
                <div className="bg-white rounded-lg shadow-xl max-w-md w-full max-h-[90vh] overflow-y-auto">
                    <div className="p-6">
                        <h3 className="text-lg font-semibold text-gray-900 mb-4">
                            Edit & Approve: {field.field_label}
                        </h3>

                        <div className="mb-4">
                            <MetadataFieldInput
                                field={{
                                    key: field.field_key,
                                    display_label: field.field_label,
                                    type: field.field_type,
                                    options: field.options || [], // Use field options if available
                                    can_edit: true,
                                }}
                                value={value}
                                onChange={onChange}
                                disabled={processing}
                                isUploadContext={false}
                            />
                        </div>

                        <div className="flex items-center justify-end gap-3">
                            <button
                                type="button"
                                onClick={onClose}
                                disabled={processing}
                                className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-50"
                            >
                                Cancel
                            </button>
                            <button
                                type="button"
                                onClick={onSave}
                                disabled={processing || !value || (Array.isArray(value) && value.length === 0)}
                                className="px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-md hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-50"
                            >
                                {processing ? 'Saving...' : 'Save & Approve'}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </>
    )
}

// Confirm Modal Component
function ConfirmModal({ title, message, confirmText, confirmClass, onConfirm, onCancel, processing }) {
    return (
        <>
            {/* Backdrop */}
            <div
                className="fixed inset-0 bg-black/50 z-50 transition-opacity"
                onClick={onCancel}
                aria-hidden="true"
            />

            {/* Modal */}
            <div
                className="fixed inset-0 z-50 flex items-center justify-center p-4"
                onClick={(e) => e.stopPropagation()}
            >
                <div className="bg-white rounded-lg shadow-xl max-w-md w-full">
                    <div className="p-6">
                        <h3 className="text-lg font-semibold text-gray-900 mb-2">{title}</h3>
                        <p className="text-sm text-gray-600 mb-6">{message}</p>

                        <div className="flex items-center justify-end gap-3">
                            <button
                                type="button"
                                onClick={onCancel}
                                disabled={processing}
                                className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-50"
                            >
                                Cancel
                            </button>
                            <button
                                type="button"
                                onClick={onConfirm}
                                disabled={processing}
                                className={`px-4 py-2 text-sm font-medium text-white rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:opacity-50 ${confirmClass}`}
                            >
                                {processing ? 'Processing...' : confirmText}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </>
    )
}
