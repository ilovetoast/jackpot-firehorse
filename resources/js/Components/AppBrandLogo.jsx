import { useState } from 'react'
import { Link, router, usePage } from '@inertiajs/react'
import { getContrastTextColor } from '../utils/colorUtils'
import JackpotLogo from './JackpotLogo'

export default function AppBrandLogo({ activeBrand, brands, textColor, logoFilterStyle, onSwitchBrand, rootLinkHref }) {
    const { auth } = usePage().props
    const tenant = auth?.activeCompany
    const canViewCompanyOverview = auth?.permissions?.can_view_company_overview ?? false
    const [brandMenuOpen, setBrandMenuOpen] = useState(false)
    const [logoError, setLogoError] = useState(false)
    const validBrands = (brands || []).filter((brand) => !brand.is_disabled)
    const hasMultipleBrands = validBrands && validBrands.length > 1

    const navDisplayMode = activeBrand?.settings?.nav_display_mode || 'logo'
    const showLogoInNav = navDisplayMode === 'logo' && !!activeBrand?.logo_path && !logoError

    const computeFilterStyle = (filter, primaryColor) => {
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

    const handleSwitchBrand = (brandId, e) => {
        if (e) {
            e.preventDefault()
            e.stopPropagation()
        }
        
        setBrandMenuOpen(false)
        
        if (onSwitchBrand) {
            onSwitchBrand(brandId)
        } else {
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
            // Use the first brand as fallback
            const fallbackBrandName = firstBrand.name || 'Brand'
            const firstLetter = fallbackBrandName.charAt(0).toUpperCase()
            return (
                <Link href="/app" className="flex items-center min-w-0">
                    {firstBrand.logo_path ? (
                        <img
                            src={firstBrand.logo_path}
                            alt={fallbackBrandName}
                            className="h-12 w-auto max-w-72 object-contain"
                            style={logoFilterStyle}
                        />
                    ) : (() => {
                        const fallbackBrandColor = firstBrand.primary_color || '#4f46e5'
                        const fallbackTextColor = getContrastTextColor(fallbackBrandColor)
                        return (
                            <div 
                                className="h-12 w-12 rounded-full flex items-center justify-center flex-shrink-0"
                                style={{ backgroundColor: fallbackBrandColor }}
                            >
                                <span className="text-base font-medium" style={{ color: fallbackTextColor }}>{firstLetter}</span>
                            </div>
                        )
                    })()}
                </Link>
            )
        }
        
        // No brands available — show Jackpot logo (e.g. on no-company / errors page)
        return (
            <Link href="/" className="flex items-center hover:opacity-90">
                <JackpotLogo className="h-12 w-auto" />
            </Link>
        )
    }

    // Ensure we have a brand name to display
    const brandName = activeBrand.name || 'Brand'
    const firstLetter = brandName.charAt(0).toUpperCase()
    const isOnCompanyOverview = route().current('app')
    const displayLabel = (isOnCompanyOverview && canViewCompanyOverview && tenant)
        ? tenant.name
        : brandName
    // Get brand primary color, fallback to indigo-600 if not set
    const brandColor = activeBrand.primary_color || '#4f46e5' // indigo-600 default
    // Get appropriate text color based on background color
    const textColorForBrand = getContrastTextColor(brandColor)

    // If multiple brands, show clickable button with dropdown
    if (hasMultipleBrands) {
        return (
            <div className="relative">
                <button
                    type="button"
                    className="flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 min-w-0 max-w-full"
                    onClick={() => setBrandMenuOpen(!brandMenuOpen)}
                    aria-expanded="false"
                    aria-haspopup="true"
                    style={{ color: textColor }}
                >
                    {!(isOnCompanyOverview && canViewCompanyOverview && tenant) && (
                        showLogoInNav ? (
                            <img
                                src={activeBrand.logo_path}
                                alt={brandName}
                                className="h-12 w-auto max-w-72 object-contain flex-shrink-0"
                                style={logoFilterStyle}
                                onError={() => setLogoError(true)}
                            />
                        ) : (
                            <div 
                                className="h-8 w-8 rounded-full flex items-center justify-center flex-shrink-0"
                                style={{ backgroundColor: brandColor }}
                            >
                                <span className="text-sm font-medium" style={{ color: textColorForBrand }}>{firstLetter}</span>
                            </div>
                        )
                    )}
                    {(!showLogoInNav || (isOnCompanyOverview && canViewCompanyOverview && tenant)) && (
                        <span 
                            className="truncate min-w-0"
                            style={{ 
                                fontSize: 'clamp(0.75rem, 2vw, 0.875rem)',
                                maxWidth: '200px'
                            }}
                            title={displayLabel}
                        >
                            {displayLabel}
                        </span>
                    )}
                    <svg
                        className="h-5 w-5 flex-shrink-0"
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
                            className="fixed inset-0 z-10"
                            onClick={() => setBrandMenuOpen(false)}
                        />
                        <div 
                            className="absolute left-0 z-20 mt-2 w-64 origin-top-left rounded-md bg-white py-1 shadow-lg ring-1 ring-black ring-opacity-5 focus:outline-none"
                            onClick={(e) => e.stopPropagation()}
                        >
                            {canViewCompanyOverview && tenant && (
                                <>
                                    <div className="px-3 pt-2 pb-1 text-xs text-gray-400 uppercase">
                                        Company
                                    </div>
                                    <Link
                                        href="/app"
                                        className={`flex items-center px-3 py-2 rounded-md hover:bg-gray-100 ${
                                            route().current('app') ? 'bg-gray-100 font-medium' : ''
                                        }`}
                                        onClick={() => setBrandMenuOpen(false)}
                                    >
                                        <span className="text-sm">
                                            {tenant.name}
                                        </span>
                                    </Link>
                                </>
                            )}
                            <div className="px-3 pt-3 pb-1 text-xs text-gray-400 uppercase">
                                Switch Brand
                            </div>
                            <div className="max-h-64 overflow-y-auto">
                                {brands && brands.length > 0 ? (
                                    brands.map((brandOption) => {
                                        const isDisabled = brandOption.is_disabled
                                        const isActive = brandOption.is_active
                                        const optionFilter = computeFilterStyle(brandOption.logo_filter, brandOption.primary_color)
                                        const optionImage = brandOption.icon_path || brandOption.logo_path
                                        
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
                                        {(() => {
                                            if (optionImage) {
                                                return (
                                                    <img
                                                        src={optionImage}
                                                        alt={brandOption.name}
                                                        className="h-8 w-8 flex-shrink-0 rounded-full object-contain"
                                                        style={optionFilter}
                                                    />
                                                )
                                            }
                                            const optionBrandColor = brandOption.primary_color || '#4f46e5'
                                            const optionTextColor = getContrastTextColor(optionBrandColor)
                                            return (
                                                <div 
                                                    className="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full text-sm font-medium"
                                                    style={{ 
                                                        backgroundColor: optionBrandColor,
                                                        color: optionTextColor
                                                    }}
                                                >
                                                    {brandOption.name.charAt(0).toUpperCase()}
                                                </div>
                                            )
                                        })()}
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
                        </div>
                    </>
                )}
            </div>
        )
    }

    // Single brand - simple link to dashboard (or rootLinkHref when provided, e.g. collection landing)
    // When brands array is empty but we have activeBrand, link to /app/brands so user can switch (recovery from stale state)
    const singleBrandHref = rootLinkHref ?? '/app'
    return (
        <Link href={singleBrandHref} className="flex items-center gap-2 min-w-0 max-w-full">
            {showLogoInNav ? (
                <img
                    src={activeBrand.logo_path}
                    alt={brandName}
                    className="h-12 w-auto max-w-72 object-contain flex-shrink-0"
                    style={logoFilterStyle}
                    onError={() => setLogoError(true)}
                />
            ) : (
                <>
                    <div 
                        className="h-8 w-8 rounded-full flex items-center justify-center flex-shrink-0"
                        style={{ backgroundColor: brandColor }}
                    >
                        <span className="text-sm font-medium" style={{ color: textColorForBrand }}>{firstLetter}</span>
                    </div>
                    <span 
                        className="truncate min-w-0"
                        style={{ 
                            fontSize: 'clamp(0.75rem, 2vw, 0.875rem)',
                            maxWidth: '200px'
                        }}
                        title={brandName}
                    >
                        {brandName}
                    </span>
                </>
            )}
        </Link>
    )
}
