import { Link } from '@inertiajs/react'
import { ExclamationTriangleIcon, LockClosedIcon } from '@heroicons/react/24/outline'
import AppNav from '../../Components/AppNav'
import AppFooter from '../../Components/AppFooter'

export default function BrandDisabled({ tenant, user, plan_info }) {
    return (
        <div className="min-h-full">
            <AppNav brand={null} tenant={null} />
            <main className="bg-gray-50 flex-1">
                <div className="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8 py-16">
                    <div className="text-center">
                        <div className="mx-auto flex items-center justify-center h-20 w-20 rounded-full bg-amber-100 mb-6">
                            <LockClosedIcon className="h-10 w-10 text-amber-600" />
                        </div>

                        <h1 className="text-3xl font-bold tracking-tight text-gray-900 mb-4">
                            Brand Access Unavailable
                        </h1>

                        <div className="bg-amber-50 border-l-4 border-amber-400 p-4 mb-8 text-left max-w-2xl mx-auto">
                            <div className="flex">
                                <div className="flex-shrink-0">
                                    <ExclamationTriangleIcon className="h-5 w-5 text-amber-400" />
                                </div>
                                <div className="ml-3">
                                    <h3 className="text-sm font-medium text-amber-800">
                                        Brand Limit Reached
                                    </h3>
                                    <div className="mt-2 text-sm text-amber-700">
                                        <p>
                                            {tenant?.name} has <strong>{plan_info?.total_brands} brand{plan_info?.total_brands !== 1 ? 's' : ''}</strong>, but the current plan ({plan_info?.plan_name}) only allows <strong>{plan_info?.max_brands} brand{plan_info?.max_brands !== 1 ? 's' : ''}</strong>.
                                        </p>
                                        <p className="mt-2">
                                            The brand{plan_info?.total_brands - plan_info?.max_brands > 1 ? 's' : ''} you have access to {plan_info?.total_brands - plan_info?.max_brands > 1 ? 'are' : 'is'} currently disabled because {plan_info?.total_brands - plan_info?.max_brands > 1 ? 'they exceed' : 'it exceeds'} the plan limit. Contact your administrator to either upgrade the plan or reassign your access to an active brand.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div className="bg-white shadow-sm ring-1 ring-gray-200 rounded-lg p-6 mb-8 text-left max-w-2xl mx-auto">
                            <h2 className="text-lg font-semibold text-gray-900 mb-4">What can be done?</h2>
                            <ul className="space-y-3 text-sm text-gray-700">
                                <li className="flex items-start">
                                    <span className="mr-2">•</span>
                                    <span>
                                        <strong>Upgrade the plan</strong> — a higher plan allows more brands, which would re-enable the ones you need.
                                    </span>
                                </li>
                                <li className="flex items-start">
                                    <span className="mr-2">•</span>
                                    <span>
                                        <strong>Reassign your access</strong> — an admin can add you to a brand that is still within the plan limit.
                                    </span>
                                </li>
                                <li className="flex items-start">
                                    <span className="mr-2">•</span>
                                    <span>
                                        <strong>Remove unused brands</strong> — reducing the total brand count below the limit will re-enable access.
                                    </span>
                                </li>
                                <li className="flex items-start">
                                    <span className="mr-2">•</span>
                                    <span>
                                        Brands are prioritized by default status and alphabetical order. Brands earlier in the list stay active when the limit is reached.
                                    </span>
                                </li>
                            </ul>
                        </div>

                        <div className="flex flex-col sm:flex-row gap-4 justify-center items-center">
                            <Link
                                href="/gateway"
                                className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                            >
                                Back to Gateway
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
