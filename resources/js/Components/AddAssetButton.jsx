// Phase 2 verification UI — NOT final UX
// Safe to refactor or remove in Phase 3
// See docs/UPLOAD_AND_QUEUE.md for upload pipeline status

import { usePage } from '@inertiajs/react'
import { usePermission } from '../hooks/usePermission'
import { DELIVERABLES_PAGE_LABEL_SINGULAR } from '../utils/uiLabels'
import {
    getWorkspacePrimaryActionButtonColors,
    getSolidFillButtonForegroundHex,
} from '../utils/colorUtils'

/**
 * AddAssetButton - Button to trigger upload dialog (gated by permissions)
 * 
 * BUGFIX: Dialog ownership lifted to page level to prevent duplicate renders.
 * This component is now a trigger-only button.
 * 
 * @param {string} defaultAssetType - Default asset type ('asset' or 'deliverable')
 * @param {string} buttonText - Optional button text override
 * @param {string} className - Optional additional CSS classes
 * @param {function} onClick - Callback when button is clicked (should open dialog)
 */
export default function AddAssetButton({ 
    defaultAssetType = 'asset', 
    buttonText = null,
    className = '',
    onClick = null,
    disabled = false
}) {
    const { auth } = usePage().props
    const { can } = usePermission()
    const canUpload = can('asset.upload')
    
    // Hide button if user doesn't have upload permission
    if (!canUpload) {
        return null
    }
    
    const defaultText = defaultAssetType === 'asset' 
        ? 'Add Asset' 
        : `Add ${DELIVERABLES_PAGE_LABEL_SINGULAR}`

    const { resting: btnColor, hover: hoverBg } = getWorkspacePrimaryActionButtonColors(auth.activeBrand)
    // Prefer light copy on brand-orange fills when WCAG AA for large text (3:1) passes — strict
    // getContrastTextColor often picks black on saturated oranges by a small ratio margin.
    const labelColor = getSolidFillButtonForegroundHex(btnColor)
    const hoverLabelColor = getSolidFillButtonForegroundHex(hoverBg)

    return (
        <button
            type="button"
            data-help="assets-upload"
            onClick={disabled ? undefined : (onClick || (() => {}))}
            disabled={disabled}
            className={`inline-flex items-center rounded-md px-4 py-2.5 text-sm font-semibold shadow-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 transition-colors ${disabled ? 'opacity-50 cursor-not-allowed' : ''} ${className}`}
            style={{
                backgroundColor: btnColor,
                color: labelColor,
            }}
            onMouseEnter={(e) => {
                if (!disabled && btnColor) {
                    e.currentTarget.style.backgroundColor = hoverBg
                    e.currentTarget.style.color = hoverLabelColor
                }
            }}
            onMouseLeave={(e) => {
                if (!disabled && btnColor) {
                    e.currentTarget.style.backgroundColor = btnColor
                    e.currentTarget.style.color = labelColor
                }
            }}
        >
            <svg className="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" d="M12 16.5V9.75m0 0l3 3m-3-3l-3 3M6.75 19.5a4.5 4.5 0 01-1.41-8.775 5.25 5.25 0 0110.233-2.33 3 3 0 013.758 3.848A3.752 3.752 0 0118 19.5H6.75z" />
            </svg>
            {buttonText || defaultText}
        </button>
    )
}
