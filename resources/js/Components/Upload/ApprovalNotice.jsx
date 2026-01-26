/**
 * UI-only metadata approval workflow
 * Does not alter approval logic or persistence
 * 
 * Approval Notice Component
 * 
 * Shows a non-blocking informational notice when metadata fields require approval.
 * Displayed in the uploader submit panel for contributors.
 */

import { InformationCircleIcon } from '@heroicons/react/24/outline'

export default function ApprovalNotice({ approvalRequired, pendingMetadataCount }) {
    // Only show if approval is required and there are pending fields
    if (!approvalRequired || pendingMetadataCount === 0) {
        return null
    }

    return (
        <div className="mb-4 rounded-md bg-blue-50 border border-blue-200 p-3">
            <div className="flex">
                <div className="flex-shrink-0">
                    <InformationCircleIcon className="h-5 w-5 text-blue-400" />
                </div>
                <div className="ml-3">
                    <p className="text-sm text-blue-800">
                        Some metadata fields will require review before becoming visible to others.
                    </p>
                </div>
            </div>
        </div>
    )
}
