import { useState, useEffect, useCallback } from 'react'
import { ChevronDownIcon, ChevronRightIcon } from '@heroicons/react/24/outline'
import axios from 'axios'
import ConfirmDialog from '../ConfirmDialog'
import { WorkbenchEmptyState } from '../../components/brand-workspace/workbenchPatterns'

function getCsrfToken() {
    if (typeof document === 'undefined') return ''
    return document.querySelector('meta[name="csrf-token"]')?.content || ''
}

/**
 * Approved metadata values by field (excludes tags). Purge removes matching asset_metadata rows brand-wide.
 */
export default function ManageValuesWorkspace({ brandId, brandName, canPurgeMetadataValues }) {
    const [fields, setFields] = useState([])
    const [meta, setMeta] = useState({ truncated: false, row_cap: 2500 })
    const [loading, setLoading] = useState(true)
    const [error, setError] = useState(null)
    const [expanded, setExpanded] = useState({})
    const [purgeTarget, setPurgeTarget] = useState(null)
    const [purging, setPurging] = useState(false)
    const [successMessage, setSuccessMessage] = useState(null)

    const load = useCallback(async () => {
        setLoading(true)
        setError(null)
        setSuccessMessage(null)
        try {
            const { data } = await axios.get(`/app/api/brands/${brandId}/metadata-values/summary`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            })
            setFields(Array.isArray(data.fields) ? data.fields : [])
            setMeta({
                truncated: Boolean(data.meta?.truncated),
                row_cap: Number(data.meta?.row_cap) || 2500,
            })
        } catch (e) {
            setError(e.response?.data?.message || e.message || 'Failed to load values')
            setFields([])
        } finally {
            setLoading(false)
        }
    }, [brandId])

    useEffect(() => {
        load()
    }, [load])

    const toggleField = (key) => {
        setExpanded((prev) => ({ ...prev, [key]: !prev[key] }))
    }

    const runPurge = async () => {
        if (!purgeTarget) return
        setPurging(true)
        try {
            const { data } = await axios.post(
                `/app/api/brands/${brandId}/metadata-values/purge`,
                { field_key: purgeTarget.field_key, value_json: purgeTarget.value_json },
                {
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': getCsrfToken(),
                    },
                }
            )
            setPurgeTarget(null)
            const n = Number(data?.rows_deleted)
            setSuccessMessage(
                Number.isFinite(n) && n >= 0
                    ? `Removed from ${n} ${n === 1 ? 'asset' : 'assets'}.`
                    : 'Value removed.'
            )
            await load()
        } catch (e) {
            setError(e.response?.data?.message || e.message || 'Remove failed')
        } finally {
            setPurging(false)
        }
    }

    return (
        <div className="mt-4 space-y-4 sm:mt-6">
            {meta.truncated && (
                <div className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">
                    Showing up to {meta.row_cap} value groups. If something is missing, narrow usage in the library or ask
                    for a higher cap.
                </div>
            )}

            {successMessage && (
                <div className="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                    {successMessage}
                </div>
            )}

            {error && (
                <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{error}</div>
            )}

            {loading ? (
                <p className="py-8 text-sm text-slate-500">Loading values…</p>
            ) : fields.length === 0 ? (
                <WorkbenchEmptyState
                    title="No custom field values yet"
                    description={`Approved values from custom fields on assets in ${brandName ?? 'this brand'} will appear here. System and automated values are hidden; use Tags for tag values.`}
                />
            ) : (
                <div className="space-y-2">
                    {fields.map((f) => (
                        <div key={f.field_key} className="rounded-lg border border-gray-200 bg-white shadow-sm overflow-hidden">
                            <button
                                type="button"
                                onClick={() => toggleField(f.field_key)}
                                className="flex w-full items-center justify-between px-4 py-3 text-left text-sm font-semibold text-gray-900 hover:bg-gray-50"
                            >
                                <span>
                                    {f.field_label}{' '}
                                    <span className="font-normal text-gray-500">({f.field_key})</span>
                                </span>
                                {expanded[f.field_key] ? (
                                    <ChevronDownIcon className="h-4 w-4 shrink-0 text-gray-400" aria-hidden />
                                ) : (
                                    <ChevronRightIcon className="h-4 w-4 shrink-0 text-gray-400" aria-hidden />
                                )}
                            </button>
                            {expanded[f.field_key] && (
                                <div className="border-t border-gray-100">
                                    <table className="min-w-full divide-y divide-gray-100 text-sm">
                                        <thead className="bg-gray-50">
                                            <tr>
                                                <th className="px-4 py-2 text-left font-medium text-gray-600">Value</th>
                                                <th className="px-4 py-2 text-right font-medium text-gray-600">Assets</th>
                                                {canPurgeMetadataValues && (
                                                    <th className="px-4 py-2 text-right font-medium text-gray-600 w-40">
                                                        Actions
                                                    </th>
                                                )}
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-gray-50">
                                            {(f.values || []).map((v, idx) => (
                                                <tr key={`${f.field_key}-${idx}-${v.value_json}`}>
                                                    <td className="px-4 py-2 text-gray-900 break-words max-w-md">
                                                        {v.display_value || (
                                                            <span className="text-gray-400 italic">(empty)</span>
                                                        )}
                                                    </td>
                                                    <td className="px-4 py-2 text-right tabular-nums text-gray-600">
                                                        {v.asset_count}
                                                    </td>
                                                    {canPurgeMetadataValues && (
                                                        <td className="px-4 py-2 text-right">
                                                            <button
                                                                type="button"
                                                                onClick={() =>
                                                                    setPurgeTarget({
                                                                        field_key: f.field_key,
                                                                        value_json: v.value_json,
                                                                        display_value: v.display_value,
                                                                        asset_count: v.asset_count,
                                                                    })
                                                                }
                                                                className="text-sm font-medium text-red-600 hover:text-red-500"
                                                            >
                                                                Remove from all…
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
                    ))}
                </div>
            )}

            {!canPurgeMetadataValues && !loading && fields.length > 0 && (
                <p className="text-sm text-gray-500">
                    You can browse values. To remove a value from every asset, you need bulk metadata permissions (e.g.{' '}
                    <span className="font-medium text-gray-700">metadata.bulk_edit</span> or tenant field manage).
                </p>
            )}

            <ConfirmDialog
                open={Boolean(purgeTarget)}
                onClose={() => !purging && setPurgeTarget(null)}
                onConfirm={runPurge}
                title="Remove this value from all assets?"
                message={
                    purgeTarget
                        ? `Remove “${purgeTarget.display_value || purgeTarget.value_json}” from field “${purgeTarget.field_key}” on every asset in this brand (${purgeTarget.asset_count} assets)? Approved metadata rows only; this cannot be undone.`
                        : ''
                }
                confirmText="Remove value"
                cancelText="Cancel"
                variant="danger"
                loading={purging}
            />
        </div>
    )
}
