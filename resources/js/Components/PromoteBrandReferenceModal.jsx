import { useEffect, useState } from 'react'
import { XMarkIcon } from '@heroicons/react/24/outline'

/**
 * Confirm promoting an asset into the brand style-reference pool (EBI).
 *
 * @param {Object} props
 * @param {boolean} props.isOpen
 * @param {() => void} props.onClose
 * @param {string} props.assetId
 * @param {'reference'|'guideline'} props.initialType
 * @param {(payload: { reference_promotion: { kind: string, tier: number, category: string|null } }) => void} props.onSuccess
 */
export default function PromoteBrandReferenceModal({ isOpen, onClose, assetId, initialType = 'reference', onSuccess }) {
    const [type, setType] = useState(initialType)
    const [category, setCategory] = useState('')
    const [loading, setLoading] = useState(false)
    const [error, setError] = useState(null)

    useEffect(() => {
        if (!isOpen) return
        setType(initialType === 'guideline' ? 'guideline' : 'reference')
        setCategory('')
        setError(null)
    }, [isOpen, initialType])

    if (!isOpen) {
        return null
    }

    const handleSubmit = async (e) => {
        e.preventDefault()
        setLoading(true)
        setError(null)
        try {
            const token = document.querySelector('meta[name="csrf-token"]')?.content
            const res = await fetch(`/app/api/brand-assets/${assetId}/promote`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': token,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    type,
                    category: category.trim() || null,
                }),
            })
            const data = await res.json().catch(() => ({}))
            if (!res.ok) {
                const msg =
                    data?.message ||
                    data?.errors?.asset?.[0] ||
                    data?.errors?.type?.[0] ||
                    `Request failed (${res.status})`
                throw new Error(msg)
            }
            const promo = data?.reference?.reference_promotion
            if (promo && onSuccess) {
                onSuccess({ reference_promotion: promo })
            }
            onClose()
        } catch (err) {
            setError(err instanceof Error ? err.message : 'Promotion failed')
        } finally {
            setLoading(false)
        }
    }

    return (
        <div className="fixed inset-0 z-[80] flex items-center justify-center p-4" role="dialog" aria-modal="true">
            <button
                type="button"
                className="absolute inset-0 bg-black/40"
                aria-label="Close"
                onClick={() => !loading && onClose()}
            />
            <div className="relative w-full max-w-md rounded-xl bg-white shadow-xl ring-1 ring-black/5">
                <div className="flex items-center justify-between border-b border-gray-100 px-4 py-3">
                    <h2 className="text-base font-semibold text-gray-900">Use as reference</h2>
                    <button
                        type="button"
                        className="rounded-md p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600"
                        onClick={() => !loading && onClose()}
                    >
                        <XMarkIcon className="h-5 w-5" />
                    </button>
                </div>
                <form onSubmit={handleSubmit} className="px-4 py-4 space-y-4">
                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">Type</label>
                        <select
                            value={type}
                            onChange={(e) => setType(e.target.value === 'guideline' ? 'guideline' : 'reference')}
                            className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                            disabled={loading}
                        >
                            <option value="reference">Reference (tier 2, weight 0.6)</option>
                            <option value="guideline">Brand guideline (tier 3, weight 1.0)</option>
                        </select>
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">Category (optional)</label>
                        <input
                            type="text"
                            value={category}
                            onChange={(e) => setCategory(e.target.value)}
                            placeholder="e.g. photography, social"
                            className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                            disabled={loading}
                            maxLength={255}
                        />
                    </div>
                    {error && (
                        <p className="text-sm text-red-600" role="alert">
                            {error}
                        </p>
                    )}
                    <div className="flex justify-end gap-2 pt-2">
                        <button
                            type="button"
                            className="rounded-md px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100"
                            onClick={() => !loading && onClose()}
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            disabled={loading}
                            className="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50"
                        >
                            {loading ? 'Saving…' : 'Confirm'}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    )
}
