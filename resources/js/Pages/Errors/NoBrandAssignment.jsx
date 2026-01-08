import { Link } from '@inertiajs/react'
import AppNav from '../../Components/AppNav'
import AppFooter from '../../Components/AppFooter'

export default function NoBrandAssignment({ tenant, user }) {
    return (
        <div className="min-h-screen bg-gray-50">
            <AppNav />
            <main className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-16">
                <div className="mx-auto max-w-2xl">
                    <div className="text-center">
                        {/* Error Icon */}
                        <div className="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-red-100">
                            <svg
                                className="h-8 w-8 text-red-600"
                                fill="none"
                                viewBox="0 0 24 24"
                                strokeWidth="1.5"
                                stroke="currentColor"
                            >
                                <path
                                    strokeLinecap="round"
                                    strokeLinejoin="round"
                                    d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"
                                />
                            </svg>
                        </div>

                        {/* Error Title */}
                        <h1 className="mt-6 text-3xl font-bold text-gray-900">
                            Brand Access Required
                        </h1>

                        {/* Error Message */}
                        <p className="mt-4 text-lg text-gray-600">
                            You haven't been assigned to any brands yet.
                        </p>

                        <div className="mt-6 rounded-lg bg-yellow-50 p-6 text-left">
                            <div className="flex">
                                <div className="flex-shrink-0">
                                    <svg
                                        className="h-5 w-5 text-yellow-400"
                                        viewBox="0 0 20 20"
                                        fill="currentColor"
                                    >
                                        <path
                                            fillRule="evenodd"
                                            d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z"
                                            clipRule="evenodd"
                                        />
                                    </svg>
                                </div>
                                <div className="ml-3">
                                    <h3 className="text-sm font-medium text-yellow-800">
                                        What does this mean?
                                    </h3>
                                    <div className="mt-2 text-sm text-yellow-700">
                                        <p>
                                            You've been added to {' '}
                                            {tenant ? (
                                                <span className="font-semibold">{tenant.name}</span>
                                            ) : (
                                                'the company'
                                            )}{' '}
                                            but haven't been assigned to any brands yet. Brand assignments are required to access the platform features.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {/* Instructions */}
                        <div className="mt-8 rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                            <h2 className="text-lg font-semibold text-gray-900">
                                What to do next:
                            </h2>
                            <ul className="mt-4 space-y-3 text-left text-sm text-gray-600">
                                <li className="flex items-start">
                                    <span className="mr-2 font-semibold text-indigo-600">1.</span>
                                    <span>
                                        Contact your company administrator to request access to one or more brands.
                                    </span>
                                </li>
                                <li className="flex items-start">
                                    <span className="mr-2 font-semibold text-indigo-600">2.</span>
                                    <span>
                                        Once you've been assigned to a brand, you'll be able to access the platform features.
                                    </span>
                                </li>
                                <li className="flex items-start">
                                    <span className="mr-2 font-semibold text-indigo-600">3.</span>
                                    <span>
                                        If you're an administrator or owner, please ensure you've been assigned to brands even though you may have broader access.
                                    </span>
                                </li>
                            </ul>
                        </div>

                        {/* Actions */}
                        <div className="mt-8 flex flex-col gap-4 sm:flex-row sm:justify-center">
                            <Link
                                href="/app/companies"
                                className="inline-flex items-center justify-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                            >
                                <svg
                                    className="mr-2 h-4 w-4"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    strokeWidth="1.5"
                                    stroke="currentColor"
                                >
                                    <path
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                        d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25"
                                    />
                                </svg>
                                View Companies
                            </Link>
                            <form
                                method="POST"
                                action="/app/logout"
                                className="inline-flex items-center justify-center rounded-md bg-white px-4 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
                            >
                                <svg
                                    className="mr-2 h-4 w-4"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    strokeWidth="1.5"
                                    stroke="currentColor"
                                >
                                    <path
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                        d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75"
                                    />
                                </svg>
                                Logout
                            </form>
                        </div>

                        {/* Tenant Info */}
                        {tenant && (
                            <div className="mt-8 text-sm text-gray-500">
                                <p>
                                    Company: <span className="font-medium text-gray-700">{tenant.name}</span>
                                </p>
                                {user && (
                                    <p className="mt-1">
                                        User: <span className="font-medium text-gray-700">{user.email}</span>
                                    </p>
                                )}
                            </div>
                        )}
                    </div>
                </div>
            </main>
            <AppFooter />
        </div>
    )
}
