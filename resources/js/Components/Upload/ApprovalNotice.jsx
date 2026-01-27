/**
 * UI-only approval workflow notice
 * Does not alter approval logic or persistence
 * 
 * Approval Notice Component
 * 
 * Shows a non-blocking informational notice when:
 * - Asset approval is required (contributor uploads need approval)
 * - OR metadata approval is required (metadata fields need approval)
 * 
 * Displayed in the uploader submit panel for contributors only.
 */

import { InformationCircleIcon } from '@heroicons/react/24/outline'

export default function ApprovalNotice({ 
    assetApprovalRequired = false, 
    metadataApprovalRequired = false, 
    pendingMetadataCount = 0 
}) {
    // Show if asset approval is required OR metadata approval is required
    const shouldShow = assetApprovalRequired || (metadataApprovalRequired && pendingMetadataCount > 0)
    
    if (!shouldShow) {
        return null
    }

    // Determine the message based on what requires approval
    let message = ''
    if (assetApprovalRequired && metadataApprovalRequired && pendingMetadataCount > 0) {
        message = 'This asset and some metadata fields will be reviewed by a brand manager before they\'re published and visible to others.'
    } else if (assetApprovalRequired) {
        message = 'This asset will be reviewed by a brand manager before it\'s published and visible to others.'
    } else if (metadataApprovalRequired && pendingMetadataCount > 0) {
        message = 'Some metadata fields will require review before becoming visible to others.'
    }

    return (
        <div className="mb-4 rounded-md bg-blue-50 border border-blue-200 p-3">
            <div className="flex">
                <div className="flex-shrink-0">
                    <InformationCircleIcon className="h-5 w-5 text-blue-400" />
                </div>
                <div className="ml-3">
                    <p className="text-sm font-medium text-blue-900 mb-0.5">
                        Approval required
                    </p>
                    <p className="text-sm text-blue-800">
                        {message}
                    </p>
                </div>
            </div>
        </div>
    )
}
