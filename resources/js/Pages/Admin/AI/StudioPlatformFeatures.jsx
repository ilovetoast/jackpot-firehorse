import { Link, router } from '@inertiajs/react'
import AdminAiCenterPage from '../../../Components/Admin/AdminAiCenterPage'

export default function StudioPlatformFeatures({ features, environment, canManage }) {
    const submitToggle = (key, enabled) => {
        router.post(
            '/app/admin/ai/studio-platform-features',
            { feature_key: key, enabled, environment },
            { preserveScroll: true },
        )
    }

    return (
        <AdminAiCenterPage
            breadcrumbs={[
                { label: 'Admin', href: '/app/admin' },
                { label: 'AI Control Center', href: '/app/admin/ai' },
                { label: 'AI feature controls' },
            ]}
            title="AI feature controls"
            description="Turn paid Studio capabilities on or off for everyone. When off, controls are hidden in the editor and the API rejects the action."
            technicalNote={
                <p className="mt-2 text-xs text-slate-500">
                    Environment: <span className="font-medium text-slate-800">{environment}</span>
                    {' · '}
                    <Link href="/app/admin/ai/budgets" className="font-medium text-indigo-600 hover:text-indigo-800">
                        Spend &amp; budgets
                    </Link>{' '}
                    for monthly USD caps on segmentation, background fill, and still → video.
                </p>
            }
        >
            <div className="max-w-4xl">
                <div className="mt-2 overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                    <ul className="divide-y divide-gray-200">
                        {features.map((f) => (
                            <li key={f.key} className="px-6 py-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                <div className="min-w-0">
                                    <p className="text-sm font-medium text-gray-900">{f.label}</p>
                                    <p className="text-xs text-gray-500 mt-0.5">{f.description}</p>
                                    <p className="text-[10px] font-mono text-gray-400 mt-1">{f.key}</p>
                                </div>
                                <label className="inline-flex items-center gap-2 shrink-0 cursor-pointer">
                                    <span className="text-sm text-gray-600">{f.enabled ? 'On' : 'Off'}</span>
                                    <input
                                        type="checkbox"
                                        className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                        checked={f.enabled}
                                        disabled={!canManage}
                                        onChange={(e) => {
                                            if (!canManage) {
                                                return
                                            }
                                            submitToggle(f.key, e.target.checked)
                                        }}
                                    />
                                </label>
                            </li>
                        ))}
                    </ul>
                </div>

                {!canManage && (
                    <p className="mt-4 text-sm text-amber-800 bg-amber-50 ring-1 ring-amber-200 rounded-md px-3 py-2">
                        You have read-only access. Ask a site admin with <code className="text-xs">ai.dashboard.manage</code> to
                        change toggles.
                    </p>
                )}
            </div>
        </AdminAiCenterPage>
    )
}
