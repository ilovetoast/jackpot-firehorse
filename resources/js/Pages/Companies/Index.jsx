import { router } from '@inertiajs/react'
import { Link } from '@inertiajs/react'
import AppNav from '../../Components/AppNav'
import AppFooter from '../../Components/AppFooter'
import { usePage } from '@inertiajs/react'

export default function CompaniesIndex({ companies }) {
    const { auth } = usePage().props

    const handleSwitch = (companyId) => {
        router.post(`/app/companies/${companyId}/switch`, {}, {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                window.location.href = '/app/dashboard'
            },
        })
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
        <div className="min-h-full bg-gray-50">
            <AppNav brand={auth.activeBrand} tenant={null} />
            <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
                <div className="mb-8">
                    <h1 className="text-3xl font-bold tracking-tight text-gray-900">Company Management</h1>
                    <p className="mt-2 text-sm text-gray-700">Manage and switch between your companies</p>
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
                                            Timezone
                                        </th>
                                        <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Status
                                        </th>
                                        <th scope="col" className="relative px-6 py-3">
                                            <span className="sr-only">Actions</span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="bg-white divide-y divide-gray-200">
                                    {companies.map((company) => (
                                        <tr key={company.id} className={company.is_active ? 'bg-indigo-50' : 'hover:bg-gray-50'}>
                                            <td className="px-6 py-4 whitespace-nowrap">
                                                <div className="flex items-center">
                                                    <div className="flex-shrink-0">
                                                        {company.is_active && (
                                                            <div className="h-2 w-2 rounded-full bg-indigo-600" />
                                                        )}
                                                    </div>
                                                    <div className="ml-4">
                                                        <div className="text-sm font-medium text-gray-900">
                                                            {company.name}
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
                                                <div className="text-sm text-gray-900">{company.timezone}</div>
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap">
                                                {company.is_active ? (
                                                    <span className="inline-flex items-center rounded-full bg-indigo-100 px-2.5 py-0.5 text-xs font-medium text-indigo-800">
                                                        Active
                                                    </span>
                                                ) : (
                                                    <span className="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-800">
                                                        Inactive
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                                {!company.is_active ? (
                                                    <button
                                                        type="button"
                                                        onClick={() => handleSwitch(company.id)}
                                                        className="text-indigo-600 hover:text-indigo-900"
                                                    >
                                                        Switch
                                                    </button>
                                                ) : (
                                                    <Link
                                                        href="/app/companies/settings"
                                                        className="text-indigo-600 hover:text-indigo-900"
                                                    >
                                                        Settings
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
            <AppFooter />
        </div>
    )
}
