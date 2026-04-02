/**
 * Phase B1-UI Polish: Bulk Actions modal – contextual rendering, motion, Brand Settings design language.
 * Two-step: (1) Action selection, (2) Configuration / confirmation.
 *
 * Phase B2: Expiration bulk editing
 * Phase B3: System operations
 * Phase B4: AI reprocessing (batched + throttled)
 */

import { useState, useCallback, useEffect, useMemo } from 'react'
import { usePage } from '@inertiajs/react'
import {
    XMarkIcon,
    CheckCircleIcon,
    ArchiveBoxIcon,
    ArchiveBoxArrowDownIcon,
    DocumentCheckIcon,
    DocumentDuplicateIcon,
    ClockIcon,
    NoSymbolIcon,
    TrashIcon,
    ArrowUturnLeftIcon,
    PencilSquareIcon,
    ExclamationTriangleIcon,
    PhotoIcon,
    SparklesIcon,
} from '@heroicons/react/24/outline'
import axios from 'axios'
import { usePermission } from '../hooks/usePermission'

const EASING_TOOLBAR = 'cubic-bezier(0.16, 1, 0.3, 1)'

const RENAME_ASSETS_ACTION = 'RENAME_ASSETS'

const SITE_RERUN_THUMBNAILS = 'SITE_RERUN_THUMBNAILS'
const SITE_RERUN_AI_METADATA_TAGGING = 'SITE_RERUN_AI_METADATA_TAGGING'
const SITE_PIPELINE_ACTIONS = new Set([SITE_RERUN_THUMBNAILS, SITE_RERUN_AI_METADATA_TAGGING])
const SITE_PIPELINE_ROLES = new Set(['site_owner', 'site_admin', 'site_engineering'])

/** Match upload batch naming slug (see Upload/BatchNamingBar.jsx). */
function slugifyForRename(str) {
    if (!str || typeof str !== 'string') return 'untitled'
    return str
        .toLowerCase()
        .trim()
        .replace(/[^\w\s-]/g, '')
        .replace(/[\s_-]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 100) || 'untitled'
}

function extensionFromFilename(name) {
    if (!name || typeof name !== 'string') return ''
    const lastDot = name.lastIndexOf('.')
    if (lastDot === -1 || lastDot === name.length - 1) return ''
    return name.substring(lastDot + 1).toLowerCase()
}

function getIndexPadding(count) {
    if (count <= 9) return 1
    if (count <= 99) return 2
    return 3
}

// Dynamic group config; rendering layer uses computedValidActions for contextual visibility
const BULK_ACTION_GROUPS = [
    {
        label: 'Publication',
        sectionDescription: 'Control visibility of selected assets.',
        actions: [
            { id: 'PUBLISH', label: 'Publish', helper: 'Make visible in the grid', icon: DocumentCheckIcon },
            { id: 'UNPUBLISH', label: 'Unpublish', helper: 'Hide from the grid', icon: DocumentDuplicateIcon },
        ],
    },
    {
        label: 'Archive',
        sectionDescription: 'Archive or restore selected assets.',
        actions: [
            { id: 'ARCHIVE', label: 'Archive', helper: 'Move to archive', icon: ArchiveBoxIcon },
            { id: 'RESTORE_ARCHIVE', label: 'Restore from Archive', helper: 'Make visible again', icon: ArchiveBoxArrowDownIcon },
        ],
    },
    {
        label: 'Approval',
        sectionDescription: 'Change approval state.',
        actions: [
            { id: 'APPROVE', label: 'Mark Approved', helper: 'Approve selected assets', icon: CheckCircleIcon },
            { id: 'MARK_PENDING', label: 'Mark Pending', helper: 'Set back to pending', icon: ClockIcon },
            { id: 'REJECT', label: 'Mark Rejected', helper: 'Reject with a reason', icon: NoSymbolIcon, warningTint: true },
        ],
    },
    {
        label: 'Classification',
        sectionDescription: 'Assign category (library, execution, or generative) and move to main grid.',
        actions: [
            { id: 'ASSIGN_CATEGORY', label: 'Assign Category', helper: 'Set category, asset type, and move to main grid', icon: DocumentCheckIcon },
        ],
    },
    {
        label: 'Metadata',
        sectionDescription: 'Add, replace, or clear metadata fields.',
        actions: [
            { id: 'METADATA_ADD', label: 'Add Metadata', helper: 'Add or merge field values', icon: PencilSquareIcon },
            { id: 'METADATA_REPLACE', label: 'Replace Metadata', helper: 'Overwrite field values', icon: PencilSquareIcon },
            { id: 'METADATA_CLEAR', label: 'Clear Metadata', helper: 'Remove field values', icon: PencilSquareIcon },
        ],
    },
    {
        label: 'Names',
        sectionDescription: 'Rename display names and filenames in sequence (same pattern as batch upload).',
        actions: [
            {
                id: RENAME_ASSETS_ACTION,
                label: 'Rename assets',
                helper: 'Base name with 1 of N titles and matching filenames',
                icon: PencilSquareIcon,
            },
        ],
    },
    {
        label: 'Trash',
        sectionDescription: 'Move to trash or restore.',
        actions: [
            { id: 'SOFT_DELETE', label: 'Move to Trash', helper: 'Soft delete selected assets', icon: TrashIcon, dangerTint: true },
            { id: 'RESTORE_TRASH', label: 'Restore from Trash', helper: 'Restore deleted assets', icon: ArrowUturnLeftIcon },
            { id: 'FORCE_DELETE', label: 'Permanently Delete', helper: 'Permanently remove from trash (cannot be undone)', icon: TrashIcon, dangerTint: true },
        ],
    },
]

const LIFECYCLE_ACTIONS = new Set([
    'PUBLISH', 'UNPUBLISH', 'ARCHIVE', 'RESTORE_ARCHIVE', 'APPROVE', 'MARK_PENDING', 'SOFT_DELETE', 'RESTORE_TRASH', 'FORCE_DELETE',
])
const METADATA_ACTIONS = new Set(['METADATA_ADD', 'METADATA_REPLACE', 'METADATA_CLEAR'])
const ASSIGN_CATEGORY_ACTION = 'ASSIGN_CATEGORY'

/** Keys aligned with App\Enums\AssetType for bulk assign */
const BULK_ASSET_TYPE_OPTIONS = [
    { value: 'asset', label: 'Library' },
    { value: 'deliverable', label: 'Execution' },
    { value: 'ai_generated', label: 'Generative' },
]

/**
 * Build selection summary from current-page assets and selected IDs.
 * Used so the modal can hide Restore from Trash, Restore from Archive, Unpublish, and approval
 * actions when they don't apply. Assets should have is_published/published_at, archived_at,
 * approval_status; grid assets are never deleted so deletedCount is 0.
 *
 * @param {Array<{ id: string, is_published?: boolean, published_at?: string|null, archived_at?: string|null, deleted_at?: string|null, approval_status?: string|null }>} assets
 * @param {string[]} selectedIds
 * @returns {{ publishedCount: number, unpublishedCount: number, archivedCount: number, deletedCount: number, approvalStates: { approved: number, pending: number, rejected: number } } | null}
 */
export function computeSelectionSummary(assets, selectedIds) {
    if (!Array.isArray(assets) || !Array.isArray(selectedIds) || selectedIds.length === 0) return null
    const selected = assets.filter((a) => a && selectedIds.includes(a.id))
    if (selected.length === 0) return null
    let publishedCount = 0
    let unpublishedCount = 0
    let archivedCount = 0
    let deletedCount = 0
    const approvalStates = { approved: 0, pending: 0, rejected: 0 }
    for (const a of selected) {
        const published = a.is_published === true || (a.published_at != null && a.published_at !== '')
        if (published) publishedCount++
        else unpublishedCount++
        if (a.archived_at != null && a.archived_at !== '') archivedCount++
        if (a.deleted_at != null && a.deleted_at !== '') deletedCount++
        const status = (a.approval_status || '').toLowerCase()
        if (status === 'approved') approvalStates.approved++
        else if (status === 'pending') approvalStates.pending++
        else if (status === 'rejected') approvalStates.rejected++
    }
    return {
        publishedCount,
        unpublishedCount,
        archivedCount,
        deletedCount,
        approvalStates,
    }
}

/**
 * Returns which action ids are valid for the given selection summary.
 * - If selectionSummary is null/undefined: return null → UI shows all actions.
 * - If selectionSummary is provided: apply strict filtering; no fallback to "show all".
 *   Trash: Move to Trash only when deletedCount === 0; Restore from Trash only when deletedCount > 0.
 *   Phase B2: When isTrashMode and canForceDelete, add FORCE_DELETE; in trash mode hide SOFT_DELETE (deletedCount > 0).
 */
function computeValidActionIds(selectionSummary, { isTrashMode = false, canForceDelete = false, selectedCount = 0 } = {}) {
    if (!selectionSummary) return null
    const publishedCount = Number(selectionSummary.publishedCount ?? 0)
    const unpublishedCount = Number(selectionSummary.unpublishedCount ?? 0)
    const archivedCount = Number(selectionSummary.archivedCount ?? 0)
    const deletedCount = Number(selectionSummary.deletedCount ?? 0)
    const approvalStates = selectionSummary.approvalStates ?? {}
    const approved = Number(approvalStates.approved ?? 0)
    const pending = Number(approvalStates.pending ?? 0)
    const rejected = Number(approvalStates.rejected ?? 0)

    const valid = new Set()
    if (unpublishedCount > 0) valid.add('PUBLISH')
    if (publishedCount > 0) valid.add('UNPUBLISH')
    if (archivedCount === 0) valid.add('ARCHIVE')
    if (archivedCount > 0) valid.add('RESTORE_ARCHIVE')
    if (pending > 0 || rejected > 0) valid.add('APPROVE')
    if (approved > 0 || rejected > 0) valid.add('MARK_PENDING')
    if (pending > 0) valid.add('REJECT')
    valid.add('ASSIGN_CATEGORY')
    valid.add('METADATA_ADD')
    valid.add('METADATA_REPLACE')
    valid.add('METADATA_CLEAR')
    if (deletedCount === 0) valid.add('SOFT_DELETE')
    if (deletedCount > 0) valid.add('RESTORE_TRASH')
    if (isTrashMode && deletedCount > 0 && canForceDelete) valid.add('FORCE_DELETE')
    if (selectedCount >= 2) {
        valid.add(RENAME_ASSETS_ACTION)
    }
    return valid
}

/**
 * For each group, return only actions that are valid. Filter out groups with zero valid actions.
 * When validIds is null, all actions in all groups are shown.
 * Classification (ASSIGN_CATEGORY) is hidden when there are no categories for any asset type.
 */
function computedValidActions(groups, validIds, categories = [], bulkCategoriesByAssetType = null, assetCount = 0) {
    let filteredGroups = groups
    const hasAnyTypedCategory =
        bulkCategoriesByAssetType &&
        typeof bulkCategoriesByAssetType === 'object' &&
        ['asset', 'deliverable', 'ai_generated'].some(
            (k) => Array.isArray(bulkCategoriesByAssetType[k]) && bulkCategoriesByAssetType[k].length > 0
        )
    if (Array.isArray(categories) && categories.length === 0 && !hasAnyTypedCategory) {
        filteredGroups = groups.filter((g) => g.label !== 'Classification')
    }
    const stripRenameIfNeeded = (list) => {
        if (assetCount >= 2) return list
        return list
            .map((g) => ({
                ...g,
                validActions: g.validActions.filter((a) => a.id !== RENAME_ASSETS_ACTION),
            }))
            .filter((g) => g.validActions.length > 0)
    }
    if (!validIds) {
        return stripRenameIfNeeded(filteredGroups.map((g) => ({ ...g, validActions: g.actions })))
    }
    return stripRenameIfNeeded(
        filteredGroups
            .map((group) => ({
                ...group,
                validActions: group.actions.filter((a) => validIds.has(a.id)),
            }))
            .filter((g) => g.validActions.length > 0)
    )
}

function getActionLabel(actionId) {
    for (const group of BULK_ACTION_GROUPS) {
        const a = group.actions.find((x) => x.id === actionId)
        if (a) return a.label
    }
    if (actionId === SITE_RERUN_THUMBNAILS) return 'Rerun thumbnails'
    if (actionId === SITE_RERUN_AI_METADATA_TAGGING) return 'Rerun AI metadata & tagging'
    return actionId
}

function getConfirmSummaryText(actionId, count) {
    if (actionId === SITE_RERUN_THUMBNAILS) {
        return `This will queue thumbnail regeneration for ${count} selected asset${count !== 1 ? 's' : ''} (background workers).`
    }
    if (actionId === SITE_RERUN_AI_METADATA_TAGGING) {
        return `This will queue AI vision metadata and tag auto-apply for ${count} selected asset${count !== 1 ? 's' : ''}. Assets without completed thumbnails will be skipped. Uses tenant AI tagging quota.`
    }
    const verb = actionId === 'PUBLISH' ? 'publish' : actionId === 'UNPUBLISH' ? 'unpublish' : actionId === 'ARCHIVE' ? 'archive' : actionId === 'RESTORE_ARCHIVE' ? 'restore from archive' : actionId === 'APPROVE' ? 'mark as approved' : actionId === 'MARK_PENDING' ? 'mark as pending' : actionId === 'REJECT' ? 'reject' : actionId === 'SOFT_DELETE' ? 'move to trash' : actionId === 'RESTORE_TRASH' ? 'restore from trash' : 'update'
    return `This will ${verb} ${count} selected asset${count !== 1 ? 's' : ''}.`
}

export default function BulkActionsModal({
    assetIds,
    onClose,
    onComplete,
    onOpenMetadataEdit = null,
    /** Optional: { publishedCount, unpublishedCount, archivedCount, deletedCount, approvalStates } for contextual actions */
    selectionSummary = null,
    /** Optional: minimal asset data for pre-action summary */
    selectedAssetSummary = null,
    showPermissionWarning = false,
    /** Phase B2: When true, we're in trash view (hide Move to Trash, show Restore + Permanently Delete if canForceDelete) */
    isTrashMode = false,
    /** Phase B2: Tenant admin only — show Permanently Delete bulk action */
    canForceDelete = false,
    /** Staged intake: categories for Assign Category bulk action */
    categories = [],
    /** Optional: { asset: [...], deliverable: [...], ai_generated: [...] } from Assets grid — enables Library / Execution / Generative */
    bulkCategoriesByAssetType = null,
}) {
    const { auth } = usePage().props
    const { can } = usePermission()
    const canBulkRename = can('metadata.edit_post_upload')
    const brandPrimary = auth?.activeBrand?.primary_color || null
    const canSitePipeline = useMemo(() => {
        const roles = auth?.user?.site_roles
        if (!Array.isArray(roles)) return false
        return roles.some((r) => SITE_PIPELINE_ROLES.has(r))
    }, [auth?.user?.site_roles])

    const [step, setStep] = useState('select')
    const [selectedAction, setSelectedAction] = useState(null)
    const [rejectionReason, setRejectionReason] = useState('')
    const [assignAssetType, setAssignAssetType] = useState('asset')
    const [assignCategoryId, setAssignCategoryId] = useState('')
    const [bulkRenameBase, setBulkRenameBase] = useState('')
    const [renamePreviewExpanded, setRenamePreviewExpanded] = useState(false)
    const [submitting, setSubmitting] = useState(false)
    const [error, setError] = useState(null)
    const [modalEntered, setModalEntered] = useState(false)
    const [stepEntered, setStepEntered] = useState(false)
    const [confirmPanelEntered, setConfirmPanelEntered] = useState(false)

    const bulkActionUrl = typeof route !== 'undefined' ? route('assets.bulk-action') : '/app/assets/bulk-action'
    const n = assetIds.length

    const validIds = useMemo(
        () => computeValidActionIds(selectionSummary, { isTrashMode, canForceDelete, selectedCount: n }),
        [selectionSummary, isTrashMode, canForceDelete, n]
    )
    const groupsWithValidActions = useMemo(() => {
        let g = computedValidActions(BULK_ACTION_GROUPS, validIds, categories, bulkCategoriesByAssetType, n)
        if (!canBulkRename) {
            g = g
                .map((gr) => ({
                    ...gr,
                    validActions: gr.validActions.filter((a) => a.id !== RENAME_ASSETS_ACTION),
                }))
                .filter((gr) => gr.validActions.length > 0)
        }
        return g
    }, [validIds, categories, bulkCategoriesByAssetType, n, canBulkRename])

    const renamePreview = useMemo(() => {
        if (!bulkRenameBase.trim() || n < 2) return []
        const base = bulkRenameBase.trim()
        const slug = slugifyForRename(base)
        const total = n
        const padLen = getIndexPadding(total)
        const byId = new Map((selectedAssetSummary || []).map((x) => [x.id, x]))
        return assetIds.map((id, i) => {
            const row = byId.get(id)
            const ext = extensionFromFilename(row?.original_filename || '')
            const indexStr = String(i + 1).padStart(padLen, '0')
            const filename = ext ? `${slug}-${indexStr}.${ext}` : `${slug}-${indexStr}`
            const title = `${base} ${i + 1} of ${total}`
            return { id, title, filename }
        })
    }, [bulkRenameBase, n, assetIds, selectedAssetSummary])

    const hasTypedBulkCategories =
        bulkCategoriesByAssetType &&
        typeof bulkCategoriesByAssetType === 'object' &&
        BULK_ASSET_TYPE_OPTIONS.some(
            (o) => Array.isArray(bulkCategoriesByAssetType[o.value]) && bulkCategoriesByAssetType[o.value].length > 0
        )

    const assignCategoryOptions = useMemo(() => {
        if (hasTypedBulkCategories && assignAssetType && bulkCategoriesByAssetType[assignAssetType]) {
            return bulkCategoriesByAssetType[assignAssetType]
        }
        return Array.isArray(categories) ? categories : []
    }, [hasTypedBulkCategories, assignAssetType, bulkCategoriesByAssetType, categories])

    useEffect(() => {
        const t = requestAnimationFrame(() => requestAnimationFrame(() => setModalEntered(true)))
        return () => cancelAnimationFrame(t)
    }, [])

    useEffect(() => {
        setStepEntered(false)
        const id = setTimeout(() => setStepEntered(true), 20)
        return () => clearTimeout(id)
    }, [step])

    useEffect(() => {
        if (
            step === 'configure' &&
            selectedAction &&
            (LIFECYCLE_ACTIONS.has(selectedAction) || SITE_PIPELINE_ACTIONS.has(selectedAction))
        ) {
            setConfirmPanelEntered(false)
            const id = setTimeout(() => setConfirmPanelEntered(true), 20)
            return () => clearTimeout(id)
        }
    }, [step, selectedAction])

    const handleSelectAction = useCallback((actionId) => {
        if (METADATA_ACTIONS.has(actionId) && onOpenMetadataEdit) {
            const op = actionId === 'METADATA_ADD' ? 'add' : actionId === 'METADATA_REPLACE' ? 'replace' : 'clear'
            onClose()
            onOpenMetadataEdit(assetIds, op)
            return
        }
        setSelectedAction(actionId)
        setStep('configure')
        setError(null)
        if (actionId === ASSIGN_CATEGORY_ACTION) {
            setAssignCategoryId('')
            setAssignAssetType('asset')
        }
        if (actionId === RENAME_ASSETS_ACTION) {
            setBulkRenameBase('')
            setRenamePreviewExpanded(false)
        }
    }, [assetIds, onOpenMetadataEdit, onClose])

    const handleBack = useCallback(() => {
        setStep('select')
        setSelectedAction(null)
        setRejectionReason('')
        setBulkRenameBase('')
        setRenamePreviewExpanded(false)
        setError(null)
    }, [])

    const handleClose = useCallback(() => {
        setStep('select')
        setSelectedAction(null)
        setRejectionReason('')
        setAssignCategoryId('')
        setAssignAssetType('asset')
        setBulkRenameBase('')
        setRenamePreviewExpanded(false)
        setError(null)
        onClose()
    }, [onClose])

    const handleSubmit = async () => {
        if (selectedAction === 'REJECT' && !rejectionReason.trim()) {
            setError('Rejection reason is required.')
            return
        }
        if (selectedAction === ASSIGN_CATEGORY_ACTION && !assignCategoryId) {
            setError('Please select a category.')
            return
        }
        if (selectedAction === RENAME_ASSETS_ACTION) {
            if (n < 2) {
                setError('Select at least two assets.')
                return
            }
            if (!bulkRenameBase.trim()) {
                setError('Enter a base name for the naming pattern.')
                return
            }
        }
        setSubmitting(true)
        setError(null)
        try {
            let payload = {}
            if (selectedAction === 'REJECT') payload = { rejection_reason: rejectionReason.trim() }
            else if (selectedAction === RENAME_ASSETS_ACTION) payload = { base_name: bulkRenameBase.trim() }
            else if (selectedAction === ASSIGN_CATEGORY_ACTION) {
                payload = { category_id: parseInt(assignCategoryId, 10) }
                if (hasTypedBulkCategories) {
                    payload.asset_type = assignAssetType
                }
            }
            const { data } = await axios.post(bulkActionUrl, {
                asset_ids: assetIds,
                action: selectedAction,
                payload,
            })
            const { processed = 0, skipped = 0, errors: errs = [] } = data
            const mainMsg = `${processed} asset${processed !== 1 ? 's' : ''} updated${skipped > 0 ? `. ${skipped} skipped.` : '.'}`
            if (typeof window !== 'undefined' && window.toast) {
                window.toast(mainMsg, processed > 0 ? 'success' : 'info')
            } else if (typeof window !== 'undefined' && window.flash) {
                window.flash('success', mainMsg)
            }
            if (errs.length > 0 && typeof window !== 'undefined') {
                const errMsg = `${errs.length} asset${errs.length !== 1 ? 's' : ''} failed to update.`
                if (window.toast) window.toast(errMsg, 'error')
                else if (window.flash) window.flash('error', errMsg)
            }
            onComplete?.(processed > 0 ? { actionId: selectedAction, assignCategory: selectedAction === ASSIGN_CATEGORY_ACTION } : undefined)
            handleClose()
        } catch (e) {
            const msg = e.response?.data?.message || e.message || 'Request failed.'
            setError(msg)
        } finally {
            setSubmitting(false)
        }
    }

    const isReject = selectedAction === 'REJECT'
    const isAssignCategory = selectedAction === ASSIGN_CATEGORY_ACTION
    const isRename = selectedAction === RENAME_ASSETS_ACTION
    const isLifecycle = selectedAction && LIFECYCLE_ACTIONS.has(selectedAction)
    const isSitePipeline = selectedAction && SITE_PIPELINE_ACTIONS.has(selectedAction)

    const summaryEligible = selectedAssetSummary
        ? (selectedAction === 'PUBLISH' ? selectedAssetSummary.filter((a) => !a.is_published).length : selectedAction === 'UNPUBLISH' ? selectedAssetSummary.filter((a) => a.is_published).length : null)
        : null
    const summarySkipped = summaryEligible != null ? n - summaryEligible : null
    const summaryLine = summaryEligible != null && summarySkipped != null
        ? `${summaryEligible} will be updated, ${summarySkipped} will be skipped.`
        : `${n} selected. Some may be skipped if already in the desired state or if you don't have permission.`

    return (
        <div className="fixed inset-0 z-[100] flex items-start sm:items-center justify-center p-4 pt-8 sm:pt-4 bg-black/50 overflow-y-auto" onClick={handleClose}>
            <div
                className="bg-white rounded-xl shadow-lg max-w-3xl w-full max-h-[90vh] overflow-y-auto transition-all duration-[180ms] ease-[cubic-bezier(0.16,1,0.3,1)]"
                style={{
                    opacity: modalEntered ? 1 : 0,
                    transform: modalEntered ? 'translateY(0)' : 'translateY(8px)',
                }}
                onClick={(e) => e.stopPropagation()}
            >
                <div className="flex items-center justify-between px-6 md:px-8 py-4 border-b border-gray-200">
                    <div className="min-w-0">
                        <h2 className="text-lg font-semibold text-gray-900 truncate">
                            Bulk Actions ({n} asset{n !== 1 ? 's' : ''})
                        </h2>
                        <p className="mt-0.5 text-sm text-gray-500">
                            Choose what you want to change for the selected assets.
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={handleClose}
                        className="ml-2 p-1.5 rounded-lg text-gray-500 hover:bg-gray-100 shrink-0 transition-colors"
                        aria-label="Close"
                    >
                        <XMarkIcon className="w-5 h-5" />
                    </button>
                </div>

                <div className="px-6 md:px-8 py-6">
                    {step === 'select' && (
                        <div
                            className="transition-all duration-[180ms] ease-[cubic-bezier(0.16,1,0.3,1)]"
                            style={{
                                opacity: stepEntered ? 1 : 0,
                                transform: stepEntered ? 'translateX(0)' : 'translateX(8px)',
                            }}
                        >
                            <div className="space-y-6">
                                {groupsWithValidActions.map((group, gIdx) => (
                                    <div key={group.label} className="space-y-4">
                                        {gIdx > 0 && group.label === 'Trash' && (
                                            <div className="border-t border-gray-100 pt-6 -mt-2" />
                                        )}
                                        <div className="space-y-1">
                                            <h3 className="text-sm font-semibold text-gray-700">
                                                {group.label}
                                            </h3>
                                            {group.sectionDescription && (
                                                <p className="text-xs text-gray-500 leading-tight">
                                                    {group.sectionDescription}
                                                </p>
                                            )}
                                        </div>
                                        <div
                                            className={
                                                group.validActions.length === 1
                                                    ? 'mt-3 flex justify-start'
                                                    : 'mt-3 grid grid-cols-[repeat(auto-fill,minmax(14rem,1fr))] gap-3'
                                            }
                                        >
                                            {group.validActions.map((action) => {
                                                const { id, label, helper, icon: Icon, warningTint, dangerTint } = action
                                                return (
                                                    <button
                                                        key={id}
                                                        type="button"
                                                        onClick={() => handleSelectAction(id)}
                                                        className={`flex w-full items-center gap-3 p-3.5 text-left rounded-xl bg-white shadow-sm border border-gray-100 transition-all duration-150 ease-out hover:shadow-md hover:-translate-y-px active:scale-[0.98] active:duration-75 ${
                                                            group.validActions.length === 1 ? 'max-w-sm' : ''
                                                        } ${
                                                            warningTint
                                                                ? 'hover:bg-amber-50/80'
                                                                : dangerTint
                                                                ? 'hover:bg-red-50/80'
                                                                : 'hover:bg-gray-50/80'
                                                        }`}
                                                    >
                                                        <span
                                                            className={`flex items-center justify-center w-8 h-8 rounded-lg shrink-0 ${
                                                                warningTint ? 'bg-amber-100' : dangerTint ? 'bg-red-100' : 'bg-gray-100'
                                                            }`}
                                                        >
                                                            <Icon
                                                                className={`w-4 h-4 ${
                                                                    warningTint ? 'text-amber-600' : dangerTint ? 'text-red-600' : 'text-gray-600'
                                                                }`}
                                                            />
                                                        </span>
                                                        <div className="min-w-0">
                                                            <span className="block text-sm font-medium text-gray-900">
                                                                {label}
                                                            </span>
                                                            {helper && (
                                                                <span className="block text-xs text-gray-500 leading-snug mt-0.5">
                                                                    {helper}
                                                                </span>
                                                            )}
                                                        </div>
                                                    </button>
                                                )
                                            })}
                                        </div>
                                    </div>
                                ))}
                            </div>
                            {showPermissionWarning && (
                                <div className="mt-6 flex items-center gap-2 p-3 rounded-xl bg-amber-50 border border-amber-100">
                                    <ExclamationTriangleIcon className="w-5 h-5 text-amber-600 shrink-0" />
                                    <span className="text-sm text-amber-800">
                                        Some selected assets cannot be modified and will be skipped.
                                    </span>
                                </div>
                            )}

                            {canSitePipeline && (
                                <div
                                    className="mt-8 rounded-2xl border-2 border-indigo-200/90 p-5 shadow-sm"
                                    style={
                                        brandPrimary
                                            ? {
                                                  borderColor: brandPrimary,
                                                  background: `linear-gradient(135deg, ${brandPrimary}14 0%, ${brandPrimary}08 50%, rgb(238 242 255) 100%)`,
                                              }
                                            : { background: 'linear-gradient(135deg, rgb(238 242 255) 0%, rgb(245 243 255) 100%)' }
                                    }
                                >
                                    <div className="flex items-start gap-2 mb-3">
                                        <span className="inline-flex items-center rounded-md bg-indigo-600/90 px-2 py-0.5 text-[10px] font-bold tracking-wide text-white">
                                            Admin
                                        </span>
                                        <div className="min-w-0 flex-1">
                                            <h3 className="text-sm font-semibold text-indigo-950">Pipeline tools</h3>
                                            <p className="text-xs text-indigo-900/70 mt-0.5 leading-snug">
                                                Site admin or engineering only. Work runs in the queue (Horizon) — not instant. Max 100 assets per request.
                                            </p>
                                        </div>
                                    </div>
                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                        <button
                                            type="button"
                                            onClick={() => handleSelectAction(SITE_RERUN_THUMBNAILS)}
                                            className="flex w-full items-center gap-3 p-3.5 text-left rounded-xl bg-white/80 border border-indigo-100 shadow-sm hover:shadow-md hover:-translate-y-px transition-all"
                                        >
                                            <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-indigo-100 shrink-0">
                                                <PhotoIcon className="w-5 h-5 text-indigo-700" />
                                            </span>
                                            <div className="min-w-0">
                                                <span className="block text-sm font-medium text-gray-900">Rerun thumbnails</span>
                                                <span className="block text-xs text-gray-600 mt-0.5">Regenerate preview images (all styles)</span>
                                            </div>
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => handleSelectAction(SITE_RERUN_AI_METADATA_TAGGING)}
                                            className="flex w-full items-center gap-3 p-3.5 text-left rounded-xl bg-white/80 border border-indigo-100 shadow-sm hover:shadow-md hover:-translate-y-px transition-all"
                                        >
                                            <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-violet-100 shrink-0">
                                                <SparklesIcon className="w-5 h-5 text-violet-700" />
                                            </span>
                                            <div className="min-w-0">
                                                <span className="block text-sm font-medium text-gray-900">Rerun AI metadata &amp; tagging</span>
                                                <span className="block text-xs text-gray-600 mt-0.5">Vision + tag auto-apply; needs completed thumbnails</span>
                                            </div>
                                        </button>
                                    </div>
                                </div>
                            )}
                        </div>
                    )}

                    {step === 'configure' && selectedAction && (
                        <div
                            className="transition-all duration-[180ms] ease-[cubic-bezier(0.16,1,0.3,1)]"
                            style={{
                                opacity: stepEntered ? 1 : 0,
                                transform: stepEntered ? 'translateX(0)' : 'translateX(-8px)',
                            }}
                        >
                            <div className="flex items-center gap-2 mb-6">
                                <button
                                    type="button"
                                    onClick={handleBack}
                                    className="text-sm font-medium text-gray-600 hover:text-gray-900 transition-colors"
                                >
                                    ← Back
                                </button>
                                <span className="text-sm text-gray-500">{getActionLabel(selectedAction)}</span>
                            </div>

                            {selectedAction === ASSIGN_CATEGORY_ACTION && (
                                <>
                                    {hasTypedBulkCategories && (
                                        <>
                                            <label className="block text-sm font-medium text-gray-700 mb-1">
                                                Asset type
                                            </label>
                                            <select
                                                value={assignAssetType}
                                                onChange={(e) => {
                                                    setAssignAssetType(e.target.value)
                                                    setAssignCategoryId('')
                                                }}
                                                className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm mb-4"
                                            >
                                                {BULK_ASSET_TYPE_OPTIONS.map((opt) => (
                                                    <option key={opt.value} value={opt.value}>
                                                        {opt.label}
                                                    </option>
                                                ))}
                                            </select>
                                        </>
                                    )}
                                    <label className="block text-sm font-medium text-gray-700 mb-1">
                                        Category
                                    </label>
                                    <select
                                        value={assignCategoryId}
                                        onChange={(e) => setAssignCategoryId(e.target.value)}
                                        className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                                    >
                                        <option value="">Select a category...</option>
                                        {assignCategoryOptions.map((cat) => (
                                            <option key={cat.id} value={cat.id}>{cat.name}</option>
                                        ))}
                                    </select>
                                    {assignCategoryOptions.length === 0 && (
                                        <p className="mt-2 text-xs text-amber-600">No categories for this asset type. Create a category first.</p>
                                    )}
                                </>
                            )}

                            {isReject && (
                                <>
                                    <label className="block text-sm font-medium text-gray-700 mb-1">
                                        Rejection Reason
                                    </label>
                                    <textarea
                                        value={rejectionReason}
                                        onChange={(e) => setRejectionReason(e.target.value)}
                                        rows={3}
                                        maxLength={2000}
                                        className="w-full rounded-xl border border-gray-300 px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 shadow-sm"
                                        placeholder="Enter reason for rejection..."
                                    />
                                    <p className="mt-1 text-xs text-gray-400">
                                        {rejectionReason.length}/2000
                                    </p>
                                </>
                            )}

                            {isRename && (
                                <div className="space-y-4">
                                    <p className="text-sm text-gray-600">
                                        Assets are renamed in the order shown below. Each asset gets a display title like{' '}
                                        <span className="font-medium text-gray-900">Base name 1 of {n}</span> and a filename like{' '}
                                        <span className="font-mono text-xs text-gray-800">base-name-01.ext</span> (extension preserved from the current filename).
                                    </p>
                                    <div>
                                        <label className="block text-sm font-medium text-gray-700 mb-1" htmlFor="bulk-rename-base">
                                            Base name
                                        </label>
                                        <input
                                            id="bulk-rename-base"
                                            type="text"
                                            value={bulkRenameBase}
                                            onChange={(e) => setBulkRenameBase(e.target.value)}
                                            placeholder="e.g. Photo Shoot XY"
                                            maxLength={200}
                                            className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                                        />
                                    </div>
                                    {bulkRenameBase.trim() && renamePreview.length > 0 && (
                                        <div className="rounded-xl border border-gray-200 bg-gray-50/80 p-4">
                                            <div className="flex flex-wrap items-center gap-2 mb-2">
                                                <span className="text-xs font-medium text-gray-600">Preview</span>
                                                <button
                                                    type="button"
                                                    onClick={() => setRenamePreviewExpanded(!renamePreviewExpanded)}
                                                    className="inline-flex items-center gap-1 text-xs text-indigo-600 hover:text-indigo-800"
                                                >
                                                    <DocumentDuplicateIcon className="h-4 w-4" />
                                                    {renamePreviewExpanded ? 'Hide list' : 'Show all filenames'}
                                                </button>
                                            </div>
                                            <p className="text-sm text-gray-800">
                                                <span className="text-gray-500">Example title:</span>{' '}
                                                <span className="font-medium">{renamePreview[0]?.title}</span>
                                                {renamePreview.length > 1 && (
                                                    <span className="text-gray-500"> · … · </span>
                                                )}
                                                {renamePreview.length > 1 && (
                                                    <span className="font-medium">{renamePreview[renamePreview.length - 1]?.title}</span>
                                                )}
                                            </p>
                                            {renamePreviewExpanded && (
                                                <ul className="mt-3 max-h-40 overflow-y-auto space-y-1 border-t border-gray-200 pt-3">
                                                    {renamePreview.map((row) => (
                                                        <li key={row.id} className="text-xs font-mono text-gray-700 truncate" title={row.filename}>
                                                            <span className="text-gray-500 select-none">{row.title}</span>
                                                            <span className="mx-2 text-gray-300">→</span>
                                                            {row.filename}
                                                        </li>
                                                    ))}
                                                </ul>
                                            )}
                                        </div>
                                    )}
                                </div>
                            )}

                            {(isLifecycle || isSitePipeline) && (
                                <div
                                    className={`rounded-xl border p-4 mb-6 shadow-sm transition-all duration-[140ms] ease-out ${
                                        isSitePipeline
                                            ? 'border-indigo-200 bg-indigo-50/60'
                                            : 'border-gray-200 bg-gray-50/50'
                                    }`}
                                    style={{
                                        opacity: confirmPanelEntered ? 1 : 0,
                                        transform: confirmPanelEntered ? 'translateY(0)' : 'translateY(4px)',
                                    }}
                                >
                                    <h3 className="text-sm font-semibold text-gray-900 mb-2">
                                        {isSitePipeline ? 'Confirm site pipeline action' : 'Confirm Bulk Action'}
                                    </h3>
                                    <p className="text-sm text-gray-700 mb-3">
                                        {getConfirmSummaryText(selectedAction, n)}
                                    </p>
                                    <p className="text-xs text-gray-500">
                                        {summaryLine}
                                    </p>
                                </div>
                            )}

                            {error && (
                                <div className="mb-4 p-3 rounded-xl bg-red-50 text-red-800 text-sm border border-red-100">
                                    {error}
                                </div>
                            )}

                            <div className="flex justify-end gap-3 pt-2">
                                <button
                                    type="button"
                                    onClick={handleBack}
                                    className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-xl hover:bg-gray-50 shadow-sm transition-colors"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="button"
                                    onClick={handleSubmit}
                                    disabled={
                                        submitting ||
                                        (isReject && !rejectionReason.trim()) ||
                                        (isAssignCategory && !assignCategoryId) ||
                                        (isRename && (!bulkRenameBase.trim() || n < 2))
                                    }
                                    className="px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-xl hover:bg-indigo-700 disabled:opacity-50 disabled:pointer-events-none shadow-sm transition-colors"
                                >
                                    {submitting
                                        ? `Processing ${n} assets...`
                                        : isSitePipeline
                                        ? 'Queue jobs'
                                        : isLifecycle
                                        ? 'Confirm Action'
                                        : isReject
                                        ? 'Reject'
                                        : isAssignCategory
                                        ? 'Assign Category'
                                        : isRename
                                        ? 'Rename assets'
                                        : 'Apply'}
                                </button>
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </div>
    )
}
