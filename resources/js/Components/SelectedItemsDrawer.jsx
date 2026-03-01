/**
 * Selected Items Preview Panel
 *
 * Expand-up panel anchored to SelectionActionBar.
 * Shows selected items with thumbnails, names, type badges.
 */
import { useEffect, useState } from 'react'
import { XMarkIcon, ArrowDownTrayIcon } from '@heroicons/react/24/outline'
import { useSelection } from '../contexts/SelectionContext'
import FileTypeIcon from './FileTypeIcon'

const TYPE_LABELS = {
    asset: 'Asset',
    execution: 'Execution',
    collection: 'Collection',
    generative: 'Generative',
}

export default function SelectedItemsDrawer({
    open,
    onClose,
    canEditMetadata = false,
    onCreateDownload = null,
    onOpenActions = null,
}) {
    const { selectedItems, selectedCount, deselectItem, clearSelection } = useSelection()
    const [isVisible, setIsVisible] = useState(false)
    const [exiting, setExiting] = useState(false)
    const [removingIds, setRemovingIds] = useState(new Set())

    useEffect(() => {
        if (!open) return
        const handleEscape = (e) => {
            if (e.key === 'Escape') onClose()
        }
        document.addEventListener('keydown', handleEscape)
        return () => document.removeEventListener('keydown', handleEscape)
    }, [open, onClose])

    useEffect(() => {
        if (open) {
            setExiting(false)
            setIsVisible(false)
            const id = requestAnimationFrame(() => {
                requestAnimationFrame(() => setIsVisible(true))
            })
            return () => cancelAnimationFrame(id)
        } else {
            setExiting(true)
            const id = setTimeout(() => setExiting(false), 150)
            return () => clearTimeout(id)
        }
    }, [open])

    const handleRemoveItem = (itemId) => {
        setRemovingIds((prev) => new Set(prev).add(itemId))
        setTimeout(() => {
            deselectItem(itemId)
            setRemovingIds((prev) => {
                const next = new Set(prev)
                next.delete(itemId)
                return next
            })
        }, 150)
    }

    if (!open && !exiting) return null

    const handleClear = () => {
        clearSelection()
        onClose()
    }

    const panelVisible = open && isVisible && !exiting

    return (
        <div
            className={`absolute bottom-full mb-3 left-1/2 -translate-x-1/2 w-[calc(100vw-2rem)] sm:w-[520px] selected-items-drawer-max-h bg-white rounded-xl shadow-xl border border-gray-200 flex flex-col overflow-hidden transition-all duration-150 ease-out ${
                panelVisible ? 'opacity-100 translate-y-0 scale-100' : 'opacity-0 translate-y-2 scale-95 pointer-events-none'
            }`}
            role="dialog"
            aria-labelledby="selected-items-title"
        >
            {/* Header */}
            <div className="px-4 py-3 border-b flex items-center justify-between flex-shrink-0">
                <div className="font-semibold text-sm tabular-nums">
                    Selected Items ({selectedCount})
                </div>
                <div className="flex gap-3">
                    <button
                        type="button"
                        onClick={handleClear}
                        className="text-sm font-medium text-gray-600 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 rounded px-2 py-1 transition-all duration-100 hover:bg-gray-100 active:scale-95"
                    >
                        Clear
                    </button>
                    <button
                        type="button"
                        onClick={onClose}
                        className="p-1 rounded-md text-gray-500 hover:text-gray-700 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-indigo-500 transition-all duration-100 active:scale-95"
                        aria-label="Close"
                    >
                        ✕
                    </button>
                </div>
            </div>

            {/* Scrollable list */}
            <div className="overflow-y-auto p-3 space-y-2 flex-1 min-h-0">
                {selectedItems.length === 0 ? (
                    <div className="py-6 text-center text-sm text-gray-500">
                        No items selected
                    </div>
                ) : (
                    <>
                    {(selectedCount > 75 ? selectedItems.slice(0, 50) : selectedItems).map((item) => (
                        <div
                            key={item.id}
                            className={`flex items-center gap-3 p-2 rounded-lg hover:bg-gray-50 transition-all duration-150 ease-out ${
                                removingIds.has(item.id) ? 'opacity-0 -translate-x-2' : ''
                            }`}
                        >
                            {/* Thumbnail 64x64 */}
                            <div className="flex-shrink-0 w-16 h-16 rounded-md overflow-hidden bg-gray-100">
                                {item.thumbnail_url ? (
                                    <img
                                        src={item.thumbnail_url}
                                        alt=""
                                        className="w-full h-full object-cover"
                                    />
                                ) : (
                                    <div className="w-full h-full flex items-center justify-center">
                                        <FileTypeIcon
                                            mimeType={null}
                                            fileExtension={null}
                                            size="md"
                                            iconClassName="text-gray-400"
                                        />
                                    </div>
                                )}
                            </div>

                            {/* Name + type */}
                            <div className="flex-1 min-w-0">
                                <p className="text-sm font-medium text-gray-900 truncate">
                                    {item.name || 'Untitled'}
                                </p>
                                <span className="inline-block mt-0.5 px-2 py-0.5 text-xs font-medium rounded bg-gray-100 text-gray-600">
                                    {TYPE_LABELS[item.type] || item.type}
                                </span>
                            </div>

                            {/* Remove */}
                            <button
                                type="button"
                                onClick={() => handleRemoveItem(item.id)}
                                className="flex-shrink-0 p-1.5 rounded-md text-gray-400 hover:text-gray-600 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-indigo-500 transition-all duration-100 active:scale-95"
                                aria-label={`Remove ${item.name || 'item'}`}
                            >
                                <XMarkIcon className="h-5 w-5" />
                            </button>
                        </div>
                    ))}
                    {selectedCount > 75 && (
                        <div className="py-3 text-center text-sm text-gray-500">
                            + {selectedCount - 50} more items selected
                        </div>
                    )}
                    </>
                )}
            </div>

            {/* Footer sticky */}
            <div className="p-3 border-t flex items-center justify-between text-sm flex-shrink-0">
                <button
                    type="button"
                    onClick={handleClear}
                    className="text-gray-500 hover:text-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 rounded"
                >
                    Clear
                </button>
                <div className="flex gap-4 items-center">
                    {canEditMetadata && onOpenActions && (
                        <button
                            type="button"
                            onClick={() => {
                                onOpenActions()
                                onClose()
                            }}
                            className="text-gray-600 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 rounded transition-all duration-100 hover:bg-gray-100 active:scale-95"
                        >
                            Actions
                        </button>
                    )}
                    <button
                        type="button"
                        onClick={() => {
                            onCreateDownload?.()
                            onClose()
                        }}
                        className="flex items-center gap-1 text-gray-600 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 rounded transition-all duration-100 hover:bg-gray-100 active:scale-95"
                    >
                        <ArrowDownTrayIcon className="w-4 h-4" />
                        Download
                    </button>
                </div>
            </div>
        </div>
    )
}
