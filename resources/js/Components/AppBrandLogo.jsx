import { useState, useEffect, useMemo } from 'react'
import { Link, usePage } from '@inertiajs/react'
import JackpotLogo from './JackpotLogo'
import { usePermission } from '../hooks/usePermission'
import BrandIconUnified from './BrandIconUnified'
import AgencyContextNavPicker from './agency/AgencyContextNavPicker'
import { showWorkspaceSwitchingOverlay } from '../utils/workspaceSwitchOverlay'
import { getBrandLogoForSurface, hasDedicatedVariantForSurface } from '../utils/brandLogo'

function inertiaPathname(pageUrl) {
    if (!pageUrl) {
        return typeof window !== 'undefined' ? window.location.pathname : ''
    }
    try {
        const u = String(pageUrl)
        return (u.startsWith('http') ? new URL(u).pathname : u.split('?')[0].split('#')[0]) || ''
    } catch {
        return typeof window !== 'undefined' ? window.location.pathname : ''
    }
}

export default function AppBrandLogo({ activeBrand, brands, textColor, logoFilterStyle, onSwitchBrand, rootLinkHref }) {
    const page = usePage()
    const { auth } = page.props
    const { can } = usePermission()
    const tenant = auth?.activeCompany
    const canViewCompanyOverview = auth?.permissions?.can_view_company_overview ?? false
    /** Viewers (no company.view) must not land on /app — use brand workspace instead. */
    const workspaceHomeHref = canViewCompanyOverview ? '/app' : '/app/overview'
    const planLimitInfo = auth?.brand_plan_limit_info
    const canAddBrand = can('brand_settings.manage')
        && planLimitInfo
        && !planLimitInfo.brand_limit_exceeded
    const [brandMenuOpen, setBrandMenuOpen] = useState(false)
    const [logoError, setLogoError] = useState(false)
    const validBrands = (brands || []).filter((brand) => !brand.is_disabled)
    const hasMultipleBrands = validBrands && validBrands.length > 1

    const handleSwitchBrand = (brandId, e) => {
        if (e) {
            e.preventDefault()
            e.stopPropagation()
        }
        
        setBrandMenuOpen(false)
        
        if (onSwitchBrand) {
            onSwitchBrand(brandId)
        } else {
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
                    const search = typeof window !== 'undefined' ? window.location.search : ''
                    window.location.href = newPath + search
                } else {
                    window.location.href = '/app/overview'
                }
            }).catch(() => {
                window.location.href = '/app/overview'
            })
        }
    }

    // If no active brand, try to use the first brand from brands array, or show placeholder
    // This ensures the component always renders something visible
    if (!activeBrand) {
        // Try to get the first active brand from valid brands array
        const firstBrand = validBrands && validBrands.length > 0 ? validBrands.find(b => b.is_active) || validBrands[0] : null
        if (firstBrand) {
            // Surface-aware logo pick: white text = dark chrome; default chrome = light surface.
            // Resolver falls back to primary when the requested variant isn't set.
            const fallbackSurface = textColor === '#ffffff' ? 'dark' : 'light'
            const fallbackSrc = getBrandLogoForSurface(firstBrand, fallbackSurface)
            const fallbackHasVariant = hasDedicatedVariantForSurface(firstBrand, fallbackSurface)
            return (
                <Link href={workspaceHomeHref} className="flex min-w-0 max-w-full items-center gap-2.5 py-2">
                    {fallbackSrc ? (
                        <img
                            src={fallbackSrc}
                            alt={firstBrand.name || 'Brand'}
                            className="h-12 w-auto max-w-full object-contain object-left"
                            style={fallbackHasVariant ? undefined : logoFilterStyle}
                        />
                    ) : (
                        <>
                            <BrandIconUnified brand={firstBrand} size="lg" />
                            <span
                                className="min-w-0 truncate font-semibold leading-snug"
                                style={{ fontSize: 'clamp(0.8rem, 1.5vw, 1rem)', maxWidth: '10rem', color: textColor || 'inherit' }}
                            >
                                {firstBrand.name || 'Brand'}
                            </span>
                        </>
                    )}
                </Link>
            )
        }
        
        // No brands available — show Jackpot logo (e.g. on no-company / errors page)
        return (
            <Link href="/" className="flex items-center py-2 hover:opacity-90">
                <JackpotLogo className="h-12 w-auto" />
            </Link>
        )
    }

    const brandName = activeBrand.name || 'Brand'
    /** Transparent / cinematic nav passes white text — avoid light gray hover (unreadable). */
    const isDarkNavChrome = textColor === '#ffffff'
    /**
     * Pick the logo variant for the current nav chrome via the surface resolver.
     *
     * 'dark'  → uses logo_dark_path when set, falls back to primary.
     * 'light' → uses logo_light_path when set, falls back to primary.
     *
     * When a dedicated variant *is* in use, suppress logoFilterStyle — the user's
     * designer-chosen variant already has the right colors, we shouldn't CSS-invert
     * or recolor it on top (that was the old single-slot workaround).
     */
    const navSurface = isDarkNavChrome ? 'dark' : 'light'
    const navLogoSrc = useMemo(
        () => getBrandLogoForSurface(activeBrand, navSurface),
        [navSurface, activeBrand.logo_path, activeBrand.logo_dark_path, activeBrand.logo_light_path]
    )
    const useDedicatedVariantLogo = hasDedicatedVariantForSurface(activeBrand, navSurface)

    useEffect(() => {
        setLogoError(false)
    }, [navLogoSrc])
    /** Match GET /app (company portal), not Ziggy during Inertia — avoids logo ↔ text flash on nav. */
    const navPath = inertiaPathname(page.url)
    const isOnCompanyOverview = navPath === '/app' || navPath === '/app/'
    const displayLabel = (isOnCompanyOverview && canViewCompanyOverview && tenant)
        ? tenant.name
        : brandName

    const agencyContextPicker = auth?.agency_context_picker
    if (agencyContextPicker?.is_agency_context_picker === true) {
        return (
            <AgencyContextNavPicker
                agencyPicker={agencyContextPicker}
                activeBrand={activeBrand}
                textColor={textColor}
                isDarkNavChrome={isDarkNavChrome}
                logoFilterStyle={logoFilterStyle}
                navLogoSrc={navLogoSrc}
                useDedicatedVariantLogo={useDedicatedVariantLogo}
                brandName={brandName}
                logoError={logoError}
                setLogoError={setLogoError}
            />
        )
    }

    // If multiple brands, show logo + name + chevron as single clickable area that opens dropdown
    // Dropdown anchored to far left of logo; includes Company Portal link + brand switcher
    if (hasMultipleBrands) {
        return (
            <div className="relative flex min-w-0 max-w-full items-center">
                <button
                    type="button"
                    onClick={() => setBrandMenuOpen(!brandMenuOpen)}
                    aria-expanded={brandMenuOpen}
                    aria-haspopup="true"
                    className={`flex min-w-0 max-w-full items-center gap-2 rounded-md px-2 py-2 text-sm font-medium transition-colors focus:outline-none sm:px-3 ${
                        isDarkNavChrome
                            ? 'hover:bg-white/10 focus-visible:ring-2 focus-visible:ring-white/35 focus-visible:ring-offset-0'
                            : 'hover:bg-gray-50 focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2'
                    }`}
                    style={{ color: textColor }}
                    title="Open menu"
                >
                    {!(isOnCompanyOverview && canViewCompanyOverview && tenant) ? (
                        navLogoSrc && !logoError ? (
                            <img
                                src={navLogoSrc}
                                alt={brandName}
                                className="h-12 w-auto max-w-full object-contain object-left"
                                style={useDedicatedVariantLogo ? undefined : logoFilterStyle}
                                onError={() => setLogoError(true)}
                            />
                        ) : (
                            <>
                                <BrandIconUnified brand={activeBrand} size="lg" />
                                <span
                                    className="min-w-0 truncate font-semibold leading-snug"
                                    style={{ fontSize: 'clamp(0.8rem, 1.5vw, 1rem)', maxWidth: '10rem', color: textColor || 'inherit' }}
                                >
                                    {brandName}
                                </span>
                            </>
                        )
                    ) : (
                        <span
                            className="min-w-0 truncate leading-snug"
                            style={{
                                fontSize: 'clamp(0.75rem, 2vw, 0.875rem)',
                                maxWidth: '200px',
                                color: textColor || '#ffffff',
                            }}
                            title={displayLabel}
                        >
                            {displayLabel}
                        </span>
                    )}
                    <svg
                        className={`h-5 w-5 flex-shrink-0 transition-transform ${brandMenuOpen ? 'rotate-180' : ''}`}
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
                </button>

                {brandMenuOpen && (
                    <>
                        <div
                            className="fixed inset-0 z-[100]"
                            onClick={() => setBrandMenuOpen(false)}
                        />
                        <div 
                            className="absolute left-0 top-full z-[101] mt-2 w-72 origin-top-left rounded-xl bg-white py-1.5 shadow-lg ring-1 ring-black/5 focus:outline-none"
                            onClick={(e) => e.stopPropagation()}
                        >
                            {canViewCompanyOverview && tenant && (
                                <>
                                    <div className="px-4 pt-2 pb-1 text-xs text-gray-400 uppercase">
                                        Company
                                    </div>
                                    <Link
                                        href="/app"
                                        className={`flex items-center gap-3 px-4 py-3 text-sm hover:bg-gray-100 ${
                                            isOnCompanyOverview ? 'bg-gray-100 font-medium' : ''
                                        }`}
                                        onClick={() => setBrandMenuOpen(false)}
                                    >
                                        <div className="h-10 w-10 rounded-lg bg-gray-100 flex items-center justify-center flex-shrink-0">
                                            <svg className="h-5 w-5 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 7.5h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008z" />
                                            </svg>
                                        </div>
                                        <span className="flex-1 truncate">{tenant?.name || 'Company Portal'}</span>
                                    </Link>
                                </>
                            )}
                            <div className="px-4 pt-3 pb-1 text-xs text-gray-400 uppercase">
                                Switch Brand
                            </div>
                            <div className="max-h-64 overflow-y-auto">
                                {brands && brands.length > 0 ? (
                                    brands.map((brandOption) => {
                                        const isDisabled = brandOption.is_disabled
                                        const isActive = brandOption.is_active
                                        
                                        return (
                                            <button
                                                key={brandOption.id}
                                                type="button"
                                                onClick={(e) => {
                                                    if (!isDisabled) {
                                                        handleSwitchBrand(brandOption.id, e)
                                                    }
                                                }}
                                                disabled={isDisabled}
                                                className={`flex w-full items-center gap-3 px-4 py-3 text-left text-sm ${
                                                    isDisabled
                                                        ? 'opacity-50 cursor-not-allowed text-gray-400'
                                                        : isActive
                                                        ? 'bg-indigo-50 text-indigo-700 font-medium hover:bg-indigo-100'
                                                        : 'text-gray-700 hover:bg-gray-50'
                                                }`}
                                                title={isDisabled ? 'This brand is not accessible on your current plan. Upgrade to access it.' : undefined}
                                            >
                                                <BrandIconUnified brand={brandOption} size="md" />
                                                <span className="flex-1 truncate">{brandOption.name}</span>
                                                {isActive && !isDisabled && (
                                                    <svg
                                                        className="h-4 w-4 flex-shrink-0 text-indigo-600"
                                                        fill="currentColor"
                                                        viewBox="0 0 20 20"
                                                    >
                                                        <path
                                                            fillRule="evenodd"
                                                            d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z"
                                                            clipRule="evenodd"
                                                        />
                                                    </svg>
                                                )}
                                                {isDisabled && (
                                                    <svg
                                                        className="h-4 w-4 flex-shrink-0 text-gray-400"
                                                        fill="currentColor"
                                                        viewBox="0 0 20 20"
                                                        title="This brand is not accessible on your current plan. Upgrade to access it."
                                                    >
                                                        <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 101.06-1.06L11.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L10 8.94 8.28 7.22z" clipRule="evenodd" />
                                                    </svg>
                                                )}
                                            </button>
                                        )
                                    })
                                ) : (
                                    <div className="px-4 py-3 text-sm text-gray-500 text-center">
                                        No brands available
                                    </div>
                                )}
                            </div>
                            {canAddBrand && (
                                <div className="border-t border-gray-100">
                                    <Link
                                        href="/app/brands/create"
                                        className="flex items-center gap-3 px-4 py-3 text-sm transition-colors hover:bg-gray-50"
                                        onClick={() => setBrandMenuOpen(false)}
                                        aria-label={`Create a new brand for ${tenant?.name?.trim() || 'this company'}`}
                                    >
                                        <div className="h-10 w-10 shrink-0 rounded-lg border-2 border-dashed border-gray-300 flex items-center justify-center">
                                            <svg className="h-5 w-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M12 4v16m8-8H4" />
                                            </svg>
                                        </div>
                                        <span className="min-w-0 flex-1">
                                            <span className="block font-medium text-gray-900">New brand</span>
                                            <span className="mt-0.5 block truncate text-[11px] leading-snug text-gray-500">
                                                For{' '}
                                                <span className="font-medium text-gray-600">
                                                    {tenant?.name?.trim() || 'this company'}
                                                </span>
                                            </span>
                                        </span>
                                    </Link>
                                </div>
                            )}
                        </div>
                    </>
                )}
            </div>
        )
    }

    // Single brand - simple link to dashboard (or rootLinkHref when provided, e.g. collection landing)
    // When brands array is empty but we have activeBrand, link to /app/brands so user can switch (recovery from stale state)
    const singleBrandHref = rootLinkHref ?? workspaceHomeHref
    return (
        <Link href={singleBrandHref} className="flex min-w-0 max-w-full items-center gap-2.5 py-2">
            {navLogoSrc && !logoError ? (
                <img
                    src={navLogoSrc}
                    alt={brandName}
                    className="h-12 w-auto max-w-full object-contain object-left"
                    style={useDedicatedVariantLogo ? undefined : logoFilterStyle}
                    onError={() => setLogoError(true)}
                />
            ) : (
                <>
                    <BrandIconUnified brand={activeBrand} size="lg" />
                    <span
                        className="min-w-0 truncate font-semibold leading-snug"
                        style={{ fontSize: 'clamp(0.8rem, 1.5vw, 1rem)', maxWidth: '10rem', color: textColor || 'inherit' }}
                    >
                        {brandName}
                    </span>
                </>
            )}
        </Link>
    )
}
