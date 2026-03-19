import { useState, useEffect } from 'react'
import { Link, router } from '@inertiajs/react'
import InsightsLayout from '../../layouts/InsightsLayout'
import { SparklesIcon, TagIcon, FolderIcon, CheckIcon, XMarkIcon, ArrowTopRightOnSquareIcon } from '@heroicons/react/24/outline'
import { usePermission } from '../../hooks/usePermission'

export default function AnalyticsReview({ initialTab = 'tags' }) {
    const [activeTab, setActiveTab] = useState(initialTab)
    const [items, setItems] = useState([])
    const [loading, setLoading] = useState(true)
    const [processing, setProcessing] = useState(new Set())
    const { can } = usePermission()
    const canView = can('metadata.suggestions.view')
    const canApply = can('metadata.suggestions.apply')

    useEffect(() => {
        if (!canView) {
            setLoading(false)
            return
        }
        setLoading(true)
        fetch(`/app/api/ai/review?type=${activeTab}`, {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin',
        })
            .then((r) => r.json())
            .then((data) => {
                setItems(data.items || [])
                setLoading(false)
            })
            .catch(() => setLoading(false))
    }, [activeTab, canView])

    const handleApprove = async (item) => {
        if (processing.has(item.id) || !canApply) return
        setProcessing((p) => new Set(p).add(item.id))
        try {
            const url =
                item.type === 'tag'
                    ? `/app/assets/${item.asset_id}/tags/suggestions/${item.id}/accept`
                    : `/app/metadata/candidates/${item.id}/approve`
            const res = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                },
                credentials: 'same-origin',
            })
            if (res.ok) setItems((prev) => prev.filter((i) => i.id !== item.id))
        } finally {
            setProcessing((p) => {
                const next = new Set(p)
                next.delete(item.id)
                return next
            })
        }
    }

    const handleReject = async (item) => {
        if (processing.has(item.id) || !canApply) return
        setProcessing((p) => new Set(p).add(item.id))
        try {
            const url =
                item.type === 'tag'
                    ? `/app/assets/${item.asset_id}/tags/suggestions/${item.id}/dismiss`
                    : `/app/metadata/candidates/${item.id}/reject`
            const res = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                },
                credentials: 'same-origin',
            })
            if (res.ok) setItems((prev) => prev.filter((i) => i.id !== item.id))
        } finally {
            setProcessing((p) => {
                const next = new Set(p)
                next.delete(item.id)
                return next
            })
        }
    }

    if (!canView) {
        return (
            <InsightsLayout title="AI Review" activeSection="review">
                <div className="rounded-lg bg-amber-50 p-4 text-amber-800">
                    You do not have permission to view AI suggestions.
                </div>
            </InsightsLayout>
        )
    }

    return (
        <InsightsLayout title="AI Review" activeSection="review">
            <div className="space-y-6">
                <div>
                    <h1 className="text-2xl font-semibold text-gray-900">AI Review</h1>
                    <p className="mt-1 text-sm text-gray-500">
                        Review and approve AI tag and category suggestions.
                    </p>
                </div>

                <div className="border-b border-gray-200">
                    <nav className="-mb-px flex gap-6">
                        <button
                            type="button"
                            onClick={() => setActiveTab('tags')}
                            className={`flex items-center gap-2 border-b-2 py-3 text-sm font-medium ${
                                activeTab === 'tags'
                                    ? 'border-indigo-500 text-indigo-600'
                                    : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
                            }`}
                        >
                            <TagIcon className="h-5 w-5" />
                            Tags
                        </button>
                        <button
                            type="button"
                            onClick={() => setActiveTab('categories')}
                            className={`flex items-center gap-2 border-b-2 py-3 text-sm font-medium ${
                                activeTab === 'categories'
                                    ? 'border-indigo-500 text-indigo-600'
                                    : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
                            }`}
                        >
                            <FolderIcon className="h-5 w-5" />
                            Categories
                        </button>
                    </nav>
                </div>

                {loading ? (
                    <div className="rounded-lg bg-white p-8 text-center text-gray-500">Loading...</div>
                ) : items.length === 0 ? (
                    <div className="rounded-lg bg-white p-8 text-center">
                        <SparklesIcon className="mx-auto h-12 w-12 text-gray-300" />
                        <p className="mt-2 text-gray-500">No pending {activeTab} suggestions.</p>
                    </div>
                ) : (
                    <div className="overflow-hidden rounded-lg bg-white shadow">
                        <ul className="divide-y divide-gray-200">
                            {items.map((item) => (
                                <li key={item.id} className="flex items-center gap-4 p-4 hover:bg-gray-50">
                                    <div className="h-14 w-14 flex-shrink-0 overflow-hidden rounded bg-gray-100">
                                        {item.thumbnail_url ? (
                                            <img
                                                src={item.thumbnail_url}
                                                alt=""
                                                className="h-full w-full object-cover"
                                            />
                                        ) : (
                                            <div className="flex h-full w-full items-center justify-center text-gray-400">
                                                <SparklesIcon className="h-6 w-6" />
                                            </div>
                                        )}
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <p className="font-medium text-gray-900">{item.suggestion}</p>
                                        <p className="text-sm text-gray-500">
                                            {item.asset_title || item.asset_filename || 'Asset'}
                                            {item.field_label && ` • ${item.field_label}`}
                                        </p>
                                    </div>
                                    <div className="flex items-center gap-3">
                                        {item.confidence != null && (
                                            <span className="text-sm text-gray-500">
                                                {Math.round(item.confidence * 100)}%
                                            </span>
                                        )}
                                        {canApply && (
                                            <>
                                                <button
                                                    type="button"
                                                    onClick={() => handleApprove(item)}
                                                    disabled={processing.has(item.id)}
                                                    className="inline-flex items-center gap-1 rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50"
                                                >
                                                    <CheckIcon className="h-4 w-4" />
                                                    Accept
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => handleReject(item)}
                                                    disabled={processing.has(item.id)}
                                                    className="inline-flex items-center gap-1 rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50"
                                                >
                                                    <XMarkIcon className="h-4 w-4" />
                                                    Reject
                                                </button>
                                            </>
                                        )}
                                        <Link
                                            href={`/app/assets?asset=${item.asset_id}`}
                                            className="text-gray-400 hover:text-indigo-600"
                                            title="Open in grid"
                                        >
                                            <ArrowTopRightOnSquareIcon className="h-5 w-5" />
                                        </Link>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
            </div>
        </InsightsLayout>
    )
}
