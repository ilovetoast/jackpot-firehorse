import { useState, useEffect, useRef } from 'react'
import { Link, router, usePage } from '@inertiajs/react'
import { DELIVERABLES_PAGE_LABEL } from '../utils/uiLabels'
import { resolveOverviewIconColor } from '../utils/colorUtils'
import { showWorkspaceSwitchingOverlay } from '../utils/workspaceSwitchOverlay'
import AppBrandLogo from './AppBrandLogo'
import JackpotLogo from './JackpotLogo'
import AgencyStripBrandSelect from './agency/AgencyStripBrandSelect'
import GlobalUserControls from './Layout/GlobalUserControls'
import {
    AdjustmentsHorizontalIcon,
    ArrowDownTrayIcon,
    ArrowTrendingUpIcon,
    BookOpenIcon,
    ChartBarIcon,
    ChevronDownIcon,
    ChevronRightIcon,
    ClipboardDocumentListIcon,
    Cog6ToothIcon,
    FolderIcon,
    HomeIcon,
    PhotoIcon,
    RectangleGroupIcon,
    SparklesIcon,
    Squares2X2Icon,
    UserGroupIcon,
} from '@heroicons/react/24/outline'

/** Build `rgba(...)` from `#RRGGBB` for translucent brand accents (e.g. Overview → Creators row). */
function rgbaFromHex(hex, alpha) {
    const m = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(String(hex || '').trim())
    if (!m) {
        return `rgba(99, 102, 241, ${alpha})`
    }
    return `rgba(${parseInt(m[1], 16)}, ${parseInt(m[2], 16)}, ${parseInt(m[3], 16)}, ${alpha})`
}

/**
 * ---------------------------------------------------------------------------
 * AGENCY NAV (TOP STRIP) — LOCKING PRODUCT CONTRACT — READ BEFORE CHANGING
 * ---------------------------------------------------------------------------
 * The thin row ABOVE the main white nav is the “agency nav” (agency workspace strip).
 *
 * LOCK — WHO MAY EVER SEE IT:
 *   - ONLY users who are part of an agency workflow. Concretely: `auth.companies` must include
 *     at least one tenant with `is_agency === true` (`agencyHomeCompany`). If that row does not
 *     exist, the agency nav MUST NOT render. No exceptions for “just add a switcher”.
 *
 * LOCK — WHEN IT IS VISIBLE (session context):
 *   - Active workspace is the agency tenant itself (`activeCompany.is_agency === true`), OR
 *   - Active workspace is a client company that is tied to an agency via provisioning
 *     (`activeCompany.is_agency_managed === true`). Viewing that client company IS agency context;
 *     the user is operating inside the agency’s client portfolio, not a standalone “basic company”.
 *
 * LOCK — WHO MUST NEVER SEE IT:
 *   - Pure / basic company users: active tenant is neither `is_agency` nor `is_agency_managed`.
 *     They get the main nav only. Do not add a second top row for them (no WorkspaceSwitcher bar,
 *     no agency strip).
 *   - Collection-only / external collection / guest chrome (`isExternalCollectionChrome`).
 *   - When `hideAgencyStrip` is true (call site override — must remain respected).
 *
 * LOCK — DO NOT “HELP” BY LOOSENING CONDITIONS:
 *   - Widening `agencyStripVisible` to show for all `/app` pages or for every multi-company user
 *     will regress basic-company UX and duplicate chrome. If unsure, default to hidden.
 *
 * Backend alignment: `is_agency` on tenants and `is_agency_managed` on the active company in
 * shared Inertia `auth.companies` are authoritative; this file only reflects them.
 * ---------------------------------------------------------------------------
 */

export default function AppNav({
    brand,
    tenant,
    variant,
    hideWorkspaceAppNav = false,
    /** LOCK (agency nav): When true, the agency top strip is suppressed — keep for dashboards that must not show agency chrome. */
    hideAgencyStrip = false,
}) {
    const page = usePage()
    const {
        auth,
        collection_only: collectionOnly,
        collection_only_collection: collectionOnlyCollection,
        collection_only_collections: collectionOnlyCollections = [],
    } = page.props
    const showBrandGuidelinesNav = auth?.permissions?.show_brand_guidelines_nav === true
    const [showPlanAlert, setShowPlanAlert] = useState(false)
    const [collectionsDropdownOpen, setCollectionsDropdownOpen] = useState(false)
    const [mobileNavOpen, setMobileNavOpen] = useState(false)
    const [navHovered, setNavHovered] = useState(false)
    const [overviewNavHover, setOverviewNavHover] = useState(false)
    const overviewNavCloseTimerRef = useRef(null)
    
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

    // --- LOCK: Agency nav data prerequisites (see file-level AGENCY NAV contract) ---
    // LOCK: `agency_flat_brands` is ONLY for the agency strip brand picker; backend must not send it for non-agency users.
    const agencyFlatBrands = Array.isArray(auth.agency_flat_brands) ? auth.agency_flat_brands : []
    // LOCK: `agencyHomeCompany` === user has an agency tenant in their workspace list. No row => no agency nav, ever.
    const agencyHomeCompany = auth.companies?.find((c) => c.is_agency === true) ?? null
    // LOCK: Synonym for “this user is agency-capable” for quick links; still gated by `agencyStripVisible` for actual strip.
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
    const canViewWorkspaceInsights = Boolean(auth?.permissions?.can_view_workspace_insights)
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
    /** Backend: collection grants but no brand membership (nav + asset view may omit `collection_only` container). */
    const isCollectionGuestExperience = Boolean(auth?.is_collection_guest_experience)
    const isExternalCollectionChrome = Boolean(isCollectionOnlyNav || isCollectionGuestExperience)
    // When on collection-access URL, parse current collection id for Collections link (fallback when backend didn't send collection_only_collection)
    const collectionIdFromUrl = (() => {
        const m = currentUrl.match(/\/collection-access\/([^/]+)/)
        return m ? m[1] : null
    })()
    const effectiveCollection = collectionOnlyCollection || (collectionIdFromUrl ? { id: collectionIdFromUrl, name: 'Collection', slug: '' } : null)
    const effectiveCollectionsList = (collectionOnlyCollections && collectionOnlyCollections.length > 0)
        ? collectionOnlyCollections
        : (effectiveCollection ? [effectiveCollection] : [])
    /** Brand for logo/accent when URL fallback omitted `brand` (match row from collection_only_collections). */
    const collectionBrandForLogo =
        effectiveCollection?.brand
        ?? (effectiveCollection?.id && Array.isArray(collectionOnlyCollections) && collectionOnlyCollections.length > 0
            ? collectionOnlyCollections.find((c) => String(c.id) === String(effectiveCollection.id))?.brand
            : null)
        ?? (collectionOnlyCollections?.length === 1 ? collectionOnlyCollections[0].brand : null)
    const collectionNavAccent = collectionBrandForLogo?.primary_color || '#6366f1'
    /** Main nav tab always reads "Collections" like internal users (not the current collection name). */
    const collectionMainNavTabLabel = 'Collections'

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

    /** Cinematic app shell (Overview, etc.): keep dark translucent chrome on hover — avoid flipping to solid white. */
    const isCinematicNav = variant === 'transparent'

    useEffect(() => {
        const root = document.documentElement
        if (!isCinematicNav) {
            root.removeAttribute('data-cinematic-nav')
            return undefined
        }
        root.setAttribute('data-cinematic-nav', 'true')
        return () => {
            root.removeAttribute('data-cinematic-nav')
        }
    }, [isCinematicNav])

    /** Same easing/duration as nav + agency strip so cinematic header surfaces stay in sync */
    const cinematicSurfaceTransition = 'background-color 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease, backdrop-filter 0.3s ease'
    const cinematicNavSurfaceStyle = isCinematicNav
        ? {
              backgroundColor: navHovered ? 'rgba(11, 11, 13, 0.88)' : 'rgba(0, 0, 0, 0.22)',
              backdropFilter: 'blur(14px)',
              WebkitBackdropFilter: 'blur(14px)',
              borderBottomWidth: 1,
              borderBottomStyle: 'solid',
              borderBottomColor: navHovered ? 'rgba(255, 255, 255, 0.12)' : 'rgba(255, 255, 255, 0.06)',
              transition: cinematicSurfaceTransition,
          }
        : null
    const navColor = isCinematicNav ? undefined : '#ffffff'
    const logoFilter = activeBrand?.logo_filter || 'none'
    const textColor = isCinematicNav ? '#ffffff' : '#000000'
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
    /** No filter transition: animating filter on every nav remount reads as logo flicker. */
    const logoFilterStyle = baseLogoFilterStyle

    // Check if we're on any /app page (full width nav for all app pages)
    const isAppPage = currentUrl.startsWith('/app')
    /** Agency dashboard (etc.): hide brand workspace links — keep logo, agency strip, notifications, user menu */
    const suppressWorkspaceChrome = Boolean(hideWorkspaceAppNav && isAppPage && !isExternalCollectionChrome)

    // --- LOCK: Agency nav — active workspace classification (see file-level contract) ---
    // LOCK: User is literally inside the agency tenant workspace (not a client).
    const activeWorkspaceIsAgency = activeCompany?.is_agency === true
    // LOCK: User is inside a client company that is associated with / provisioned by an agency (`brand_user`/tenant pivot).
    //       This is STILL agency context: show agency nav so they never think they are a standalone basic company.
    const activeWorkspaceIsAgencyManagedClient = activeCompany?.is_agency_managed === true

    /**
     * LOCK — `agencyStripVisible` (agency nav render gate): ALL of the following are mandatory.
     *
     *   1. `isAppPage` — agency nav is app shell only.
     *   2. `showAgencyQuickLink` / `agencyHomeCompany` — user MUST be agency-capable (tenant with is_agency in auth.companies).
     *      If you remove this, basic-company-only users could see agency UI when props leak — forbidden.
     *   3. `!hideAgencyStrip` — honor parent override (e.g. agency dashboard layouts).
     *   4. `!isExternalCollectionChrome` — guests / collection-only never see agency chrome.
     *   5. `(activeWorkspaceIsAgency || activeWorkspaceIsAgencyManagedClient)` — active tenant must be agency OR
     *      agency-managed client. Basic direct company (`!is_agency && !is_agency_managed`) => MUST be false.
     *
     * LOCK: Do not OR in extra cases (e.g. “has multiple companies”) without product sign-off — that broke basic UX before.
     */
    const agencyStripVisible = Boolean(
        isAppPage &&
        showAgencyQuickLink &&
        agencyHomeCompany &&
        !hideAgencyStrip &&
        !isExternalCollectionChrome &&
        (activeWorkspaceIsAgency || activeWorkspaceIsAgencyManagedClient)
    )
    // Check if we're in admin area - never show plan limit banner in admin
    const isAdminPage = currentUrl.startsWith('/app/admin')
    
    // Check for plan limit alerts
    const planLimitInfo = auth.brand_plan_limit_info
    const hasPlanLimitIssue = planLimitInfo && planLimitInfo.brand_limit_exceeded && isAppPage && !isAdminPage
    // User is in company but has no brand access (e.g. removed from all brands)
    const showNoBrandAccessAlert = Boolean(
        !isExternalCollectionChrome &&
            (auth?.no_brand_access ??
                (auth?.activeCompany && !collectionOnly && (!auth?.brands || auth.brands.length === 0)))
    )
    
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

        if (isAppPage && !isExternalCollectionChrome && !isAdminPage && !hideWorkspaceAppNav) {
            document.body.classList.add('has-mobile-tabbar')
        } else {
            document.body.classList.remove('has-mobile-tabbar')
        }

        return () => {
            document.body.classList.remove('has-mobile-tabbar')
        }
    }, [isAppPage, isExternalCollectionChrome, isAdminPage, hideWorkspaceAppNav])

    useEffect(
        () => () => {
            if (overviewNavCloseTimerRef.current) {
                clearTimeout(overviewNavCloseTimerRef.current)
            }
        },
        []
    )

    const clearOverviewNavCloseTimer = () => {
        if (overviewNavCloseTimerRef.current) {
            clearTimeout(overviewNavCloseTimerRef.current)
            overviewNavCloseTimerRef.current = null
        }
    }

    const openOverviewSubmenu = () => {
        clearOverviewNavCloseTimer()
        if (activeBrand) {
            setOverviewNavHover(true)
        }
    }

    const scheduleCloseOverviewSubmenu = () => {
        clearOverviewNavCloseTimer()
        overviewNavCloseTimerRef.current = setTimeout(() => {
            setOverviewNavHover(false)
            overviewNavCloseTimerRef.current = null
        }, 160)
    }

    /** Desktop workspace nav: Overview + hover panel (Tasks, Creator Home, Creators, Insights, Manage, Settings). */
    const renderDesktopOverviewNav = () => {
        const creatorHomePathActive = currentUrl.startsWith('/app/overview/creator-progress')
        const tasksPathActive =
            !creatorHomePathActive &&
            (currentUrl === '/app/overview' || currentUrl.startsWith('/app/overview?'))
        const insightsPathActive = currentUrl.startsWith('/app/insights')
        const managePathActive = currentUrl.startsWith('/app/manage')
        const creatorsPathActive =
            Boolean(activeBrand?.id) && currentUrl.startsWith(`/app/brands/${activeBrand.id}/creators`)
        const brandSettingsPathActive =
            Boolean(activeBrand?.id) &&
            currentUrl.startsWith(`/app/brands/${activeBrand.id}`) &&
            !creatorsPathActive
        const overviewGroupActive =
            tasksPathActive ||
            creatorHomePathActive ||
            insightsPathActive ||
            managePathActive ||
            brandSettingsPathActive ||
            creatorsPathActive

        const inactiveNavColor = textColor === '#ffffff' ? 'rgba(255, 255, 255, 0.7)' : 'rgba(0, 0, 0, 0.7)'
        const accent = activeBrand?.primary_color || '#6366f1'
        /** Same treatment as Overview primary-action cards — brand tint that reads on dark surfaces. */
        const creatorsNavIconColor =
            variant === 'transparent'
                ? resolveOverviewIconColor(accent, { surface: '#0f1115' })
                : accent
        const linkStyle = {
            color: overviewGroupActive ? textColor : inactiveNavColor,
            borderBottomColor: overviewGroupActive ? accent : 'transparent',
        }
        const overviewLinkClass =
            'inline-flex items-center gap-1.5 border-b-2 px-1 py-2 text-sm font-medium border-transparent transition-colors duration-200 ease-out focus:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2'

        if (!activeBrand) {
            return (
                <Link href="/app/overview" title={overviewNavTitle} className={overviewLinkClass} style={linkStyle}>
                    <HomeIcon className="h-4 w-4 shrink-0" aria-hidden="true" />
                    Overview
                </Link>
            )
        }

        const showBrandSettings = can('brand_settings.manage')
        const showInsightsNav = canViewWorkspaceInsights
        const showManageNav =
            can('metadata.registry.view') || can('metadata.tenant.visibility.manage')
        const canViewCreatorsDashboard = Boolean(auth?.permissions?.can_view_creators_dashboard)
        const showCreatorsNav = Boolean(activeBrand?.id) && canViewCreatorsDashboard
        const showCreatorHomeNav = Boolean(auth?.permissions?.show_creator_home_nav)
        const creatorHomeAttentionCount = Math.max(0, Number(auth?.creator_home_attention_count) || 0)
        const creatorHomeHref =
            typeof route === 'function' ? route('overview.creator-progress') : '/app/overview/creator-progress'

        const subLinkBase =
            'block border-l-[3px] px-3 py-2 text-sm font-medium leading-snug transition-[border-color,background-color,color] duration-150 ease-out'
        const subLinkInactive =
            variant === 'transparent'
                ? 'border-transparent text-white/70 hover:bg-white/[0.08] hover:text-white hover:[border-left-color:var(--overview-dd-accent)]'
                : 'border-transparent text-gray-600 hover:bg-slate-50 hover:text-gray-900 hover:[border-left-color:var(--overview-dd-accent)]'
        const subLinkActive =
            variant === 'transparent' ? 'bg-white/[0.1] text-white' : 'bg-slate-50 text-gray-900'

        return (
            <div
                className="relative shrink-0"
                onMouseEnter={openOverviewSubmenu}
                onMouseLeave={scheduleCloseOverviewSubmenu}
            >
                <Link
                    href="/app/overview"
                    className={overviewLinkClass}
                    style={linkStyle}
                    aria-haspopup="true"
                    aria-expanded={overviewNavHover}
                >
                    <HomeIcon className="h-4 w-4 shrink-0" aria-hidden="true" />
                    Overview
                    <ChevronDownIcon
                        className={`h-3.5 w-3.5 shrink-0 opacity-50 motion-safe:transition-transform duration-200 ease-out ${
                            overviewNavHover ? '-rotate-180' : ''
                        }`}
                        aria-hidden="true"
                    />
                </Link>
                <div
                    className={`absolute left-0 top-full z-[100] min-w-[13.5rem] pt-1.5 transition-all duration-200 ease-out motion-reduce:transition-none ${
                        overviewNavHover
                            ? 'pointer-events-auto translate-y-0 opacity-100'
                            : 'pointer-events-none translate-y-1 opacity-0'
                    }`}
                >
                    <div
                        className={
                            variant === 'transparent'
                                ? 'rounded-md bg-[#0f1115]/95 py-0.5 text-sm shadow-xl ring-1 ring-white/10 backdrop-blur-xl'
                                : 'rounded-md bg-white py-0.5 text-sm shadow-sm ring-1 ring-slate-200/90 backdrop-blur-sm dark:bg-gray-900 dark:ring-white/10'
                        }
                        style={{ '--overview-dd-accent': accent }}
                    >
                        <Link
                            href="/app/overview"
                            className={`${subLinkBase} ${tasksPathActive ? subLinkActive : subLinkInactive}`}
                            style={tasksPathActive ? { borderLeftColor: accent } : undefined}
                        >
                            <span className="inline-flex items-center gap-2">
                                <ClipboardDocumentListIcon className="h-4 w-4 shrink-0 opacity-70" aria-hidden="true" />
                                Tasks
                            </span>
                        </Link>
                        {showCreatorHomeNav ? (
                            <Link
                                href={creatorHomeHref}
                                className={`${subLinkBase} ${creatorHomePathActive ? subLinkActive : subLinkInactive}`}
                                style={creatorHomePathActive ? { borderLeftColor: accent } : undefined}
                            >
                                <span className="flex w-full min-w-0 items-center justify-between gap-2">
                                    <span className="inline-flex min-w-0 items-center gap-2">
                                        <ArrowTrendingUpIcon
                                            className="h-4 w-4 shrink-0 opacity-70"
                                            aria-hidden="true"
                                        />
                                        <span className="truncate">Creator Home</span>
                                    </span>
                                    {creatorHomeAttentionCount > 0 ? (
                                        <span
                                            className={
                                                variant === 'transparent'
                                                    ? 'inline-flex min-w-[1.25rem] shrink-0 items-center justify-center rounded-full bg-rose-500/90 px-1.5 py-0.5 text-[10px] font-bold leading-none text-white'
                                                    : 'inline-flex min-w-[1.25rem] shrink-0 items-center justify-center rounded-full bg-rose-600 px-1.5 py-0.5 text-[10px] font-bold leading-none text-white'
                                            }
                                            title="Assets need your attention (e.g. re-upload)"
                                        >
                                            {creatorHomeAttentionCount > 99 ? '99+' : creatorHomeAttentionCount}
                                        </span>
                                    ) : null}
                                </span>
                            </Link>
                        ) : null}
                        {showCreatorsNav ? (
                            <Link
                                href={route('brands.creators', { brand: activeBrand.id })}
                                className={`${subLinkBase} ${creatorsPathActive ? subLinkActive : subLinkInactive}`}
                                style={creatorsPathActive ? { borderLeftColor: accent } : undefined}
                            >
                                <span className="inline-flex min-w-0 items-center gap-2.5">
                                    <span
                                        className="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg"
                                        style={{
                                            backgroundColor: rgbaFromHex(
                                                accent,
                                                variant === 'transparent' ? 0.38 : 0.14
                                            ),
                                            boxShadow: `inset 0 0 0 1px ${rgbaFromHex(accent, variant === 'transparent' ? 0.55 : 0.22)}`,
                                        }}
                                    >
                                        <UserGroupIcon
                                            className="h-4 w-4 shrink-0"
                                            style={{ color: creatorsNavIconColor }}
                                            aria-hidden="true"
                                        />
                                    </span>
                                    <span
                                        className={`truncate font-semibold tracking-tight ${
                                            variant === 'transparent' ? 'text-white' : ''
                                        }`}
                                        style={variant === 'transparent' ? undefined : { color: accent }}
                                    >
                                        Creators
                                    </span>
                                </span>
                            </Link>
                        ) : null}
                        {showInsightsNav ? (
                            <Link
                                href={route('insights.overview')}
                                className={`${subLinkBase} ${insightsPathActive ? subLinkActive : subLinkInactive}`}
                                style={insightsPathActive ? { borderLeftColor: accent } : undefined}
                            >
                                <span className="inline-flex items-center gap-2">
                                    <ChartBarIcon className="h-4 w-4 shrink-0 opacity-70" aria-hidden="true" />
                                    Insights
                                </span>
                            </Link>
                        ) : null}
                        {showManageNav ? (
                            <Link
                                href={typeof route === 'function' ? route('manage.categories') : '/app/manage/categories'}
                                className={`${subLinkBase} ${managePathActive ? subLinkActive : subLinkInactive}`}
                                style={managePathActive ? { borderLeftColor: accent } : undefined}
                            >
                                <span className="inline-flex items-center gap-2">
                                    <AdjustmentsHorizontalIcon
                                        className="h-4 w-4 shrink-0 opacity-70"
                                        aria-hidden="true"
                                    />
                                    Manage
                                </span>
                            </Link>
                        ) : null}
                        {(showCreatorsNav || showInsightsNav || showManageNav) && showBrandSettings ? (
                            <div
                                role="separator"
                                className={
                                    variant === 'transparent'
                                        ? 'mx-2 my-1 border-t border-white/10'
                                        : 'mx-2 my-1 border-t border-slate-200/90 dark:border-white/10'
                                }
                            />
                        ) : null}
                        {showBrandSettings ? (
                            <Link
                                href={route('brands.edit', { brand: activeBrand.id })}
                                title={brandSettingsLabel}
                                className={`${subLinkBase} ${brandSettingsPathActive ? subLinkActive : subLinkInactive}`}
                                style={brandSettingsPathActive ? { borderLeftColor: accent } : undefined}
                            >
                                <span className="inline-flex items-center gap-2">
                                    <Cog6ToothIcon className="h-4 w-4 shrink-0 opacity-70" aria-hidden="true" />
                                    Settings
                                </span>
                            </Link>
                        ) : null}
                    </div>
                </div>
            </div>
        )
    }

    // Guides removed from bottom nav on mobile — shown as icon in header next to Downloads
    const mobileAppNavItems = [
        {
            href: '/app/overview',
            label: 'Overview',
            shortLabel: 'Overview',
            icon: HomeIcon,
            isActive: (url) => {
                if (url === '/app/overview' || url.startsWith('/app/overview')) return true
                if (url.startsWith('/app/insights')) return true
                if (url.startsWith('/app/manage')) return true
                if (activeBrand?.id && url.startsWith(`/app/brands/${activeBrand.id}`)) return true
                return false
            },
        },
        { href: '/app/assets', label: 'Assets', shortLabel: 'Assets', icon: PhotoIcon, isActive: (url) => url.startsWith('/app/assets') && !url.startsWith('/app/executions') },
        { href: '/app/executions', label: DELIVERABLES_PAGE_LABEL, shortLabel: 'Exec', icon: Squares2X2Icon, isActive: (url) => url.startsWith('/app/executions') },
        { href: '/app/generative', label: 'Generate', shortLabel: 'Gen', icon: SparklesIcon, isActive: (url) => url.startsWith('/app/generative') },
        { href: '/app/collections', label: 'Collections', shortLabel: 'Coll', icon: FolderIcon, isActive: (url) => url.startsWith('/app/collections') },
    ]

    /**
     * LOCK (agency nav): Relocate notifications + avatar menu into the agency strip ONLY when `agencyStripVisible`.
     * Never tie this to unrelated layout flags — otherwise basic-company users get duplicate or misplaced chrome.
     */
    const relocateUserChromeToAgencyStrip = agencyStripVisible

    const globalUserControls = (
        <GlobalUserControls
            textColor={textColor}
            isTransparentVariant={isCinematicNav}
            activeBrand={activeBrand}
            effectiveCollection={effectiveCollection}
            collectionOnly={collectionOnly || isCollectionGuestExperience}
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

            {/*
              LOCK (agency nav): Cinematic header wrapper groups agency strip + main nav hover for transparent variant only.
              Do not use this wrapper to inject non-agency top rows — basic companies must see a single nav stack.
            */}
            <div
                className={variant === 'transparent' ? 'relative z-[140] flex flex-col overflow-visible' : undefined}
                onMouseEnter={variant === 'transparent' ? () => setNavHovered(true) : undefined}
                onMouseLeave={variant === 'transparent' ? () => setNavHovered(false) : undefined}
            >
            {/*
              =============================================================================
              AGENCY NAV (TOP STRIP) — RENDER — LOCKING REMINDERS
              =============================================================================
              LOCK: This block is the only allowed “second row” above the main nav for agency workflows.
              LOCK: Gated exclusively by `agencyStripVisible` (see boolean LOCK comment above). Do not add a
                    parallel strip or duplicate switcher for basic companies.
              LOCK: Shows agency name + brand picker + agency dashboard entry — meaningful ONLY when the user
                    is agency-capable AND (on agency tenant OR agency-managed client). Client companies with
                    `is_agency_managed` are explicitly included — that association IS agency context.
              LOCK: `aria-label` must stay agency-scoped; do not reuse this region for generic “Company” chrome.
              =============================================================================
            */}
            {agencyStripVisible && (
                <div
                    className={`relative z-[150] flex items-center transition-colors duration-300 ${
                        isCinematicNav ? 'text-white/90' : 'text-slate-800'
                    }`}
                    style={{
                        borderLeftWidth: 3,
                        borderLeftStyle: 'solid',
                        borderLeftColor: agencyBrandColor,
                        ...(variant === 'transparent'
                            ? {
                                  backgroundColor: navHovered ? 'rgba(11, 11, 13, 0.88)' : 'rgba(0, 0, 0, 0.35)',
                                  borderBottomWidth: 1,
                                  borderBottomStyle: 'solid',
                                  borderBottomColor: navHovered ? 'rgba(255, 255, 255, 0.12)' : 'rgba(255, 255, 255, 0.08)',
                                  transition: cinematicSurfaceTransition,
                                  backdropFilter: 'blur(12px)',
                                  WebkitBackdropFilter: 'blur(12px)',
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
                            <div className="min-w-0 flex-1">
                                <p
                                    className={`hidden text-[10px] font-semibold uppercase tracking-wider sm:block ${
                                        isCinematicNav ? 'text-white/45' : 'text-slate-500'
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
                                isTransparentVariant={isCinematicNav}
                                currentTenantId={activeCompany?.id}
                                currentBrandId={auth.activeBrand?.id}
                            />
                            <button
                                type="button"
                                onClick={goAgencyDashboardFromMenu}
                                className={`inline-flex shrink-0 items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-semibold transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-1 sm:text-sm ${
                                    isCinematicNav
                                        ? 'bg-white/10 text-white hover:bg-white/15 focus-visible:ring-white/40 focus-visible:ring-offset-transparent'
                                        : 'bg-white shadow-sm ring-1 ring-slate-200/80 hover:bg-slate-50 focus-visible:ring-indigo-500 focus-visible:ring-offset-white'
                                }`}
                                style={!isCinematicNav ? { color: agencyBrandColor } : undefined}
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
                className={`relative z-[140] overflow-visible app-nav ${isExternalCollectionChrome ? 'is-collection-only' : ''} ${variant === 'transparent' ? '' : 'shadow-sm'}`}
                style={{
                    ...(isCinematicNav && cinematicNavSurfaceStyle ? cinematicNavSurfaceStyle : { backgroundColor: navColor, transition: cinematicSurfaceTransition }),
                    ...(isExternalCollectionChrome ? { '--collection-only-user': '1' } : {}),
                }}
                data-collection-only={isExternalCollectionChrome ? 'true' : undefined}
                aria-label={isExternalCollectionChrome ? 'Collection-only access — some links disabled' : undefined}
            >
                <div className={isAppPage ? "px-4 sm:px-6 lg:px-8" : "mx-auto max-w-7xl px-4 sm:px-6 lg:px-8"}>
                <div className="flex h-20 min-w-0 justify-between gap-2 overflow-visible">
                    <div className="flex min-w-0 flex-1 items-center gap-2 overflow-visible sm:gap-3">
                        {/* Mobile: hamburger to open main nav drawer (main nav links hidden below sm) */}
                        {isAppPage && isExternalCollectionChrome && (
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
                        <div className="flex min-h-12 min-w-0 max-w-[200px] sm:max-w-[220px] md:max-w-[240px] shrink-0 items-center">
                            {isAppPage ? (isExternalCollectionChrome && collectionBrandForLogo && effectiveCollection?.id ? (
                                <AppBrandLogo
                                    activeBrand={collectionBrandForLogo}
                                    brands={effectiveCollectionsList.length > 1 ? [...new Map(effectiveCollectionsList.filter(c => c.brand).map(c => [c.brand.id, { ...c.brand, is_active: c.brand.id === collectionBrandForLogo?.id }])).values()] : []}
                                    textColor={textColor}
                                    logoFilterStyle={computeLogoFilterStyle(collectionBrandForLogo?.logo_filter, collectionBrandForLogo?.primary_color)}
                                    onSwitchBrand={(brandId) => {
                                        const col = effectiveCollectionsList.find(c => c.brand?.id === brandId)
                                        if (col) router.post(route('collection-invite.switch', { collection: col.id }))
                                    }}
                                    rootLinkHref={route('collection-invite.landing', { collection: effectiveCollection.id })}
                                />
                            ) : isExternalCollectionChrome && effectiveCollection ? (
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

                        {/* Main menu: Overview (dropdown: Tasks, Creator Home, …), Assets, Executions, Collections, Generative */}
                        {isAppPage ? (isExternalCollectionChrome ? (
                            <div className="hidden min-w-0 flex-1 sm:flex sm:min-w-0 sm:items-center sm:gap-6 lg:gap-8 sm:pl-4 lg:pl-6 overflow-x-auto" data-collection-only="true">
                                {(() => {
                                    /** Inactive but available (e.g. Collections tab when not on collection URL) */
                                    const colOnlyMuted = textColor === '#ffffff' ? 'rgba(255, 255, 255, 0.72)' : 'rgba(0, 0, 0, 0.55)'
                                    /** Unavailable main nav — clearly weaker than active / allowed tabs */
                                    const colOnlyDisabledColor =
                                        textColor === '#ffffff' ? 'rgba(255, 255, 255, 0.38)' : '#9ca3af'
                                    const colOnlyDisabledClass =
                                        'inline-flex items-center gap-1.5 border-b-2 border-transparent px-1 py-2 text-sm font-medium cursor-not-allowed select-none pointer-events-none opacity-[0.42]'
                                    const colOnlyDisabledTitle =
                                        'Not available for your access — use Collections and Downloads to work with shared content'
                                    return (
                                        <>
                                            <div className="shrink-0">
                                                <span
                                                    className={colOnlyDisabledClass}
                                                    style={{ color: colOnlyDisabledColor, borderBottomColor: 'transparent' }}
                                                    title={colOnlyDisabledTitle}
                                                    aria-disabled="true"
                                                >
                                                    <HomeIcon className="h-4 w-4 shrink-0" aria-hidden="true" />
                                                    Overview
                                                </span>
                                            </div>
                                            <div className="app-nav-main-links flex min-w-0 flex-1 items-center gap-6 overflow-x-auto lg:gap-8">
                                                <span
                                                    className={colOnlyDisabledClass}
                                                    style={{ color: colOnlyDisabledColor, borderBottomColor: 'transparent' }}
                                                    title={colOnlyDisabledTitle}
                                                    aria-disabled="true"
                                                >
                                                    <PhotoIcon className="h-4 w-4 shrink-0" aria-hidden="true" />
                                                    Assets
                                                </span>
                                                <span
                                                    className={colOnlyDisabledClass}
                                                    style={{ color: colOnlyDisabledColor, borderBottomColor: 'transparent' }}
                                                    title={colOnlyDisabledTitle}
                                                    aria-disabled="true"
                                                >
                                                    <Squares2X2Icon className="h-4 w-4 shrink-0" aria-hidden="true" />
                                                    {DELIVERABLES_PAGE_LABEL}
                                                </span>
                                                {collectionOnlyCollection?.id && collectionOnlyCollections?.length > 0 ? (
                                                    collectionOnlyCollections.length > 1 ? (
                                                        <div className="relative">
                                                            <button
                                                                type="button"
                                                                onClick={() => setCollectionsDropdownOpen(open => !open)}
                                                                onBlur={() => setTimeout(() => setCollectionsDropdownOpen(false), 150)}
                                                                className="inline-flex items-center gap-1.5 border-b-2 px-1 py-2 text-sm font-medium border-transparent focus:outline-none focus:ring-0"
                                                                style={{
                                                                    color: currentUrl.includes('/collection-access/') ? textColor : colOnlyMuted,
                                                                    borderBottomColor: currentUrl.includes('/collection-access/')
                                                                        ? collectionNavAccent
                                                                        : 'transparent',
                                                                }}
                                                            >
                                                                <RectangleGroupIcon className="h-4 w-4 shrink-0" aria-hidden="true" />
                                                                {collectionMainNavTabLabel}
                                                                <ChevronDownIcon className="h-3.5 w-3.5 shrink-0 opacity-60" aria-hidden="true" />
                                                            </button>
                                                            {collectionsDropdownOpen && (
                                                                <div className="absolute left-0 top-full z-50 mt-1 w-56 rounded-md bg-white py-1 shadow-lg ring-1 ring-black ring-opacity-5">
                                                                    {collectionOnlyCollections.map((c) => (
                                                                        <button
                                                                            key={c.id}
                                                                            type="button"
                                                                            onClick={() => {
                                                                                setCollectionsDropdownOpen(false)
                                                                                router.post(route('collection-invite.switch', { collection: c.id }))
                                                                            }}
                                                                            className={`block w-full text-left px-4 py-2 text-sm ${
                                                                                c.id === collectionOnlyCollection?.id
                                                                                    ? 'font-medium'
                                                                                    : 'text-gray-700 hover:bg-gray-50'
                                                                            }`}
                                                                            style={
                                                                                c.id === collectionOnlyCollection?.id
                                                                                    ? { color: collectionNavAccent, backgroundColor: `${collectionNavAccent}14` }
                                                                                    : undefined
                                                                            }
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
                                                            className="inline-flex items-center gap-1.5 border-b-2 px-1 py-2 text-sm font-medium border-transparent"
                                                            style={{
                                                                color: currentUrl.includes('/collection-access/') ? textColor : colOnlyMuted,
                                                                borderBottomColor: currentUrl.includes('/collection-access/')
                                                                    ? collectionNavAccent
                                                                    : 'transparent',
                                                            }}
                                                        >
                                                            <RectangleGroupIcon className="h-4 w-4 shrink-0" aria-hidden="true" />
                                                            {collectionMainNavTabLabel}
                                                        </Link>
                                                    )
                                                ) : effectiveCollection?.id ? (
                                                    <Link
                                                        href={route('collection-invite.landing', { collection: effectiveCollection.id })}
                                                        className="inline-flex items-center gap-1.5 border-b-2 px-1 py-2 text-sm font-medium border-transparent"
                                                        style={{
                                                            color: currentUrl.includes('/collection-access/') ? textColor : colOnlyMuted,
                                                            borderBottomColor: currentUrl.includes('/collection-access/')
                                                                ? collectionNavAccent
                                                                : 'transparent',
                                                        }}
                                                    >
                                                        <RectangleGroupIcon className="h-4 w-4 shrink-0" aria-hidden="true" />
                                                        {collectionMainNavTabLabel}
                                                    </Link>
                                                ) : (
                                                    <span
                                                        className={colOnlyDisabledClass}
                                                        style={{ color: colOnlyDisabledColor, borderBottomColor: 'transparent' }}
                                                        title={colOnlyDisabledTitle}
                                                        aria-disabled="true"
                                                    >
                                                        <RectangleGroupIcon className="h-4 w-4 shrink-0" aria-hidden="true" />
                                                        {collectionMainNavTabLabel}
                                                    </span>
                                                )}
                                                <span
                                                    className={colOnlyDisabledClass}
                                                    style={{ color: colOnlyDisabledColor, borderBottomColor: 'transparent' }}
                                                    title={colOnlyDisabledTitle}
                                                    aria-disabled="true"
                                                >
                                                    <SparklesIcon className="h-4 w-4 shrink-0" aria-hidden="true" />
                                                    Generative
                                                </span>
                                            </div>
                                        </>
                                    )
                                })()}
                            </div>
                        ) : suppressWorkspaceChrome ? (
                            <div className="hidden min-w-0 flex-1 sm:block" aria-hidden="true" />
                        ) : (
                            <div className="hidden min-w-0 flex-1 sm:flex sm:min-w-0 sm:items-center sm:gap-6 lg:gap-8 sm:pl-4 lg:pl-6">
                                <div className="shrink-0">{renderDesktopOverviewNav()}</div>
                                <div className="app-nav-main-links flex min-w-0 flex-1 items-center gap-6 overflow-x-auto lg:gap-8">
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
                            </div>
                        )) : (
                            <div className="hidden min-w-0 flex-1 sm:flex sm:min-w-0 sm:items-center sm:gap-6 lg:gap-8 sm:ml-6">
                                <div className="shrink-0">{renderDesktopOverviewNav()}</div>
                                <div className="app-nav-main-links flex min-w-0 flex-1 items-center gap-6 overflow-x-auto lg:gap-8">
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
                            </div>
                        )}
                    </div>
                    <div className="flex shrink-0 items-center gap-2 lg:gap-4">
                        {/* Right-side nav: Brand Guidelines (only when published, or user can set up DNA), Downloads */}
                        {isAppPage && showBrandGuidelinesNav && !suppressWorkspaceChrome && (
                            <Link
                                href="/app/brand-guidelines"
                                title={brandGuidelinesNavTitle}
                                className={`hidden lg:inline-flex items-center gap-1.5 px-2 py-1.5 text-sm font-medium rounded-md border border-transparent ${isCinematicNav ? 'hover:bg-white/10' : 'hover:bg-gray-100'} focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2`}
                                style={{
                                    color: textColor === '#ffffff' ? 'rgba(255, 255, 255, 0.9)' : 'rgba(0, 0, 0, 0.85)',
                                }}
                            >
                                <span>Brand Guidelines</span>
                            </Link>
                        )}
                        {isAppPage && !suppressWorkspaceChrome && (
                            <>
                                <Link
                                    href="/app/downloads"
                                    className={`inline-flex items-center gap-1.5 px-2 py-1.5 text-sm font-medium rounded-md border border-transparent ${isCinematicNav ? 'hover:bg-white/10' : 'hover:bg-gray-100'} focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2`}
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
                                        className={`lg:hidden inline-flex items-center p-2 text-sm font-medium rounded-md border border-transparent ${isCinematicNav ? 'hover:bg-white/10' : 'hover:bg-gray-100'} focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2`}
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
            {isAppPage && isExternalCollectionChrome && mobileNavOpen && (
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
                                {['Overview', 'Assets', DELIVERABLES_PAGE_LABEL].map((label) => (
                                    <div
                                        key={label}
                                        className="flex items-center px-3 py-3 rounded-lg text-sm font-medium text-gray-400 opacity-40 cursor-not-allowed select-none"
                                        title="Not available for your access — use Collections and Downloads for shared content"
                                        aria-disabled="true"
                                    >
                                        {label}
                                    </div>
                                ))}
                                {collectionOnlyCollection?.id && collectionOnlyCollections?.length > 0 ? (
                                    collectionOnlyCollections.length > 1 ? (
                                        collectionOnlyCollections.map((c) => {
                                            const isCurrent = c.id === collectionOnlyCollection?.id
                                            return (
                                                <button
                                                    key={c.id}
                                                    type="button"
                                                    onClick={() => {
                                                        setMobileNavOpen(false)
                                                        router.post(route('collection-invite.switch', { collection: c.id }))
                                                    }}
                                                    className={`flex w-full items-center px-3 py-3 rounded-lg text-sm font-medium text-left transition-colors ${
                                                        isCurrent ? 'bg-gray-100 text-gray-900' : 'text-gray-700 hover:bg-gray-50'
                                                    }`}
                                                    style={isCurrent ? { color: collectionNavAccent } : undefined}
                                                >
                                                    {c.name}
                                                    {isCurrent && ' (current)'}
                                                </button>
                                            )
                                        })
                                    ) : (
                                        <Link
                                            href={route('collection-invite.landing', { collection: effectiveCollection.id })}
                                            onClick={() => setMobileNavOpen(false)}
                                            className="flex items-center px-3 py-3 rounded-lg text-sm font-medium bg-gray-100"
                                            style={{ color: collectionNavAccent }}
                                        >
                                            {collectionMainNavTabLabel}
                                        </Link>
                                    )
                                ) : effectiveCollection?.id ? (
                                    <Link
                                        href={route('collection-invite.landing', { collection: effectiveCollection.id })}
                                        onClick={() => setMobileNavOpen(false)}
                                        className="flex items-center px-3 py-3 rounded-lg text-sm font-medium bg-gray-100"
                                        style={{ color: collectionNavAccent }}
                                    >
                                        {collectionMainNavTabLabel}
                                    </Link>
                                ) : (
                                    <div
                                        className="flex items-center px-3 py-3 rounded-lg text-sm font-medium text-gray-400 opacity-40 cursor-not-allowed select-none"
                                        aria-disabled="true"
                                    >
                                        {collectionMainNavTabLabel}
                                    </div>
                                )}
                                <div
                                    className="flex items-center px-3 py-3 rounded-lg text-sm font-medium text-gray-400 opacity-40 cursor-not-allowed select-none"
                                    title="Not available for your access — use Collections and Downloads for shared content"
                                    aria-disabled="true"
                                >
                                    Generative
                                </div>
                            </div>
                        </nav>
                    </div>
                </>
            )}

            {/* Mobile PWA bottom app navigation */}
            {isAppPage && !isExternalCollectionChrome && !isAdminPage && !hideWorkspaceAppNav && (() => {
                const isOnOverview = currentUrl === '/app/overview' || currentUrl.startsWith('/app/overview')
                const isOnCreators =
                    activeBrand?.id && currentUrl.startsWith(`/app/brands/${activeBrand.id}/creators`)
                const isDarkNav =
                    isOnOverview ||
                    currentUrl.startsWith('/app/insights') ||
                    currentUrl.startsWith('/app/manage') ||
                    Boolean(isOnCreators)
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
