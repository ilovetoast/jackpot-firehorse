import { Link } from '@inertiajs/react'
import { switchCompanyWorkspace } from '../../utils/workspaceCompanySwitch'
import AppNav from '../../Components/AppNav'
import AppHead from '../../Components/AppHead'
import AppFooter from '../../Components/AppFooter'
import { usePage } from '@inertiajs/react'

export default function CompaniesIndex({ companies }) {
    const { auth } = usePage().props

    const handleSwitch = (companyId) => {
        switchCompanyWorkspace({ companyId, redirect: '/app' })
    }

    const formatPlanName = (plan) => {
        if (!plan || plan === 'free') return 'Free'
        return plan.charAt(0).toUpperCase() + plan.slice(1)
    }

    const formatSubscriptionStatus = (status) => {
        if (!status || status === 'none') return 'No Subscription'
        return status.charAt(0).toUpperCase() + status.slice(1)
    }

    return (
        <div className="min-h-screen flex flex-col bg-gray-50">
            <AppHead title="Companies" />
            <AppNav brand={auth.activeBrand} tenant={null} />
            <main className="flex-1">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
                    <div className="mb-8">
                        <h1 className="text-3xl font-bold text-gray-900">Company Management</h1>
                        <p className="mt-2 text-sm text-gray-600">Manage and switch between your companies</p>
                    </div>

                <div className="bg-white shadow-sm ring-1 ring-gray-200 rounded-lg overflow-hidden">
                    {companies.length === 0 ? (
                        <div className="px-6 py-12 text-center">
                            <p className="text-sm text-gray-500">You are not associated with any companies.</p>
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-gray-200">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Company Name
                                        </th>
                                        <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Billing
                                        </th>
                                        <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Current
                                        </th>
                                        <th scope="col" className="relative px-6 py-3">
                                            <span className="sr-only">Actions</span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="bg-white divide-y divide-gray-200">
                                    {companies.map((company) => (
                                        <tr key={company.id} className={company.is_active ? 'bg-violet-50' : 'hover:bg-gray-50'}>
                                            <td className="px-6 py-4 whitespace-nowrap">
                                                <div className="flex items-center">
                                                    <div className="flex-shrink-0">
                                                        {company.is_active && (
                                                            <div className="h-2 w-2 rounded-full bg-violet-600" />
                                                        )}
                                                    </div>
                                                    <div className="ml-4">
                                                        <div className="flex flex-wrap items-center gap-2">
                                                            <span className="text-sm font-medium text-gray-900">
                                                                {company.name}
                                                            </span>
                                                            {company.is_agency ? (
                                                                <span
                                                                    className="inline-flex items-center rounded-full border border-violet-200 bg-violet-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-violet-800"
                                                                    title="Agency workspace"
                                                                >
                                                                    Agency
                                                                </span>
                                                            ) : null}
                                                        </div>
                                                        <div className="text-sm text-gray-500">{company.slug}</div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap">
                                                <div className="text-sm text-gray-900">
                                                    {formatPlanName(company.billing?.current_plan)}
                                                </div>
                                                <div className="text-sm text-gray-500">
                                                    {formatSubscriptionStatus(company.billing?.subscription_status)}
                                                </div>
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap">
                                                {company.is_active ? (
                                                    <span className="inline-flex items-center rounded-full bg-violet-100 px-2.5 py-0.5 text-xs font-medium text-violet-800">
                                                        Current
                                                    </span>
                                                ) : (
                                                    <span className="text-sm text-gray-400">—</span>
                                                )}
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                                {!company.is_active ? (
                                                    <button
                                                        type="button"
                                                        onClick={() => handleSwitch(company.id)}
                                                        className="text-violet-600 hover:text-violet-900"
                                                    >
                                                        Switch
                                                    </button>
                                                ) : (
                                                    <Link
                                                        href="/app/companies/settings"
                                                        className="text-violet-600 hover:text-violet-900"
                                                    >
                                                        More info
                                                    </Link>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
                </div>
            </main>
            <AppFooter variant="settings" />
        </div>
    )
}
