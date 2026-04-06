import { useState, useEffect, useCallback, useRef } from 'react'
import axios from 'axios'
import ConfirmDialog from '../ConfirmDialog'

const PER_PAGE = 25
const MAX_BULK_TAGS = 25

/**
 * Lists canonical tags used on brand assets and allows removing tags from every asset (requires assets.tags.delete).
 * Paginated; bulk select up to MAX_BULK_TAGS; brand-settings purges skip debounced EBI rescoring server-side.
 */
export default function BrandTagsSettingsSection({ brandId, canPurge }) {
    const [tags, setTags] = useState([])
    const [meta, setMeta] = useState({ current_page: 1, per_page: PER_PAGE, total: 0, last_page: 1 })
    const [loading, setLoading] = useState(true)
    const [error, setError] = useState(null)
    const [page, setPage] = useState(1)

    const [purgeTarget, setPurgeTarget] = useState(null)
    const [bulkConfirmOpen, setBulkConfirmOpen] = useState(false)
    const [purging, setPurging] = useState(false)
    const [selectedTags, setSelectedTags] = useState([])

    const headerCheckboxRef = useRef(null)

    const load = useCallback(async () => {
        setLoading(true)
        setError(null)
        try {
            const { data } = await axios.get(`/app/api/brands/${brandId}/tags/summary`, {
                params: { page, per_page: PER_PAGE },
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            })
            setTags(Array.isArray(data.tags) ? data.tags : [])
            const m = data.meta || {}
            setMeta({
                current_page: Number(m.current_page) || page,
                per_page: Number(m.per_page) || PER_PAGE,
                total: Number(m.total) || 0,
                last_page: Math.max(1, Number(m.last_page) || 1),
            })
        } catch (e) {
            setError(e.response?.data?.message || 'Could not load tags')
            setTags([])
            setMeta((prev) => ({ ...prev, total: 0, last_page: 1 }))
        } finally {
            setLoading(false)
        }
    }, [brandId, page])

    useEffect(() => {
        load()
    }, [load])

    useEffect(() => {
        if (meta.last_page >= 1 && page > meta.last_page) {
            setPage(meta.last_page)
        }
    }, [meta.last_page, page])

    const tagsOnPage = tags.map((r) => r.tag)
    const selectedSet = new Set(selectedTags)
    const selectedOnPageCount = tagsOnPage.filter((t) => selectedSet.has(t)).length
    const allOnPageSelected = tags.length > 0 && selectedOnPageCount === tags.length
    const someOnPageSelected = selectedOnPageCount > 0 && !allOnPageSelected

    useEffect(() => {
        const el = headerCheckboxRef.current
        if (el) {
            el.indeterminate = someOnPageSelected
        }
    }, [someOnPageSelected, tags, loading])

    const toggleTag = (tag) => {
        setSelectedTags((prev) => {
            const s = new Set(prev)
            if (s.has(tag)) {
                s.delete(tag)
            } else if (s.size < MAX_BULK_TAGS) {
                s.add(tag)
            }
            return Array.from(s)
        })
    }

    const selectAllOnPage = () => {
        setSelectedTags((prev) => {
            const s = new Set(prev)
            let room = MAX_BULK_TAGS - s.size
            for (const row of tags) {
                if (room <= 0) break
                if (!s.has(row.tag)) {
                    s.add(row.tag)
                    room -= 1
                }
            }
            return Array.from(s)
        })
    }

    const deselectAllOnPage = () => {
        setSelectedTags((prev) => {
            const onPage = new Set(tagsOnPage)
            return prev.filter((t) => !onPage.has(t))
        })
    }

    const confirmPurge = async () => {
        if (!purgeTarget || !canPurge) return
        setPurging(true)
        setError(null)
        try {
            await axios.post(
                `/app/api/brands/${brandId}/tags/purge`,
                { tag: purgeTarget },
                {
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                }
            )
            setPurgeTarget(null)
            setSelectedTags((prev) => prev.filter((t) => t !== purgeTarget))
            await load()
            if (typeof window !== 'undefined' && window.toast) {
                window.toast('Removed tag from all assets in this brand', 'success')
            }
        } catch (e) {
            setError(e.response?.data?.message || 'Failed to remove tag')
        } finally {
            setPurging(false)
        }
    }

    const confirmBulkPurge = async () => {
        if (selectedTags.length === 0 || !canPurge) return
        setPurging(true)
        setError(null)
        try {
            await axios.post(
                `/app/api/brands/${brandId}/tags/purge-bulk`,
                { tags: selectedTags },
                {
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                }
            )
            setBulkConfirmOpen(false)
            setSelectedTags([])
            await load()
            if (typeof window !== 'undefined' && window.toast) {
                window.toast('Removed selected tags from all assets in this brand', 'success')
            }
        } catch (e) {
            setError(e.response?.data?.message || 'Failed to remove tags')
        } finally {
            setPurging(false)
        }
    }

    const goPrev = () => setPage((p) => Math.max(1, p - 1))
    const goNext = () => setPage((p) => Math.min(meta.last_page, p + 1))

    return (
        <div id="tags" className="scroll-mt-8 space-y-8">
            <div className="rounded-xl bg-white shadow-sm ring-1 ring-gray-200/20 overflow-hidden">
                <div className="px-6 py-10 sm:px-10 sm:py-12">
                    <div className="mb-1">
                        <h2 className="text-xl font-semibold text-gray-900">Asset tags</h2>
                        <p className="mt-3 text-sm text-gray-600 leading-relaxed max-w-3xl">
                            Tags currently used on assets in this brand. Removing a tag here deletes it from every asset
                            that has it (same as bulk &ldquo;Remove tags&rdquo; across the whole brand). List is
                            paginated ({PER_PAGE} per page). You can select up to {MAX_BULK_TAGS} tags at a time and
                            remove them in one step.
                        </p>
                        <p className="mt-2 text-sm text-gray-500 leading-relaxed max-w-3xl">
                            Large cleanups from this screen do{' '}
                            <span className="font-medium text-gray-700">not</span> enqueue per-asset brand-intelligence
                            rescoring (so jobs stay manageable). Tags still disappear from search and filters
                            immediately.
                        </p>
                    </div>

                    {error && (
                        <div className="mt-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-800">{error}</div>
                    )}

                    {canPurge && selectedTags.length > 0 && (
                        <div className="mt-6 flex flex-col gap-3 rounded-lg border border-amber-200 bg-amber-50/80 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                            <p className="text-sm text-amber-950">
                                <span className="font-semibold">{selectedTags.length}</span> tag
                                {selectedTags.length === 1 ? '' : 's'} selected
                                {selectedTags.length >= MAX_BULK_TAGS ? ` (max ${MAX_BULK_TAGS})` : ''}
                            </p>
                            <div className="flex flex-wrap gap-2">
                                <button
                                    type="button"
                                    onClick={() => setSelectedTags([])}
                                    className="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50"
                                >
                                    Clear selection
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setBulkConfirmOpen(true)}
                                    className="rounded-md bg-red-600 px-3 py-1.5 text-sm font-semibold text-white shadow-sm hover:bg-red-700"
                                >
                                    Remove selected from all assets
                                </button>
                            </div>
                        </div>
                    )}

                    <div className="mt-8">
                        {loading ? (
                            <p className="text-sm text-gray-500">Loading tags…</p>
                        ) : meta.total === 0 ? (
                            <p className="text-sm text-gray-500">No tags on brand assets yet.</p>
                        ) : (
                            <>
                                <div className="overflow-x-auto rounded-lg border border-gray-200">
                                    <table className="min-w-full divide-y divide-gray-200 text-sm">
                                        <thead className="bg-gray-50">
                                            <tr>
                                                {canPurge && (
                                                    <th className="w-12 px-3 py-3 text-left">
                                                        <input
                                                            ref={headerCheckboxRef}
                                                            type="checkbox"
                                                            className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                                            checked={allOnPageSelected}
                                                            onChange={(e) => {
                                                                if (e.target.checked) {
                                                                    selectAllOnPage()
                                                                } else {
                                                                    deselectAllOnPage()
                                                                }
                                                            }}
                                                            aria-label="Select all tags on this page"
                                                        />
                                                    </th>
                                                )}
                                                <th className="px-4 py-3 text-left font-medium text-gray-700">Tag</th>
                                                <th className="px-4 py-3 text-left font-medium text-gray-700">Assets</th>
                                                {canPurge && (
                                                    <th className="px-4 py-3 text-right font-medium text-gray-700">
                                                        Actions
                                                    </th>
                                                )}
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-gray-100 bg-white">
                                            {tags.map((row) => {
                                                const checked = selectedSet.has(row.tag)
                                                const atCap = selectedTags.length >= MAX_BULK_TAGS && !checked
                                                return (
                                                    <tr key={row.tag}>
                                                        {canPurge && (
                                                            <td className="px-3 py-3">
                                                                <input
                                                                    type="checkbox"
                                                                    className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 disabled:opacity-40"
                                                                    checked={checked}
                                                                    disabled={atCap}
                                                                    onChange={() => toggleTag(row.tag)}
                                                                    aria-label={`Select tag ${row.tag}`}
                                                                    title={
                                                                        atCap
                                                                            ? `Select at most ${MAX_BULK_TAGS} tags`
                                                                            : undefined
                                                                    }
                                                                />
                                                            </td>
                                                        )}
                                                        <td className="px-4 py-3 font-mono text-gray-900">{row.tag}</td>
                                                        <td className="px-4 py-3 text-gray-600">{row.asset_count}</td>
                                                        {canPurge && (
                                                            <td className="px-4 py-3 text-right">
                                                                <button
                                                                    type="button"
                                                                    onClick={() => setPurgeTarget(row.tag)}
                                                                    className="text-sm font-medium text-red-600 hover:text-red-800"
                                                                >
                                                                    Remove from all assets
                                                                </button>
                                                            </td>
                                                        )}
                                                    </tr>
                                                )
                                            })}
                                        </tbody>
                                    </table>
                                </div>

                                <div className="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                    <p className="text-sm text-gray-500">
                                        Showing {(page - 1) * meta.per_page + 1}–
                                        {Math.min(page * meta.per_page, meta.total)} of {meta.total} tag
                                        {meta.total === 1 ? '' : 's'}
                                    </p>
                                    <div className="flex items-center gap-2">
                                        <button
                                            type="button"
                                            onClick={goPrev}
                                            disabled={page <= 1 || loading}
                                            className="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
                                        >
                                            Previous
                                        </button>
                                        <span className="text-sm text-gray-600">
                                            Page {page} of {meta.last_page}
                                        </span>
                                        <button
                                            type="button"
                                            onClick={goNext}
                                            disabled={page >= meta.last_page || loading}
                                            className="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
                                        >
                                            Next
                                        </button>
                                    </div>
                                </div>
                            </>
                        )}
                    </div>
                </div>
            </div>

            <ConfirmDialog
                open={Boolean(purgeTarget)}
                loading={purging}
                onClose={() => !purging && setPurgeTarget(null)}
                onConfirm={confirmPurge}
                title="Remove tag from all brand assets?"
                message={
                    purgeTarget
                        ? `This permanently removes the tag “${purgeTarget}” from every asset in this brand. Other tags are not affected.`
                        : ''
                }
                confirmText="Remove everywhere"
                cancelText="Cancel"
                variant="danger"
            />

            <ConfirmDialog
                open={bulkConfirmOpen}
                loading={purging}
                onClose={() => !purging && setBulkConfirmOpen(false)}
                onConfirm={confirmBulkPurge}
                title="Remove selected tags from all assets?"
                message={
                    selectedTags.length > 0 ? (
                        <div className="space-y-2 text-left">
                            <p>
                                This permanently removes{' '}
                                <span className="font-semibold">{selectedTags.length}</span> tag
                                {selectedTags.length === 1 ? '' : 's'} from every asset in this brand that has them.
                            </p>
                            <ul className="max-h-40 list-disc overflow-y-auto pl-5 font-mono text-xs text-gray-700">
                                {selectedTags.map((t) => (
                                    <li key={t}>{t}</li>
                                ))}
                            </ul>
                        </div>
                    ) : (
                        ''
                    )
                }
                confirmText="Remove all selected"
                cancelText="Cancel"
                variant="danger"
                panelClassName="sm:max-w-lg"
            />
        </div>
    )
}
