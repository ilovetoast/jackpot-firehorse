import { Head } from '@inertiajs/react'
import { ExclamationTriangleIcon } from '@heroicons/react/24/outline'

export default function TenantPortalLogin({ tenant, login_url, subdomain_url }) {
    return (
        <>
            <Head title={`${tenant.name} - Login`} />
            
            <div className="flex min-h-full flex-col justify-center py-12 sm:px-6 lg:px-8 bg-gray-50">
                <div className="sm:mx-auto sm:w-full sm:max-w-md">
                    <div className="mx-auto h-12 w-12 flex items-center justify-center rounded-full bg-blue-100">
                        <svg className="h-6 w-6 text-blue-600" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M13.5 21v-7.5a.75.75 0 01.75-.75h3a.75.75 0 01.75.75V21m-4.5 0H2.25A2.25 2.25 0 010 18.75V10.5a2.25 2.25 0 012.25-2.25h5.25m13.5 0h-13.5m0 0V5.25A2.25 2.25 0 017.5 3h5.25A2.25 2.25 0 0115 5.25V10.5" />
                        </svg>
                    </div>
                    <h2 className="mt-6 text-center text-3xl font-bold tracking-tight text-gray-900">
                        Welcome to {tenant.name}
                    </h2>
                    <p className="mt-2 text-center text-sm text-gray-600">
                        Please log in to access your account
                    </p>
                </div>

                <div className="mt-8 sm:mx-auto sm:w-full sm:max-w-md">
                    <div className="bg-white px-4 py-8 shadow sm:rounded-lg sm:px-10">
                        <div className="rounded-md bg-blue-50 p-4 mb-6">
                            <div className="flex">
                                <div className="flex-shrink-0">
                                    <svg className="h-5 w-5 text-blue-400" viewBox="0 0 20 20" fill="currentColor">
                                        <path fillRule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clipRule="evenodd" />
                                    </svg>
                                </div>
                                <div className="ml-3">
                                    <p className="text-sm text-blue-700">
                                        You are accessing <strong>{tenant.name}</strong> through their dedicated portal.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div className="space-y-6">
                            <div className="text-center">
                                <a
                                    href={login_url}
                                    className="w-full flex justify-center py-3 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition-colors"
                                >
                                    Sign In to {tenant.name}
                                </a>
                            </div>

                            <div className="relative">
                                <div className="absolute inset-0 flex items-center">
                                    <div className="w-full border-t border-gray-300" />
                                </div>
                                <div className="relative flex justify-center text-sm">
                                    <span className="bg-white px-2 text-gray-500">or</span>
                                </div>
                            </div>

                            <div className="text-center">
                                <a
                                    href={subdomain_url.replace(/^https?:\/\/[^.]+\./, 'http://') + login_url.replace(/^https?:\/\/[^\/]+/, '')}
                                    className="w-full flex justify-center py-3 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition-colors"
                                >
                                    Go to Main Login Page
                                </a>
                            </div>

                            <div className="text-center">
                                <p className="text-xs text-gray-500">
                                    Access URL: <code className="bg-gray-100 px-1 py-0.5 rounded text-xs">{subdomain_url}</code>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </>
    )
}