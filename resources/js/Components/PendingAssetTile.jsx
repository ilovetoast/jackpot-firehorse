/**
 * Phase J.2: Pending Asset Review Tile Component
 * 
 * Dashboard tile that displays the count of pending/rejected assets.
 * Clicking opens a modal to review and approve/reject assets.
 */

import { useState } from 'react'
import { DocumentCheckIcon } from '@heroicons/react/24/outline'
import PendingAssetReviewModal from './PendingAssetReviewModal'

export default function PendingAssetTile({ pendingCount = 0 }) {
    const [isModalOpen, setIsModalOpen] = useState(false)

    // Only show tile for approvers (Owner/Admin/Brand Manager)
    // Contributors should never see this tile
    if (pendingCount === 0) {
        return null
    }

    return (
        <>
            <div className="overflow-hidden rounded-lg bg-white px-4 py-5 shadow sm:p-6 border border-gray-200">
                <div className="flex items-center">
                    <div className="flex-shrink-0">
                        <div className="relative">
                            <DocumentCheckIcon className="h-6 w-6 text-blue-500" aria-hidden="true" />
                            {pendingCount > 0 && (
                                <span className="absolute -top-1 -right-1 flex h-4 w-4 items-center justify-center rounded-full bg-red-500 text-xs font-bold text-white">
                                    {pendingCount > 99 ? '99+' : pendingCount}
                                </span>
                            )}
                        </div>
                    </div>
                    <div className="ml-5 w-0 flex-1">
                        <dt className="text-sm font-medium text-gray-500 truncate">
                            Pending Publication
                        </dt>
                        <dd className="mt-1">
                            <div className="flex items-baseline">
                                <span className="text-2xl font-semibold tracking-tight text-gray-900">
                                    {pendingCount.toLocaleString()}
                                </span>
                            </div>
                            <p className="mt-1 text-xs text-gray-500">
                                Review contributor-submitted assets
                            </p>
                        </dd>
                    </div>
                </div>
                <div className="mt-4">
                    <button
                        onClick={() => setIsModalOpen(true)}
                        className="w-full inline-flex items-center justify-center px-4 py-2 text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition-colors"
                    >
                        <DocumentCheckIcon className="h-4 w-4 mr-2" />
                        Review Assets
                    </button>
                </div>
            </div>

            {isModalOpen && (
                <PendingAssetReviewModal
                    isOpen={isModalOpen}
                    onClose={() => setIsModalOpen(false)}
                />
            )}
        </>
    )
}
