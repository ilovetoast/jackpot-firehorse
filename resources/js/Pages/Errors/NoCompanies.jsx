import { Link } from '@inertiajs/react'
import AppNav from '../../Components/AppNav'
import AppFooter from '../../Components/AppFooter'

export default function NoCompanies({ user }) {
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
                            No Company Access
                        </h1>

                        {/* Error Message */}
                        <p className="mt-4 text-lg text-gray-600">
                            Your account is not associated with any company or organization.
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
                                            You've successfully logged in, but your account hasn't been added to any company or organization yet. 
                                            You need to be invited to a company before you can access the platform.
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
                                        Wait for a company administrator to invite you to their organization.
                                    </span>
                                </li>
                                <li className="flex items-start">
                                    <span className="mr-2 font-semibold text-indigo-600">2.</span>
                                    <span>
                                        Check your email for an invitation link. Click the link to accept the invitation and join the company.
                                    </span>
                                </li>
                                <li className="flex items-start">
                                    <span className="mr-2 font-semibold text-indigo-600">3.</span>
                                    <span>
                                        If you believe you should have access to a company, contact the company administrator or support.
                                    </span>
                                </li>
                            </ul>
                            
                            {/* TODO Comments for Future Actions */}
                            <div className="mt-6 rounded-md bg-gray-50 p-4 border-l-4 border-indigo-500">
                                <h3 className="text-sm font-semibold text-gray-900 mb-2">TODO: Future Actions</h3>
                                <ul className="text-xs text-gray-600 space-y-1 list-disc list-inside">
                                    <li>Add "Create New Company" button for users who want to start their own organization</li>
                                    <li>Add "Request Company Access" form that sends notification to site admins</li>
                                    <li>Show pending invitations if any exist for this user</li>
                                    <li>Add link to contact support</li>
                                    <li>Add ability to resend invitation emails if user has pending invitations</li>
                                </ul>
                            </div>
                        </div>

                        {/* Actions */}
                        <div className="mt-8 flex flex-col gap-4 sm:flex-row sm:justify-center">
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

                        {/* User Info */}
                        {user && (
                            <div className="mt-8 text-sm text-gray-500">
                                <p>
                                    User: <span className="font-medium text-gray-700">{user.email}</span>
                                </p>
                            </div>
                        )}
                    </div>
                </div>
            </main>
            <AppFooter />
        </div>
    )
}
