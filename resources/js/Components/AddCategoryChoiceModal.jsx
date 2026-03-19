/**
 * Add Category choice modal — Bulk Actions style.
 * Two options: Add New Category (custom) or Add Existing Category (system template).
 */
import { useState, useEffect } from 'react'
import { XMarkIcon, PlusCircleIcon, FolderIcon } from '@heroicons/react/24/outline'

const ADD_NEW = 'add_new'
const ADD_EXISTING = 'add_existing'

export default function AddCategoryChoiceModal({
    isOpen,
    onClose,
    onSelectAddNew,
    onSelectAddExisting,
}) {
    const [entered, setEntered] = useState(false)

    useEffect(() => {
        if (isOpen) {
            const t = requestAnimationFrame(() => requestAnimationFrame(() => setEntered(true)))
            return () => cancelAnimationFrame(t)
        }
        setEntered(false)
    }, [isOpen])

    if (!isOpen) return null

    const handleAddNew = () => {
        onClose()
        onSelectAddNew?.()
    }

    const handleAddExisting = () => {
        onClose()
        onSelectAddExisting?.()
    }

    return (
        <div
            className="fixed inset-0 z-[100] flex items-start sm:items-center justify-center p-4 pt-8 sm:pt-4 bg-black/50 overflow-y-auto"
            onClick={onClose}
        >
            <div
                className="bg-white rounded-xl shadow-lg max-w-2xl w-full max-h-[90vh] overflow-y-auto transition-all duration-[180ms] ease-[cubic-bezier(0.16,1,0.3,1)]"
                style={{
                    opacity: entered ? 1 : 0,
                    transform: entered ? 'translateY(0)' : 'translateY(8px)',
                }}
                onClick={(e) => e.stopPropagation()}
            >
                <div className="flex items-center justify-between px-6 md:px-8 py-4 border-b border-gray-200">
                    <div className="min-w-0">
                        <h2 className="text-lg font-semibold text-gray-900 truncate">
                            Add Category
                        </h2>
                        <p className="mt-0.5 text-sm text-gray-500">
                            Create a new category or add one from the system library.
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        className="ml-2 p-1.5 rounded-lg text-gray-500 hover:bg-gray-100 shrink-0 transition-colors"
                        aria-label="Close"
                    >
                        <XMarkIcon className="w-5 h-5" />
                    </button>
                </div>

                <div className="px-6 md:px-8 py-6">
                    <div className="space-y-6">
                        <div className="space-y-4">
                            <div className="space-y-1">
                                <h3 className="text-sm font-semibold text-gray-700">
                                    Create
                                </h3>
                                <p className="text-xs text-gray-500 leading-tight">
                                    Define a custom category with your own name and settings.
                                </p>
                            </div>
                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <button
                                    type="button"
                                    onClick={handleAddNew}
                                    className="flex w-full items-center gap-3 p-3.5 text-left rounded-xl bg-white shadow-sm border border-gray-100 transition-all duration-150 ease-out hover:shadow-md hover:-translate-y-px hover:bg-gray-50/80 active:scale-[0.98]"
                                >
                                    <span className="flex items-center justify-center w-8 h-8 rounded-lg shrink-0 bg-gray-100">
                                        <PlusCircleIcon className="w-4 h-4 text-gray-600" />
                                    </span>
                                    <div className="min-w-0">
                                        <span className="block text-sm font-medium text-gray-900">
                                            Add New Category
                                        </span>
                                        <span className="block text-xs text-gray-500 leading-snug mt-0.5">
                                            Create a custom category with your own name and access rules.
                                        </span>
                                    </div>
                                </button>

                                <button
                                    type="button"
                                    onClick={handleAddExisting}
                                    className="flex w-full items-center gap-3 p-3.5 text-left rounded-xl bg-white shadow-sm border border-gray-100 transition-all duration-150 ease-out hover:shadow-md hover:-translate-y-px hover:bg-gray-50/80 active:scale-[0.98]"
                                >
                                    <span className="flex items-center justify-center w-8 h-8 rounded-lg shrink-0 bg-gray-100">
                                        <FolderIcon className="w-4 h-4 text-gray-600" />
                                    </span>
                                    <div className="min-w-0">
                                        <span className="block text-sm font-medium text-gray-900">
                                            Add Existing Category
                                        </span>
                                        <span className="block text-xs text-gray-500 leading-snug mt-0.5">
                                            Add a pre-built category from the system library (e.g. Digital, Print).
                                        </span>
                                    </div>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    )
}
