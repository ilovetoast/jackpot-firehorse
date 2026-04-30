import { Link, usePage } from '@inertiajs/react'
import AppHead from '../../../Components/AppHead'
import AppNav from '../../../Components/AppNav'
import AppFooter from '../../../Components/AppFooter'
import AdminShell from '../../../Components/Admin/AdminShell'
import AdminSupportSectionSidebar from '../../../Components/Admin/AdminSupportSectionSidebar'
import { ChartBarIcon, CogIcon } from '@heroicons/react/24/outline'

const card =
    'flex items-center gap-3 rounded-xl border border-slate-200 bg-white p-5 shadow-sm transition-colors hover:border-indigo-200 hover:bg-indigo-50/40'

export default function AdminSupportHubIndex() {
    const { auth } = usePage().props

    return (
        <div className="min-h-full">
            <AppHead title="Support" suffix="Admin" />
            <AppNav brand={auth.activeBrand} tenant={null} />
            <main className="min-h-0">
                <AdminShell
                    centerKey="support"
                    breadcrumbs={[{ label: 'Admin', href: '/app/admin' }, { label: 'Support' }]}
                    title="Support"
                    description="Customer and internal tickets, engineering queue, and SLA follow-up."
                    sidebar={<AdminSupportSectionSidebar />}
                >
                    <div className="grid gap-5 sm:grid-cols-2 sm:gap-6">
                        <Link href="/app/admin/support/tickets" className={card}>
                            <ChartBarIcon className="h-8 w-8 shrink-0 text-indigo-600" />
                            <div>
                                <p className="text-lg font-semibold text-slate-900">Support tickets</p>
                                <p className="text-sm text-slate-600">Staff queue, assignments, and customer-visible threads.</p>
                            </div>
                        </Link>
                        <Link href="/app/admin/support/tickets?type=engineering" className={card}>
                            <CogIcon className="h-8 w-8 shrink-0 text-amber-600" />
                            <div>
                                <p className="text-lg font-semibold text-slate-900">Engineering queue</p>
                                <p className="text-sm text-slate-600">Internal and engineering-classified work.</p>
                            </div>
                        </Link>
                    </div>
                </AdminShell>
            </main>
            <AppFooter />
        </div>
    )
}
