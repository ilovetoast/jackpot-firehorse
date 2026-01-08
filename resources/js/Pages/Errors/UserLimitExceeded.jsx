import { Link } from '@inertiajs/react'
import { ExclamationTriangleIcon, LockClosedIcon } from '@heroicons/react/24/outline'
import AppNav from '../../Components/AppNav'
import AppFooter from '../../Components/AppFooter'

export default function UserLimitExceeded({ tenant, user, plan_info }) {
    return (
        <div className="min-h-full">
            <AppNav brand={null} tenant={null} />
            <main className="bg-gray-50 flex-1">
                <div className="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8 py-16">
                    <div className="text-center">
                        {/* Icon */}
                        <div className="mx-auto flex items-center justify-center h-20 w-20 rounded-full bg-red-100 mb-6">
                            <LockClosedIcon className="h-10 w-10 text-red-600" />
                        </div>

                        {/* Heading */}
                        <h1 className="text-3xl font-bold tracking-tight text-gray-900 mb-4">
                            Account Access Limited
                        </h1>

                        {/* Description */}
                        <div className="bg-yellow-50 border-l-4 border-yellow-400 p-4 mb-8 text-left max-w-2xl mx-auto">
                            <div className="flex">
                                <div className="flex-shrink-0">
                                    <ExclamationTriangleIcon className="h-5 w-5 text-yellow-400" />
                                </div>
                                <div className="ml-3">
                                    <h3 className="text-sm font-medium text-yellow-800">
                                        Plan Limit Exceeded
                                    </h3>
                                    <div className="mt-2 text-sm text-yellow-700">
                                        <p>
                                            {tenant?.name} has <strong>{plan_info?.current_user_count} users</strong>, but the current plan ({plan_info?.plan_name}) only allows <strong>{plan_info?.max_users === Number.MAX_SAFE_INTEGER || plan_info?.max_users > 1000 ? 'unlimited' : plan_info?.max_users} user{plan_info?.max_users !== 1 ? 's' : ''}</strong>.
                                        </p>
                                        <p className="mt-2">
                                            You have been disabled from accessing <strong>{tenant?.name}</strong> due to this limit. You can still access other companies you belong to. The company owner or administrator can upgrade the plan to restore your access.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {/* Additional Info */}
                        <div className="bg-white shadow-sm ring-1 ring-gray-200 rounded-lg p-6 mb-8 text-left max-w-2xl mx-auto">
                            <h2 className="text-lg font-semibold text-gray-900 mb-4">What does this mean?</h2>
                            <ul className="space-y-3 text-sm text-gray-700">
                                <li className="flex items-start">
                                    <span className="mr-2">•</span>
                                    <span>
                                        Your account remains active, but you cannot access <strong>{tenant?.name}</strong> until the plan is upgraded or the user count is reduced.
                                    </span>
                                </li>
                                <li className="flex items-start">
                                    <span className="mr-2">•</span>
                                    <span>
                                        You can still access other companies you belong to. Only this company ({tenant?.name}) is affected by the limit.
                                    </span>
                                </li>
                                <li className="flex items-start">
                                    <span className="mr-2">•</span>
                                    <span>
                                        The company owner always has access, regardless of plan limits.
                                    </span>
                                </li>
                                <li className="flex items-start">
                                    <span className="mr-2">•</span>
                                    <span>
                                        Users are indexed by when they joined the company. Users who joined earlier have priority access within the plan limit.
                                    </span>
                                </li>
                                <li className="flex items-start">
                                    <span className="mr-2">•</span>
                                    <span>
                                        Contact your company administrator or owner to upgrade the plan to restore access for all users.
                                    </span>
                                </li>
                            </ul>
                        </div>

                        {/* Actions */}
                        <div className="flex flex-col sm:flex-row gap-4 justify-center items-center">
                            <Link
                                href="/app/companies"
                                className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                            >
                                Switch to Another Company
                            </Link>
                            <Link
                                href="/app/billing"
                                className="rounded-md bg-white px-4 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
                            >
                                View Billing & Upgrade Plan
                            </Link>
                            <form method="POST" action="/app/logout" className="inline">
                                <button
                                    type="submit"
                                    className="text-sm font-medium text-gray-600 hover:text-gray-900"
                                >
                                    Logout
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </main>
            <AppFooter />
        </div>
    )
}
