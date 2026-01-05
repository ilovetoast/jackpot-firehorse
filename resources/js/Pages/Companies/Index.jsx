import { router } from '@inertiajs/react'
import { Link } from '@inertiajs/react'

export default function CompaniesIndex({ companies }) {
    const handleSwitch = (companyId) => {
        router.post(`/companies/${companyId}/switch`, {}, {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                window.location.href = '/dashboard'
            },
        })
    }

    return (
        <div className="min-h-full bg-gray-50">
            <nav className="bg-white shadow-sm">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div className="flex h-16 justify-between">
                        <div className="flex">
                            <div className="flex flex-shrink-0 items-center">
                                <Link href="/dashboard" className="text-xl font-bold text-gray-900">
                                    Jackpot Asset Manager
                                </Link>
                            </div>
                        </div>
                    </div>
                </div>
            </nav>
            <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
                <div className="mb-8">
                    <h1 className="text-3xl font-bold tracking-tight text-gray-900">Company Management</h1>
                    <p className="mt-2 text-sm text-gray-700">Manage and switch between your companies</p>
                </div>

                <div className="bg-white shadow sm:rounded-lg">
                    <div className="px-4 py-5 sm:p-6">
                        <h3 className="text-base font-semibold leading-6 text-gray-900 mb-4">
                            Your Companies
                        </h3>
                        <div className="space-y-4">
                            {companies.length === 0 ? (
                                <p className="text-sm text-gray-500">You are not associated with any companies.</p>
                            ) : (
                                companies.map((company) => (
                                    <div
                                        key={company.id}
                                        className={`flex items-center justify-between rounded-lg border p-4 ${
                                            company.is_active
                                                ? 'border-indigo-500 bg-indigo-50'
                                                : 'border-gray-200 bg-white'
                                        }`}
                                    >
                                        <div className="flex items-center">
                                            <div className="flex-shrink-0">
                                                {company.is_active && (
                                                    <div className="flex h-2 w-2 items-center justify-center">
                                                        <div className="h-2 w-2 rounded-full bg-indigo-600" />
                                                    </div>
                                                )}
                                            </div>
                                            <div className="ml-4">
                                                <div className="flex items-center">
                                                    <p
                                                        className={`text-sm font-medium ${
                                                            company.is_active ? 'text-indigo-900' : 'text-gray-900'
                                                        }`}
                                                    >
                                                        {company.name}
                                                    </p>
                                                    {company.is_active && (
                                                        <span className="ml-2 inline-flex items-center rounded-full bg-indigo-100 px-2.5 py-0.5 text-xs font-medium text-indigo-800">
                                                            Active
                                                        </span>
                                                    )}
                                                </div>
                                                <p className={`text-sm ${company.is_active ? 'text-indigo-700' : 'text-gray-500'}`}>
                                                    {company.slug}
                                                </p>
                                            </div>
                                        </div>
                                        {!company.is_active && (
                                            <button
                                                type="button"
                                                onClick={() => handleSwitch(company.id)}
                                                className="ml-4 rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
                                            >
                                                Switch to this company
                                            </button>
                                        )}
                                    </div>
                                ))
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    )
}
