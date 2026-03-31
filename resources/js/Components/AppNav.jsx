import { useState, useEffect } from 'react'
import { Link, router, usePage } from '@inertiajs/react'
import { DELIVERABLES_PAGE_LABEL } from '../utils/uiLabels'
import { showWorkspaceSwitchingOverlay } from '../utils/workspaceSwitchOverlay'
import AppBrandLogo from './AppBrandLogo'
import JackpotLogo from './JackpotLogo'
import AgencyStripBrandSelect from './agency/AgencyStripBrandSelect'
import WorkspaceSwitcher from './Workspace/WorkspaceSwitcher'
import GlobalUserControls from './Layout/GlobalUserControls'
import {
    ArrowDownTrayIcon,
    BookOpenIcon,
    ChevronRightIcon,
    FolderIcon,
    HomeIcon,
    PhotoIcon,
    RectangleGroupIcon,
    SparklesIcon,
    Squares2X2Icon,
} from '@heroicons/react/24/outline'

export default function AppNav({ brand, tenant, variant, hideWorkspaceAppNav = false, hideAgencyStrip = false }) {
    const page = usePage()
    const { auth, collection_only: collectionOnly, collection_only_collection: collectionOnlyCollection, collection_only_collections: collectionOnlyCollections = [] } = page.props
    const showBrandGuidelinesNav = auth?.permissions?.show_brand_guidelines_nav === true
    const [showPlanAlert, setShowPlanAlert] = useState(false)
    const [collectionsDropdownOpen, setCollectionsDropdownOpen] = useState(false)
    const [mobileNavOpen, setMobileNavOpen] = useState(false)
    const [navHovered, setNavHovered] = useState(false)
    
    // Get current URL for active link detection (use Inertia page.url so it's correct on first render and client nav)
    const currentUrl = (typeof window !== 'undefined' ? window.location.pathname : null) ?? (page.url ? new URL(page.url, 'http://localhost').pathname : '')

    const handleSwitchCompanyTo = (companyId, redirectPath = '/app/overview') => {
        showWorkspaceSwitchingOverlay('company')
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content
        const fd = new FormData()
        fd.append('_token', csrfToken)
        fd.append('redirect', redirectPath)
        fetch(`/app/companies/${companyId}/switch`, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        })
            .then(() => {
                window.location.href = redirectPath
            })
            .catch(() => {
                window.location.href = redirectPath
            })
    }

    const handleSwitchBrand = (brandId) => {
        showWorkspaceSwitchingOverlay('brand')
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content
        fetch(`/app/brands/${brandId}/switch`, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json',
            },
            credentials: 'same-origin',
        }).then(() => {
            const path = typeof window !== 'undefined' ? window.location.pathname : ''
            const brandUrlMatch = path.match(/^\/app\/brands\/(\d+)(\/.*)?$/)
            if (brandUrlMatch && brandUrlMatch[1] !== String(brandId)) {
                const newPath = `/app/brands/${brandId}${brandUrlMatch[2] || ''}`
                let search = typeof window !== 'undefined' ? window.location.search : ''
                if (newPath.includes('/brand-guidelines/builder') && search) {
                    const params = new URLSearchParams(search)
                    const step = params.get('step')
                    if (step === 'processing' || step === 'research-summary') {
                        params.delete('step')
                    }
                    search = params.toString() ? `?${params}` : ''
                }
                window.location.href = newPath + search
            } else {
                window.location.href = '/app/overview'
            }
        }).catch(() => {
            window.location.href = '/app/overview'
        })
    }

    const activeCompany = auth.companies?.find((c) => c.is_active)
    /** Direct tenant membership (not agency-provisioned). Agency client workspaces use the agency strip / brand switcher. */
    const directWorkspaceCompanies = Array.isArray(auth.companies)
        ? auth.companies.filter((c) => !c.is_agency_managed)
        : []
    const agencyFlatBrands = Array.isArray(auth.agency_flat_brands) ? auth.agency_flat_brands : []
    const agencyHomeCompany = auth.companies?.find((c) => c.is_agency === true) ?? null
    const showAgencyQuickLink = Boolean(agencyHomeCompany)

    const goAgencyDashboardFromMenu = () => {
        if (!agencyHomeCompany) {
            return
        }
        if (activeCompany?.id === agencyHomeCompany.id) {
            window.location.href = '/app/agency/dashboard'
        } else {
            handleSwitchCompanyTo(agencyHomeCompany.id, '/app/agency/dashboard')
        }
    }

    const effectivePermissions = Array.isArray(auth.effective_permissions) ? auth.effective_permissions : []
    const can = (p) => effectivePermissions.includes(p)
    const brands = collectionOnly ? [] : (auth.brands || [])
    // C12: In collection-only mode there is no active brand
    const activeBrand = collectionOnly ? null : auth.activeBrand

    // C12: Treat as collection-only for nav when backend says so OR when on collection-access URL (fallback if shared prop missing)
    const isOnCollectionAccessUrl = currentUrl.startsWith('/app') && /\/collection-access\/[^/]+(\/view)?$/.test(currentUrl.replace(/\?.*$/, ''))
    const isCollectionOnlyNav = Boolean(
        collectionOnly ||
        isOnCollectionAccessUrl ||
        collectionOnlyCollection ||
        (collectionOnlyCollections && collectionOnlyCollections.length > 0)
    )
    // When on collection-access URL, parse current collection id for Collections link (fallback when backend didn't send collection_only_collection)
    const collectionIdFromUrl = (() => {
        const m = currentUrl.match(/\/collection-access\/([^/]+)/)
        return m ? m[1] : null
    })()
    const effectiveCollection = collectionOnlyCollection || (collectionIdFromUrl ? { id: collectionIdFromUrl, name: 'Collection', slug: '' } : null)
    const effectiveCollectionsList = (collectionOnlyCollections && collectionOnlyCollections.length > 0)
        ? collectionOnlyCollections
        : (effectiveCollection ? [effectiveCollection] : [])
    
    // Admin/owner: have team.manage (backend effective_permissions) — plan limit banner
    const hasAdminOrOwnerRole = can('team.manage')

    const companyNm = activeCompany?.name?.trim() ?? ''
    const overviewNavTitle = companyNm ? `Overview (${companyNm})` : 'Overview'
    const brandGuidelinesNavTitle = companyNm ? `Brand Guidelines (${companyNm})` : 'Brand Guidelines'
    const companySettingsLabel = companyNm ? `Company overview (${companyNm})` : 'Company overview'
    const brandSettingsLabel = companyNm ? `Brand settings (${companyNm})` : 'Brand settings'

    /** Company default brand color (from shared auth) + active brand; drives workspace menu accent */
    const workspaceBrandColor = activeBrand?.primary_color || activeCompany?.primary_color || '#6366f1'
    const agencyBrandColor = agencyHomeCompany?.primary_color || '#4f46e5'
    const agencyMonogramChar = agencyHomeCompany
        ? (agencyHomeCompany.name || '').trim().charAt(0).toUpperCase() || '?'
        : '?'

    const currentWorkspace = activeCompany
        ? {
              id: activeCompany.id,
              name: activeCompany.name,
              type: activeCompany.is_agency ? 'agency_workspace' : 'company',
          }
        : page.props.currentWorkspace ?? null

    const isTransparentVariant = variant === 'transparent' && !navHovered
    const navColor = isTransparentVariant ? 'transparent' : '#ffffff'
    /** Same easing/duration as nav + agency strip so cinematic header surfaces stay in sync */
    const cinematicSurfaceTransition = 'background-color 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease, backdrop-filter 0.3s ease'
    const logoFilter = activeBrand?.logo_filter || 'none'
    const textColor = isTransparentVariant ? '#ffffff' : '#000000'
    const computeLogoFilterStyle = (filter, primaryColor) => {
        if (filter === 'white') return { filter: 'brightness(0) invert(1)' }
        if (filter === 'black') return { filter: 'brightness(0)' }
        if (filter === 'primary' && primaryColor) {
            const c = primaryColor.replace('#', '')
            const r = parseInt(c.substr(0, 2), 16) / 255
            const g = parseInt(c.substr(2, 2), 16) / 255
            const b = parseInt(c.substr(4, 2), 16) / 255
            const max = Math.max(r, g, b), min = Math.min(r, g, b)
            let h = 0
            if (max !== min) {
                const d = max - min
                if (max === r) h = (g - b) / d + (g < b ? 6 : 0)
                else if (max === g) h = (b - r) / d + 2
                else h = (r - g) / d + 4
                h *= 60
            }
            return { filter: `brightness(0) sepia(1) saturate(5) hue-rotate(${h - 30}deg)` }
        }
        return {}
    }
    const baseLogoFilterStyle = computeLogoFilterStyle(logoFilter, activeBrand?.primary_color)
    /** Cinematic (transparent) header: show primary logo as uploaded — no luminance auto-invert (brand supplies on-light mark). */
    const logoFilterStyle = { ...baseLogoFilterStyle, transition: 'filter 0.3s ease' }

    // Check if we're on any /app page (full width nav for all app pages)
    const isAppPage = currentUrl.startsWith('/app')
    /** Agency dashboard (etc.): hide brand workspace links — keep logo, agency strip, notifications, user menu */
    const suppressWorkspaceChrome = Boolean(hideWorkspaceAppNav && isAppPage && !isCollectionOnlyNav)
    /** Agency strip visible (brand quick-switch lives here); otherwise show workspace bar above main nav */
    const agencyStripVisible = Boolean(isAppPage && showAgencyQuickLink && agencyHomeCompany && !hideAgencyStrip)
    const showWorkspaceContextBar = isAppPage && !isCollectionOnlyNav && !agencyStripVisible
    // Check if we're in admin area - never show plan limit banner in admin
    const isAdminPage = currentUrl.startsWith('/app/admin')
    
    // Check for plan limit alerts
    const planLimitInfo = auth.brand_plan_limit_info
    const hasPlanLimitIssue = planLimitInfo && planLimitInfo.brand_limit_exceeded && isAppPage && !isAdminPage
    // User is in company but has no brand access (e.g. removed from all brands)
    const showNoBrandAccessAlert = Boolean(auth?.no_brand_access ?? (auth?.activeCompany && !collectionOnly && (!auth?.brands || auth.brands.length === 0)))
    
    // Track last shown brand ID to detect brand switches
    const [lastShownBrandId, setLastShownBrandId] = useState(() => {
        if (typeof window === 'undefined') return null
        return sessionStorage.getItem('plan_alert_last_brand_id')
    })
    
    // Show pop-up on initial page load and when switching brands (but never in admin area)
    useEffect(() => {
        // Never show in admin area
        if (isAdminPage) {
            setShowPlanAlert(false)
            return
        }
        
        if (hasPlanLimitIssue && typeof window !== 'undefined') {
            // Check if this is a full page reload vs Inertia navigation
            const navigation = window.performance?.getEntriesByType('navigation')[0]
            const navigationType = navigation?.type
            const isFullPageReload = navigationType === 'reload' || 
                                     navigationType === 'navigate' ||
                                     !window.history?.state?._inertia
            
            // Get current active brand ID
            const currentBrandId = activeBrand?.id?.toString()
            
            // Show alert if:
            // 1. It's a full page reload and we haven't shown it yet this load, OR
            // 2. Brand has changed (user switched brands)
            const brandChanged = currentBrandId && currentBrandId !== lastShownBrandId
            
            if (isFullPageReload) {
                const shownThisLoad = sessionStorage.getItem('plan_alert_shown_this_load')
                if (!shownThisLoad) {
                    setShowPlanAlert(true)
                    sessionStorage.setItem('plan_alert_shown_this_load', 'true')
                    if (currentBrandId) {
                        setLastShownBrandId(currentBrandId)
                        sessionStorage.setItem('plan_alert_last_brand_id', currentBrandId)
                    }
                }
            } else if (brandChanged && currentBrandId) {
                // Brand switch detected - show alert again
                setShowPlanAlert(true)
                setLastShownBrandId(currentBrandId)
                sessionStorage.setItem('plan_alert_last_brand_id', currentBrandId)
            }
        }
    }, [hasPlanLimitIssue, activeBrand?.id, lastShownBrandId, isAdminPage])
    
    // Clear the flag on page unload so it can show again on next reload
    useEffect(() => {
        const handleBeforeUnload = () => {
            if (typeof window !== 'undefined') {
                sessionStorage.removeItem('plan_alert_shown_this_load')
            }
        }
        
        window.addEventListener('beforeunload', handleBeforeUnload)
        return () => {
            window.removeEventListener('beforeunload', handleBeforeUnload)
            // Also clear on component unmount (which happens on reload)
            if (typeof window !== 'undefined') {
                const navigation = window.performance?.getEntriesByType('navigation')[0]
                if (navigation?.type === 'reload') {
                    sessionStorage.removeItem('plan_alert_shown_this_load')
                }
            }
        }
    }, [])
    
    const handleDismissPlanAlert = () => {
        setShowPlanAlert(false)
    }

    useEffect(() => {
        if (typeof document === 'undefined') {
            return
        }

        if (isAppPage && !isCollectionOnlyNav && !isAdminPage && !hideWorkspaceAppNav) {
            document.body.classList.add('has-mobile-tabbar')
        } else {
            document.body.classList.remove('has-mobile-tabbar')
        }

        return () => {
            document.body.classList.remove('has-mobile-tabbar')
        }
    }, [isAppPage, isCollectionOnlyNav, isAdminPage, hideWorkspaceAppNav])

    // Guides removed from bottom nav on mobile — shown as icon in header next to Downloads
    const mobileAppNavItems = [
        { href: '/app/overview', label: 'Overview', shortLabel: 'Overview', icon: HomeIcon, isActive: (url) => url === '/app/overview' || url.startsWith('/app/overview') },
        { href: '/app/assets', label: 'Assets', shortLabel: 'Assets', icon: PhotoIcon, isActive: (url) => url.startsWith('/app/assets') && !url.startsWith('/app/executions') },
        { href: '/app/executions', label: DELIVERABLES_PAGE_LABEL, shortLabel: 'Exec', icon: Squares2X2Icon, isActive: (url) => url.startsWith('/app/executions') },
        { href: '/app/generative', label: 'Generate', shortLabel: 'Gen', icon: SparklesIcon, isActive: (url) => url.startsWith('/app/generative') },
        { href: '/app/collections', label: 'Collections', shortLabel: 'Coll', icon: FolderIcon, isActive: (url) => url.startsWith('/app/collections') },
    ]

    /** When the Velvet Hammer agency strip is visible, park notifications + user menu there. */
    const relocateUserChromeToAgencyStrip = Boolean(isAppPage && agencyHomeCompany && !hideAgencyStrip)

    const globalUserControls = (
        <GlobalUserControls
            textColor={textColor}
            isTransparentVariant={isTransparentVariant}
            activeBrand={activeBrand}
            effectiveCollection={effectiveCollection}
            collectionOnly={collectionOnly}
            workspaceBrandColor={workspaceBrandColor}
            companySettingsLabel={companySettingsLabel}
            brandSettingsLabel={brandSettingsLabel}
        />
    )

    return (
        <div>
            {/* Plan Limit Alert Banner */}
            {showPlanAlert && planLimitInfo && (
                <div className="relative bg-white border-b border-yellow-200 shadow-sm">
                    {/* Yellow accent bar */}
                    <div className="absolute left-0 top-0 bottom-0 w-1 bg-yellow-500"></div>
                    <div className={isAppPage ? "px-4 sm:px-6 lg:px-8" : "mx-auto max-w-7xl px-4 sm:px-6 lg:px-8"}>
                        <div className="py-3 flex items-center justify-between relative">
                            <div className="flex items-center ml-4">
                                {/* Warning icon */}
                                <svg className="h-5 w-5 text-yellow-600 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                    <path fillRule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clipRule="evenodd" />
                                </svg>
                                <div className="text-sm">
                                    {hasAdminOrOwnerRole ? (
                                        <div className="text-gray-700">
                                            <span className="font-medium text-yellow-600">Plan Limit Exceeded:</span>{' '}
                                            You have <strong>{planLimitInfo.current_brand_count} brands</strong> but {tenant?.name ? `${tenant.name}'s` : 'your'} plan only allows <strong>{planLimitInfo.max_brands}</strong>.
                                            {planLimitInfo.disabled_brand_names && planLimitInfo.disabled_brand_names.length > 0 && (
                                                <span> <strong>{planLimitInfo.disabled_brand_names.join(', ')}</strong> {planLimitInfo.disabled_brand_names.length === 1 ? 'is' : 'are'} not accessible on {tenant?.name ? `${tenant.name}'s` : 'your'} current plan.</span>
                                            )}
                                            {' '}
                                            <Link href="/app/billing" className="font-medium text-yellow-600 underline hover:text-yellow-700" onClick={handleDismissPlanAlert}>
                                                Upgrade your plan
                                            </Link> to access all brands.
                                        </div>
                                    ) : (
                                        planLimitInfo.disabled_brand_names && planLimitInfo.disabled_brand_names.length > 0 && (
                                            <div className="text-gray-700">
                                                You've been added to <strong>{planLimitInfo.disabled_brand_names.join(', ')}</strong>, but {planLimitInfo.disabled_brand_names.length === 1 ? 'it is' : 'they are'} not accessible on {tenant?.name ? `${tenant.name}'s` : 'your'} current plan.
                                            </div>
                                        )
                                    )}
                                </div>
                            </div>
                            <div className="ml-4 flex items-center gap-2">
                                {hasAdminOrOwnerRole && (
                                    <Link
                                        href="/app/billing"
                                        onClick={handleDismissPlanAlert}
                                        className="inline-flex items-center rounded-md bg-yellow-500 px-3 py-1.5 text-sm font-semibold text-white shadow-sm hover:bg-yellow-400"
                                    >
                                        Upgrade Plan
                                    </Link>
                                )}
                                <button
                                    type="button"
                                    onClick={handleDismissPlanAlert}
                                    className="inline-flex rounded-md bg-white p-1.5 text-gray-400 hover:text-gray-500 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-yellow-500 focus:ring-offset-2 focus:ring-offset-white"
                                >
                                    <span className="sr-only">Dismiss</span>
                                    <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            )}

            {/* No brand access alert — user is in company but has no brand access */}
            {showNoBrandAccessAlert && (
                <div className="relative bg-amber-50 border-b border-amber-200 shadow-sm">
                    <div className="absolute left-0 top-0 bottom-0 w-1 bg-amber-500" />
                    <div className={isAppPage ? 'px-4 sm:px-6 lg:px-8' : 'mx-auto max-w-7xl px-4 sm:px-6 lg:px-8'}>
                        <div className="py-3 flex items-center">
                            <div className="flex items-center ml-4">
                                <svg className="h-5 w-5 text-amber-600 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                                    <path fillRule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clipRule="evenodd" />
                                </svg>
                                <p className="text-sm text-amber-800">
                                    <span className="font-medium">No brand access.</span> You're in the company but not assigned to any brands. Contact your company administrator to get access.{' '}
                                    <Link
                                        href="/app"
                                        className="font-medium underline hover:text-amber-900 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-1 rounded"
                                    >
                                        View brands
                                    </Link>
                                    {' '}to switch, or{' '}
                                    <button
                                        type="button"
                                        onClick={() => router.post(route('companies.reset-session'), {}, { preserveState: false })}
                                        className="font-medium underline hover:text-amber-900 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-1 rounded"
                                    >
                                        re-select company
                                    </button>
                                    {' '}to refresh.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            )}

            {/* Cinematic header group: shared hover + matched bg transition between agency strip and nav */}
            <div
                className={variant === 'transparent' ? 'relative z-[55] flex flex-col' : undefined}
                onMouseEnter={variant === 'transparent' ? () => setNavHovered(true) : undefined}
                onMouseLeave={variant === 'transparent' ? () => setNavHovered(false) : undefined}
            >
            {/* Workspace context row when no agency strip (direct company switcher) */}
            {showWorkspaceContextBar && (
                <div
                    className={`flex min-h-[48px] items-center border-b border-slate-200/90 ${
                        isTransparentVariant ? 'bg-black/25 text-white backdrop-blur-md' : 'bg-slate-50/95'
                    }`}
                    role="region"
                    aria-label="Workspace context"
                >
                    <div className="flex w-full items-center px-4 sm:px-6 lg:px-8">
                        <WorkspaceSwitcher
                            currentWorkspace={currentWorkspace}
                            availableWorkspaces={directWorkspaceCompanies}
                            accentColor={workspaceBrandColor}
                            variant="bar"
                            isTransparentVariant={isTransparentVariant}
                        />
                    </div>
                </div>
            )}

            {/* Agency strip — monogram + name + dashboard; agency primary color as left accent (light + cinematic) */}
            {isAppPage && showAgencyQuickLink && agencyHomeCompany && !hideAgencyStrip && (
                <div
                    className={`flex items-center transition-colors duration-300 ${
                        isTransparentVariant ? 'text-white/90' : 'text-slate-800'
                    }`}
                    style={{
                        borderLeftWidth: 3,
                        borderLeftStyle: 'solid',
                        borderLeftColor: agencyBrandColor,
                        ...(variant === 'transparent'
                            ? {
                                  backgroundColor: navHovered ? '#ffffff' : 'rgba(0, 0, 0, 0.35)',
                                  borderBottomWidth: 1,
                                  borderBottomStyle: 'solid',
                                  borderBottomColor: navHovered ? 'rgb(226 232 240)' : 'rgba(255, 255, 255, 0.08)',
                                  transition: cinematicSurfaceTransition,
                                  backdropFilter: navHovered ? 'none' : 'blur(12px)',
                                  WebkitBackdropFilter: navHovered ? 'none' : 'blur(12px)',
                              }
                            : {
                                  backgroundColor: '#ffffff',
                                  borderBottomWidth: 1,
                                  borderBottomStyle: 'solid',
                                  borderBottomColor: 'rgb(226 232 240)',
                                  transition: cinematicSurfaceTransition,
                              }),
                    }}
                    role="region"
                    aria-label={`Agency workspace, ${agencyHomeCompany.name}`}
                >
                    <div className="flex w-full min-w-0 items-center justify-between gap-3 px-4 py-2 sm:px-6 sm:py-2.5 lg:px-8">
                        <div className="flex min-w-0 flex-1 items-center gap-2.5 sm:gap-3">
                            <span
                                className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-sm font-semibold leading-none ring-1 ring-inset ${
                                    isTransparentVariant ? 'ring-white/15' : 'ring-black/[0.06]'
                                }`}
                                style={{
                                    backgroundColor: isTransparentVariant
                                        ? `color-mix(in srgb, ${agencyBrandColor} 38%, rgba(255,255,255,0.07))`
                                        : `color-mix(in srgb, ${agencyBrandColor} 22%, white)`,
                                    color: isTransparentVariant ? '#ffffff' : agencyBrandColor,
                                }}
                                aria-hidden
                            >
                                {agencyMonogramChar}
                            </span>
                            <div className="min-w-0 flex-1">
                                <p
                                    className={`hidden text-[10px] font-semibold uppercase tracking-wider sm:block ${
                                        isTransparentVariant ? 'text-white/45' : 'text-slate-500'
                                    }`}
                                >
                                    Agency workspace
                                </p>
                                <p
                                    className="truncate text-sm font-medium leading-tight sm:text-[15px]"
                                    title={agencyHomeCompany.name}
                                >
                                    {agencyHomeCompany.name}
                                </p>
                            </div>
                        </div>
                        <div
                            className={`flex min-w-0 shrink-0 items-center gap-2 ${
                                relocateUserChromeToAgencyStrip ? 'flex-wrap justify-end' : ''
                            }`}
                        >
                            <AgencyStripBrandSelect
                                brands={agencyFlatBrands}
                                brandColor={agencyBrandColor}
                                isTransparentVariant={isTransparentVariant}
                                currentTenantId={activeCompany?.id}
                                currentBrandId={auth.activeBrand?.id}
                            />
                            <button
                                type="button"
                                onClick={goAgencyDashboardFromMenu}
                                className={`inline-flex shrink-0 items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-semibold transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-1 sm:text-sm ${
                                    isTransparentVariant
                                        ? 'bg-white/10 text-white hover:bg-white/15 focus-visible:ring-white/40 focus-visible:ring-offset-transparent'
                                        : 'bg-white shadow-sm ring-1 ring-slate-200/80 hover:bg-slate-50 focus-visible:ring-indigo-500 focus-visible:ring-offset-white'
                                }`}
                                style={!isTransparentVariant ? { color: agencyBrandColor } : undefined}
                                title="Agency dashboard"
                            >
                                <span className="hidden sm:inline">Agency dashboard</span>
                                <span className="sm:hidden">Dashboard</span>
                                <ChevronRightIcon className="h-4 w-4 opacity-80" aria-hidden />
                            </button>
                            {relocateUserChromeToAgencyStrip && globalUserControls}
                        </div>
                    </div>
                </div>
            )}

            <nav
                className={`relative app-nav ${isCollectionOnlyNav ? 'is-collection-only' : ''} ${variant === 'transparent' && !navHovered ? '' : 'shadow-sm'}`}
                style={{
                    backgroundColor: navColor,
                    transition: cinematicSurfaceTransition,
                    ...(isCollectionOnlyNav ? { '--collection-only-user': '1' } : {}),
                }}
                data-collection-only={isCollectionOnlyNav ? 'true' : undefined}
                aria-label={isCollectionOnlyNav ? 'Collection-only access — some links disabled' : undefined}
            >
                <div className={isAppPage ? "px-4 sm:px-6 lg:px-8" : "mx-auto max-w-7xl px-4 sm:px-6 lg:px-8"}>
                <div className="flex h-20 justify-between">
                    <div className="flex flex-1 items-center">
                        {/* Mobile: hamburger to open main nav drawer (main nav links hidden below sm) */}
                        {isAppPage && isCollectionOnlyNav && (
                            <div className="flex flex-shrink-0 mr-2 sm:mr-0 sm:hidden">
                                <button
                                    type="button"
                                    onClick={() => setMobileNavOpen(true)}
                                    className="inline-flex items-center justify-center rounded-md p-2 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-primary"
                                    style={{ color: textColor === '#ffffff' ? 'rgba(255, 255, 255, 0.9)' : 'rgba(0, 0, 0, 0.85)' }}
                                    aria-label="Open menu"
                                >
                                    <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                                    </svg>
                                </button>
                            </div>
                        )}
                        {/* Brand Logo Component — max width keeps main nav from overlapping wide wordmarks */}
                        <div className="flex min-w-0 max-w-[200px] sm:max-w-[220px] md:max-w-[240px] shrink-0 items-center">
                            {isAppPage ? (isCollectionOnlyNav && effectiveCollection?.brand ? (
                                <AppBrandLogo
                                    activeBrand={effectiveCollection.brand}
                                    brands={effectiveCollectionsList.length > 1 ? [...new Map(effectiveCollectionsList.filter(c => c.brand).map(c => [c.brand.id, { ...c.brand, is_active: c.brand.id === effectiveCollection?.brand?.id }])).values()] : []}
                                    textColor={textColor}
                                    logoFilterStyle={computeLogoFilterStyle(effectiveCollection.brand?.logo_filter, effectiveCollection.brand?.primary_color)}
                                    onSwitchBrand={(brandId) => {
                                        const col = effectiveCollectionsList.find(c => c.brand?.id === brandId)
                                        if (col) router.post(route('collection-invite.switch', { collection: col.id }))
                                    }}
                                    rootLinkHref={route('collection-invite.landing', { collection: effectiveCollection.id })}
                                />
                            ) : isCollectionOnlyNav && effectiveCollection ? (
                                <span className="text-base font-semibold text-gray-900" title="Collection-only access">
                                    {effectiveCollection.name}
                                </span>
                            ) : (
                                <AppBrandLogo
                                    activeBrand={activeBrand}
                                    brands={brands}
                                    textColor={textColor}
                                    logoFilterStyle={logoFilterStyle}
                                    onSwitchBrand={handleSwitchBrand}
                                />
                            )) : (
                                <Link href="/" className="flex items-center">
                                    <JackpotLogo className="h-8 w-auto" />
                                </Link>
                            )}
                        </div>

                        {/* Main menu: Overview, Assets, Executions, Collections, Generative (C12: flag + .app-nav-main-links for CSS) */}
                        {isAppPage ? (isCollectionOnlyNav ? (
                            <div className="app-nav-main-links hidden min-w-0 flex-1 sm:flex sm:items-center sm:space-x-6 lg:space-x-8 sm:pl-4 lg:pl-6 overflow-x-auto" data-collection-only="true">
                                {['Overview', 'Assets', DELIVERABLES_PAGE_LABEL].map((label) => (
                                    <span
                                        key={label}
                                        className="nav-link-disabled inline-flex items-center border-b-2 border-transparent px-1 py-2 text-sm font-medium text-gray-400 opacity-50 cursor-not-allowed pointer-events-none select-none"
                                        title="Collection-only access — not available"
                                        aria-disabled="true"
                                    >
                                        {label}
                                    </span>
                                ))}
                                {collectionOnlyCollection?.id && collectionOnlyCollections?.length > 0 ? (
                                    collectionOnlyCollections.length > 1 ? (
                                        <div className="relative">
                                            <button
                                                type="button"
                                                onClick={() => setCollectionsDropdownOpen(open => !open)}
                                                onBlur={() => setTimeout(() => setCollectionsDropdownOpen(false), 150)}
                                                className="inline-flex items-center border-b-2 px-1 py-2 text-sm font-medium border-transparent text-gray-900 hover:text-gray-700 focus:outline-none focus:ring-0"
                                                style={{
                                                    borderBottomColor: currentUrl.includes('/collection-access/') ? '#4f46e5' : 'transparent',
                                                    color: currentUrl.includes('/collection-access/') ? '#111827' : 'rgba(0, 0, 0, 0.85)',
                                                }}
                                            >
                                                Collections
                                                <svg className="ml-1 h-4 w-4" fill="none" viewBox="0 0 24 24" strokeWidth="2" stroke="currentColor">
                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                                                </svg>
                                            </button>
                                            {collectionsDropdownOpen && (
                                                <div className="absolute left-0 top-full mt-1 w-56 rounded-md bg-white py-1 shadow-lg ring-1 ring-black ring-opacity-5 z-50">
                                                    {collectionOnlyCollections.map((c) => (
                                                        <button
                                                            key={c.id}
                                                            type="button"
                                                            onClick={() => {
                                                                setCollectionsDropdownOpen(false)
                                                                router.post(route('collection-invite.switch', { collection: c.id }))
                                                            }}
                                                            className={`block w-full text-left px-4 py-2 text-sm ${c.id === collectionOnlyCollection?.id ? 'bg-indigo-50 text-indigo-700 font-medium' : 'text-gray-700 hover:bg-gray-50'}`}
                                                        >
                                                            {c.name}
                                                            {c.id === collectionOnlyCollection?.id && ' (current)'}
                                                        </button>
                                                    ))}
                                                </div>
                                            )}
                                        </div>
                                    ) : (
                                        <Link
                                            href={route('collection-invite.landing', { collection: effectiveCollection.id })}
                                            className="inline-flex items-center border-b-2 px-1 py-2 text-sm font-medium border-transparent text-gray-900 hover:text-gray-700"
                                            style={{
                                                borderBottomColor: currentUrl.includes('/collection-access/') ? '#4f46e5' : 'transparent',
                                                color: currentUrl.includes('/collection-access/') ? '#111827' : 'rgba(0, 0, 0, 0.85)',
                                            }}
                                        >
                                            Collections
                                        </Link>
                                    )
                                ) : (
                                    <span
                                        className="nav-link-disabled inline-flex items-center border-b-2 border-transparent px-1 py-2 text-sm font-medium text-gray-400 opacity-50 cursor-not-allowed pointer-events-none select-none"
                                        title="Collection-only access"
                                        aria-disabled="true"
                                    >
                                        Collections
                                    </span>
                                )}
                                <span
                                    className="nav-link-disabled inline-flex items-center border-b-2 border-transparent px-1 py-2 text-sm font-medium text-gray-400 opacity-50 cursor-not-allowed pointer-events-none select-none"
                                    title="Collection-only access — not available"
                                    aria-disabled="true"
                                >
                                    Generative
                                </span>
                            </div>
                        ) : suppressWorkspaceChrome ? (
                            <div className="hidden min-w-0 flex-1 sm:block" aria-hidden="true" />
                        ) : (
                            <div className="app-nav-main-links hidden min-w-0 flex-1 sm:flex sm:items-center sm:space-x-6 lg:space-x-8 sm:pl-4 lg:pl-6 overflow-x-auto">
                                <Link
                                    href="/app/overview"
                                    title={overviewNavTitle}
                                    className="inline-flex items-center gap-1.5 border-b-2 px-1 py-2 text-sm font-medium border-transparent"
                                    style={{
                                        color: (currentUrl === '/app/overview' || currentUrl.startsWith('/app/overview'))
                                            ? textColor
                                            : textColor === '#ffffff' ? 'rgba(255, 255, 255, 0.7)' : 'rgba(0, 0, 0, 0.7)',
                                        borderBottomColor: (currentUrl === '/app/overview' || currentUrl.startsWith('/app/overview'))
                                            ? (activeBrand?.primary_color || '#6366f1')
                                            : 'transparent'
                                    }}
                                >
                                    <HomeIcon className="h-4 w-4 shrink-0" />
                                    Overview
                                </Link>
                                <Link
                                    href="/app/assets"
                                    className="inline-flex items-center gap-1.5 border-b-2 px-1 py-2 text-sm font-medium border-transparent"
                                    style={{
                                        color: currentUrl.startsWith('/app/assets') && !currentUrl.startsWith('/app/executions')
                                            ? textColor
                                            : textColor === '#ffffff' ? 'rgba(255, 255, 255, 0.7)' : 'rgba(0, 0, 0, 0.7)',
                                        borderBottomColor: currentUrl.startsWith('/app/assets') && !currentUrl.startsWith('/app/executions')
                                            ? (activeBrand?.primary_color || '#6366f1')
                                            : 'transparent'
                                    }}
                                >
                                    <PhotoIcon className="h-4 w-4 shrink-0" />
                                    Assets
                                </Link>
                                <Link
                                    href="/app/executions"
                                    className="inline-flex items-center gap-1.5 border-b-2 px-1 py-2 text-sm font-medium border-transparent"
                                    style={{
                                        color: currentUrl.startsWith('/app/executions')
                                            ? textColor
                                            : textColor === '#ffffff' ? 'rgba(255, 255, 255, 0.7)' : 'rgba(0, 0, 0, 0.7)',
                                        borderBottomColor: currentUrl.startsWith('/app/executions')
                                            ? (activeBrand?.primary_color || '#6366f1')
                                            : 'transparent'
                                    }}
                                >
                                    <Squares2X2Icon className="h-4 w-4 shrink-0" />
                                    {DELIVERABLES_PAGE_LABEL}
                                </Link>
                                <Link
                                    href="/app/collections"
                                    className="inline-flex items-center gap-1.5 border-b-2 px-1 py-2 text-sm font-medium border-transparent"
                                    style={{
                                        color: currentUrl.startsWith('/app/collections')
                                            ? textColor
                                            : textColor === '#ffffff' ? 'rgba(255, 255, 255, 0.7)' : 'rgba(0, 0, 0, 0.7)',
                                        borderBottomColor: currentUrl.startsWith('/app/collections')
                                            ? (activeBrand?.primary_color || '#6366f1')
                                            : 'transparent'
                                    }}
                                >
                                    <RectangleGroupIcon className="h-4 w-4 shrink-0" />
                                    Collections
                                </Link>
                                <Link
                                    href="/app/generative"
                                    className="inline-flex items-center gap-1.5 border-b-2 px-1 py-2 text-sm font-medium border-transparent"
                                    style={{
                                        color: currentUrl.startsWith('/app/generative')
                                            ? textColor
                                            : textColor === '#ffffff' ? 'rgba(255, 255, 255, 0.7)' : 'rgba(0, 0, 0, 0.7)',
                                        borderBottomColor: currentUrl.startsWith('/app/generative')
                                            ? (activeBrand?.primary_color || '#6366f1')
                                            : 'transparent'
                                    }}
                                >
                                    <SparklesIcon className="h-4 w-4 shrink-0" />
                                    Generative
                                </Link>
                            </div>
                        )) : (
                            <div className="app-nav-main-links hidden min-w-0 flex-1 sm:flex sm:items-center sm:space-x-6 lg:space-x-8 sm:ml-6 overflow-x-auto">
                                <Link
                                    href="/app/overview"
                                    title={overviewNavTitle}
                                    className="inline-flex items-center gap-1.5 border-b-2 px-1 py-2 text-sm font-medium border-transparent"
                                    style={{
                                        color: (currentUrl === '/app/overview' || currentUrl.startsWith('/app/overview'))
                                            ? textColor
                                            : textColor === '#ffffff' ? 'rgba(255, 255, 255, 0.7)' : 'rgba(0, 0, 0, 0.7)',
                                        borderBottomColor: (currentUrl === '/app/overview' || currentUrl.startsWith('/app/overview'))
                                            ? (activeBrand?.primary_color || '#6366f1')
                                            : 'transparent'
                                    }}
                                >
                                    <HomeIcon className="h-4 w-4 shrink-0" />
                                    Overview
                                </Link>
                                <Link
                                    href="/app/assets"
                                    className="inline-flex items-center gap-1.5 border-b-2 px-1 py-2 text-sm font-medium border-transparent"
                                    style={{
                                        color: currentUrl.startsWith('/app/assets') && !currentUrl.startsWith('/app/executions')
                                            ? textColor
                                            : textColor === '#ffffff' ? 'rgba(255, 255, 255, 0.7)' : 'rgba(0, 0, 0, 0.7)',
                                        borderBottomColor: currentUrl.startsWith('/app/assets') && !currentUrl.startsWith('/app/executions')
                                            ? (activeBrand?.primary_color || '#6366f1')
                                            : 'transparent'
                                    }}
                                >
                                    <PhotoIcon className="h-4 w-4 shrink-0" />
                                    Assets
                                </Link>
                                <Link
                                    href="/app/executions"
                                    className="inline-flex items-center gap-1.5 border-b-2 px-1 py-2 text-sm font-medium border-transparent"
                                    style={{
                                        color: currentUrl.startsWith('/app/executions')
                                            ? textColor
                                            : textColor === '#ffffff' ? 'rgba(255, 255, 255, 0.7)' : 'rgba(0, 0, 0, 0.7)',
                                        borderBottomColor: currentUrl.startsWith('/app/executions')
                                            ? (activeBrand?.primary_color || '#6366f1')
                                            : 'transparent'
                                    }}
                                >
                                    <Squares2X2Icon className="h-4 w-4 shrink-0" />
                                    {DELIVERABLES_PAGE_LABEL}
                                </Link>
                                <Link
                                    href="/app/collections"
                                    className="inline-flex items-center gap-1.5 border-b-2 px-1 py-2 text-sm font-medium border-transparent"
                                    style={{
                                        color: currentUrl.startsWith('/app/collections')
                                            ? textColor
                                            : textColor === '#ffffff' ? 'rgba(255, 255, 255, 0.7)' : 'rgba(0, 0, 0, 0.7)',
                                        borderBottomColor: currentUrl.startsWith('/app/collections')
                                            ? (activeBrand?.primary_color || '#6366f1')
                                            : 'transparent'
                                    }}
                                >
                                    <RectangleGroupIcon className="h-4 w-4 shrink-0" />
                                    Collections
                                </Link>
                                <Link
                                    href="/app/generative"
                                    className="inline-flex items-center gap-1.5 border-b-2 px-1 py-2 text-sm font-medium border-transparent"
                                    style={{
                                        color: currentUrl.startsWith('/app/generative')
                                            ? textColor
                                            : textColor === '#ffffff' ? 'rgba(255, 255, 255, 0.7)' : 'rgba(0, 0, 0, 0.7)',
                                        borderBottomColor: currentUrl.startsWith('/app/generative')
                                            ? (activeBrand?.primary_color || '#6366f1')
                                            : 'transparent'
                                    }}
                                >
                                    <SparklesIcon className="h-4 w-4 shrink-0" />
                                    Generative
                                </Link>
                            </div>
                        )}
                    </div>
                    <div className="flex items-center gap-2 lg:gap-4">
                        {/* C12: Collection access link on the right when in collection-only mode */}
                        {isCollectionOnlyNav && effectiveCollection?.id && (
                            <Link
                                href={route('collection-invite.landing', { collection: effectiveCollection.id })}
                                className="inline-flex items-center border-b-2 border-transparent px-1 py-2 text-sm font-medium text-gray-700 hover:text-gray-900"
                                style={{
                                    borderBottomColor: currentUrl.includes('/collection-access/') ? '#4f46e5' : 'transparent',
                                }}
                            >
                                Collection access
                            </Link>
                        )}
                        {/* Right-side nav: Brand Guidelines (only when published, or user can set up DNA), Downloads */}
                        {isAppPage && showBrandGuidelinesNav && !suppressWorkspaceChrome && (
                            <Link
                                href="/app/brand-guidelines"
                                title={brandGuidelinesNavTitle}
                                className={`hidden lg:inline-flex items-center gap-1.5 px-2 py-1.5 text-sm font-medium rounded-md border border-transparent ${isTransparentVariant ? 'hover:bg-white/10' : 'hover:bg-gray-100'} focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2`}
                                style={{
                                    color: textColor === '#ffffff' ? 'rgba(255, 255, 255, 0.9)' : 'rgba(0, 0, 0, 0.85)',
                                }}
                            >
                                <span>Brand Guidelines</span>
                            </Link>
                        )}
                        {isAppPage && !isCollectionOnlyNav && !suppressWorkspaceChrome && (
                            <>
                                <Link
                                    href="/app/downloads"
                                    className={`inline-flex items-center gap-1.5 px-2 py-1.5 text-sm font-medium rounded-md border border-transparent ${isTransparentVariant ? 'hover:bg-white/10' : 'hover:bg-gray-100'} focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2`}
                                    style={{
                                        color: currentUrl.startsWith('/app/downloads')
                                            ? (activeBrand?.primary_color || '#4f46e5')
                                            : (textColor === '#ffffff' ? 'rgba(255, 255, 255, 0.85)' : 'rgba(0, 0, 0, 0.75)'),
                                    }}
                                    aria-label="Downloads"
                                    title="Downloads"
                                >
                                    <ArrowDownTrayIcon className="h-5 w-5 flex-shrink-0" aria-hidden="true" />
                                    <span className="hidden lg:inline">Downloads</span>
                                </Link>
                                {showBrandGuidelinesNav && (
                                    <Link
                                        href="/app/brand-guidelines"
                                        className={`lg:hidden inline-flex items-center p-2 text-sm font-medium rounded-md border border-transparent ${isTransparentVariant ? 'hover:bg-white/10' : 'hover:bg-gray-100'} focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2`}
                                        style={{
                                            color: currentUrl.startsWith('/app/brand-guidelines') || currentUrl.includes('/guidelines')
                                                ? (activeBrand?.primary_color || '#4f46e5')
                                                : (textColor === '#ffffff' ? 'rgba(255, 255, 255, 0.85)' : 'rgba(0, 0, 0, 0.75)'),
                                        }}
                                        aria-label={brandGuidelinesNavTitle}
                                        title={brandGuidelinesNavTitle}
                                    >
                                        <BookOpenIcon className="h-5 w-5 flex-shrink-0" aria-hidden="true" />
                                    </Link>
                                )}
                            </>
                        )}
                        
                        
                        {!relocateUserChromeToAgencyStrip && globalUserControls}
                    </div>
                </div>
            </div>

            {/* Mobile main nav drawer: slide-in from left (below sm breakpoint) */}
            {isAppPage && isCollectionOnlyNav && mobileNavOpen && (
                <>
                    <div
                        className="fixed inset-0 z-40 bg-gray-900/50 backdrop-blur-sm sm:hidden"
                        aria-hidden="true"
                        onClick={() => setMobileNavOpen(false)}
                    />
                    <div
                        className="fixed inset-y-0 left-0 z-50 w-72 max-w-[85vw] bg-white shadow-xl sm:hidden flex flex-col transition-transform duration-300 ease-out"
                        role="dialog"
                        aria-modal="true"
                        aria-label="Main navigation"
                    >
                        <div className="flex items-center justify-between h-16 px-4 border-b border-gray-200">
                            <span className="text-sm font-semibold text-gray-900">Menu</span>
                            <button
                                type="button"
                                onClick={() => setMobileNavOpen(false)}
                                className="rounded-md p-2 text-gray-500 hover:bg-gray-100 hover:text-gray-700 focus:outline-none focus:ring-2 focus:ring-primary"
                                aria-label="Close menu"
                            >
                                <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>
                        <nav className="flex-1 overflow-y-auto py-4 px-3" aria-label="Primary">
                            <div className="space-y-0.5">
                                {[
                                    { href: '/app/overview', label: 'Overview' },
                                    { href: '/app/assets', label: 'Assets' },
                                    { href: '/app/executions', label: DELIVERABLES_PAGE_LABEL },
                                    { href: '/app/collections', label: 'Collections' },
                                    { href: '/app/generative', label: 'Generative' },
                                ].map(({ href, label }) => {
                                    const isActive = (href === '/app/overview') ? (currentUrl === '/app/overview' || currentUrl.startsWith('/app/overview')) : currentUrl.startsWith(href) && (href !== '/app/assets' || !currentUrl.startsWith('/app/executions'))
                                    return (
                                        <Link
                                            key={href}
                                            href={href}
                                            onClick={() => setMobileNavOpen(false)}
                                            className={`flex items-center px-3 py-3 rounded-lg text-sm font-medium transition-colors ${
                                                isActive
                                                    ? 'bg-indigo-50 text-indigo-700'
                                                    : 'text-gray-700 hover:bg-gray-50'
                                            }`}
                                        >
                                            {label}
                                        </Link>
                                    )
                                })}
                            </div>
                        </nav>
                    </div>
                </>
            )}

            {/* Mobile PWA bottom app navigation */}
            {isAppPage && !isCollectionOnlyNav && !isAdminPage && !hideWorkspaceAppNav && (() => {
                const isOnOverview = currentUrl === '/app/overview' || currentUrl.startsWith('/app/overview')
                const isDarkNav = isOnOverview
                return (
                    <div className={`fixed inset-x-0 bottom-0 z-[95] sm:hidden safe-area-pb ${
                        isDarkNav
                            ? 'border-t border-white/10 bg-[#0B0B0D]/90 backdrop-blur'
                            : 'border-t border-gray-200 bg-white/95 backdrop-blur'
                    }`}>
                        <nav className="grid grid-cols-5 gap-0 min-w-0 px-1 py-2.5" aria-label="App navigation">
                            {mobileAppNavItems.map(({ href, label, shortLabel, icon: Icon, isActive }) => {
                                const active = isActive(currentUrl)
                                return (
                                    <Link
                                        key={href}
                                        href={href}
                                        className={`flex flex-col items-center justify-center gap-1 min-w-0 flex-1 rounded-lg py-2 px-1 text-xs font-medium transition-colors ${
                                            isDarkNav
                                                ? (active ? 'text-white bg-white/15' : 'text-white/60 hover:bg-white/10 hover:text-white/80')
                                                : (active ? 'text-indigo-700 bg-indigo-50' : 'text-gray-500 hover:bg-gray-50')
                                        }`}
                                        aria-label={label}
                                        aria-current={active ? 'page' : undefined}
                                        style={active && !isDarkNav ? { color: activeBrand?.primary_color || '#4338ca' } : undefined}
                                    >
                                        <Icon className="h-5 w-5 flex-shrink-0" aria-hidden="true" />
                                        <span className="leading-none truncate w-full text-center">{shortLabel}</span>
                                    </Link>
                                )
                            })}
                        </nav>
                    </div>
                )
            })()}
            </nav>
            </div>
        </div>
    )
}
