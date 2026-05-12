import { router, usePage } from '@inertiajs/react'
import AppHead from '../../../Components/AppHead'
import AppNav from '../../../Components/AppNav'
import AppFooter from '../../../Components/AppFooter'

function StatusPill({ status }) {
    const map = {
        pending: 'bg-amber-100 text-amber-900',
        in_progress: 'bg-blue-100 text-blue-900',
        completed: 'bg-emerald-100 text-emerald-900',
        rejected: 'bg-gray-200 text-gray-800',
        failed: 'bg-red-100 text-red-900',
    }
    const cls = map[status] || 'bg-gray-100 text-gray-800'
    return (
        <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${cls}`}>{status}</span>
    )
}

export default function DataSubjectRequestsIndex({ requests = [] }) {
    const { auth } = usePage().props

    const approve = (id) => {
        if (!confirm('Approve erasure? This will queue anonymization for the user account.')) return
        router.post(`/app/admin/data-subject-requests/${id}/approve-erasure`)
    }

    const reject = (id) => {
        const notes = window.prompt('Optional note (stored on the request):') ?? ''
        router.post(`/app/admin/data-subject-requests/${id}/reject`, { admin_notes: notes })
    }

    return (
        <div className="min-h-full bg-gray-50">
            <AppHead title="Data subject requests" />
            <AppNav brand={auth?.activeBrand} tenant={auth?.activeCompany} />
            <main className="mx-auto max-w-admin-shell px-4 py-8 sm:px-6 lg:px-8">
                <div className="mb-6">
                    <h1 className="text-2xl font-bold text-gray-900">Data subject requests</h1>
                    <p className="mt-1 text-sm text-gray-600">
                        GDPR / CCPA export logs and erasure review queue.
                    </p>
                </div>

                <div className="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
                    <table className="min-w-full divide-y divide-gray-200">
                        <thead className="bg-gray-50">
                            <tr>
                                <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">ID</th>
                                <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Type</th>
                                <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Status</th>
                                <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">User</th>
                                <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Created</th>
                                <th className="px-4 py-3 text-right text-xs font-medium uppercase tracking-wide text-gray-500">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {requests.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="px-4 py-8 text-center text-sm text-gray-500">
                                        No requests yet.
                                    </td>
                                </tr>
                            )}
                            {requests.map((r) => (
                                <tr key={r.id}>
                                    <td className="whitespace-nowrap px-4 py-3 text-sm text-gray-900">{r.id}</td>
                                    <td className="whitespace-nowrap px-4 py-3 text-sm text-gray-700">{r.type}</td>
                                    <td className="whitespace-nowrap px-4 py-3">
                                        <StatusPill status={r.status} />
                                    </td>
                                    <td className="px-4 py-3 text-sm text-gray-700">
                                        {r.user ? (
                                            <>
                                                <span className="font-medium text-gray-900">{r.user.name}</span>
                                                <span className="block text-xs text-gray-500">{r.user.email}</span>
                                            </>
                                        ) : (
                                            '—'
                                        )}
                                    </td>
                                    <td className="whitespace-nowrap px-4 py-3 text-sm text-gray-500">
                                        {r.created_at ? new Date(r.created_at).toLocaleString() : '—'}
                                    </td>
                                    <td className="whitespace-nowrap px-4 py-3 text-right text-sm">
                                        {r.type === 'erasure' && r.status === 'pending' && (
                                            <span className="inline-flex gap-2">
                                                <button
                                                    type="button"
                                                    onClick={() => approve(r.id)}
                                                    className="font-medium text-emerald-700 hover:text-emerald-900"
                                                >
                                                    Approve
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => reject(r.id)}
                                                    className="font-medium text-gray-600 hover:text-gray-900"
                                                >
                                                    Reject
                                                </button>
                                            </span>
                                        )}
                                        {r.failure_reason && (
                                            <p className="mt-1 max-w-xs text-left text-xs text-red-600" title={r.failure_reason}>
                                                {r.failure_reason}
                                            </p>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </main>
            <AppFooter />
        </div>
    )
}
