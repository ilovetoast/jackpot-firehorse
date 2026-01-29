/**
 * Asset State Matrix
 * 
 * Canonical source of truth for asset state determination.
 * 
 * This utility encodes the business rules for:
 * - Publication state (is_published)
 * - Approval state (approval_status)
 * - Button visibility
 * - Badge labels
 * 
 * Rules:
 * - Publication is determined ONLY by is_published (published_at !== null)
 * - Approval is separate from publication
 * - Button states are derived from publication + approval + permissions
 * 
 * @module assetStateMatrix
 */

/**
 * Get asset state matrix
 * 
 * Returns canonical state information for an asset based on:
 * - is_published: boolean (from API)
 * - approval_status: string ('pending', 'approved', 'rejected', 'not_required')
 * - requires_approval: boolean (brand/category setting)
 * 
 * @param {Object} asset - Asset object
 * @param {boolean} asset.is_published - Publication state (published_at !== null)
 * @param {string} asset.approval_status - Approval status
 * @param {boolean} asset.requires_approval - Whether approval is required (optional, inferred from context)
 * @param {Object} auth - Auth object with approval_features
 * @returns {Object} State matrix with:
 *   - isPublished: boolean
 *   - approvalStatus: string
 *   - badgeLabel: string | null (badge to show, if any)
 *   - showPublishButton: boolean
 *   - showUnpublishButton: boolean
 *   - showReviewButton: boolean
 *   - showResubmitButton: boolean
 */
export function getAssetStateMatrix(asset, auth = {}) {
    if (!asset) {
        return {
            isPublished: false,
            approvalStatus: 'not_required',
            badgeLabel: null,
            showPublishButton: false,
            showUnpublishButton: false,
            showReviewButton: false,
            showResubmitButton: false,
        }
    }

    // CANONICAL RULE: Publication is determined ONLY by is_published
    const isPublished = asset.is_published === true
    
    // Approval status (separate from publication)
    const approvalStatus = asset.approval_status || 'not_required'
    const approvalsEnabled = auth?.approval_features?.approvals_enabled === true
    
    // Determine badge label
    // Priority: Archived > Expired > Unpublished > Approval states
    let badgeLabel = null
    
    if (asset.archived_at) {
        badgeLabel = 'Archived'
    } else if (asset.expires_at && new Date(asset.expires_at) < new Date()) {
        badgeLabel = 'Expired'
    } else if (!isPublished) {
        // CRITICAL: Only show "Unpublished" when is_published === false
        // Do NOT show "Unpublished" for approval states
        badgeLabel = 'Unpublished'
    } else if (approvalsEnabled && approvalStatus === 'pending') {
        badgeLabel = 'Pending Approval'
    } else if (approvalsEnabled && approvalStatus === 'rejected') {
        badgeLabel = 'Rejected'
    }
    
    // Button visibility rules
    // Note: Permission checks are handled separately in components
    // This matrix only determines state-based visibility
    
    // Publish button: Show if unpublished and not archived
    const showPublishButton = !isPublished && !asset.archived_at
    
    // Unpublish button: Show if published and not archived
    const showUnpublishButton = isPublished && !asset.archived_at
    
    // Review & Approve button: Show if pending approval and user is approver
    // (Permission check happens in component)
    const showReviewButton = approvalsEnabled && approvalStatus === 'pending'
    
    // Resubmit button: Show if rejected
    const showResubmitButton = approvalsEnabled && approvalStatus === 'rejected'
    
    return {
        isPublished,
        approvalStatus,
        badgeLabel,
        showPublishButton,
        showUnpublishButton,
        showReviewButton,
        showResubmitButton,
    }
}

/**
 * Check if asset should appear in default grid (no filters)
 * 
 * Rules:
 * - Published assets appear (is_published === true)
 * - Unpublished assets do NOT appear (is_published === false)
 * - Approval status does NOT affect visibility
 * 
 * @param {Object} asset - Asset object
 * @returns {boolean} True if asset should appear in default grid
 */
export function shouldAppearInDefaultGrid(asset) {
    if (!asset) return false
    
    // CANONICAL RULE: Visibility is determined ONLY by is_published
    return asset.is_published === true
}

/**
 * Check if asset should appear on homepage
 * 
 * Rules:
 * - Published assets appear (is_published === true)
 * - Approval status does NOT affect homepage visibility
 * 
 * @param {Object} asset - Asset object
 * @returns {boolean} True if asset should appear on homepage
 */
export function shouldAppearOnHomepage(asset) {
    if (!asset) return false
    
    // CANONICAL RULE: Homepage visibility is determined ONLY by is_published
    return asset.is_published === true
}

/**
 * Check if asset is downloadable
 * 
 * Rules:
 * - Published assets are downloadable (is_published === true)
 * - Approval status does NOT affect downloadability
 * 
 * @param {Object} asset - Asset object
 * @returns {boolean} True if asset is downloadable
 */
export function isDownloadable(asset) {
    if (!asset) return false
    
    // CANONICAL RULE: Downloadability is determined ONLY by is_published
    return asset.is_published === true
}
