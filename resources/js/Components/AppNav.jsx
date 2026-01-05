import { useState } from 'react'
import { useForm, Link, router, usePage } from '@inertiajs/react'
import AppBrandLogo from './AppBrandLogo'

export default function AppNav({ brand, tenant }) {
    const { auth } = usePage().props
    const { post } = useForm()
    const [userMenuOpen, setUserMenuOpen] = useState(false)
    
    // Get current URL for active link detection
    const currentUrl = typeof window !== 'undefined' ? window.location.pathname : ''

    const handleLogout = () => {
        post('/app/logout')
    }

    const handleSwitchCompany = (companyId) => {
        router.post(`/app/companies/${companyId}/switch`, {}, {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                window.location.reload()
            },
        })
    }

    const handleSwitchBrand = (brandId) => {
        router.post(`/app/brands/${brandId}/switch`, {}, {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                window.location.reload()
            },
        })
    }

    const activeCompany = auth.companies?.find((c) => c.is_active)
    const isSiteOwner = auth.user?.id === 1 || auth.permissions?.includes('site admin') || auth.permissions?.includes('site owner')
    const brands = auth.brands || []
    // Always use auth.activeBrand - this is the currently active brand from the session
    const activeBrand = auth.activeBrand

    // Use default white background for nav (no brand color)
    const navColor = '#ffffff'
    const logoFilter = activeBrand?.logo_filter || 'none'
    
    // Use default dark text color
    const textColor = '#000000'
    const logoFilterStyle = logoFilter === 'white' 
        ? { filter: 'brightness(0) invert(1)' }
        : logoFilter === 'black'
        ? { filter: 'brightness(0)' }
        : {}

    // Check if we're on any /app page (full width nav for all app pages)
    const isAppPage = currentUrl.startsWith('/app')
    
    return (
        <nav className="shadow-sm relative" style={{ backgroundColor: navColor }}>
            <div className={isAppPage ? "px-4 sm:px-6 lg:px-8" : "mx-auto max-w-7xl px-4 sm:px-6 lg:px-8"}>
                <div className="flex h-20 justify-between">
                    <div className="flex flex-1 items-center">
                        {/* Brand Logo Component */}
                        <div className="flex flex-shrink-0 items-center">
                            {isAppPage ? (
                                <AppBrandLogo
                                    activeBrand={activeBrand}
                                    brands={brands}
                                    textColor={textColor}
                                    logoFilterStyle={logoFilterStyle}
                                    onSwitchBrand={handleSwitchBrand}
                                />
                            ) : (
                                <Link href="/" className="flex items-center">
                                    <h1 className="text-xl font-bold text-gray-900">
                                        Jackpot
                                    </h1>
                                </Link>
                            )}
                        </div>

                        {/* Navigation Links - Aligned with content area (after sidebar) */}
                        {isAppPage ? (
                            <div className="hidden sm:flex sm:space-x-8 absolute" style={{ left: '18rem' }}>
                                <Link
                                    href="/app/assets"
                                    className={`inline-flex items-center border-b-2 px-1 pt-1 text-sm font-medium ${
                                        currentUrl.startsWith('/app/assets') && !currentUrl.startsWith('/app/marketing-assets')
                                            ? 'border-primary'
                                            : 'border-transparent'
                                    }`}
                                    style={{
                                        color: currentUrl.startsWith('/app/assets') && !currentUrl.startsWith('/app/marketing-assets')
                                            ? textColor
                                            : textColor === '#ffffff' ? 'rgba(255, 255, 255, 0.7)' : 'rgba(0, 0, 0, 0.7)'
                                    }}
                                >
                                    Assets
                                </Link>
                                <Link
                                    href="/app/marketing-assets"
                                    className={`inline-flex items-center border-b-2 px-1 pt-1 text-sm font-medium ${
                                        currentUrl.startsWith('/app/marketing-assets')
                                            ? 'border-primary'
                                            : 'border-transparent'
                                    }`}
                                    style={{
                                        color: currentUrl.startsWith('/app/marketing-assets')
                                            ? textColor
                                            : textColor === '#ffffff' ? 'rgba(255, 255, 255, 0.7)' : 'rgba(0, 0, 0, 0.7)'
                                    }}
                                >
                                    Marketing Assets
                                </Link>
                            </div>
                        ) : (
                            <div className="hidden sm:flex sm:space-x-8 sm:ml-6">
                                <Link
                                    href="/app/assets"
                                    className={`inline-flex items-center border-b-2 px-1 pt-1 text-sm font-medium ${
                                        currentUrl.startsWith('/app/assets') && !currentUrl.startsWith('/app/marketing-assets')
                                            ? 'border-primary'
                                            : 'border-transparent'
                                    }`}
                                    style={{
                                        color: currentUrl.startsWith('/app/assets') && !currentUrl.startsWith('/app/marketing-assets')
                                            ? textColor
                                            : textColor === '#ffffff' ? 'rgba(255, 255, 255, 0.7)' : 'rgba(0, 0, 0, 0.7)'
                                    }}
                                >
                                    Assets
                                </Link>
                                <Link
                                    href="/app/marketing-assets"
                                    className={`inline-flex items-center border-b-2 px-1 pt-1 text-sm font-medium ${
                                        currentUrl.startsWith('/app/marketing-assets')
                                            ? 'border-primary'
                                            : 'border-transparent'
                                    }`}
                                    style={{
                                        color: currentUrl.startsWith('/app/marketing-assets')
                                            ? textColor
                                            : textColor === '#ffffff' ? 'rgba(255, 255, 255, 0.7)' : 'rgba(0, 0, 0, 0.7)'
                                    }}
                                >
                                    Marketing Assets
                                </Link>
                            </div>
                        )}
                    </div>
                    <div className="flex items-center gap-4">
                        {/* User Menu */}
                        <div className="relative ml-3">
                            <div>
                                <button
                                    type="button"
                                    className="flex max-w-xs items-center rounded-full bg-white text-sm focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2"
                                    onClick={() => setUserMenuOpen(!userMenuOpen)}
                                    aria-expanded="false"
                                    aria-haspopup="true"
                                >
                                    <span className="sr-only">Open user menu</span>
                                    <span className="text-sm font-medium hidden sm:block" style={{ color: textColor }}>
                                        {auth.user?.first_name && auth.user?.last_name
                                            ? `${auth.user.first_name} ${auth.user.last_name}`
                                            : auth.user?.first_name || auth.user?.email}
                                    </span>
                                    <svg className="ml-2 h-5 w-5" style={{ color: textColor === '#ffffff' ? 'rgba(255, 255, 255, 0.7)' : 'rgba(0, 0, 0, 0.7)' }} viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                        <path fillRule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clipRule="evenodd" />
                                    </svg>
                                                    <div className="ml-3 flex h-8 w-8 items-center justify-center rounded-full bg-primary text-sm font-medium text-white">
                                        {auth.user?.first_name?.charAt(0).toUpperCase() || auth.user?.email?.charAt(0).toUpperCase()}
                                    </div>
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
                                            <div className="flex items-center justify-between">
                                                <div className="flex-1 min-w-0">
                                                    <p className="text-sm font-semibold text-gray-900">
                                                        {auth.user?.first_name && auth.user?.last_name
                                                            ? `${auth.user.first_name} ${auth.user.last_name}`
                                                            : auth.user?.first_name || auth.user?.email}
                                                    </p>
                                                    <p className="mt-1 text-sm text-gray-500 truncate">{auth.user?.email}</p>
                                                    {activeCompany && (
                                                        <p className="mt-1 text-xs text-gray-500">
                                                            Active: <span className="font-medium">{activeCompany.name}</span>
                                                        </p>
                                                    )}
                                                </div>
                                                <div className="ml-3 flex-shrink-0">
                                                    <div className="flex h-10 w-10 items-center justify-center rounded-full bg-indigo-600 text-sm font-medium text-white">
                                                        {auth.user?.first_name?.charAt(0).toUpperCase() || auth.user?.email?.charAt(0).toUpperCase()}
                                                    </div>
                                                </div>
                                            </div>
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
                                                                    ? 'font-medium'
                                                                    : 'text-gray-700 hover:bg-gray-50'
                                                            }`}
                                                            style={company.is_active ? {
                                                                backgroundColor: `rgba(var(--primary-rgb, 99, 102, 241), 0.1)`,
                                                                color: 'var(--primary)'
                                                            } : {}}
                                                        >
                                                            <div className="flex items-center justify-between">
                                                                <span>{company.name}</span>
                                                                {company.is_active && (
                                                                    <svg className="h-4 w-4 text-primary" fill="currentColor" viewBox="0 0 20 20">
                                                                        <path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
                                                                    </svg>
                                                                )}
                                                            </div>
                                                        </button>
                                                    ))}
                                                </div>
                                            </div>
                                        )}

                                        {/* Company Section */}
                                        <div className="px-4 py-2 border-b border-gray-200">
                                            <p className="text-xs font-medium text-gray-500 uppercase tracking-wider mb-2">Company</p>
                                            <Link
                                                href="/app/companies"
                                                className="block px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 rounded-md"
                                                onClick={() => setUserMenuOpen(false)}
                                            >
                                                Company Management
                                            </Link>
                                            <Link
                                                href="/app/companies/settings"
                                                className="block px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 rounded-md"
                                                onClick={() => setUserMenuOpen(false)}
                                            >
                                                Company Settings
                                            </Link>
                                            <Link
                                                href="/app/billing"
                                                className="block px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 rounded-md"
                                                onClick={() => setUserMenuOpen(false)}
                                            >
                                                Billing & Plans
                                            </Link>
                                        </div>

                                        {/* Brand Section */}
                                        {activeBrand && (
                                            <div className="px-4 py-2 border-b border-gray-200">
                                                <p className="text-xs font-medium text-gray-500 uppercase tracking-wider mb-2">Brand</p>
                                                <p className="px-3 py-1 text-xs text-gray-500 mb-2">{activeBrand.name}</p>
                                                <Link
                                                    href={`/app/brands/${activeBrand.id}/edit`}
                                                    className="block px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 rounded-md"
                                                    onClick={() => setUserMenuOpen(false)}
                                                >
                                                    Brand Settings
                                                </Link>
                                                <Link
                                                    href={`/app/brands/${activeBrand.id}/edit#categories`}
                                                    className="block px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 rounded-md"
                                                    onClick={() => setUserMenuOpen(false)}
                                                >
                                                    Brand Categories
                                                </Link>
                                            </div>
                                        )}

                                        {/* Brands Section */}
                                        <div className="px-4 py-2 border-b border-gray-200">
                                            <p className="text-xs font-medium text-gray-500 uppercase tracking-wider mb-2">Brands</p>
                                            <Link
                                                href="/app/brands"
                                                className="block px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 rounded-md"
                                                onClick={() => setUserMenuOpen(false)}
                                            >
                                                Brand Management
                                            </Link>
                                            <Link
                                                href="/app/categories"
                                                className="block px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 rounded-md"
                                                onClick={() => setUserMenuOpen(false)}
                                            >
                                                Categories
                                            </Link>
                                        </div>

                                        {/* User Section */}
                                        <div className="px-4 py-2">
                                            <p className="text-xs font-medium text-gray-500 uppercase tracking-wider mb-2">User</p>
                                            <Link
                                                href="/app/profile"
                                                className="block px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 rounded-md"
                                                onClick={() => setUserMenuOpen(false)}
                                            >
                                                Your Profile
                                            </Link>
                                            {isSiteOwner && (
                                                <Link
                                                    href="/app/admin"
                                                    className="block px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 rounded-md border-t border-gray-200 mt-2 pt-2"
                                                    onClick={() => setUserMenuOpen(false)}
                                                >
                                                    Site Admin
                                                </Link>
                                            )}
                                            <button
                                                type="button"
                                                onClick={() => {
                                                    handleLogout()
                                                    setUserMenuOpen(false)
                                                }}
                                                className="block w-full text-left px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 rounded-md border-t border-gray-200 mt-2 pt-2"
                                            >
                                                Sign out
                                            </button>
                                        </div>
                                    </div>
                                </>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </nav>
    )
}
