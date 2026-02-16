import { useState } from 'react'
import { useForm, Link, router, usePage } from '@inertiajs/react'
import AppNav from '../../../../Components/AppNav'
import { TrashIcon } from '@heroicons/react/24/outline'

export default function BrandBootstrapIndex({ brand, runs }) {
    const { auth, flash = {} } = usePage().props
    const { data, setData, post, processing, errors } = useForm({ url: '' })
    const [deleteRunId, setDeleteRunId] = useState(null)

    const handleSubmit = (e) => {
        e.preventDefault()
        const url = typeof route === 'function'
            ? route('brands.dna.bootstrap.store', { brand: brand.id })
            : `/app/brands/${brand.id}/dna/bootstrap`
        post(url)
    }

    const handleDelete = (run) => {
        if (!confirm('Delete this bootstrap run? This cannot be undone.')) return
        const url = typeof route === 'function'
            ? route('brands.dna.bootstrap.destroy', { brand: brand.id, run: run.id })
            : `/app/brands/${brand.id}/dna/bootstrap/${run.id}`
        router.delete(url)
        setDeleteRunId(null)
    }

    const statusBadgeClass = (status) => {
        switch (status) {
            case 'inferred': return 'bg-emerald-100 text-emerald-800'
            case 'completed': return 'bg-green-100 text-green-800'
            case 'failed': return 'bg-red-100 text-red-800'
            case 'running': return 'bg-blue-100 text-blue-800'
            default: return 'bg-gray-100 text-gray-800'
        }
    }

    const getDomain = (url) => {
        if (!url) return '—'
        try {
            const u = new URL(url.startsWith('http') ? url : `https://${url}`)
            return u.hostname
        } catch {
            return url
        }
    }

    return (
        <div className="min-h-full">
            <AppNav brand={auth?.activeBrand} tenant={null} />
            <main className="bg-gray-50">
                <div className="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8 py-8">
                    <Link
                        href={typeof route === 'function' ? route('brands.dna.index', { brand: brand.id }) : `/app/brands/${brand.id}/dna`}
                        className="text-sm font-medium text-gray-500 hover:text-gray-700"
                    >
                        ← Back to Brand DNA
                    </Link>
                    <h1 className="mt-6 text-2xl font-bold text-gray-900">Run AI Brand Research</h1>
                    {flash.success && (
                        <p className="mt-4 text-sm text-green-600">{flash.success}</p>
                    )}
                    <p className="mt-1 text-sm text-gray-600">
                        This analyzes a website and proposes a new Brand DNA draft. Your active Brand DNA will not change automatically.
                    </p>

                    <form onSubmit={handleSubmit} className="mt-8">
                        <div className="flex gap-3">
                            <input
                                type="url"
                                value={data.url}
                                onChange={(e) => setData('url', e.target.value)}
                                placeholder="https://example.com/brand"
                                className="block flex-1 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                            />
                            <button
                                type="submit"
                                disabled={processing}
                                className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50"
                            >
                                {processing ? 'Creating…' : 'Generate Brand DNA'}
                            </button>
                        </div>
                        {errors.url && <p className="mt-2 text-sm text-red-600">{errors.url}</p>}
                    </form>

                    <div className="mt-12">
                        <h2 className="text-lg font-semibold text-gray-900">Runs</h2>
                        {runs?.length === 0 ? (
                            <p className="mt-4 text-sm text-gray-500">No runs yet.</p>
                        ) : (
                            <div className="mt-4 space-y-4">
                                {runs?.map((run) => (
                                    <div
                                        key={run.id}
                                        className="rounded-lg border border-gray-200 bg-white p-4 shadow-sm"
                                    >
                                        <div className="flex items-start justify-between gap-4">
                                            <div className="min-w-0 flex-1">
                                                <p className="text-sm font-medium text-gray-900">
                                                    Run #{run.id} — {getDomain(run.source_url)}
                                                </p>
                                                <p className="mt-0.5 text-xs text-gray-500 truncate">{run.source_url || '—'}</p>
                                                <div className="mt-2 flex flex-wrap items-center gap-2">
                                                    <span className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ${statusBadgeClass(run.status)}`}>
                                                        {run.status}
                                                    </span>
                                                    {run.raw_payload?.ai_signals?.confidence_score != null && (
                                                        <span className="text-xs text-gray-500">
                                                            Confidence: {Math.round(run.raw_payload.ai_signals.confidence_score)}%
                                                        </span>
                                                    )}
                                                    {run.created_at && (
                                                        <span className="text-xs text-gray-400">
                                                            {new Date(run.created_at).toLocaleString()}
                                                        </span>
                                                    )}
                                                </div>
                                            </div>
                                            <div className="flex flex-shrink-0 items-center gap-2">
                                                {run.approved_version_id ? (
                                                    <>
                                                        <span className="text-xs text-gray-500">Draft Created: v{run.approved_version?.version_number ?? '?'}</span>
                                                        <Link
                                                            href={typeof route === 'function' ? route('brands.dna.index', { brand: brand.id, editing: run.approved_version_id }) : `/app/brands/${brand.id}/dna?editing=${run.approved_version_id}`}
                                                            className="rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-indigo-700"
                                                        >
                                                            View Draft
                                                        </Link>
                                                    </>
                                                ) : run.status === 'inferred' ? (
                                                    <Link
                                                        href={typeof route === 'function' ? route('brands.dna.bootstrap.show', { brand: brand.id, run: run.id }) : `/app/brands/${brand.id}/dna/bootstrap/${run.id}`}
                                                        className="rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-emerald-700"
                                                    >
                                                        Create Draft from This
                                                    </Link>
                                                ) : run.status !== 'running' && run.status !== 'pending' ? (
                                                    <Link
                                                        href={typeof route === 'function' ? route('brands.dna.bootstrap.show', { brand: brand.id, run: run.id }) : `/app/brands/${brand.id}/dna/bootstrap/${run.id}`}
                                                        className="rounded-md bg-gray-100 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-200"
                                                    >
                                                        View
                                                    </Link>
                                                ) : null}
                                                {!run.approved_version_id && (
                                                    <button
                                                        type="button"
                                                        onClick={() => setDeleteRunId(run.id)}
                                                        className="rounded-md p-1.5 text-gray-400 hover:bg-red-50 hover:text-red-600"
                                                        title="Delete run"
                                                    >
                                                        <TrashIcon className="h-4 w-4" />
                                                    </button>
                                                )}
                                            </div>
                                        </div>
                                        {deleteRunId === run.id && (
                                            <div className="mt-3 flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3">
                                                <p className="flex-1 text-sm text-amber-800">Delete this run? This cannot be undone.</p>
                                                <button
                                                    type="button"
                                                    onClick={() => handleDelete(run)}
                                                    className="rounded-md bg-red-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-red-700"
                                                >
                                                    Delete
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => setDeleteRunId(null)}
                                                    className="rounded-md bg-gray-200 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-300"
                                                >
                                                    Cancel
                                                </button>
                                            </div>
                                        )}
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                </div>
            </main>
        </div>
    )
}
