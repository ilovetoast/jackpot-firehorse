import { Link, usePage } from '@inertiajs/react'
import AppNav from '../../Components/AppNav'
import AppFooter from '../../Components/AppFooter'
import { ExclamationTriangleIcon } from '@heroicons/react/24/outline'

export default function AccountSuspended() {
    const { auth } = usePage().props

    return (
        <div className="min-h-full">
            <AppNav brand={auth.activeBrand} tenant={null} />
            <main className="bg-gray-50">
                <div className="mx-auto max-w-2xl px-4 sm:px-6 lg:px-8 py-16">
                    <div className="bg-white rounded-lg shadow-sm ring-1 ring-gray-200 overflow-hidden">
                        <div className="px-6 py-8 text-center">
                            <div className="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-red-100 mb-4">
                                <ExclamationTriangleIcon className="h-6 w-6 text-red-600" />
                            </div>
                            <h1 className="text-2xl font-bold text-gray-900 mb-2">Account Suspended</h1>
                            <p className="text-sm text-gray-600 mb-6">
                                Your account has been suspended and you no longer have access to this platform.
                            </p>
                            
                            <div className="bg-red-50 border border-red-200 rounded-md p-4 mb-6 text-left">
                                <h2 className="text-sm font-semibold text-red-900 mb-2">What does this mean?</h2>
                                <p className="text-sm text-red-800">
                                    Your account access has been temporarily blocked by an administrator. You will not be able to log in 
                                    or access any pages until your account is reactivated.
                                </p>
                            </div>

                            <div className="bg-gray-50 border border-gray-200 rounded-md p-4 mb-6 text-left">
                                <h2 className="text-sm font-semibold text-gray-900 mb-2">What can you do?</h2>
                                <p className="text-sm text-gray-700 mb-3">
                                    If you believe this was done in error, please contact support:
                                </p>
                                <ul className="text-sm text-gray-600 space-y-1 list-disc list-inside">
                                    <li>Submit a support ticket through our support system</li>
                                    <li>Contact your account administrator directly</li>
                                    <li>Email support at support@example.com</li>
                                </ul>
                            </div>

                            <div className="flex flex-col sm:flex-row gap-3 justify-center">
                                <a
                                    href="/support"
                                    className="inline-flex justify-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                                >
                                    Submit Support Ticket
                                </a>
                                <Link
                                    href="/"
                                    className="inline-flex justify-center rounded-md bg-white px-4 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
                                >
                                    Return to Home
                                </Link>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
            <AppFooter />
        </div>
    )
}
