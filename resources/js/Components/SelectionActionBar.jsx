/**
 * Phase 4 — Unified Selection ActionBar
 *
 * Floating bar shown when items are selected.
 * Replaces DownloadBucketBar visually (DownloadBucketBar not deleted yet).
 */
import { useState, useCallback, useEffect } from 'react'
import { usePage } from '@inertiajs/react'
import { useSelection } from '../contexts/SelectionContext'
import { usePermission } from '../hooks/usePermission'
import { useBucketOptional } from '../contexts/BucketContext'
import CreateDownloadPanel from './CreateDownloadPanel'
import ConfirmDialog from './ConfirmDialog'
import SelectedItemsDrawer from './SelectedItemsDrawer'

export default function SelectionActionBar({
    currentPageIds = [],
    currentPageItems = [],
    /** Open bulk actions modal (Publish, Archive, Trash, Metadata, etc.) */
    onOpenBulkEdit = null,
}) {
    const { auth } = usePage().props
    const { can } = usePermission()
    const primaryColor = auth?.activeBrand?.primary_color || '#6366f1'
    // Use same pattern as AssetDetailPanel/drawer: effective_permissions
    const canEditMetadata = auth?.permissions?.can_edit_metadata === true || can('metadata.edit_post_upload') || can('metadata.bulk_edit')
    const selection = useSelection()
    const bucket = useBucketOptional()

    const {
        selectedItems,
        selectedCount,
        clearSelection,
        getSelectedOnPage,
        getSelectionTypeBreakdown,
        selectMultiple,
        deselectItem,
        getSelectedIds,
        isSelected,
    } = selection

    const [showCreatePanel, setShowCreatePanel] = useState(false)
    const [drawerOpen, setDrawerOpen] = useState(false)
    const [showBulkEditConfirm, setShowBulkEditConfirm] = useState(false)
    const [showMixedTypeModal, setShowMixedTypeModal] = useState(false)
    const [mixedTypeMessage, setMixedTypeMessage] = useState('')
    const [showLargeSelectionConfirm, setShowLargeSelectionConfirm] = useState(false)
    const [pendingBulkEditIds, setPendingBulkEditIds] = useState([])
    const [countJustChanged, setCountJustChanged] = useState(false)

    const TYPE_LABELS = { asset: 'Assets', execution: 'Executions', collection: 'Collections', generative: 'Generative' }

    // Phase 10: Count badge subtle pop when selectedCount changes
    useEffect(() => {
        if (selectedCount === 0) return
        setCountJustChanged(true)
        const id = setTimeout(() => setCountJustChanged(false), 150)
        return () => clearTimeout(id)
    }, [selectedCount])

    const pageSelected = getSelectedOnPage(currentPageIds)
    const allPageSelected = currentPageIds.length > 0 && pageSelected.length === currentPageIds.length

    const handleSelectAllPage = useCallback(() => {
        if (currentPageIds.length === 0) return
        if (allPageSelected) {
            currentPageIds.forEach((id) => deselectItem(id))
        } else {
            const missing = currentPageItems.filter((item) => item?.id && !isSelected(item.id))
            if (missing.length > 0) {
                selectMultiple(missing)
            }
        }
    }, [currentPageIds, currentPageItems, allPageSelected, deselectItem, selectMultiple, isSelected])

    const handleCreateDownload = useCallback(async () => {
        if (!bucket) return
        const ids = getSelectedIds()
        await bucket.bucketClear()
        if (ids.length > 0) {
            await bucket.bucketAddBatch(ids)
        }
        setShowCreatePanel(true)
    }, [bucket, getSelectedIds])

    const handleCreateSuccess = useCallback(() => {
        setShowCreatePanel(false)
        clearSelection()
    }, [clearSelection])

    const handleOpenBulkActionsModal = useCallback(() => {
        const ids = pageSelected.map((item) => item.id)
        if (ids.length === 0) return
        if (selectedCount > 100) {
            setPendingBulkEditIds(ids)
            setShowLargeSelectionConfirm(true)
            return
        }
        if (pageSelected.length !== selectedCount) {
            setPendingBulkEditIds(ids)
            setShowBulkEditConfirm(true)
        } else {
            onOpenBulkEdit?.(ids)
        }
    }, [pageSelected, selectedCount, onOpenBulkEdit])

    const handleConfirmBulkEdit = useCallback(() => {
        setShowBulkEditConfirm(false)
        if (pendingBulkEditIds.length > 0) {
            onOpenBulkEdit?.(pendingBulkEditIds)
            setPendingBulkEditIds([])
        }
    }, [pendingBulkEditIds, onOpenBulkEdit])

    const handleConfirmLargeSelection = useCallback(() => {
        setShowLargeSelectionConfirm(false)
        if (pendingBulkEditIds.length === 0) return
        if (pageSelected.length !== selectedCount) {
            setShowBulkEditConfirm(true)
        } else {
            onOpenBulkEdit?.(pendingBulkEditIds)
            setPendingBulkEditIds([])
        }
    }, [pendingBulkEditIds, pageSelected.length, selectedCount, onOpenBulkEdit])

    const items = selectedItems.map((item) => ({
        id: item.id,
        original_filename: item.name,
        title: item.name,
        thumbnail_url: item.thumbnail_url,
        final_thumbnail_url: item.thumbnail_url,
        preview_thumbnail_url: item.thumbnail_url,
    }))

    const breakdown = getSelectionTypeBreakdown()
    const types = Object.keys(breakdown)
    const isMixedType = types.length > 1
    const mixedTypeTooltip = isMixedType
        ? `Actions can only be applied to one type at a time. You have selected: ${types.map((t) => `${TYPE_LABELS[t] || t} (${breakdown[t]})`).join(', ')}. Please refine your selection.`
        : null

    return (
        <>
            <div
                className={`fixed left-1/2 -translate-x-1/2 z-50 transition-all duration-200 ease-out ${
                    selectedCount > 0 ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-3 pointer-events-none'
                } app-mobile-selection-bar-offset`}
            >
                <div className="relative">
                    <SelectedItemsDrawer
                        open={drawerOpen}
                        onClose={() => setDrawerOpen(false)}
                        canEditMetadata={canEditMetadata}
                        onCreateDownload={handleCreateDownload}
                        onOpenActions={onOpenBulkEdit ? handleOpenBulkActionsModal : null}
                    />
                    <div
                        className="flex flex-row items-center justify-between gap-1.5 sm:gap-4 px-2.5 py-1.5 sm:px-4 sm:py-3 bg-white/95 backdrop-blur-md rounded-full shadow-2xl border border-gray-200 w-full max-w-[900px] transition-shadow duration-200 hover:shadow-[0_35px_60px_-15px_rgba(0,0,0,0.3)]"
                        style={{ maxWidth: 'min(900px, calc(100vw - 2rem))' }}
                    >
                    {/* Cluster 1: Selection info — compact on mobile */}
                    <div className="flex items-center gap-1.5 sm:gap-3 min-w-0">
                        <span className={`text-xs sm:text-sm font-semibold text-gray-700 tabular-nums transition-transform duration-150 shrink-0 ${countJustChanged ? 'scale-110' : 'scale-100'}`}>
                            <span className="sm:hidden">{selectedCount}</span>
                            <span className="hidden sm:inline">{selectedCount} selected</span>
                        </span>
                        <button
                            type="button"
                            onClick={() => setDrawerOpen(true)}
                            className="text-xs sm:text-sm font-medium text-gray-600 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 rounded px-1 py-0.5 sm:px-2 sm:py-1 transition-all duration-100 hover:bg-gray-100 active:scale-95 shrink-0"
                            title="Preview selected items"
                        >
                            Preview
                        </button>
                    </div>

                    {/* Cluster 2: Primary actions — "Download" on mobile, "Create Download" on desktop */}
                    <div className="flex items-center gap-1 sm:gap-2 shrink-0">
                        <button
                            type="button"
                            onClick={handleCreateDownload}
                            className="inline-flex items-center rounded-md px-2.5 py-1.5 sm:px-4 sm:py-2 text-xs sm:text-sm font-semibold text-white shadow-sm hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 transition-all duration-100 active:scale-95 shrink-0"
                            style={{ backgroundColor: primaryColor, ['--tw-ring-color']: primaryColor }}
                            title="Create download link"
                        >
                            <span className="sm:hidden">Download</span>
                            <span className="hidden sm:inline">Create Download</span>
                        </button>
                        {canEditMetadata && onOpenBulkEdit && (
                            <span
                                title={isMixedType ? mixedTypeTooltip : undefined}
                                className={isMixedType ? 'inline-flex cursor-not-allowed' : 'inline-flex'}
                            >
                                <button
                                    type="button"
                                    onClick={isMixedType ? undefined : handleOpenBulkActionsModal}
                                    disabled={isMixedType}
                                    className="inline-flex items-center rounded-md px-2 py-1.5 sm:px-4 sm:py-2 text-xs sm:text-sm font-medium border border-gray-300 bg-white text-gray-700 shadow-sm hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition-all duration-100 active:scale-95 shrink-0"
                                    title="Bulk actions"
                                >
                                    Actions
                                </button>
                            </span>
                        )}
                    </div>

                    {/* Cluster 3: Secondary controls — shorter labels on mobile */}
                    <div className="flex items-center gap-1 shrink-0">
                        <button
                            type="button"
                            onClick={handleSelectAllPage}
                            className="text-xs sm:text-sm font-medium text-gray-600 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 rounded px-1 py-0.5 sm:px-2 sm:py-1 transition-all duration-100 hover:bg-gray-100 active:scale-95 shrink-0"
                            title={allPageSelected ? 'Deselect all' : 'Select all on page'}
                        >
                            <span className="hidden md:inline">{allPageSelected ? 'Deselect all' : 'Select all (page)'}</span>
                            <span className="hidden sm:inline md:hidden">{allPageSelected ? 'Deselect' : 'Select all'}</span>
                            <span className="sm:hidden">{allPageSelected ? 'Deselect' : 'All'}</span>
                        </button>
                        <button
                            type="button"
                            onClick={clearSelection}
                            className="text-xs sm:text-sm font-medium text-gray-600 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 rounded px-1 py-0.5 sm:px-2 sm:py-1 transition-all duration-100 hover:bg-gray-100 active:scale-95 shrink-0"
                            title="Clear selection"
                        >
                            Clear
                        </button>
                    </div>
                </div>
                </div>
            </div>

            <CreateDownloadPanel
                open={showCreatePanel}
                onClose={() => setShowCreatePanel(false)}
                bucketCount={selectedCount}
                previewItems={items}
                onSuccess={handleCreateSuccess}
            />

            <ConfirmDialog
                open={showBulkEditConfirm}
                onClose={() => setShowBulkEditConfirm(false)}
                onConfirm={handleConfirmBulkEdit}
                title="Actions scope"
                message={`Actions apply only to items on this page. Continue with ${pendingBulkEditIds.length} items?`}
                confirmText="Continue"
                cancelText="Cancel"
                variant="info"
            />

            <ConfirmDialog
                open={showMixedTypeModal}
                onClose={() => setShowMixedTypeModal(false)}
                onConfirm={() => setShowMixedTypeModal(false)}
                title="Mixed selection"
                message={mixedTypeMessage}
                confirmText="OK"
                cancelText="Cancel"
                variant="warning"
            />

            <ConfirmDialog
                open={showLargeSelectionConfirm}
                onClose={() => { setShowLargeSelectionConfirm(false); setPendingBulkEditIds([]) }}
                onConfirm={handleConfirmLargeSelection}
                title="Large selection"
                message={`You are about to bulk edit ${selectedCount} items. Continue?`}
                confirmText="Continue"
                cancelText="Cancel"
                variant="info"
            />
        </>
    )
}
