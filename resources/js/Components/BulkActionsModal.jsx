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
    SparklesIcon,
} from '@heroicons/react/24/outline'
import axios from 'axios'
import { usePermission } from '../hooks/usePermission'
import ProcessingActionCard from './ProcessingActionCard'

const EASING_TOOLBAR = 'cubic-bezier(0.16, 1, 0.3, 1)'

/** Compact rail header — matches metadata / bulk density */
function BulkModalSuperSection({ title, description, children }) {
    return (
        <section className="space-y-3">
            <div className="border-b border-gray-200 pb-1.5">
                <h2 className="text-[11px] font-semibold uppercase tracking-wider text-gray-500">{title}</h2>
                {description ? (
                    <p className="mt-1 max-w-prose text-[11px] leading-snug text-gray-500">{description}</p>
                ) : null}
            </div>
            <div className="space-y-5">{children}</div>
        </section>
    )
}

function pickBulkGroupByLabel(groups, label) {
    return groups.find((g) => g.label === label)
}

function assetLooksLikeVideo(a) {
    if (!a || typeof a !== 'object') return false
    const mime = String(a.mime_type || '').toLowerCase()
    if (mime.startsWith('video/')) return true
    const name = String(a.original_filename || a.title || a.name || '')
    const ext = name.includes('.') ? name.split('.').pop().toLowerCase() : ''
    return ['mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v', 'mpeg', 'mpg'].includes(ext)
}

function BulkActionGroupBlock({ group, groupIndex, onPick, hideSectionHeader = false, workflowColumn = false }) {
    return (
        <div className="space-y-2">
            {groupIndex > 0 && group.label === 'Trash' && <div className="border-t border-gray-100 pt-4 -mt-1" />}
            {!hideSectionHeader && (
                <div
                    className={
                        workflowColumn
                            ? 'flex flex-col gap-1'
                            : 'flex flex-wrap items-end justify-between gap-x-3 gap-y-0.5'
                    }
                >
                    <h3 className="text-xs font-semibold text-gray-800">{group.label}</h3>
                    {group.sectionDescription ? (
                        <p
                            className={
                                workflowColumn
                                    ? 'max-w-full text-[11px] leading-snug text-gray-500'
                                    : 'max-w-full text-[11px] leading-snug text-gray-500 sm:max-w-[70%] sm:text-right'
                            }
                        >
                            {group.sectionDescription}
                        </p>
                    ) : null}
                </div>
            )}
            <div
                className={
                    group.validActions.length === 1
                        ? workflowColumn
                            ? 'mt-1.5 flex w-full justify-stretch'
                            : 'mt-1.5 flex justify-start'
                        : 'mt-1.5 grid grid-cols-[repeat(auto-fill,minmax(13.5rem,1fr))] gap-2.5'
                }
            >
                {group.validActions.map((action) => {
                    const { id, label, helper, icon: Icon, warningTint, dangerTint } = action
                    return (
                        <button
                            key={id}
                            type="button"
                            onClick={() => onPick(id)}
                            className={`flex w-full items-start gap-2.5 rounded-lg border border-gray-100 bg-white p-3 text-left shadow-sm transition-all duration-150 ease-out hover:-translate-y-px hover:shadow-md active:scale-[0.99] active:duration-75 ${
                                group.validActions.length === 1 && !workflowColumn ? 'max-w-sm' : ''
                            } ${
                                warningTint
                                    ? 'hover:bg-amber-50/80'
                                    : dangerTint
                                      ? 'hover:bg-red-50/80'
                                      : 'hover:bg-gray-50/80'
                            }`}
                        >
                            <span
                                className={`mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-md ${
                                    warningTint ? 'bg-amber-100' : dangerTint ? 'bg-red-100' : 'bg-gray-100'
                                }`}
                            >
                                <Icon
                                    className={`h-4 w-4 ${
                                        warningTint ? 'text-amber-600' : dangerTint ? 'text-red-600' : 'text-gray-600'
                                    }`}
                                />
                            </span>
                            <div className="min-w-0 pt-0.5">
                                <span className="block text-xs font-semibold text-gray-900">{label}</span>
                                {helper ? (
                                    <span className="mt-0.5 block text-[11px] leading-snug text-gray-500">{helper}</span>
                                ) : null}
                            </div>
                        </button>
                    )
                })}
            </div>
        </div>
    )
}

const RENAME_ASSETS_ACTION = 'RENAME_ASSETS'

const SITE_RERUN_THUMBNAILS = 'SITE_RERUN_THUMBNAILS'
const SITE_RERUN_AI_METADATA_TAGGING = 'SITE_RERUN_AI_METADATA_TAGGING'
const SITE_GENERATE_VIDEO_PREVIEWS = 'SITE_GENERATE_VIDEO_PREVIEWS'
const SITE_DELETE_VIDEO_PREVIEWS = 'SITE_DELETE_VIDEO_PREVIEWS'
const SITE_REPROCESS_SYSTEM_METADATA = 'SITE_REPROCESS_SYSTEM_METADATA'
const SITE_REPROCESS_FULL_PIPELINE = 'SITE_REPROCESS_FULL_PIPELINE'
const SITE_PIPELINE_ACTIONS = new Set([
    SITE_RERUN_THUMBNAILS,
    SITE_RERUN_AI_METADATA_TAGGING,
    SITE_GENERATE_VIDEO_PREVIEWS,
    SITE_DELETE_VIDEO_PREVIEWS,
    SITE_REPROCESS_SYSTEM_METADATA,
    SITE_REPROCESS_FULL_PIPELINE,
])
/** Subset of site pipeline: queued jobs (excludes synchronous delete preview). */
const SITE_PIPELINE_QUEUED_ACTIONS = new Set([
    SITE_RERUN_THUMBNAILS,
    SITE_RERUN_AI_METADATA_TAGGING,
    SITE_GENERATE_VIDEO_PREVIEWS,
    SITE_REPROCESS_SYSTEM_METADATA,
    SITE_REPROCESS_FULL_PIPELINE,
])
/** Tenant video AI insights (same confirm / queue UX as site pipeline jobs). */
const GENERATE_VIDEO_INSIGHTS = 'GENERATE_VIDEO_INSIGHTS'
const BACKGROUND_QUEUE_BULK_ACTIONS = new Set([...SITE_PIPELINE_QUEUED_ACTIONS, GENERATE_VIDEO_INSIGHTS])
/** Full pipeline only: explicit acknowledgment (resource intensive). */
const SITE_FULL_PIPELINE_ACTION = SITE_REPROCESS_FULL_PIPELINE
/** Align with config/asset_processing.php max_bulk_pipeline_assets */
const MAX_BULK_PIPELINE_ASSETS = 25
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
        sectionDescription:
            'Sets the primary folder for each asset — it drives library placement, visibility, and how items sort on the grid. Choose Library or Execution type when applicable.',
        actions: [
            {
                id: 'ASSIGN_CATEGORY',
                label: 'Assign folder',
                helper: 'Set the top-level folder, asset type, and main-grid placement as a group',
                icon: DocumentCheckIcon,
            },
        ],
    },
    {
        label: 'Asset data',
        sectionDescription: 'Structured fields and tags: add or merge values, replace wholesale, clear fields, or strip specific tags only.',
        actions: [
            { id: 'METADATA_ADD', label: 'Add fields', helper: 'Add or merge metadata field values', icon: PencilSquareIcon },
            { id: 'METADATA_REPLACE', label: 'Replace fields', helper: 'Overwrite metadata field values', icon: PencilSquareIcon },
            { id: 'METADATA_CLEAR', label: 'Clear fields', helper: 'Remove metadata field values', icon: PencilSquareIcon },
            { id: 'METADATA_REMOVE_TAGS', label: 'Remove tags', helper: 'Strip chosen tag(s) only; other tags stay', icon: PencilSquareIcon },
        ],
    },
    {
        label: 'Rename',
        sectionDescription: 'Rename display names and filenames in sequence (same pattern as batch upload).',
        actions: [
            {
                id: RENAME_ASSETS_ACTION,
                label: 'Rename',
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
const METADATA_ACTIONS = new Set(['METADATA_ADD', 'METADATA_REPLACE', 'METADATA_CLEAR', 'METADATA_REMOVE_TAGS'])
const ASSIGN_CATEGORY_ACTION = 'ASSIGN_CATEGORY'

/** Keys aligned with App\Enums\AssetType for bulk assign */
const BULK_ASSET_TYPE_OPTIONS = [
    { value: 'asset', label: 'Library' },
    { value: 'deliverable', label: 'Execution' },
]

/**
 * Build selection summary from current-page assets and selected IDs.
 * Used so the modal can hide Restore from Trash, Restore from Archive, Unpublish, and approval
 * actions when they don't apply. Assets should have is_published/published_at, archived_at,
 * approval_status; grid assets are never deleted so deletedCount is 0.
 *
 * @param {Array<{ id: string, is_published?: boolean, published_at?: string|null, archived_at?: string|null, deleted_at?: string|null, approval_status?: string|null }>} assets
 * @param {string[]} selectedIds
 * @returns {{ publishedCount: number, unpublishedCount: number, archivedCount: number, deletedCount: number, videoCount: number, approvalStates: { approved: number, pending: number, rejected: number } } | null}
 */
export function computeSelectionSummary(assets, selectedIds) {
    if (!Array.isArray(assets) || !Array.isArray(selectedIds) || selectedIds.length === 0) return null
    const selected = assets.filter((a) => a && selectedIds.includes(a.id))
    if (selected.length === 0) return null
    let publishedCount = 0
    let unpublishedCount = 0
    let archivedCount = 0
    let deletedCount = 0
    let videoCount = 0
    const approvalStates = { approved: 0, pending: 0, rejected: 0 }
    for (const a of selected) {
        const published = a.is_published === true || (a.published_at != null && a.published_at !== '')
        if (published) publishedCount++
        else unpublishedCount++
        if (a.archived_at != null && a.archived_at !== '') archivedCount++
        if (a.deleted_at != null && a.deleted_at !== '') deletedCount++
        if (assetLooksLikeVideo(a)) videoCount++
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
        videoCount,
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
    valid.add('METADATA_REMOVE_TAGS')
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
    if (actionId === SITE_RERUN_THUMBNAILS) return 'Refresh previews'
    if (actionId === SITE_RERUN_AI_METADATA_TAGGING) return 'Re-run AI tagging'
    if (actionId === SITE_GENERATE_VIDEO_PREVIEWS) return 'Generate video previews'
    if (actionId === SITE_DELETE_VIDEO_PREVIEWS) return 'Delete video quick previews'
    if (actionId === SITE_REPROCESS_SYSTEM_METADATA) return 'Re-run metadata extraction'
    if (actionId === SITE_REPROCESS_FULL_PIPELINE) return 'Reprocess entire asset'
    if (actionId === GENERATE_VIDEO_INSIGHTS) return 'Analyze video content'
    return actionId
}

function getConfirmSummaryText(actionId, count) {
    const batchNote =
        count > 10 ? ` Jobs are dispatched in batches of up to 10 with a short delay between batches.` : ''
    if (actionId === SITE_RERUN_THUMBNAILS) {
        return `This will refresh thumbnails and preview images for ${count} selected asset${count !== 1 ? 's' : ''}.${batchNote}`
    }
    if (actionId === SITE_RERUN_AI_METADATA_TAGGING) {
        return `This will re-run AI tagging and refresh AI-suggested metadata for ${count} selected asset${count !== 1 ? 's' : ''}. Assets without completed thumbnails will be skipped. Uses tenant AI tagging quota.${batchNote}`
    }
    if (actionId === SITE_GENERATE_VIDEO_PREVIEWS) {
        return `This will queue hover/quick preview MP4 regeneration for video assets in your selection (${count} selected), including correct orientation from metadata. Non-video assets and items without a poster/thumbnail will be skipped.${batchNote}`
    }
    if (actionId === SITE_DELETE_VIDEO_PREVIEWS) {
        return `This will remove stored hover/quick preview MP4 files from storage and clear preview paths for video assets in your selection (${count} selected). Non-video assets will be skipped. Does not queue regeneration — use Generate video previews afterward if you want new files.${batchNote}`
    }
    if (actionId === SITE_REPROCESS_SYSTEM_METADATA) {
        return `This will re-run technical metadata extraction for ${count} selected asset${count !== 1 ? 's' : ''}. Requires admin or the right permission on this company.${batchNote}`
    }
    if (actionId === SITE_REPROCESS_FULL_PIPELINE) {
        return `This will reprocess the entire asset (AI, previews, metadata) for ${count} selected asset${count !== 1 ? 's' : ''}. Resource intensive.${batchNote}`
    }
    if (actionId === GENERATE_VIDEO_INSIGHTS) {
        return `This will queue video AI analysis (summary, tags, transcript cues) for video files in your selection (${count} selected). Non-video assets will be skipped. Uses tenant video AI job and minute limits.${batchNote}`
    }
    const verb = actionId === 'PUBLISH' ? 'publish' : actionId === 'UNPUBLISH' ? 'unpublish' : actionId === 'ARCHIVE' ? 'archive' : actionId === 'RESTORE_ARCHIVE' ? 'restore from archive' : actionId === 'APPROVE' ? 'mark as approved' : actionId === 'MARK_PENDING' ? 'mark as pending' : actionId === 'REJECT' ? 'reject' : actionId === 'SOFT_DELETE' ? 'move to trash' : actionId === 'RESTORE_TRASH' ? 'restore from trash' : 'update'
    return `This will ${verb} ${count} selected asset${count !== 1 ? 's' : ''}.`
}

export default function BulkActionsModal({
    assetIds,
    onClose,
    onComplete,
    onOpenMetadataEdit = null,
    /** Optional: { publishedCount, unpublishedCount, archivedCount, deletedCount, videoCount, approvalStates } for contextual actions */
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
    /** Optional: { asset: [...], deliverable: [...], ai_generated: [...] } from Assets grid — enables Library / Execution category assignment */
    bulkCategoriesByAssetType = null,
    /** Default tab for "Assign category" when typed categories are available ('asset' | 'deliverable' | 'ai_generated'). Executions grid should pass "deliverable". */
    defaultAssignAssetType = 'asset',
}) {
    const { auth } = usePage().props
    const { can } = usePermission()
    const canBulkRename = can('metadata.edit_post_upload')
    const canBulkRemoveTags = can('assets.tags.delete')
    const canQueueVideoInsights = can('metadata.edit_post_upload')
    const canSiteAdminPipeline = useMemo(() => {
        const roles = auth?.user?.site_roles
        if (!Array.isArray(roles)) return false
        return roles.some((r) => SITE_PIPELINE_ROLES.has(r))
    }, [auth?.user?.site_roles])

    const [step, setStep] = useState('select')
    const [selectedAction, setSelectedAction] = useState(null)
    const [fullPipelineResourceAck, setFullPipelineResourceAck] = useState(false)
    const [rejectionReason, setRejectionReason] = useState('')
    const [assignAssetType, setAssignAssetType] = useState(defaultAssignAssetType)
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
    const pipelineSelectionOverLimit = n > MAX_BULK_PIPELINE_ASSETS

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
        if (!canBulkRemoveTags) {
            g = g
                .map((gr) => ({
                    ...gr,
                    validActions: gr.validActions.filter((a) => a.id !== 'METADATA_REMOVE_TAGS'),
                }))
                .filter((gr) => gr.validActions.length > 0)
        }
        return g
    }, [validIds, categories, bulkCategoriesByAssetType, n, canBulkRename, canBulkRemoveTags])

    const publicationGroup = pickBulkGroupByLabel(groupsWithValidActions, 'Publication')
    const archiveGroup = pickBulkGroupByLabel(groupsWithValidActions, 'Archive')
    const renameGroup = pickBulkGroupByLabel(groupsWithValidActions, 'Rename')
    const approvalGroup = pickBulkGroupByLabel(groupsWithValidActions, 'Approval')
    const trashGroup = pickBulkGroupByLabel(groupsWithValidActions, 'Trash')
    const classificationGroup = pickBulkGroupByLabel(groupsWithValidActions, 'Classification')
    const assetDataGroup = pickBulkGroupByLabel(groupsWithValidActions, 'Asset data')

    const workflowTopGroups = [publicationGroup, archiveGroup, renameGroup].filter(Boolean)
    const workflowTopColClass =
        workflowTopGroups.length >= 3
            ? 'lg:grid-cols-3'
            : workflowTopGroups.length === 2
              ? 'md:grid-cols-2'
              : 'grid-cols-1'

    const videoCountForContext =
        selectionSummary && typeof selectionSummary.videoCount === 'number'
            ? selectionSummary.videoCount
            : Array.isArray(selectedAssetSummary)
              ? selectedAssetSummary.filter(assetLooksLikeVideo).length
              : null
    const selectionIncludesVideos = videoCountForContext === null || videoCountForContext > 0
    const showVideoAiBulk = canQueueVideoInsights && selectionIncludesVideos
    const showVideoAdminPipeline = selectionIncludesVideos

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
        if (step === 'configure' && selectedAction && (LIFECYCLE_ACTIONS.has(selectedAction) || BACKGROUND_QUEUE_BULK_ACTIONS.has(selectedAction))) {
            setConfirmPanelEntered(false)
            const id = setTimeout(() => setConfirmPanelEntered(true), 20)
            return () => clearTimeout(id)
        }
    }, [step, selectedAction])

    const handleSelectAction = useCallback((actionId) => {
        if (METADATA_ACTIONS.has(actionId) && onOpenMetadataEdit) {
            const op =
                actionId === 'METADATA_ADD'
                    ? 'add'
                    : actionId === 'METADATA_REPLACE'
                      ? 'replace'
                      : actionId === 'METADATA_REMOVE_TAGS'
                        ? 'remove'
                        : 'clear'
            onClose()
            onOpenMetadataEdit(assetIds, op)
            return
        }
        setSelectedAction(actionId)
        setFullPipelineResourceAck(false)
        setStep('configure')
        setError(null)
        if (actionId === ASSIGN_CATEGORY_ACTION) {
            setAssignCategoryId('')
            setAssignAssetType(defaultAssignAssetType)
        }
        if (actionId === RENAME_ASSETS_ACTION) {
            setBulkRenameBase('')
            setRenamePreviewExpanded(false)
        }
    }, [assetIds, onOpenMetadataEdit, onClose, defaultAssignAssetType])

    const handleBack = useCallback(() => {
        setStep('select')
        setSelectedAction(null)
        setFullPipelineResourceAck(false)
        setRejectionReason('')
        setBulkRenameBase('')
        setRenamePreviewExpanded(false)
        setError(null)
    }, [])

    const handleClose = useCallback(() => {
        setStep('select')
        setSelectedAction(null)
        setFullPipelineResourceAck(false)
        setRejectionReason('')
        setAssignCategoryId('')
        setAssignAssetType(defaultAssignAssetType)
        setBulkRenameBase('')
        setRenamePreviewExpanded(false)
        setError(null)
        onClose()
    }, [onClose, defaultAssignAssetType])

    const handleSubmit = async () => {
        if (selectedAction === 'REJECT' && !rejectionReason.trim()) {
            setError('Rejection reason is required.')
            return
        }
        if (selectedAction === ASSIGN_CATEGORY_ACTION && !assignCategoryId) {
            setError('Please select a folder.')
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
            const mainMsg = selectedAction === SITE_DELETE_VIDEO_PREVIEWS
                ? `Removed quick previews for ${processed} video${processed !== 1 ? 's' : ''}${skipped > 0 ? `. ${skipped} skipped.` : '.'}`
                : BACKGROUND_QUEUE_BULK_ACTIONS.has(selectedAction)
                  ? selectedAction === GENERATE_VIDEO_INSIGHTS
                      ? `Queued ${processed} video${processed !== 1 ? 's' : ''} for analysis${skipped > 0 ? `. ${skipped} skipped.` : ''}`
                      : `Processing started for ${processed} asset${processed !== 1 ? 's' : ''}.${skipped > 0 ? ` ${skipped} skipped.` : ''}`
                  : `${processed} asset${processed !== 1 ? 's' : ''} updated${skipped > 0 ? `. ${skipped} skipped.` : '.'}`
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
    const isBackgroundQueueBulk = selectedAction && BACKGROUND_QUEUE_BULK_ACTIONS.has(selectedAction)
    const isSitePipelineSyncDelete = selectedAction === SITE_DELETE_VIDEO_PREVIEWS
    const isFullPipelineBulk = selectedAction === SITE_FULL_PIPELINE_ACTION
    const pipelineSelectionBlocksAction = (isBackgroundQueueBulk || isSitePipelineSyncDelete) && pipelineSelectionOverLimit

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
                            <div className="space-y-8">
                                {(workflowTopGroups.length > 0 || approvalGroup || trashGroup) && (
                                    <BulkModalSuperSection
                                        title="Workflow"
                                        description="Publish, archive, rename, approvals, and trash — quick lifecycle controls for the selection."
                                    >
                                        {workflowTopGroups.length > 0 && (
                                            <div className={`grid grid-cols-1 gap-3 ${workflowTopColClass}`}>
                                                {workflowTopGroups.map((group, gIdx) => (
                                                    <BulkActionGroupBlock
                                                        key={group.label}
                                                        group={group}
                                                        groupIndex={gIdx}
                                                        onPick={handleSelectAction}
                                                        workflowColumn
                                                    />
                                                ))}
                                            </div>
                                        )}
                                        {(approvalGroup || trashGroup) && (
                                            <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                                                {approvalGroup ? (
                                                    <BulkActionGroupBlock
                                                        key={approvalGroup.label}
                                                        group={approvalGroup}
                                                        groupIndex={0}
                                                        onPick={handleSelectAction}
                                                    />
                                                ) : null}
                                                {trashGroup ? (
                                                    <BulkActionGroupBlock
                                                        key={trashGroup.label}
                                                        group={trashGroup}
                                                        groupIndex={approvalGroup ? 1 : 0}
                                                        onPick={handleSelectAction}
                                                    />
                                                ) : null}
                                            </div>
                                        )}
                                    </BulkModalSuperSection>
                                )}

                                {classificationGroup ? (
                                    <BulkModalSuperSection
                                        title="Folder"
                                        description="This is the primary classification for the grid and library routing. Get it right before fine-tuning fields or tags below."
                                    >
                                        <BulkActionGroupBlock
                                            group={classificationGroup}
                                            groupIndex={0}
                                            onPick={handleSelectAction}
                                            hideSectionHeader
                                        />
                                    </BulkModalSuperSection>
                                ) : null}

                                {assetDataGroup ? (
                                    <BulkModalSuperSection
                                        title="Asset data"
                                        description="Bulk edits to custom fields and tags. Folder above stays separate so placement stays intentional."
                                    >
                                        <BulkActionGroupBlock
                                            group={assetDataGroup}
                                            groupIndex={0}
                                            onPick={handleSelectAction}
                                            hideSectionHeader
                                        />
                                    </BulkModalSuperSection>
                                ) : null}

                                {showVideoAiBulk && (
                                    <BulkModalSuperSection
                                        title="AI-assisted"
                                        description="Video-only tools. This block appears when at least one selected file looks like a video; jobs still skip non-video rows."
                                    >
                                        <div className="rounded-xl border border-violet-200/90 bg-violet-50/35 p-3.5 shadow-sm">
                                            <div className="flex flex-wrap items-center gap-x-2 gap-y-0.5 border-b border-violet-100/90 pb-1.5">
                                                <span className="inline-flex items-center rounded bg-violet-600 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-white">
                                                    Video
                                                </span>
                                                <h3 className="text-xs font-semibold text-gray-800">Searchable video insights</h3>
                                            </div>
                                            <p className="mt-2 text-[11px] leading-snug text-gray-600">
                                                {videoCountForContext != null &&
                                                n > 0 &&
                                                videoCountForContext > 0 &&
                                                videoCountForContext < n
                                                    ? `${videoCountForContext} of ${n} selected look like video — analysis runs for those only.`
                                                    : 'Queue analysis for video files (summary, tags, transcript cues). Non-videos are skipped. Respects tenant video AI job and minute limits. Jobs run in the background.'}
                                            </p>
                                            {pipelineSelectionOverLimit && (
                                                <div className="mt-2 flex items-center gap-2 rounded-md border border-amber-200 bg-amber-50 px-2.5 py-1.5 text-[11px] text-amber-900">
                                                    <ExclamationTriangleIcon className="h-3.5 w-3.5 shrink-0" />
                                                    <span>
                                                        Select at most {MAX_BULK_PIPELINE_ASSETS} assets per processing bulk action.
                                                    </span>
                                                </div>
                                            )}
                                            <button
                                                type="button"
                                                onClick={() => handleSelectAction(GENERATE_VIDEO_INSIGHTS)}
                                                disabled={pipelineSelectionOverLimit}
                                                className="mt-2.5 flex w-full items-start gap-2.5 rounded-lg border border-violet-200 bg-white p-3 text-left shadow-sm transition-all hover:-translate-y-px hover:shadow-md disabled:cursor-not-allowed disabled:opacity-50"
                                            >
                                                <span className="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-violet-100">
                                                    <SparklesIcon className="h-4 w-4 text-violet-700" />
                                                </span>
                                                <div className="min-w-0 pt-0.5">
                                                    <span className="block text-xs font-semibold text-gray-900">
                                                        Analyze video content
                                                    </span>
                                                    <span className="mt-0.5 block text-[11px] leading-snug text-gray-500">
                                                        Make videos discoverable in search (tags, scenes, summary)
                                                    </span>
                                                </div>
                                            </button>
                                        </div>
                                    </BulkModalSuperSection>
                                )}

                                {canSiteAdminPipeline && (
                                    <BulkModalSuperSection title="Admin">
                                        <div className="rounded-xl border border-gray-200 bg-gray-50/60 p-3.5 shadow-sm">
                                            <div className="flex flex-wrap items-center gap-x-2 gap-y-0.5 border-b border-gray-200/80 pb-1.5">
                                                <span className="inline-flex items-center rounded bg-indigo-600 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-white">
                                                    Admin
                                                </span>
                                                <h3 className="text-xs font-semibold text-gray-800">
                                                    Processing &amp; Automation
                                                </h3>
                                            </div>
                                            <p className="mt-2 text-[11px] leading-snug text-gray-600">
                                                Site admin or engineering only. Matches per-asset drawer tools. Max{' '}
                                                {MAX_BULK_PIPELINE_ASSETS} assets; the server dispatches in chunks of 10 with a short
                                                delay between chunks.{' '}
                                                <span className="font-medium text-amber-800">
                                                    Background jobs — may take several minutes.
                                                </span>
                                            </p>
                                            {pipelineSelectionOverLimit && (
                                                <div className="mt-2 flex items-center gap-2 rounded-md border border-amber-200 bg-amber-50 px-2.5 py-1.5 text-[11px] text-amber-900">
                                                    <ExclamationTriangleIcon className="h-3.5 w-3.5 shrink-0" />
                                                    <span>
                                                        Select at most {MAX_BULK_PIPELINE_ASSETS} assets to use processing actions.
                                                    </span>
                                                </div>
                                            )}
                                            <div className="mt-2.5 grid grid-cols-1 gap-2 sm:grid-cols-2">
                                                <ProcessingActionCard
                                                    compact
                                                    icon="sparkles"
                                                    title="Re-run AI tagging"
                                                    description="Queue another pass on AI suggestions and tags for selected assets"
                                                    onClick={() => handleSelectAction(SITE_RERUN_AI_METADATA_TAGGING)}
                                                    disabled={pipelineSelectionOverLimit}
                                                />
                                                <ProcessingActionCard
                                                    compact
                                                    icon="photo"
                                                    title="Refresh previews"
                                                    description="Rebuild thumbnails and preview images"
                                                    onClick={() => handleSelectAction(SITE_RERUN_THUMBNAILS)}
                                                    disabled={pipelineSelectionOverLimit}
                                                />
                                                {showVideoAdminPipeline ? (
                                                    <>
                                                        <ProcessingActionCard
                                                            compact
                                                            icon="video"
                                                            title="Generate video previews"
                                                            description="Rebuild hover/quick preview MP4s with correct phone/MOV rotation"
                                                            onClick={() => handleSelectAction(SITE_GENERATE_VIDEO_PREVIEWS)}
                                                            disabled={pipelineSelectionOverLimit}
                                                        />
                                                        <ProcessingActionCard
                                                            compact
                                                            icon="trash"
                                                            title="Delete video quick previews"
                                                            description="Remove hover MP4 from storage and clear paths (no regeneration)"
                                                            variant="danger"
                                                            onClick={() => handleSelectAction(SITE_DELETE_VIDEO_PREVIEWS)}
                                                            disabled={pipelineSelectionOverLimit}
                                                        />
                                                    </>
                                                ) : null}
                                                <ProcessingActionCard
                                                    compact
                                                    icon="refresh"
                                                    title="Re-run metadata extraction"
                                                    description="Technical file metadata only"
                                                    onClick={() => handleSelectAction(SITE_REPROCESS_SYSTEM_METADATA)}
                                                    disabled={pipelineSelectionOverLimit}
                                                />
                                                <ProcessingActionCard
                                                    compact
                                                    icon="refreshDanger"
                                                    title="Reprocess entire asset"
                                                    description="Full pipeline — resource intensive"
                                                    variant="danger"
                                                    onClick={() => handleSelectAction(SITE_REPROCESS_FULL_PIPELINE)}
                                                    disabled={pipelineSelectionOverLimit}
                                                />
                                            </div>
                                        </div>
                                    </BulkModalSuperSection>
                                )}

                                {showPermissionWarning && (
                                    <div className="flex items-center gap-2 rounded-lg border border-amber-100 bg-amber-50 p-2.5">
                                        <ExclamationTriangleIcon className="h-4 w-4 shrink-0 text-amber-600" />
                                        <span className="text-xs text-amber-800">
                                            Some selected assets cannot be modified and will be skipped.
                                        </span>
                                    </div>
                                )}
                            </div>
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
                                        Folder
                                    </label>
                                    <select
                                        value={assignCategoryId}
                                        onChange={(e) => setAssignCategoryId(e.target.value)}
                                        className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                                    >
                                        <option value="">Choose a folder…</option>
                                        {assignCategoryOptions.map((cat) => (
                                            <option key={cat.id} value={cat.id}>{cat.name}</option>
                                        ))}
                                    </select>
                                    {assignCategoryOptions.length === 0 && (
                                        <p className="mt-2 text-xs text-amber-600">
                                            No folders for this asset type. Add a folder under Manage → Folders &amp;
                                            fields first.
                                        </p>
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

                            {(isLifecycle || isBackgroundQueueBulk || isSitePipelineSyncDelete) && (
                                <div
                                    className={`rounded-xl border p-4 mb-6 shadow-sm transition-all duration-[140ms] ease-out ${
                                        isSitePipelineSyncDelete
                                            ? 'border-amber-200 bg-amber-50/60'
                                            : isBackgroundQueueBulk
                                              ? 'border-indigo-200 bg-indigo-50/60'
                                              : 'border-gray-200 bg-gray-50/50'
                                    }`}
                                    style={{
                                        opacity: confirmPanelEntered ? 1 : 0,
                                        transform: confirmPanelEntered ? 'translateY(0)' : 'translateY(4px)',
                                    }}
                                >
                                    <h3 className="text-sm font-semibold text-gray-900 mb-2">
                                        {isSitePipeline
                                            ? 'Confirm site pipeline action'
                                            : selectedAction === GENERATE_VIDEO_INSIGHTS
                                              ? 'Confirm video analysis'
                                              : 'Confirm Bulk Action'}
                                    </h3>
                                    <p className="text-sm text-gray-700 mb-3">
                                        {getConfirmSummaryText(selectedAction, n)}
                                    </p>
                                    <p className="text-xs text-gray-500">
                                        {summaryLine}
                                    </p>
                                    {isBackgroundQueueBulk && (
                                        <p className="text-xs text-yellow-700 mt-3">
                                            These actions run in the background and may take several minutes.
                                        </p>
                                    )}
                                    {isSitePipelineSyncDelete && (
                                        <p className="text-xs text-amber-900/80 mt-3">
                                            Runs immediately: preview files are removed from storage and paths cleared on each asset (no background job).
                                        </p>
                                    )}
                                </div>
                            )}

                            {isFullPipelineBulk && (
                                <label className="mb-4 flex cursor-pointer items-start gap-3 rounded-xl border border-amber-200 bg-amber-50/60 p-4 shadow-sm">
                                    <input
                                        type="checkbox"
                                        className="mt-0.5 h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                        checked={fullPipelineResourceAck}
                                        onChange={(e) => setFullPipelineResourceAck(e.target.checked)}
                                    />
                                    <span className="text-sm text-gray-800">
                                        I understand this is resource intensive.
                                    </span>
                                </label>
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
                                        (isRename && (!bulkRenameBase.trim() || n < 2)) ||
                                        pipelineSelectionBlocksAction ||
                                        (isFullPipelineBulk && !fullPipelineResourceAck)
                                    }
                                    className={`px-4 py-2 text-sm font-medium rounded-xl disabled:opacity-50 disabled:pointer-events-none shadow-sm transition-colors ${
                                        isFullPipelineBulk
                                            ? 'text-white bg-red-600 hover:bg-red-700'
                                            : isSitePipelineSyncDelete
                                              ? 'text-white bg-amber-700 hover:bg-amber-800'
                                              : 'text-white bg-indigo-600 hover:bg-indigo-700'
                                    }`}
                                >
                                    {submitting
                                        ? `Processing ${n} assets...`
                                        : isSitePipelineSyncDelete
                                          ? 'Delete previews'
                                          : isBackgroundQueueBulk
                                        ? 'Queue jobs'
                                        : isLifecycle
                                        ? 'Confirm Action'
                                        : isReject
                                        ? 'Reject'
                                        : isAssignCategory
                                        ? 'Assign folder'
                                        : isRename
                                        ? 'Rename'
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
