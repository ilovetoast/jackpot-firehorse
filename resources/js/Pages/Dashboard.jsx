import { useState } from 'react'
import { useForm, Link, router } from '@inertiajs/react'
import PlanLimitIndicator from '../Components/PlanLimitIndicator'

export default function Dashboard({ auth, tenant, brand, plan_limits }) {
    const { post } = useForm()
    const [userMenuOpen, setUserMenuOpen] = useState(false)

    const handleLogout = () => {
        post('/logout')
    }

    const handleSwitchCompany = (companyId) => {
        router.post(`/companies/${companyId}/switch`, {}, {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                window.location.href = '/dashboard'
            },
        })
    }

    const activeCompany = auth.companies?.find((c) => c.is_active)

    return (
        <div className="min-h-full">
            <nav className="bg-white shadow-sm">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div className="flex h-16 justify-between">
                        <div className="flex">
                            <div className="flex flex-shrink-0 items-center">
                                {brand?.logo_path ? (
                                    <img
                                        src={brand.logo_path}
                                        alt={brand.name}
                                        className="h-8 w-auto"
                                    />
                                ) : (
                                    <h1 className="text-xl font-bold text-gray-900">
                                        {brand?.name || 'Jackpot Asset Manager'}
                                    </h1>
                                )}
                            </div>
                        </div>
                        <div className="flex items-center gap-4">
                            
                            {/* User Menu */}
                            <div className="relative ml-3">
                                <div>
                                    <button
                                        type="button"
                                        className="flex max-w-xs items-center rounded-full bg-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                                        onClick={() => setUserMenuOpen(!userMenuOpen)}
                                        aria-expanded="false"
                                        aria-haspopup="true"
                                    >
                                        <span className="sr-only">Open user menu</span>
                                        <div className="flex h-8 w-8 items-center justify-center rounded-full bg-indigo-600 text-sm font-medium text-white">
                                            {auth.user?.name?.charAt(0).toUpperCase() || auth.user?.email?.charAt(0).toUpperCase()}
                                        </div>
                                        <span className="ml-3 text-sm font-medium text-gray-700 hidden sm:block">
                                            {auth.user?.name || auth.user?.email}
                                        </span>
                                        <svg className="ml-2 h-5 w-5 text-gray-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                            <path fillRule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clipRule="evenodd" />
                                        </svg>
                                    </button>
                                </div>

                                {userMenuOpen && (
                                    <>
                                        <div
                                            className="fixed inset-0 z-10"
                                            onClick={() => setUserMenuOpen(false)}
                                        />
                                        <div className="absolute right-0 z-20 mt-2 w-64 origin-top-right rounded-md bg-white py-1 shadow-lg ring-1 ring-black ring-opacity-5 focus:outline-none">
                                            {/* User Info */}
                                            <div className="px-4 py-3 border-b border-gray-200">
                                                <p className="text-sm font-medium text-gray-900">{auth.user?.name}</p>
                                                <p className="text-sm text-gray-500 truncate">{auth.user?.email}</p>
                                                {activeCompany && (
                                                    <p className="mt-1 text-xs text-gray-500">
                                                        Active: <span className="font-medium">{activeCompany.name}</span>
                                                    </p>
                                                )}
                                            </div>

                                            {/* Company Switcher */}
                                            {auth.companies && auth.companies.length > 1 && (
                                                <div className="px-4 py-2 border-b border-gray-200">
                                                    <p className="text-xs font-medium text-gray-500 uppercase tracking-wider mb-2">Switch Company</p>
                                                    <div className="space-y-1">
                                                        {auth.companies.map((company) => (
                                                            <button
                                                                key={company.id}
                                                                type="button"
                                                                onClick={() => {
                                                                    handleSwitchCompany(company.id)
                                                                    setUserMenuOpen(false)
                                                                }}
                                                                className={`block w-full text-left px-3 py-2 text-sm rounded-md ${
                                                                    company.is_active
                                                                        ? 'bg-indigo-50 text-indigo-700 font-medium'
                                                                        : 'text-gray-700 hover:bg-gray-50'
                                                                }`}
                                                            >
                                                                <div className="flex items-center justify-between">
                                                                    <span>{company.name}</span>
                                                                    {company.is_active && (
                                                                        <svg className="h-4 w-4 text-indigo-600" fill="currentColor" viewBox="0 0 20 20">
                                                                            <path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
                                                                        </svg>
                                                                    )}
                                                                </div>
                                                            </button>
                                                        ))}
                                                    </div>
                                                </div>
                                            )}

                                            {/* Menu Items */}
                                            <Link
                                                href="/brands"
                                                className="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50"
                                                onClick={() => setUserMenuOpen(false)}
                                            >
                                                Brand Management
                                            </Link>
                                            <Link
                                                href="/categories"
                                                className="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50"
                                                onClick={() => setUserMenuOpen(false)}
                                            >
                                                Categories
                                            </Link>
                                            <Link
                                                href="/companies"
                                                className="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50"
                                                onClick={() => setUserMenuOpen(false)}
                                            >
                                                Company Management
                                            </Link>
                                            <Link
                                                href="/billing"
                                                className="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50"
                                                onClick={() => setUserMenuOpen(false)}
                                            >
                                                Billing & Plans
                                            </Link>
                                            {(auth.user?.id === 1 || auth.permissions?.includes('site admin') || auth.permissions?.includes('site owner')) && (
                                                <Link
                                                    href="/admin"
                                                    className="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 border-t border-gray-200 mt-1 pt-2"
                                                    onClick={() => setUserMenuOpen(false)}
                                                >
                                                    Site Admin
                                                </Link>
                                            )}
                                            <Link
                                                href="/profile"
                                                className="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 border-t border-gray-200 mt-1 pt-2"
                                                onClick={() => setUserMenuOpen(false)}
                                            >
                                                Your Profile
                                            </Link>
                                            <button
                                                type="button"
                                                onClick={() => {
                                                    handleLogout()
                                                    setUserMenuOpen(false)
                                                }}
                                                className="block w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-50"
                                            >
                                                Sign out
                                            </button>
                                        </div>
                                    </>
                                )}
                            </div>
                        </div>
                    </div>
                </div>
            </nav>

            <main className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
                <div className="mb-8">
                    <h2 className="text-3xl font-bold tracking-tight text-gray-900">Dashboard</h2>
                    <p className="mt-2 text-sm text-gray-700">Welcome to your asset management dashboard</p>
                </div>

                <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    <div className="overflow-hidden rounded-lg bg-white px-4 py-5 shadow sm:p-6">
                        <dt className="truncate text-sm font-medium text-gray-500">Total Assets</dt>
                        <dd className="mt-1 text-3xl font-semibold tracking-tight text-gray-900">0</dd>
                    </div>
                    <div className="overflow-hidden rounded-lg bg-white px-4 py-5 shadow sm:p-6">
                        <dt className="truncate text-sm font-medium text-gray-500">Active Assets</dt>
                        <dd className="mt-1 text-3xl font-semibold tracking-tight text-gray-900">0</dd>
                    </div>
                    <div className="overflow-hidden rounded-lg bg-white px-4 py-5 shadow sm:p-6">
                        <dt className="truncate text-sm font-medium text-gray-500">Total Value</dt>
                        <dd className="mt-1 text-3xl font-semibold tracking-tight text-gray-900">$0</dd>
                    </div>
                </div>

                <div className="mt-8">
                    <div className="overflow-hidden rounded-lg bg-white shadow">
                        <div className="px-4 py-5 sm:p-6">
                            <h3 className="text-base font-semibold leading-6 text-gray-900">Recent Activity</h3>
                            <div className="mt-5">
                                <div className="text-center py-12">
                                    <svg
                                        className="mx-auto h-12 w-12 text-gray-400"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        stroke="currentColor"
                                        aria-hidden="true"
                                    >
                                        <path
                                            strokeLinecap="round"
                                            strokeLinejoin="round"
                                            strokeWidth={2}
                                            d="M9 13h6m-3-3v6m-9 1V7a2 2 0 012-2h6l2 2h6a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2z"
                                        />
                                    </svg>
                                    <h3 className="mt-2 text-sm font-semibold text-gray-900">No activity</h3>
                                    <p className="mt-1 text-sm text-gray-500">Get started by adding your first asset.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>

            <footer className="border-t border-gray-200 bg-white">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-4">
                    <p className="text-center text-sm text-gray-500">
                        {tenant?.name}
                    </p>
                </div>
            </footer>
        </div>
    )
}
