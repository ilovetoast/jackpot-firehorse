import { Link, usePage, router } from '@inertiajs/react'
import AppNav from '../../Components/AppNav'
import AppFooter from '../../Components/AppFooter'

export default function Notifications({ templates, system_templates, tenant_templates, has_invite_member }) {
    const { auth } = usePage().props
    const { flash } = usePage().props

    return (
        <div className="min-h-full">
            <AppNav brand={auth.activeBrand} tenant={null} />
            <main className="bg-gray-50">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
                    <div className="mb-8">
                        <Link href="/app/admin" className="text-sm font-medium text-gray-500 hover:text-gray-700 mb-4 inline-block">
                            ← Back to Admin Dashboard
                        </Link>
                        <div className="flex items-center justify-between">
                            <div>
                                <h1 className="text-3xl font-bold tracking-tight text-gray-900">Email Notifications</h1>
                                <p className="mt-2 text-sm text-gray-700">Manage email templates for system notifications</p>
                            </div>
                        </div>
                    </div>

                    {/* Missing Template Warning */}
                    {!has_invite_member && (
                        <div className="mb-6 rounded-md bg-yellow-50 border border-yellow-200 p-4">
                            <div className="flex">
                                <div className="flex-shrink-0">
                                    <svg className="h-5 w-5 text-yellow-400" viewBox="0 0 20 20" fill="currentColor">
                                        <path fillRule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clipRule="evenodd" />
                                    </svg>
                                </div>
                                <div className="ml-3 flex-1">
                                    <h3 className="text-sm font-medium text-yellow-800">Templates Not Seeded</h3>
                                    <div className="mt-2 text-sm text-yellow-700">
                                        <p>The notification templates haven't been seeded yet. Click the button below to create the default templates (including "Invite Member").</p>
                                    </div>
                                    <div className="mt-4">
                                        <button
                                            type="button"
                                            onClick={() => {
                                                if (confirm('This will create/update the default notification templates. Continue?')) {
                                                    router.post('/app/admin/notifications/seed', {}, {
                                                        preserveScroll: true,
                                                    })
                                                }
                                            }}
                                            className="inline-flex items-center rounded-md bg-yellow-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-yellow-500"
                                        >
                                            <svg className="h-4 w-4 mr-1.5" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                                            </svg>
                                            Seed Notification Templates
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* System Emails */}
                    <div className="mb-8 rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                        <div className="px-6 py-4 border-b border-gray-200">
                            <h2 className="text-lg font-semibold text-gray-900">System Emails (Service - Jackpot)</h2>
                            <p className="mt-1 text-sm text-gray-500">Emails sent from the service platform</p>
                        </div>
                        <div className="divide-y divide-gray-200">
                            {system_templates.length === 0 ? (
                                <div className="px-6 py-8 text-center text-sm text-gray-500">
                                    No system email templates found
                                </div>
                            ) : (
                                system_templates.map((template) => (
                                    <div key={template.id} className="px-6 py-4 hover:bg-gray-50">
                                        <div className="flex items-center justify-between">
                                            <div className="flex-1">
                                                <div className="flex items-center gap-3">
                                                    <h3 className="text-sm font-semibold text-gray-900">{template.name}</h3>
                                                    <span className="inline-flex items-center rounded-full px-2 py-1 text-xs font-medium bg-gray-100 text-gray-800">
                                                        {template.key}
                                                    </span>
                                                    {!template.is_active && (
                                                        <span className="inline-flex items-center rounded-full px-2 py-1 text-xs font-medium bg-red-100 text-red-800">
                                                            Inactive
                                                        </span>
                                                    )}
                                                </div>
                                                <p className="mt-1 text-sm text-gray-500">{template.subject}</p>
                                            </div>
                                            <div className="ml-4">
                                                <Link
                                                    href={`/app/admin/notifications/${template.id}`}
                                                    className="inline-flex items-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                                                >
                                                    Edit
                                                </Link>
                                            </div>
                                        </div>
                                    </div>
                                ))
                            )}
                        </div>
                    </div>

                    {/* Tenant Emails */}
                    <div className="rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                        <div className="px-6 py-4 border-b border-gray-200">
                            <h2 className="text-lg font-semibold text-gray-900">Tenant Emails</h2>
                            <p className="mt-1 text-sm text-gray-500">Emails sent to users from tenants (contextual to company)</p>
                        </div>
                        <div className="divide-y divide-gray-200">
                            {tenant_templates.length === 0 ? (
                                <div className="px-6 py-8 text-center text-sm text-gray-500">
                                    No tenant email templates found
                                </div>
                            ) : (
                                tenant_templates.map((template) => (
                                    <div key={template.id} className="px-6 py-4 hover:bg-gray-50">
                                        <div className="flex items-center justify-between">
                                            <div className="flex-1">
                                                <div className="flex items-center gap-3">
                                                    <h3 className="text-sm font-semibold text-gray-900">{template.name}</h3>
                                                    <span className="inline-flex items-center rounded-full px-2 py-1 text-xs font-medium bg-gray-100 text-gray-800">
                                                        {template.key}
                                                    </span>
                                                    {!template.is_active && (
                                                        <span className="inline-flex items-center rounded-full px-2 py-1 text-xs font-medium bg-red-100 text-red-800">
                                                            Inactive
                                                        </span>
                                                    )}
                                                </div>
                                                <p className="mt-1 text-sm text-gray-500">{template.subject}</p>
                                            </div>
                                            <div className="ml-4">
                                                <Link
                                                    href={`/app/admin/notifications/${template.id}`}
                                                    className="inline-flex items-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                                                >
                                                    Edit
                                                </Link>
                                            </div>
                                        </div>
                                    </div>
                                ))
                            )}
                        </div>
                    </div>
                </div>
            </main>
            <AppFooter />
        </div>
    )
}
