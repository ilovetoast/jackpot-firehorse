/**
 * Storage Upgrade Modal — Inline storage add-on purchase during upload
 *
 * Shown when upload fails due to storage limit exceeded.
 * Allows user to add storage without leaving the upload screen.
 */
import { useState } from 'react'
import { XMarkIcon, CloudIcon } from '@heroicons/react/24/outline'

export default function StorageUpgradeModal({
    open,
    onClose,
    currentUsageMb,
    maxStorageMb,
    addonPackages = [],
    onSuccess,
    onRetry,
}) {
    const [submitting, setSubmitting] = useState(false)
    const [error, setError] = useState(null)

    const handleAddPackage = async (packageId) => {
        if (!packageId || submitting) return
        setError(null)
        setSubmitting(true)
        try {
            const response = await window.axios.post('/app/billing/storage-addon', {
                package_id: packageId,
            }, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            })
            const data = response.data
            if (data?.storage) {
                onSuccess?.(data.storage)
            }
            onRetry?.()
            onClose()
        } catch (err) {
            const msg = err.response?.data?.message ?? 'Failed to add storage. Please try again.'
            setError(msg)
        } finally {
            setSubmitting(false)
        }
    }

    const handleClose = () => {
        if (!submitting) {
            setError(null)
            onClose()
        }
    }

    if (!open) return null

    return (
        <div className="fixed inset-0 z-[90] overflow-y-auto" aria-labelledby="storage-upgrade-title" role="dialog" aria-modal="true">
            <div className="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                <div className="fixed inset-0 bg-gray-500/75 transition-opacity z-[90]" aria-hidden="true" onClick={handleClose} />
                <div className="relative transform overflow-hidden rounded-lg bg-white text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-md z-[91]">
                    <div className="bg-white px-4 pb-4 pt-5 sm:p-6 sm:pb-4">
                        <div className="flex items-center justify-between mb-4">
                            <h3 className="text-lg font-semibold text-gray-900" id="storage-upgrade-title">
                                Storage limit exceeded
                            </h3>
                            <button
                                type="button"
                                onClick={handleClose}
                                disabled={submitting}
                                className="rounded-md text-gray-400 hover:text-gray-600 focus:outline-none focus:ring-2 focus:ring-indigo-500 disabled:opacity-50"
                            >
                                <XMarkIcon className="h-5 w-5" />
                            </button>
                        </div>

                        <p className="text-sm text-gray-600 mb-4">
                            Your upload cannot complete because you've reached your storage limit.
                            Add more storage to continue.
                        </p>

                        <div className="mb-4 rounded-md bg-gray-50 p-3 text-sm">
                            <div className="flex items-center gap-2 text-gray-700">
                                <CloudIcon className="h-4 w-4" />
                                <span>
                                    Current: <strong>{Number(currentUsageMb).toFixed(1)} MB</strong> used
                                    <span className="mx-1">/</span>
                                    <strong>{Number(maxStorageMb).toFixed(1)} MB</strong> limit
                                </span>
                            </div>
                        </div>

                        {error && (
                            <div className="mb-4 rounded-md bg-red-50 p-3 text-sm text-red-700">
                                {error}
                            </div>
                        )}

                        <div className="space-y-2">
                            <p className="text-sm font-medium text-gray-700">Add storage:</p>
                            <div className="grid gap-2">
                                {(addonPackages || []).filter(p => p.stripe_price_id).map((pkg) => (
                                    <button
                                        key={pkg.id}
                                        type="button"
                                        onClick={() => handleAddPackage(pkg.id)}
                                        disabled={submitting}
                                        className="flex items-center justify-between w-full rounded-md border border-gray-300 bg-white px-4 py-3 text-left text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed"
                                    >
                                        <span>{pkg.label}</span>
                                        <span className="text-indigo-600 font-semibold">
                                            ${Number(pkg.monthly_price).toFixed(2)}/mo
                                        </span>
                                    </button>
                                ))}
                            </div>
                            {(!addonPackages || addonPackages.length === 0) && (
                                <p className="text-sm text-gray-500">No storage packages available. Contact support.</p>
                            )}
                        </div>

                        <p className="mt-4 text-xs text-gray-500">
                            If you close this modal, your upload will remain paused. You can add storage later from Billing.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    )
}
