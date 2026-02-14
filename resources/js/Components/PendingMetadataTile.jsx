/**
 * UI-only metadata approval workflow
 * Does not alter approval logic or persistence
 * 
 * Pending Metadata Approvals Tile Component
 * 
 * Dashboard tile that displays the count of pending metadata approvals.
 * Clicking opens a modal to review and approve/reject metadata.
 * Modeled after PendingAiSuggestionsTile pattern.
 */

import { useState } from 'react'
import { CheckCircleIcon } from '@heroicons/react/24/outline'
import PendingMetadataModal from './PendingMetadataModal'
import { usePermission } from '../hooks/usePermission'

export default function PendingMetadataTile({ pendingCount = 0 }) {
    const [isModalOpen, setIsModalOpen] = useState(false)
    const { can } = usePermission()
    const canApprove = can('metadata.bypass_approval')

    // Only show tile for users who can approve metadata
    // Contributors cannot approve, so they shouldn't see this tile
    // (They can see their own pending count in notifications instead)
    if (!canApprove || pendingCount === 0) {
        return null
    }

    return (
        <>
            <div className="overflow-hidden rounded-lg bg-white px-4 py-5 shadow sm:p-6 border border-gray-200">
                <div className="flex items-center">
                    <div className="flex-shrink-0">
                        <div className="relative">
                            <CheckCircleIcon className="h-6 w-6 text-blue-500" aria-hidden="true" />
                            {pendingCount > 0 && (
                                <span className="absolute -top-1 -right-1 flex h-4 w-4 items-center justify-center rounded-full bg-red-500 text-xs font-bold text-white">
                                    {pendingCount > 99 ? '99+' : pendingCount}
                                </span>
                            )}
                        </div>
                    </div>
                    <div className="ml-5 w-0 flex-1">
                        <dt className="text-sm font-medium text-gray-500 truncate">
                            Pending Metadata Approvals
                        </dt>
                        <dd className="mt-1">
                            <div className="flex items-baseline">
                                <span className="text-2xl font-semibold tracking-tight text-gray-900">
                                    {pendingCount.toLocaleString()}
                                </span>
                            </div>
                            <p className="mt-1 text-xs text-gray-500">
                                Review metadata fields requiring approval
                            </p>
                        </dd>
                    </div>
                </div>
                <div className="mt-4">
                    <button
                        onClick={() => setIsModalOpen(true)}
                        className="w-full inline-flex items-center justify-center px-4 py-2 text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition-colors"
                    >
                        <CheckCircleIcon className="h-4 w-4 mr-2" />
                        Review Approvals
                    </button>
                </div>
            </div>

            {isModalOpen && (
                <PendingMetadataModal
                    isOpen={isModalOpen}
                    onClose={() => setIsModalOpen(false)}
                />
            )}
        </>
    )
}
