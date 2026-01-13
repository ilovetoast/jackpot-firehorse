// Phase 2 verification UI — NOT final UX
// Safe to refactor or remove in Phase 3
// See docs/PHASE_2_UPLOAD_SYSTEM.md for Phase 2 status

import { useState, useCallback } from 'react'
import { usePage } from '@inertiajs/react'
import UploadAssetDialog from './UploadAssetDialog'

/**
 * AddAssetButton - Button to open upload dialog (gated by permissions)
 * 
 * @param {string} defaultAssetType - Default asset type ('asset' or 'marketing')
 * @param {Array} categories - Categories array from page props
 * @param {string} buttonText - Optional button text override
 * @param {number|null} initialCategoryId - Optional category ID to prepopulate in dialog
 * @param {string} className - Optional additional CSS classes
 * @param {function} onFinalizeComplete - Optional callback when finalize completes successfully
 */
export default function AddAssetButton({ 
    defaultAssetType = 'asset', 
    categories = [],
    buttonText = null,
    initialCategoryId = null,
    className = '',
    onFinalizeComplete = null
}) {
    const { auth } = usePage().props
    const [dialogOpen, setDialogOpen] = useState(false)
    
    // BUGFIX: Ensure onClose handler properly updates the open state
    const handleClose = useCallback(() => {
        setDialogOpen(false)
    }, [])
    
    const defaultText = defaultAssetType === 'asset' 
        ? 'Add Asset' 
        : 'Add Marketing Asset'

    return (
        <>
            <button
                type="button"
                onClick={() => setDialogOpen(true)}
                className={`inline-flex items-center rounded-md px-4 py-2.5 text-sm font-semibold text-white shadow-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 transition-colors ${className}`}
                style={{
                    backgroundColor: auth.activeBrand?.primary_color || '#6366f1'
                }}
                onMouseEnter={(e) => {
                    if (auth.activeBrand?.primary_color) {
                        const hex = auth.activeBrand.primary_color.replace('#', '')
                        let r = parseInt(hex.substring(0, 2), 16)
                        let g = parseInt(hex.substring(2, 4), 16)
                        let b = parseInt(hex.substring(4, 6), 16)
                        r = Math.max(0, r - 20)
                        g = Math.max(0, g - 20)
                        b = Math.max(0, b - 20)
                        e.currentTarget.style.backgroundColor = `rgb(${r}, ${g}, ${b})`
                    }
                }}
                onMouseLeave={(e) => {
                    if (auth.activeBrand?.primary_color) {
                        e.currentTarget.style.backgroundColor = auth.activeBrand.primary_color
                    }
                }}
            >
                <svg className="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" d="M12 16.5V9.75m0 0l3 3m-3-3l-3 3M6.75 19.5a4.5 4.5 0 01-1.41-8.775 5.25 5.25 0 0110.233-2.33 3 3 0 013.758 3.848A3.752 3.752 0 0118 19.5H6.75z" />
                </svg>
                {buttonText || defaultText}
            </button>

            <UploadAssetDialog
                open={dialogOpen}
                onClose={handleClose}
                defaultAssetType={defaultAssetType}
                categories={categories}
                initialCategoryId={initialCategoryId}
                onFinalizeComplete={onFinalizeComplete}
            />
        </>
    )
}
