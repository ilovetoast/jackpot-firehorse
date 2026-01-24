import { Link, Head, usePage } from '@inertiajs/react'

export default function TenantPortalLanding({ tenant, brand, user, subdomain_url, main_app_url }) {
    const { errors } = usePage().props

    return (
        <>
            <Head title={`Welcome to ${tenant.name}`} />
            
            <div className="min-h-screen bg-gray-50 flex flex-col justify-center py-12 sm:px-6 lg:px-8">
                <div className="sm:mx-auto sm:w-full sm:max-w-md">
                    {/* Company Logo/Branding */}
                    <div className="text-center">
                        {brand?.logo_url ? (
                            <img
                                className="mx-auto h-20 w-auto"
                                src={brand.logo_url}
                                alt={tenant.name}
                            />
                        ) : (
                            <div className="mx-auto h-20 w-20 bg-indigo-600 rounded-lg flex items-center justify-center">
                                <span className="text-2xl font-bold text-white">
                                    {tenant.name.charAt(0).toUpperCase()}
                                </span>
                            </div>
                        )}
                        
                        <h1 className="mt-6 text-3xl font-bold tracking-tight text-gray-900">
                            {tenant.name}
                        </h1>
                        <p className="mt-2 text-sm text-gray-600">
                            Company Portal
                        </p>
                    </div>
                </div>

                <div className="mt-8 sm:mx-auto sm:w-full sm:max-w-md">
                    <div className="bg-white py-8 px-4 shadow sm:rounded-lg sm:px-10">
                        {user ? (
                            /* User is logged in */
                            <div className="text-center">
                                <div className="mb-6">
                                    <div className="mx-auto h-12 w-12 bg-green-100 rounded-full flex items-center justify-center">
                                        <svg className="h-6 w-6 text-green-600" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />
                                        </svg>
                                    </div>
                                    <h2 className="text-lg font-semibold text-gray-900">
                                        Hello, {user.name || user.email}!
                                    </h2>
                                </div>
                                
                                {user.belongs_to_tenant ? (
                                    <div>
                                        <p className="text-sm text-gray-600 mb-6">
                                            You have access to {tenant.name}. Click below to access the main application.
                                        </p>
                                        <Link
                                            href={`${main_app_url}/app/companies/${tenant.id}/switch`}
                                            className="w-full flex justify-center py-3 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                                        >
                                            Access {tenant.name}
                                        </Link>
                                    </div>
                                ) : (
                                    <div>
                                        <p className="text-sm text-red-600 mb-6">
                                            You don't have access to {tenant.name}. Please contact an administrator to request access.
                                        </p>
                                        <Link
                                            href={main_app_url}
                                            className="w-full flex justify-center py-3 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                                        >
                                            Go to Main App
                                        </Link>
                                    </div>
                                )}
                            </div>
                        ) : (
                            /* User is not logged in */
                            <div>
                                <div className="text-center mb-6">
                                    <h2 className="text-xl font-semibold text-gray-900">
                                        Welcome to {tenant.name}
                                    </h2>
                                    <p className="mt-2 text-sm text-gray-600">
                                        Please sign in to access your company portal
                                    </p>
                                </div>
                                
                                <div className="space-y-4">
                                    <Link
                                        href={`/tenant-portal/${tenant.slug}/login`}
                                        className="w-full flex justify-center py-3 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                                    >
                                        Sign In to {tenant.name}
                                    </Link>
                                    
                                    <div className="relative">
                                        <div className="absolute inset-0 flex items-center">
                                            <div className="w-full border-t border-gray-300" />
                                        </div>
                                        <div className="relative flex justify-center text-sm">
                                            <span className="px-2 bg-white text-gray-500">or</span>
                                        </div>
                                    </div>
                                    
                                    <Link
                                        href={main_app_url}
                                        className="w-full flex justify-center py-3 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                                    >
                                        Visit Main Application
                                    </Link>
                                </div>
                            </div>
                        )}
                    </div>
                </div>
                
                {/* Footer */}
                <div className="mt-8 text-center">
                    <p className="text-xs text-gray-500">
                        This is a secure company portal for {tenant.name}
                    </p>
                    <p className="text-xs text-gray-400 mt-1">
                        {subdomain_url}
                    </p>
                </div>
            </div>
        </>
    )
}