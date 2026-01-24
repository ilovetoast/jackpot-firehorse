import { Link, Head } from '@inertiajs/react'

export default function TenantPortalNotFound({ slug, domain }) {
    return (
        <>
            <Head title="Company Not Found" />
            
            <div className="min-h-screen bg-gray-50 flex flex-col justify-center py-12 sm:px-6 lg:px-8">
                <div className="sm:mx-auto sm:w-full sm:max-w-md">
                    <div className="text-center">
                        <div className="mx-auto h-20 w-20 bg-red-100 rounded-lg flex items-center justify-center">
                            <svg className="h-10 w-10 text-red-600" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
                            </svg>
                        </div>
                        
                        <h1 className="mt-6 text-3xl font-bold tracking-tight text-gray-900">
                            Company Not Found
                        </h1>
                        <p className="mt-2 text-sm text-gray-600">
                            We couldn't find a company with the slug "{slug}"
                        </p>
                    </div>
                </div>

                <div className="mt-8 sm:mx-auto sm:w-full sm:max-w-md">
                    <div className="bg-white py-8 px-4 shadow sm:rounded-lg sm:px-10">
                        <div className="text-center">
                            <div className="mb-6">
                                <h2 className="text-lg font-semibold text-gray-900">
                                    What you can do:
                                </h2>
                            </div>
                            
                            <div className="space-y-4">
                                <div className="text-sm text-gray-600 text-left">
                                    <ul className="list-disc list-inside space-y-2">
                                        <li>Check the URL spelling</li>
                                        <li>Contact your company administrator</li>
                                        <li>Visit the main application</li>
                                    </ul>
                                </div>
                                
                                <div className="pt-4">
                                    <Link
                                        href="https://jackpot.local"
                                        className="w-full flex justify-center py-3 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                                    >
                                        Go to Main Application
                                    </Link>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                {/* Footer */}
                <div className="mt-8 text-center">
                    <p className="text-xs text-gray-400">
                        Requested: {domain}
                    </p>
                    <p className="text-xs text-gray-400 mt-1">
                        Error: Company "{slug}" not found
                    </p>
                </div>
            </div>
        </>
    )
}