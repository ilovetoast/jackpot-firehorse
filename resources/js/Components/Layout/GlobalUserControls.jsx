import { useState, useEffect } from 'react'
import { Link, useForm, usePage } from '@inertiajs/react'
import { BuildingOffice2Icon, Cog6ToothIcon, CreditCardIcon } from '@heroicons/react/24/outline'
import PermissionGate from '../PermissionGate'
import Avatar from '../Avatar'
import NotificationBell from '../NotificationBell'
import HelpLauncher from '../HelpLauncher'
import { switchCompanyWorkspace } from '../../utils/workspaceCompanySwitch'

/**
 * Shared user avatar, notifications, and account menu — rendered once in agency strip or main nav.
 */
export default function GlobalUserControls({
    textColor = '#000000',
    isTransparentVariant = false,
    activeBrand = null,
    effectiveCollection = null,
    collectionOnly = false,
    workspaceBrandColor = '#6366f1',
    brandSettingsLabel = 'Brand Settings',
}) {
    const page = usePage()
    const { auth } = page.props
    const { post } = useForm()
    const [userMenuOpen, setUserMenuOpen] = useState(false)
    const [companyDropdownOpen, setCompanyDropdownOpen] = useState(false)

    useEffect(() => {
        if (!userMenuOpen) setCompanyDropdownOpen(false)
    }, [userMenuOpen])

    const handleLogout = () => {
        post('/app/logout')
    }

    const effectivePermissions = Array.isArray(auth?.effective_permissions) ? auth.effective_permissions : []
    const can = (p) => effectivePermissions.includes(p)
    const siteRoles = Array.isArray(auth?.user?.site_roles) ? auth.user.site_roles : []
    const isSiteAdminOrOwner = siteRoles.includes('site_admin') || siteRoles.includes('site_owner')
    // Match /app/admin controllers: only user 1 or site_owner/site_admin (not tenant Spatie permissions).
    const showPlatformAdminDashboard = auth?.user?.id === 1 || isSiteAdminOrOwner

    const hasAdminOrOwnerRole = can('team.manage')

    const hasCompanies = auth?.companies && auth.companies.length > 0
    const hasCompanySettingsAccess = can('company_settings.view')
    const hasMultipleCompanies = auth?.companies && auth.companies.length > 1
    const hasAnyCompanyAccess = hasCompanies && (hasMultipleCompanies || hasCompanySettingsAccess)

    const activeCompany = auth?.companies?.find((c) => c.is_active)
    /** Pill in user menu: never label an incubated client tenant as generic “Company”. */
    const activeWorkspaceKindPill =
        activeCompany?.is_agency_managed === true
            ? 'Client'
            : activeCompany?.is_agency === true
              ? 'Agency'
              : 'Company'
    const directWorkspaceCompanies = Array.isArray(auth?.companies)
        ? auth.companies.filter((c) => !c.is_agency_managed)
        : []
    const hasMultipleDirectCompanyWorkspaces = directWorkspaceCompanies.length > 1
    const managedAgencyClients = Array.isArray(auth?.managed_agency_clients) ? auth.managed_agency_clients : []
    const managedClientIdSet = new Set(managedAgencyClients.map((c) => c.id))
    const onlyAgencyLinkedExtraWorkspaces = hasMultipleCompanies && directWorkspaceCompanies.length === 0
    const oneDirectAmongAgencyWorkspaces = hasMultipleCompanies && directWorkspaceCompanies.length === 1

    const handleSwitchCompany = (companyId) => {
        switchCompanyWorkspace({ companyId, redirect: '/app/overview' })
        setCompanyDropdownOpen(false)
        setUserMenuOpen(false)
    }

    const brands = collectionOnly ? [] : auth?.brands || []
    const hasBrands = brands && brands.length > 0
    const hasBrandSettingsAccess = can('brand_settings.manage')
    const hasMultipleBrands = brands && brands.length > 1
    const hasAnyBrandAccess =
        (hasBrands && (hasAdminOrOwnerRole || hasMultipleBrands || hasBrandSettingsAccess)) ||
        (activeBrand && hasBrandSettingsAccess)

    const hasBillingAccess = can('billing.view')
    const showWorkspaceCard =
        hasAnyCompanyAccess ||
        (activeBrand && hasAnyBrandAccess && !collectionOnly) ||
        hasBillingAccess

    return (
        <div className="flex items-center gap-2 lg:gap-4">
            <div className="hidden sm:flex sm:items-center sm:gap-0.5">
                <HelpLauncher textColor={textColor} />
                <NotificationBell textColor={textColor} />
            </div>
            <div className="relative">
                <button
                    type="button"
                    className={`flex max-w-xs items-center rounded-full text-sm focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2 ${isTransparentVariant ? 'bg-transparent' : 'bg-white'}`}
                    onClick={() => setUserMenuOpen(!userMenuOpen)}
                    aria-expanded={userMenuOpen}
                    aria-haspopup="true"
                >
                    <span className="sr-only">Open user menu</span>
                    <span className="text-sm font-medium hidden md:block" style={{ color: textColor }}>
                        {auth.user?.first_name && auth.user?.last_name
                            ? `${auth.user.first_name} ${auth.user.last_name}`
                            : auth.user?.first_name || auth.user?.email}
                    </span>
                    <svg
                        className="hidden md:block ml-2 h-5 w-5"
                        style={{ color: textColor === '#ffffff' ? 'rgba(255, 255, 255, 0.7)' : 'rgba(0, 0, 0, 0.7)' }}
                        viewBox="0 0 20 20"
                        fill="currentColor"
                        aria-hidden="true"
                    >
                        <path
                            fillRule="evenodd"
                            d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z"
                            clipRule="evenodd"
                        />
                    </svg>
                    <div className="ml-0 md:ml-3">
                        <Avatar
                            avatarUrl={auth.user?.avatar_url}
                            firstName={auth.user?.first_name}
                            lastName={auth.user?.last_name}
                            email={auth.user?.email}
                            size="h-8 w-8 text-xs"
                            primaryColor={
                                !auth.user?.avatar_url
                                    ? activeBrand?.primary_color || effectiveCollection?.brand?.primary_color || '#6366f1'
                                    : undefined
                            }
                        />
                    </div>
                </button>

                {userMenuOpen && (
                    <>
                        <div className="fixed inset-0 z-[60]" onClick={() => setUserMenuOpen(false)} />
                        <div className="absolute right-0 z-[61] mt-2 w-[min(20rem,calc(100vw-1.5rem))] origin-top-right rounded-md bg-white py-1 shadow-lg ring-1 ring-black ring-opacity-5 focus:outline-none">
                            <div className="px-4 py-2 border-b border-gray-200">
                                <p className="text-xs font-medium text-gray-500 uppercase tracking-wider mb-1">Account</p>
                                {showWorkspaceCard ? (
                                    <Link
                                        href="/app/profile"
                                        className="group flex items-center gap-3 rounded-lg px-2 py-2 text-left transition-colors hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
                                        onClick={() => setUserMenuOpen(false)}
                                        aria-label="Edit profile"
                                        title="Edit profile"
                                    >
                                        <Avatar
                                            avatarUrl={auth.user?.avatar_url}
                                            firstName={auth.user?.first_name}
                                            lastName={auth.user?.last_name}
                                            email={auth.user?.email}
                                            size="sm"
                                        />
                                        <div className="flex-1 min-w-0">
                                            <p className="text-xs font-medium text-gray-900 truncate">
                                                {auth.user?.first_name && auth.user?.last_name
                                                    ? `${auth.user.first_name} ${auth.user.last_name}`
                                                    : auth.user?.first_name || auth.user?.email}
                                            </p>
                                            <p className="text-xs text-gray-500 truncate">{auth.user?.email}</p>
                                        </div>
                                        <Cog6ToothIcon
                                            className="h-4 w-4 shrink-0 text-gray-400 transition-colors group-hover:text-gray-600"
                                            aria-hidden
                                        />
                                    </Link>
                                ) : (
                                    <Link
                                        href="/app/profile"
                                        className="group flex items-center gap-3 rounded-lg px-2 py-2 text-left transition-colors hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
                                        onClick={() => setUserMenuOpen(false)}
                                        aria-label="Profile"
                                        title="Profile"
                                    >
                                        <Avatar
                                            avatarUrl={auth.user?.avatar_url}
                                            firstName={auth.user?.first_name}
                                            lastName={auth.user?.last_name}
                                            email={auth.user?.email}
                                            size="sm"
                                        />
                                        <div className="flex-1 min-w-0">
                                            <p className="text-xs font-medium text-gray-900 truncate">
                                                {auth.user?.first_name && auth.user?.last_name
                                                    ? `${auth.user.first_name} ${auth.user.last_name}`
                                                    : auth.user?.first_name || auth.user?.email}
                                            </p>
                                            <p className="text-xs text-gray-500 truncate">{auth.user?.email}</p>
                                        </div>
                                        <Cog6ToothIcon
                                            className="h-4 w-4 shrink-0 text-gray-400 transition-colors group-hover:text-gray-600"
                                            aria-hidden
                                        />
                                    </Link>
                                )}
                            </div>

                            {showWorkspaceCard && (
                                <div className="px-4 py-2 border-b border-gray-200">
                                    <div
                                        className="overflow-hidden rounded-xl border border-gray-200/95 bg-white shadow-sm"
                                        style={{
                                            borderLeftWidth: 4,
                                            borderLeftStyle: 'solid',
                                            borderLeftColor: workspaceBrandColor,
                                        }}
                                    >
                                        {hasMultipleCompanies && (
                                            <>
                                                <div className="px-3 pt-3 pb-2">
                                                    <p className="text-xs font-medium uppercase tracking-wider text-gray-500">
                                                        Active workspace
                                                    </p>
                                                    {hasMultipleDirectCompanyWorkspaces && (
                                                        <p className="mt-0.5 text-[10px] leading-snug text-gray-500">
                                                            Switch company below.
                                                        </p>
                                                    )}
                                                </div>

                                                {activeCompany && (
                                                    <div className="border-t border-gray-100">
                                                        {hasMultipleDirectCompanyWorkspaces ? (
                                                            <button
                                                                type="button"
                                                                onClick={() => setCompanyDropdownOpen(!companyDropdownOpen)}
                                                                className="flex w-full min-h-[44px] items-center gap-2 border-0 bg-gray-50/80 px-3 py-2.5 text-left transition hover:bg-gray-100/90 focus:outline-none focus-visible:bg-gray-100 focus-visible:ring-2 focus-visible:ring-inset"
                                                                style={{ '--tw-ring-color': `${workspaceBrandColor}99` }}
                                                                aria-expanded={companyDropdownOpen}
                                                                aria-haspopup="listbox"
                                                                aria-label={`Switch company, current workspace ${activeCompany.name}`}
                                                                title="Choose which company you are working in"
                                                            >
                                                                <svg className="h-4 w-4 flex-shrink-0 text-gray-500" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor" aria-hidden>
                                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3.75h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008z" />
                                                                </svg>
                                                                <span className="min-w-0 flex-1 truncate text-sm font-semibold text-gray-900" title={activeCompany.name}>
                                                                    {activeCompany.name}
                                                                </span>
                                                                <span className="shrink-0 rounded border border-gray-200 bg-white px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-gray-500">
                                                                    {activeWorkspaceKindPill}
                                                                </span>
                                                                <svg className={`h-4 w-4 flex-shrink-0 text-gray-500 transition-transform ${companyDropdownOpen ? 'rotate-180' : ''}`} fill="none" viewBox="0 0 24 24" strokeWidth="2" stroke="currentColor" aria-hidden>
                                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                                                                </svg>
                                                            </button>
                                                        ) : (
                                                            <div
                                                                className="flex min-h-[44px] items-center gap-2 bg-gray-50/80 px-3 py-2.5"
                                                                title={activeCompany.name}
                                                            >
                                                                <svg className="h-4 w-4 flex-shrink-0 text-gray-500" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor" aria-hidden>
                                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3.75h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008z" />
                                                                </svg>
                                                                <div className="min-w-0 flex-1">
                                                                    <p className="truncate text-sm font-semibold text-gray-900">{activeCompany.name}</p>
                                                                    <p className="text-[10px] text-gray-500">
                                                                        {onlyAgencyLinkedExtraWorkspaces
                                                                            ? 'Client workspaces: use the agency workspace bar above.'
                                                                            : oneDirectAmongAgencyWorkspaces
                                                                              ? 'Other client workspaces: use the agency bar above.'
                                                                              : 'Your only workspace right now'}
                                                                    </p>
                                                                </div>
                                                            </div>
                                                        )}
                                                        {hasMultipleDirectCompanyWorkspaces && companyDropdownOpen && (
                                                            <div className="max-h-[280px] overflow-y-auto border-t border-gray-200 bg-white py-1">
                                                                {directWorkspaceCompanies.map((company) => (
                                                                    <button
                                                                        key={company.id}
                                                                        type="button"
                                                                        onClick={() => handleSwitchCompany(company.id)}
                                                                        className={`flex w-full items-center px-3 py-2 text-left text-sm transition-colors ${
                                                                            company.is_active
                                                                                ? 'bg-primary text-white'
                                                                                : 'text-gray-700 hover:bg-gray-50'
                                                                        }`}
                                                                        title={company.name}
                                                                    >
                                                                        <svg className={`h-4 w-4 mr-2 flex-shrink-0 ${company.is_active ? 'text-white' : 'text-gray-400'}`} fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                                                            <path strokeLinecap="round" strokeLinejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3.75h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008z" />
                                                                        </svg>
                                                                        <span className="flex-1 font-medium truncate">{company.name}</span>
                                                                        {activeCompany?.is_agency && managedClientIdSet.has(company.id) && (
                                                                            <span className="ml-1 shrink-0 rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide bg-indigo-100 text-indigo-800">
                                                                                Client
                                                                            </span>
                                                                        )}
                                                                        {company.is_active && (
                                                                            <svg className="h-4 w-4 text-white flex-shrink-0 ml-1" fill="currentColor" viewBox="0 0 20 20">
                                                                                <path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
                                                                            </svg>
                                                                        )}
                                                                    </button>
                                                                ))}
                                                            </div>
                                                        )}
                                                    </div>
                                                )}
                                            </>
                                        )}

                                        <div className="border-t border-gray-100 bg-gray-50/60">
                                            <p className="px-3 pt-2.5 pb-1 text-[10px] font-semibold uppercase tracking-wide text-gray-500">
                                                This workspace
                                            </p>
                                            <div className="px-1 pb-2">
                                                <PermissionGate permission="company_settings.view">
                                                    <Link
                                                        href={
                                                            typeof route === 'function'
                                                                ? route('companies.settings')
                                                                : '/app/companies/settings'
                                                        }
                                                        className="flex min-w-0 items-center rounded-md px-2 py-1.5 text-sm text-gray-800 hover:bg-white/80"
                                                        onClick={() => setUserMenuOpen(false)}
                                                        title="Account Settings"
                                                    >
                                                        <BuildingOffice2Icon className="mr-2 h-4 w-4 flex-shrink-0 text-gray-400" aria-hidden />
                                                        <span className="min-w-0 flex-1 truncate font-medium">Account Settings</span>
                                                    </Link>
                                                </PermissionGate>
                                                {activeBrand && hasAnyBrandAccess && !collectionOnly && (
                                                    <PermissionGate permission="brand_settings.manage">
                                                        <Link
                                                            href={
                                                                activeBrand
                                                                    ? typeof route === 'function'
                                                                        ? route('brands.edit', { brand: activeBrand.id })
                                                                        : `/app/brands/${activeBrand.id}/edit`
                                                                    : '/app'
                                                            }
                                                            className="flex min-w-0 items-center rounded-md px-2 py-1.5 text-sm text-gray-800 hover:bg-white/80"
                                                            onClick={() => setUserMenuOpen(false)}
                                                            title={brandSettingsLabel}
                                                        >
                                                            <svg
                                                                className="mr-2 h-4 w-4 flex-shrink-0 text-gray-400"
                                                                fill="none"
                                                                viewBox="0 0 24 24"
                                                                strokeWidth="1.5"
                                                                stroke="currentColor"
                                                            >
                                                                <path
                                                                    strokeLinecap="round"
                                                                    strokeLinejoin="round"
                                                                    d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.281z"
                                                                />
                                                                <path
                                                                    strokeLinecap="round"
                                                                    strokeLinejoin="round"
                                                                    d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"
                                                                />
                                                            </svg>
                                                            <span className="min-w-0 flex-1 truncate font-medium">{brandSettingsLabel}</span>
                                                        </Link>
                                                    </PermissionGate>
                                                )}
                                                <PermissionGate permission="billing.view">
                                                    <Link
                                                        href={typeof route === 'function' ? route('billing') : '/app/billing'}
                                                        className="flex min-w-0 items-center rounded-md px-2 py-1.5 text-sm text-gray-800 hover:bg-white/80"
                                                        onClick={() => setUserMenuOpen(false)}
                                                        title="Billing"
                                                    >
                                                        <CreditCardIcon className="mr-2 h-4 w-4 flex-shrink-0 text-gray-400" aria-hidden />
                                                        <span className="min-w-0 flex-1 truncate font-medium">Billing</span>
                                                    </Link>
                                                </PermissionGate>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            )}

                            <div className="px-4 py-2 border-b border-gray-200">
                                <Link
                                    href="/app/support/tickets"
                                    className="flex items-center px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50 rounded-md"
                                    onClick={() => setUserMenuOpen(false)}
                                >
                                    <svg
                                        className="h-4 w-4 mr-2 text-gray-400"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        strokeWidth="1.5"
                                        stroke="currentColor"
                                    >
                                        <path
                                            strokeLinecap="round"
                                            strokeLinejoin="round"
                                            d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9 5.25h.008v.008H12v-.008z"
                                        />
                                    </svg>
                                    Support
                                </Link>
                            </div>

                            {showPlatformAdminDashboard && (
                                <div className="px-4 py-2 border-b border-gray-200">
                                    <p className="text-xs font-medium text-gray-500 uppercase tracking-wider mb-1">Admin</p>
                                    <Link
                                        href="/app/admin"
                                        className="flex items-center px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50 rounded-md"
                                        onClick={() => setUserMenuOpen(false)}
                                    >
                                        <svg
                                            className="h-4 w-4 mr-2 text-gray-400"
                                            fill="none"
                                            viewBox="0 0 24 24"
                                            strokeWidth="1.5"
                                            stroke="currentColor"
                                        >
                                            <path
                                                strokeLinecap="round"
                                                strokeLinejoin="round"
                                                d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"
                                            />
                                        </svg>
                                        Admin Dashboard
                                    </Link>
                                </div>
                            )}

                            {Array.isArray(auth.user?.site_roles) && auth.user.site_roles.includes('site_support') && (
                                <div className="px-4 py-2 border-b border-gray-200">
                                    <p className="text-xs font-medium text-gray-500 uppercase tracking-wider mb-1">Support</p>
                                    <Link
                                        href="/app/admin/support/tickets"
                                        className="flex items-center px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50 rounded-md"
                                        onClick={() => setUserMenuOpen(false)}
                                    >
                                        <svg
                                            className="h-4 w-4 mr-2 text-gray-400"
                                            fill="none"
                                            viewBox="0 0 24 24"
                                            strokeWidth="1.5"
                                            stroke="currentColor"
                                        >
                                            <path
                                                strokeLinecap="round"
                                                strokeLinejoin="round"
                                                d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
                                            />
                                        </svg>
                                        Support Tickets
                                    </Link>
                                </div>
                            )}

                            <div className="px-4 py-2">
                                <button
                                    type="button"
                                    onClick={() => {
                                        handleLogout()
                                        setUserMenuOpen(false)
                                    }}
                                    className="flex items-center w-full text-left px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50 rounded-md"
                                >
                                    <svg
                                        className="h-4 w-4 mr-2 text-gray-400"
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
                                    Log out
                                </button>
                            </div>
                        </div>
                    </>
                )}
            </div>
        </div>
    )
}
