import { useEffect, useMemo, useState } from 'react'
import { XMarkIcon } from '@heroicons/react/24/outline'

/**
 * Confirm promoting an asset into the brand style-reference pool (EBI).
 * Type is fixed by which drawer button opened the modal — no tier/weight UI.
 *
 * @param {Object} props
 * @param {boolean} props.isOpen
 * @param {() => void} props.onClose
 * @param {string} props.assetId
 * @param {'reference'|'guideline'} props.initialType
 * @param {Array<{ id?: number|string, name?: string }>} [props.categories]
 * @param {string|null} [props.defaultCategoryName] — e.g. asset’s current category name for preselect
 * @param {(payload: { reference_promotion: { kind: string, tier: number, category: string|null } }) => void} props.onSuccess
 */
export default function PromoteBrandReferenceModal({
    isOpen,
    onClose,
    assetId,
    initialType = 'reference',
    categories = [],
    defaultCategoryName = null,
    onSuccess,
}) {
    const isGuideline = initialType === 'guideline'
    const type = isGuideline ? 'guideline' : 'reference'

    const [category, setCategory] = useState('')
    const [contextType, setContextType] = useState('')
    const [loading, setLoading] = useState(false)
    const [error, setError] = useState(null)

    const contextTypeOptions = useMemo(
        () => [
            { value: '', label: 'Any context (matches all assets)' },
            { value: 'product_hero', label: 'Product / hero' },
            { value: 'lifestyle', label: 'Lifestyle' },
            { value: 'digital_ad', label: 'Digital ad' },
            { value: 'social_post', label: 'Social post' },
            { value: 'logo_only', label: 'Logo / lockup' },
            { value: 'other', label: 'Other' },
        ],
        [],
    )

    const categoryOptions = useMemo(() => {
        const rows = Array.isArray(categories) ? categories : []
        return rows
            .map((c) => ({ id: c?.id, name: (c?.name || '').trim() }))
            .filter((c) => c.name)
            .sort((a, b) => a.name.localeCompare(b.name))
    }, [categories])

    useEffect(() => {
        if (!isOpen) return
        setError(null)
        const names = categoryOptions.map((c) => c.name)
        const pref = (defaultCategoryName || '').trim()
        if (pref && names.includes(pref)) {
            setCategory(pref)
        } else {
            setCategory('')
        }
        setContextType('')
    }, [isOpen, initialType, categoryOptions, defaultCategoryName])

    if (!isOpen) {
        return null
    }

    const title = isGuideline ? 'Add to brand guidelines' : 'Add as style reference'
    const blurb = isGuideline
        ? 'This asset will be used as a strong visual guideline when we evaluate brand alignment.'
        : 'This asset will be used as inspiration when we evaluate brand alignment.'

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
                    context_type: contextType.trim() || null,
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
                    <h2 className="text-base font-semibold text-gray-900">{title}</h2>
                    <button
                        type="button"
                        className="rounded-md p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600"
                        onClick={() => !loading && onClose()}
                    >
                        <XMarkIcon className="h-5 w-5" />
                    </button>
                </div>
                <form onSubmit={handleSubmit} className="px-4 py-4 space-y-4">
                    <p className="text-sm text-gray-600 leading-relaxed">{blurb}</p>

                    <div>
                        <label htmlFor="promote-context-type" className="block text-sm font-medium text-gray-700 mb-1">
                            Creative context <span className="font-normal text-gray-500">(optional)</span>
                        </label>
                        <select
                            id="promote-context-type"
                            value={contextType}
                            onChange={(e) => setContextType(e.target.value)}
                            className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm mb-4"
                            disabled={loading}
                        >
                            {contextTypeOptions.map((o) => (
                                <option key={o.value || 'any'} value={o.value}>
                                    {o.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div>
                        <label htmlFor="promote-category" className="block text-sm font-medium text-gray-700 mb-1">
                            Category <span className="font-normal text-gray-500">(optional)</span>
                        </label>
                        {categoryOptions.length > 0 ? (
                            <select
                                id="promote-category"
                                value={category}
                                onChange={(e) => setCategory(e.target.value)}
                                className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                                disabled={loading}
                            >
                                <option value="">None</option>
                                {categoryOptions.map((c) => (
                                    <option key={c.id ?? c.name} value={c.name}>
                                        {c.name}
                                    </option>
                                ))}
                            </select>
                        ) : (
                            <p className="text-sm text-gray-500">No categories in this brand yet — you can still continue.</p>
                        )}
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
                            {loading ? 'Saving…' : 'Add'}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    )
}
