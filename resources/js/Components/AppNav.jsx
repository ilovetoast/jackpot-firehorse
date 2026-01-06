import { useState } from 'react'
import { useForm, Link, router, usePage } from '@inertiajs/react'
import AppBrandLogo from './AppBrandLogo'
import PermissionGate from './PermissionGate'

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
    
    // Check if user has admin or owner role (not member) for company/brand settings access
    // Use tenant_role from auth (tenant-scoped) instead of filtering roles
    const tenantRole = auth.tenant_role || null
    const hasAdminOrOwnerRole = tenantRole && ['admin', 'owner'].includes(tenantRole.toLowerCase())
    const hasBrandManagerRole = tenantRole && tenantRole.toLowerCase() === 'brand_manager'
    const hasManageUsersPermission = auth.permissions?.includes('manage users') || false
    const hasManageBrandsPermission = auth.permissions?.includes('manage brands') || false
    // Team management and activity logs require admin/owner OR manage users permission OR manage brands permission
    const canAccessTeamManagement = hasAdminOrOwnerRole || hasManageUsersPermission || hasManageBrandsPermission

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
                                        {/* Account Section */}
                                        <div className="px-4 py-2 border-b border-gray-200">
                                            <p className="text-xs font-medium text-gray-500 uppercase tracking-wider mb-1">Account</p>
                                            <div className="px-3 py-1">
                                                <p className="text-xs text-gray-900 truncate">
                                                    {auth.user?.first_name && auth.user?.last_name
                                                        ? `${auth.user.first_name} ${auth.user.last_name}`
                                                        : auth.user?.first_name || auth.user?.email}
                                                </p>
                                                <p className="text-xs text-gray-500 truncate">{auth.user?.email}</p>
                                            </div>
                                            <Link
                                                href="/app/profile"
                                                className="flex items-center px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50 rounded-md"
                                                onClick={() => setUserMenuOpen(false)}
                                            >
                                                <svg className="h-4 w-4 mr-2 text-gray-400" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />
                                                </svg>
                                                Profile
                                            </Link>
                                        </div>

                                        {/* Company Section */}
                                        <div className="px-4 py-2 border-b border-gray-200">
                                            <p className="text-xs font-medium text-gray-500 uppercase tracking-wider mb-1">Company</p>
                                            
                                            {/* Switch Company - only show if more than one */}
                                            {auth.companies && auth.companies.length > 1 && (
                                                <div className="mb-1 rounded-md bg-gray-50 p-1.5 border-l-2 border-primary">
                                                    <p className="text-xs font-medium text-gray-700 px-1 py-0.5 mb-1">Switch Company</p>
                                                    <div className="space-y-0.5">
                                                        {auth.companies.map((company) => (
                                                            <button
                                                                key={company.id}
                                                                type="button"
                                                                onClick={() => {
                                                                    handleSwitchCompany(company.id)
                                                                    setUserMenuOpen(false)
                                                                }}
                                                                className={`flex items-center w-full text-left px-2 py-1 text-xs rounded-md transition-colors ${
                                                                    company.is_active
                                                                        ? 'bg-primary text-white'
                                                                        : 'text-gray-700 hover:bg-gray-100'
                                                                }`}
                                                                title={company.name}
                                                            >
                                                                <svg className={`h-3.5 w-3.5 mr-1.5 flex-shrink-0 ${company.is_active ? 'text-white' : 'text-gray-400'}`} fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3.75h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008z" />
                                                                </svg>
                                                                <span className="flex-1 font-medium truncate" title={company.name}>{company.name}</span>
                                                                {company.is_active && (
                                                                    <svg className="h-3.5 w-3.5 text-white flex-shrink-0 ml-1" fill="currentColor" viewBox="0 0 20 20">
                                                                        <path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
                                                                    </svg>
                                                                )}
                                                            </button>
                                                        ))}
                                                    </div>
                                                </div>
                                            )}
                                            

                                            <PermissionGate permission="company_settings.view">
                                                <Link
                                                    href="/app/companies/settings"
                                                    className="flex items-center px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50 rounded-md"
                                                    onClick={() => setUserMenuOpen(false)}
                                                >
                                                    <svg className="h-4 w-4 mr-2 text-gray-400" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                                        <path strokeLinecap="round" strokeLinejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.281z" />
                                                        <path strokeLinecap="round" strokeLinejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                    </svg>
                                                    Company Settings
                                                </Link>
                                            </PermissionGate>
                                            <PermissionGate permission="team.manage">
                                                <Link
                                                    href="/app/companies/team"
                                                    className="flex items-center px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50 rounded-md"
                                                    onClick={() => setUserMenuOpen(false)}
                                                >
                                                    <svg className="h-4 w-4 mr-2 text-gray-400" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                                        <path strokeLinecap="round" strokeLinejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
                                                    </svg>
                                                    Team Management
                                                </Link>
                                            </PermissionGate>
                                            {canAccessTeamManagement && (
                                                <Link
                                                    href="/app/companies/activity"
                                                    className="flex items-center px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50 rounded-md"
                                                    onClick={() => setUserMenuOpen(false)}
                                                >
                                                    <svg className="h-4 w-4 mr-2 text-gray-400" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                                        <path strokeLinecap="round" strokeLinejoin="round" d="M3 13l8 0M3 6l8 0M3 20l8 0M13 13l8 0M13 6l8 0M13 20l8 0" />
                                                    </svg>
                                                    Activity Logs
                                                </Link>
                                            )}
                                        </div>

                                        {/* Brands Section */}
                                        {activeBrand && (
                                            <div className="px-4 py-2 border-b border-gray-200">
                                                <p className="text-xs font-medium text-gray-500 uppercase tracking-wider mb-1">Brands</p>
                                                <PermissionGate permission="brand_settings.manage">
                                                    <Link
                                                        href="/app/brands"
                                                        className="flex items-center px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50 rounded-md"
                                                        onClick={() => setUserMenuOpen(false)}
                                                    >
                                                        <svg className="h-4 w-4 mr-2 text-gray-400" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                                            <path strokeLinecap="round" strokeLinejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.281z" />
                                                            <path strokeLinecap="round" strokeLinejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                        </svg>
                                                        Brand Settings
                                                    </Link>
                                                </PermissionGate>
                                            </div>
                                        )}

                                        {/* Support */}
                                        <div className="px-4 py-2 border-b border-gray-200">
                                            <Link
                                                href="#"
                                                className="flex items-center px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50 rounded-md"
                                                onClick={(e) => {
                                                    e.preventDefault()
                                                    setUserMenuOpen(false)
                                                }}
                                            >
                                                <svg className="h-4 w-4 mr-2 text-gray-400" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9 5.25h.008v.008H12v-.008z" />
                                                </svg>
                                                Support
                                            </Link>
                                        </div>

                                        {/* Admin Section */}
                                        {isSiteOwner && (
                                            <div className="px-4 py-2 border-b border-gray-200">
                                                <p className="text-xs font-medium text-gray-500 uppercase tracking-wider mb-1">Admin</p>
                                                <Link
                                                    href="/app/admin"
                                                    className="flex items-center px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50 rounded-md"
                                                    onClick={() => setUserMenuOpen(false)}
                                                >
                                                    <svg className="h-4 w-4 mr-2 text-gray-400" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                                        <path strokeLinecap="round" strokeLinejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" />
                                                    </svg>
                                                    Admin Dashboard
                                                </Link>
                                            </div>
                                        )}

                                        {/* Log out */}
                                        <div className="px-4 py-2">
                                            <button
                                                type="button"
                                                onClick={() => {
                                                    handleLogout()
                                                    setUserMenuOpen(false)
                                                }}
                                                className="flex items-center w-full text-left px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50 rounded-md"
                                            >
                                                <svg className="h-4 w-4 mr-2 text-gray-400" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75" />
                                                </svg>
                                                Log out
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
