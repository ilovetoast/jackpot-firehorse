import { useEffect } from 'react'
import { ExclamationTriangleIcon, ExclamationCircleIcon, InformationCircleIcon, XMarkIcon } from '@heroicons/react/24/outline'

export default function ConfirmDialog({
    open,
    onClose,
    onConfirm,
    title = 'Confirm',
    message,
    confirmText = 'Confirm',
    cancelText = 'Cancel',
    variant = 'info',
    loading = false,
    error = null,
}) {
    // Handle ESC key
    useEffect(() => {
        if (!open) return

        const handleEscape = (e) => {
            if (e.key === 'Escape' && !loading) {
                onClose()
            }
        }

        document.addEventListener('keydown', handleEscape)
        return () => document.removeEventListener('keydown', handleEscape)
    }, [open, onClose, loading])

    // Prevent body scroll when modal is open
    useEffect(() => {
        if (open) {
            document.body.style.overflow = 'hidden'
        } else {
            document.body.style.overflow = ''
        }
        return () => {
            document.body.style.overflow = ''
        }
    }, [open])

    if (!open) return null

    // Variant configuration
    const variantConfig = {
        danger: {
            icon: ExclamationTriangleIcon,
            iconBg: 'bg-red-100',
            iconColor: 'text-red-600',
            buttonBg: 'bg-red-600',
            buttonHover: 'hover:bg-red-500',
            buttonFocus: 'focus-visible:outline-red-600',
        },
        warning: {
            icon: ExclamationCircleIcon,
            iconBg: 'bg-amber-100',
            iconColor: 'text-amber-600',
            buttonBg: 'bg-amber-600',
            buttonHover: 'hover:bg-amber-500',
            buttonFocus: 'focus-visible:outline-amber-600',
        },
        info: {
            icon: InformationCircleIcon,
            iconBg: 'bg-blue-100',
            iconColor: 'text-blue-600',
            buttonBg: 'bg-blue-600',
            buttonHover: 'hover:bg-blue-500',
            buttonFocus: 'focus-visible:outline-blue-600',
        },
    }

    const config = variantConfig[variant] || variantConfig.info
    const Icon = config.icon

    const handleBackdropClick = (e) => {
        if (e.target === e.currentTarget && !loading) {
            onClose()
        }
    }

    return (
        <div className="fixed inset-0 z-50 overflow-y-auto">
            <div className="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                {/* Backdrop */}
                <div
                    className="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity"
                    onClick={handleBackdropClick}
                />

                {/* Modal */}
                <div className="relative transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:p-6">
                    {/* Close button */}
                    <div className="absolute right-0 top-0 hidden pr-4 pt-4 sm:block">
                        <button
                            type="button"
                            className="rounded-md bg-white text-gray-400 hover:text-gray-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                            onClick={onClose}
                            disabled={loading}
                        >
                            <span className="sr-only">Close</span>
                            <XMarkIcon className="h-6 w-6" aria-hidden="true" />
                        </button>
                    </div>

                    <div className="sm:flex sm:items-start">
                        {/* Icon */}
                        <div className={`mx-auto flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-full ${config.iconBg} sm:mx-0 sm:h-10 sm:w-10`}>
                            <Icon className={`h-6 w-6 ${config.iconColor}`} aria-hidden="true" />
                        </div>

                        {/* Content */}
                        <div className="mt-3 text-center sm:ml-4 sm:mt-0 sm:text-left w-full">
                            {error && (
                                <div className="mb-3 rounded-md bg-red-50 p-3">
                                    <p className="text-sm text-red-700">{error}</p>
                                </div>
                            )}
                            <h3 className="text-base font-semibold leading-6 text-gray-900">
                                {title}
                            </h3>
                            <div className="mt-2">
                                <p className="text-sm text-gray-500">
                                    {message}
                                </p>
                            </div>
                        </div>
                    </div>

                    {/* Actions */}
                    <div className="mt-5 sm:mt-4 sm:flex sm:flex-row-reverse">
                        <button
                            type="button"
                            onClick={onConfirm}
                            disabled={loading}
                            className={`inline-flex w-full justify-center rounded-md ${config.buttonBg} px-3 py-2 text-sm font-semibold text-white shadow-sm ${config.buttonHover} focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 ${config.buttonFocus} sm:ml-3 sm:w-auto disabled:opacity-50 disabled:cursor-not-allowed`}
                        >
                            {loading ? 'Processing...' : confirmText}
                        </button>
                        <button
                            type="button"
                            onClick={onClose}
                            disabled={loading}
                            className="mt-3 inline-flex w-full justify-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 sm:mt-0 sm:w-auto disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            {cancelText}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    )
}
