import { useState, useEffect, useCallback } from 'react'
import axios from 'axios'
import ConfirmDialog from '../ConfirmDialog'

/**
 * Lists canonical tags used on brand assets and allows removing a tag from every asset (requires assets.tags.delete).
 */
export default function BrandTagsSettingsSection({ brandId, canPurge }) {
    const [tags, setTags] = useState([])
    const [loading, setLoading] = useState(true)
    const [error, setError] = useState(null)
    const [purgeTarget, setPurgeTarget] = useState(null)
    const [purging, setPurging] = useState(false)

    const load = useCallback(async () => {
        setLoading(true)
        setError(null)
        try {
            const { data } = await axios.get(`/app/api/brands/${brandId}/tags/summary`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            })
            setTags(Array.isArray(data.tags) ? data.tags : [])
        } catch (e) {
            setError(e.response?.data?.message || 'Could not load tags')
            setTags([])
        } finally {
            setLoading(false)
        }
    }, [brandId])

    useEffect(() => {
        load()
    }, [load])

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
            await load()
            if (typeof window !== 'undefined' && window.toast) {
                window.toast(`Removed tag from all assets in this brand`, 'success')
            }
        } catch (e) {
            setError(e.response?.data?.message || 'Failed to remove tag')
        } finally {
            setPurging(false)
        }
    }

    return (
        <div id="tags" className="scroll-mt-8 space-y-8">
            <div className="rounded-xl bg-white shadow-sm ring-1 ring-gray-200/20 overflow-hidden">
                <div className="px-6 py-10 sm:px-10 sm:py-12">
                    <div className="mb-1">
                        <h2 className="text-xl font-semibold text-gray-900">Asset tags</h2>
                        <p className="mt-3 text-sm text-gray-600 leading-relaxed max-w-2xl">
                            Tags currently used on assets in this brand. Removing a tag here deletes it from every asset that has it
                            (same as bulk &ldquo;Remove tags&rdquo; across the whole brand).
                        </p>
                    </div>

                    {error && (
                        <div className="mt-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-800">{error}</div>
                    )}

                    <div className="mt-8">
                        {loading ? (
                            <p className="text-sm text-gray-500">Loading tags…</p>
                        ) : tags.length === 0 ? (
                            <p className="text-sm text-gray-500">No tags on brand assets yet.</p>
                        ) : (
                            <div className="overflow-x-auto rounded-lg border border-gray-200">
                                <table className="min-w-full divide-y divide-gray-200 text-sm">
                                    <thead className="bg-gray-50">
                                        <tr>
                                            <th className="px-4 py-3 text-left font-medium text-gray-700">Tag</th>
                                            <th className="px-4 py-3 text-left font-medium text-gray-700">Assets</th>
                                            {canPurge && (
                                                <th className="px-4 py-3 text-right font-medium text-gray-700">Actions</th>
                                            )}
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-gray-100 bg-white">
                                        {tags.map((row) => (
                                            <tr key={row.tag}>
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
                                        ))}
                                    </tbody>
                                </table>
                            </div>
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
        </div>
    )
}
