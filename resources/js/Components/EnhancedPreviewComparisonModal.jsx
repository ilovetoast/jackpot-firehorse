/**
 * Compare original vs polished (studio enhanced or clean preferred) preview.
 * Dismissal: `resources/js/utils/enhancedPreviewComparisonStorage.js`.
 */
import { useCallback, useEffect, useState } from 'react'
import { Dialog, DialogPanel, DialogTitle } from '@headlessui/react'
import { XMarkIcon } from '@heroicons/react/24/outline'

export {
    markEnhancedComparisonSeenForTemplate,
    shouldShowEnhancedComparisonForTemplate,
} from '../utils/enhancedPreviewComparisonStorage'

/**
 * @param {Object} props
 * @param {boolean} props.open
 * @param {() => void} props.onClose
 * @param {string|null|undefined} props.originalUrl
 * @param {string|null|undefined} props.enhancedUrl - polished preview URL (studio or clean)
 * @param {string} [props.polishedLabel] - right column title (e.g. "Studio preview")
 * @param {string|null|undefined} props.templateLabel
 */
export default function EnhancedPreviewComparisonModal({
    open,
    onClose,
    originalUrl,
    enhancedUrl,
    polishedLabel = 'Preview',
    templateLabel,
}) {
    const showOriginal = Boolean(originalUrl)
    const showPolished = Boolean(enhancedUrl)
    const [layout, setLayout] = useState(/** @type {'side' | 'toggle'} */ ('side'))
    const [toggleIsOriginal, setToggleIsOriginal] = useState(true)

    useEffect(() => {
        if (open) {
            setLayout('side')
            setToggleIsOriginal(true)
        }
    }, [open])

    const handleToggleImageClick = useCallback(() => {
        setToggleIsOriginal((v) => !v)
    }, [])

    const segmentBtn = (active) =>
        `rounded-md px-3 py-1.5 text-xs font-medium transition-colors ${
            active ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-600 hover:text-gray-900'
        }`

    return (
        <Dialog open={open} onClose={onClose} className="relative z-[200]">
            <div className="fixed inset-0 bg-black/50" aria-hidden />
            <div className="fixed inset-0 flex items-center justify-center overflow-y-auto p-4 sm:p-6">
                <DialogPanel className="w-full max-w-4xl rounded-xl border border-gray-200 bg-white shadow-2xl">
                    <div className="flex items-start justify-between gap-3 border-b border-gray-100 px-4 py-3 sm:px-5">
                        <div>
                            <DialogTitle className="text-base font-semibold text-gray-900">Compare previews</DialogTitle>
                            <p className="mt-1 text-sm text-gray-600">
                                Original next to {polishedLabel.toLowerCase()}
                                {templateLabel ? ` (${templateLabel})` : ''}.
                            </p>
                        </div>
                        <button
                            type="button"
                            onClick={onClose}
                            className="rounded-md p-1.5 text-gray-500 hover:bg-gray-100 hover:text-gray-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
                            aria-label="Close"
                        >
                            <XMarkIcon className="h-5 w-5" />
                        </button>
                    </div>

                    <div className="border-b border-gray-100 px-4 py-2 sm:px-5">
                        <div
                            className="inline-flex rounded-lg border border-gray-200 bg-gray-50 p-0.5"
                            role="group"
                            aria-label="Comparison layout"
                        >
                            <button
                                type="button"
                                onClick={() => setLayout('side')}
                                className={segmentBtn(layout === 'side')}
                            >
                                Side-by-side
                            </button>
                            <button
                                type="button"
                                onClick={() => setLayout('toggle')}
                                className={segmentBtn(layout === 'toggle')}
                            >
                                Toggle
                            </button>
                        </div>
                        {layout === 'toggle' ? (
                            <p className="mt-2 text-xs text-gray-500">
                                Use the buttons below or click the image to switch quickly.
                            </p>
                        ) : null}
                    </div>

                    {layout === 'side' ? (
                        <div className="grid gap-4 p-4 sm:grid-cols-2 sm:p-5">
                            <div className="min-w-0">
                                <p className="mb-2 text-sm font-semibold text-gray-900">Original</p>
                                <div className="relative flex aspect-[4/3] items-center justify-center overflow-hidden rounded-lg border border-gray-200 bg-gray-50">
                                    {showOriginal ? (
                                        <img src={originalUrl} alt="" className="max-h-full max-w-full object-contain" />
                                    ) : (
                                        <span className="text-sm text-gray-500">No preview</span>
                                    )}
                                </div>
                            </div>
                            <div className="min-w-0">
                                <p className="mb-2 text-sm font-semibold text-gray-900">{polishedLabel}</p>
                                <div className="relative flex aspect-[4/3] items-center justify-center overflow-hidden rounded-lg border border-gray-200 bg-gray-50">
                                    {showPolished ? (
                                        <img src={enhancedUrl} alt="" className="max-h-full max-w-full object-contain" />
                                    ) : (
                                        <span className="text-sm text-gray-500">No preview yet</span>
                                    )}
                                </div>
                            </div>
                        </div>
                    ) : (
                        <div className="p-4 sm:p-5">
                            <div className="mb-3">
                                <p className="mb-2 text-xs font-medium uppercase tracking-wide text-gray-500">View</p>
                                <div
                                    className="inline-flex rounded-lg border border-gray-200 bg-gray-50 p-0.5"
                                    role="group"
                                    aria-label="Switch comparison side"
                                >
                                    <button
                                        type="button"
                                        aria-pressed={toggleIsOriginal}
                                        onClick={() => setToggleIsOriginal(true)}
                                        className={segmentBtn(toggleIsOriginal)}
                                    >
                                        Original
                                    </button>
                                    <button
                                        type="button"
                                        aria-pressed={!toggleIsOriginal}
                                        onClick={() => setToggleIsOriginal(false)}
                                        className={segmentBtn(!toggleIsOriginal)}
                                    >
                                        {polishedLabel}
                                    </button>
                                </div>
                            </div>
                            <p className="mb-2 text-sm font-semibold text-gray-900">
                                {toggleIsOriginal ? 'Original' : polishedLabel}
                            </p>
                            <button
                                type="button"
                                onClick={handleToggleImageClick}
                                className="relative flex w-full aspect-[4/3] cursor-pointer items-center justify-center overflow-hidden rounded-lg border border-gray-200 bg-gray-50 outline-none ring-indigo-500 transition hover:border-gray-300 focus-visible:ring-2"
                                aria-label={
                                    toggleIsOriginal
                                        ? `Showing original. Click to show ${polishedLabel}.`
                                        : `Showing ${polishedLabel}. Click to show original.`
                                }
                            >
                                {toggleIsOriginal ? (
                                    showOriginal ? (
                                        <img src={originalUrl} alt="" className="max-h-full max-w-full object-contain" />
                                    ) : (
                                        <span className="text-sm text-gray-500">No preview</span>
                                    )
                                ) : showPolished ? (
                                    <img src={enhancedUrl} alt="" className="max-h-full max-w-full object-contain" />
                                ) : (
                                    <span className="text-sm text-gray-500">No preview yet</span>
                                )}
                            </button>
                        </div>
                    )}

                    <div className="border-t border-gray-100 px-4 py-3 sm:px-5">
                        <button
                            type="button"
                            onClick={onClose}
                            className="w-full rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 sm:w-auto"
                        >
                            Got it
                        </button>
                    </div>
                </DialogPanel>
            </div>
        </Dialog>
    )
}
