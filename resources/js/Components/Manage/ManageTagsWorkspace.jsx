import { useState, useEffect, useCallback, useRef } from 'react'
import { Link } from '@inertiajs/react'
import axios from 'axios'
import ConfirmDialog from '../ConfirmDialog'

const PER_PAGE = 25
const MAX_BULK_TAGS = 25

function getCsrfToken() {
    if (typeof document === 'undefined') return ''
    return document.querySelector('meta[name="csrf-token"]')?.content || ''
}

/**
 * Brand-scoped tag list + bulk remove (restored from former Brand Settings; uses /api/brands/{id}/tags/*).
 */
export default function ManageTagsWorkspace({ brandId, canPurge }) {
    const [tags, setTags] = useState([])
    const [meta, setMeta] = useState({ current_page: 1, per_page: PER_PAGE, total: 0, last_page: 1 })
    const [loading, setLoading] = useState(true)
    const [error, setError] = useState(null)
    const [page, setPage] = useState(1)
    const [selectedTags, setSelectedTags] = useState([])
    const [purgeTarget, setPurgeTarget] = useState(null)
    const [bulkConfirmOpen, setBulkConfirmOpen] = useState(false)
    const [purging, setPurging] = useState(false)
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
            setError(e.response?.data?.message || e.message || 'Failed to load tags')
            setTags([])
        } finally {
            setLoading(false)
        }
    }, [brandId, page])

    useEffect(() => {
        load()
    }, [load])

    useEffect(() => {
        setSelectedTags([])
    }, [page, tags])

    const pageTagStrings = tags.map((t) => t.tag)
    const allOnPageSelected =
        pageTagStrings.length > 0 && pageTagStrings.every((t) => selectedTags.includes(t))
    const someOnPageSelected = pageTagStrings.some((t) => selectedTags.includes(t))

    useEffect(() => {
        if (headerCheckboxRef.current) {
            headerCheckboxRef.current.indeterminate = someOnPageSelected && !allOnPageSelected
        }
    }, [someOnPageSelected, allOnPageSelected])

    const toggleSelect = (tag) => {
        setSelectedTags((prev) =>
            prev.includes(tag) ? prev.filter((t) => t !== tag) : [...prev, tag].slice(-MAX_BULK_TAGS)
        )
    }

    const toggleSelectAllOnPage = () => {
        if (allOnPageSelected) {
            setSelectedTags((prev) => prev.filter((t) => !pageTagStrings.includes(t)))
        } else {
            const next = [...new Set([...selectedTags, ...pageTagStrings])]
            setSelectedTags(next.slice(-MAX_BULK_TAGS))
        }
    }

    const runPurge = async (tagList) => {
        setPurging(true)
        try {
            if (tagList.length === 1) {
                await axios.post(
                    `/app/api/brands/${brandId}/tags/purge`,
                    { tag: tagList[0] },
                    {
                        headers: {
                            Accept: 'application/json',
                            'Content-Type': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': getCsrfToken(),
                        },
                    }
                )
            } else {
                await axios.post(
                    `/app/api/brands/${brandId}/tags/purge-bulk`,
                    { tags: tagList },
                    {
                        headers: {
                            Accept: 'application/json',
                            'Content-Type': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': getCsrfToken(),
                        },
                    }
                )
            }
            setPurgeTarget(null)
            setBulkConfirmOpen(false)
            setSelectedTags([])
            await load()
        } catch (e) {
            setError(e.response?.data?.message || e.message || 'Remove failed')
        } finally {
            setPurging(false)
        }
    }

    return (
        <div className="mt-8 space-y-4">
            <div className="rounded-lg border border-gray-200 bg-white shadow-sm">
                <div className="border-b border-gray-100 px-4 py-3 sm:px-6">
                    <h3 className="text-sm font-semibold text-gray-900">Tags in use</h3>
                    <p className="mt-1 text-xs text-gray-500">
                        Canonical tags on assets in this brand. Removing a tag deletes it from every asset that has it (and
                        matching approved metadata rows). Up to {MAX_BULK_TAGS} tags per bulk action.
                    </p>
                </div>

                {error && (
                    <div className="px-4 py-3 text-sm text-red-700 bg-red-50 border-b border-red-100">{error}</div>
                )}

                {canPurge && selectedTags.length > 0 && (
                    <div className="flex flex-wrap items-center gap-2 border-b border-gray-100 bg-slate-50 px-4 py-2 sm:px-6">
                        <span className="text-sm text-gray-600">{selectedTags.length} selected</span>
                        <button
                            type="button"
                            onClick={() => setBulkConfirmOpen(true)}
                            className="rounded-md bg-red-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-red-500"
                        >
                            Remove from all assets…
                        </button>
                    </div>
                )}

                <div className="overflow-x-auto">
                    <table className="min-w-full divide-y divide-gray-200 text-sm">
                        <thead className="bg-gray-50">
                            <tr>
                                {canPurge && (
                                    <th className="w-10 px-3 py-2 text-left">
                                        <input
                                            ref={headerCheckboxRef}
                                            type="checkbox"
                                            checked={allOnPageSelected}
                                            onChange={toggleSelectAllOnPage}
                                            className="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                            aria-label="Select all on this page"
                                        />
                                    </th>
                                )}
                                <th className="px-3 py-2 text-left font-medium text-gray-600">Tag</th>
                                <th className="px-3 py-2 text-right font-medium text-gray-600">Assets</th>
                                {canPurge && <th className="px-3 py-2 text-right font-medium text-gray-600">Actions</th>}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100 bg-white">
                            {loading ? (
                                <tr>
                                    <td colSpan={canPurge ? 4 : 2} className="px-4 py-8 text-center text-gray-500">
                                        Loading…
                                    </td>
                                </tr>
                            ) : tags.length === 0 ? (
                                <tr>
                                    <td colSpan={canPurge ? 4 : 2} className="px-4 py-8 text-center text-gray-500">
                                        No tags on assets in this brand yet.
                                    </td>
                                </tr>
                            ) : (
                                tags.map((row) => (
                                    <tr key={row.tag}>
                                        {canPurge && (
                                            <td className="px-3 py-2">
                                                <input
                                                    type="checkbox"
                                                    checked={selectedTags.includes(row.tag)}
                                                    onChange={() => toggleSelect(row.tag)}
                                                    className="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                                    aria-label={`Select ${row.tag}`}
                                                />
                                            </td>
                                        )}
                                        <td className="px-3 py-2 font-medium text-gray-900">{row.tag}</td>
                                        <td className="px-3 py-2 text-right tabular-nums text-gray-600">
                                            {row.asset_count}
                                        </td>
                                        {canPurge && (
                                            <td className="px-3 py-2 text-right">
                                                <button
                                                    type="button"
                                                    onClick={() => setPurgeTarget(row)}
                                                    className="text-sm font-medium text-red-600 hover:text-red-500"
                                                >
                                                    Remove from all…
                                                </button>
                                            </td>
                                        )}
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                {meta.last_page > 1 && (
                    <div className="flex items-center justify-between border-t border-gray-100 px-4 py-3 text-sm text-gray-600 sm:px-6">
                        <span>
                            Page {meta.current_page} of {meta.last_page} · {meta.total} distinct tags
                        </span>
                        <div className="flex gap-2">
                            <button
                                type="button"
                                disabled={page <= 1 || loading}
                                onClick={() => setPage((p) => Math.max(1, p - 1))}
                                className="rounded-md border border-gray-300 bg-white px-3 py-1 disabled:opacity-40"
                            >
                                Previous
                            </button>
                            <button
                                type="button"
                                disabled={page >= meta.last_page || loading}
                                onClick={() => setPage((p) => p + 1)}
                                className="rounded-md border border-gray-300 bg-white px-3 py-1 disabled:opacity-40"
                            >
                                Next
                            </button>
                        </div>
                    </div>
                )}
            </div>

            {!canPurge && (
                <p className="text-sm text-gray-500">
                    You can view tag usage. To remove tags from all assets, you need the{' '}
                    <span className="font-medium text-gray-700">assets.tags.delete</span> permission.
                </p>
            )}

            <p className="text-xs text-gray-500">
                Find assets missing tags from{' '}
                <Link href="/app/insights/metadata" className="font-medium text-indigo-600 hover:text-indigo-500">
                    Insights → Metadata
                </Link>{' '}
                (<span className="font-medium text-gray-700">Fix missing tags</span>).
            </p>

            <ConfirmDialog
                open={Boolean(purgeTarget)}
                onClose={() => !purging && setPurgeTarget(null)}
                onConfirm={() => purgeTarget && runPurge([purgeTarget.tag])}
                title="Remove tag from all assets?"
                message={
                    purgeTarget
                        ? `Remove “${purgeTarget.tag}” from every asset in this brand (${purgeTarget.asset_count} assets currently have this tag)? This cannot be undone.`
                        : ''
                }
                confirmText="Remove tag"
                cancelText="Cancel"
                variant="danger"
                loading={purging}
            />

            <ConfirmDialog
                open={bulkConfirmOpen}
                onClose={() => !purging && setBulkConfirmOpen(false)}
                onConfirm={() => runPurge(selectedTags)}
                title="Remove tags from all assets?"
                message={`Remove ${selectedTags.length} tag(s) from every asset in this brand that uses them? This cannot be undone.`}
                confirmText="Remove tags"
                cancelText="Cancel"
                variant="danger"
                loading={purging}
            />
        </div>
    )
}
