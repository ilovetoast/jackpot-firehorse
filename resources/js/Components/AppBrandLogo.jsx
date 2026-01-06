import { useState } from 'react'
import { Link, router } from '@inertiajs/react'
import { getContrastTextColor } from '../utils/colorUtils'

export default function AppBrandLogo({ activeBrand, brands, textColor, logoFilterStyle, onSwitchBrand }) {
    const [brandMenuOpen, setBrandMenuOpen] = useState(false)
    const hasMultipleBrands = brands && brands.length > 1

    const handleSwitchBrand = (brandId) => {
        if (onSwitchBrand) {
            onSwitchBrand(brandId)
        } else {
            router.post(`/app/brands/${brandId}/switch`, {}, {
                preserveState: true,
                preserveScroll: true,
                onSuccess: () => {
                    window.location.reload()
                },
            })
        }
        setBrandMenuOpen(false)
    }

    // If no active brand, try to use the first brand from brands array, or show placeholder
    // This ensures the component always renders something visible
    if (!activeBrand) {
        // Try to get the first active brand from brands array
        const firstBrand = brands && brands.length > 0 ? brands.find(b => b.is_active) || brands[0] : null
        if (firstBrand) {
            // Use the first brand as fallback
            const fallbackBrandName = firstBrand.name || 'Brand'
            const firstLetter = fallbackBrandName.charAt(0).toUpperCase()
            return (
                <Link href="/app/dashboard" className="flex items-center min-w-0">
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
        
        // No brands available, show minimal placeholder
        return (
            <div className="flex items-center">
                <div className="h-12 w-12 rounded-full bg-indigo-600 flex items-center justify-center" style={{ backgroundColor: '#4f46e5' }}>
                    <span className="text-sm font-medium text-white">B</span>
                </div>
            </div>
        )
    }

    // Ensure we have a brand name to display
    const brandName = activeBrand.name || 'Brand'
    const firstLetter = brandName.charAt(0).toUpperCase()
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
                    {activeBrand.logo_path ? (
                        <img
                            src={activeBrand.logo_path}
                            alt={brandName}
                            className="h-12 w-auto max-w-72 object-contain flex-shrink-0"
                            style={logoFilterStyle}
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
                        <div className="absolute left-0 z-20 mt-2 w-64 origin-top-left rounded-md bg-white py-1 shadow-lg ring-1 ring-black ring-opacity-5 focus:outline-none">
                            <div className="px-4 py-3 border-b border-gray-200">
                                <p className="text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Switch Brand
                                </p>
                            </div>
                            <div className="max-h-64 overflow-y-auto">
                                {brands
                                    .filter((brandOption) => !brandOption.is_disabled) // Hide disabled brands (inaccessible brands are already filtered in backend)
                                    .map((brandOption) => (
                                    <button
                                        key={brandOption.id}
                                        type="button"
                                        onClick={() => handleSwitchBrand(brandOption.id)}
                                        className={`flex w-full items-center gap-3 px-4 py-3 text-left text-sm ${
                                            brandOption.is_active
                                                ? 'bg-indigo-50 text-indigo-700 font-medium hover:bg-indigo-100'
                                                : 'text-gray-700 hover:bg-gray-50'
                                        }`}
                                    >
                                        {(() => {
                                            if (brandOption.logo_path) {
                                                return (
                                                    <img
                                                        src={brandOption.logo_path}
                                                        alt={brandOption.name}
                                                        className="h-8 w-auto flex-shrink-0"
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
                                        {brandOption.is_active && (
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
                                    </button>
                                ))}
                            </div>
                        </div>
                    </>
                )}
            </div>
        )
    }

    // Single brand - simple link to dashboard
    return (
        <Link href="/app/dashboard" className="flex items-center min-w-0 max-w-full">
            {activeBrand.logo_path ? (
                <img
                    src={activeBrand.logo_path}
                    alt={brandName}
                    className="h-12 w-auto max-w-72 object-contain flex-shrink-0"
                    style={logoFilterStyle}
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
                        className="truncate min-w-0 ml-2"
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
