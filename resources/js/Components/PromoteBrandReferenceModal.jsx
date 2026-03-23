import { useEffect, useMemo, useState } from 'react'
import { XMarkIcon } from '@heroicons/react/24/outline'

/**
 * Promote an asset into the brand style-reference pool (EBI).
 * One primary path (style reference) with optional upgrade to guideline tier via checkbox.
 *
 * @param {Object} props
 * @param {boolean} props.isOpen
 * @param {() => void} props.onClose
 * @param {string} props.assetId
 * @param {Array<{ id?: number|string, name?: string }>} [props.categories]
 * @param {string|null} [props.defaultCategoryName]
 * @param {(payload: { reference_promotion: { kind: string, tier: number, category: string|null } }) => void} props.onSuccess
 */
export default function PromoteBrandReferenceModal({
    isOpen,
    onClose,
    assetId,
    categories = [],
    defaultCategoryName = null,
    onSuccess,
}) {
    const [category, setCategory] = useState('')
    const [contextType, setContextType] = useState('')
    const [alsoAddToGuidelines, setAlsoAddToGuidelines] = useState(false)
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
        setAlsoAddToGuidelines(false)
        const names = categoryOptions.map((c) => c.name)
        const pref = (defaultCategoryName || '').trim()
        if (pref && names.includes(pref)) {
            setCategory(pref)
        } else {
            setCategory('')
        }
        setContextType('')
    }, [isOpen, categoryOptions, defaultCategoryName])

    if (!isOpen) {
        return null
    }

    const promotionType = alsoAddToGuidelines ? 'guideline' : 'reference'

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
                    type: promotionType,
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
        <div className="fixed inset-0 z-[80] flex items-center justify-center p-4" role="dialog" aria-modal="true" aria-labelledby="promote-reference-title">
            <button
                type="button"
                className="absolute inset-0 bg-black/40"
                aria-label="Close"
                onClick={() => !loading && onClose()}
            />
            <div className="relative w-full max-w-lg rounded-xl bg-white shadow-xl ring-1 ring-black/5">
                <div className="flex items-center justify-between border-b border-gray-100 px-4 py-3">
                    <h2 id="promote-reference-title" className="text-base font-semibold text-gray-900">
                        Add as brand reference
                    </h2>
                    <button
                        type="button"
                        className="rounded-md p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600"
                        onClick={() => !loading && onClose()}
                    >
                        <XMarkIcon className="h-5 w-5" />
                    </button>
                </div>
                <form onSubmit={handleSubmit} className="px-4 py-4 space-y-4 max-h-[min(85vh,32rem)] overflow-y-auto">
                    <p className="text-sm text-gray-600 leading-relaxed">
                        Add this asset to your brand’s reference library. Brand Intelligence uses these examples when scoring alignment
                        and surfacing suggestions—especially when paired with the category and creative context you choose below.
                    </p>

                    <div className="rounded-lg border border-slate-200 bg-slate-50/80 p-3 space-y-2">
                        <h3 className="text-sm font-semibold text-gray-900">Promote as a style reference</h3>
                        <p className="text-sm text-gray-600 leading-relaxed">
                            This asset will be treated as a promoted reference when we evaluate on-brand fit. We prioritize examples in
                            the same <span className="font-medium text-gray-800">category</span> (and optional creative context) so the
                            model learns what “good” looks like for your team—not generic stock, but your brand’s own bar.
                        </p>
                    </div>

                    <div className="rounded-lg border border-violet-100 bg-violet-50/40 p-3 space-y-3">
                        <label className="flex items-start gap-3 cursor-pointer">
                            <input
                                type="checkbox"
                                checked={alsoAddToGuidelines}
                                onChange={(e) => setAlsoAddToGuidelines(e.target.checked)}
                                disabled={loading}
                                className="mt-1 h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                            />
                            <span className="min-w-0">
                                <span className="text-sm font-medium text-gray-900">Also add to brand guidelines</span>
                                <span className="block text-sm text-gray-600 leading-relaxed mt-1">
                                    When checked, this asset is also presented as a formal <strong className="font-medium">creative reference</strong> in
                                    your brand guidelines. That uses a <strong className="font-medium">stronger guideline weight</strong> in Brand
                                    Intelligence than a standard reference—so it can influence alignment scores and AI suggestions more
                                    noticeably. Use this when the creative should publicly represent the brand; leave unchecked for most
                                    day-to-day reference adds.
                                </span>
                            </span>
                        </label>
                    </div>

                    <div>
                        <label htmlFor="promote-context-type" className="block text-sm font-medium text-gray-700 mb-1">
                            Creative context <span className="font-normal text-gray-500">(optional)</span>
                        </label>
                        <select
                            id="promote-context-type"
                            value={contextType}
                            onChange={(e) => setContextType(e.target.value)}
                            className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
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
                    <div className="flex justify-end gap-2 pt-2 border-t border-gray-100">
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
                            {loading
                                ? 'Saving…'
                                : alsoAddToGuidelines
                                  ? 'Add as guideline reference'
                                  : 'Add style reference'}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    )
}
