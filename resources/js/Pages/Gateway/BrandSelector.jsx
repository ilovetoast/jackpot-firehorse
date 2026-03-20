import { router, usePage } from '@inertiajs/react'
import { useState } from 'react'
import BrandIconUnified from '../../Components/BrandIconUnified'

function BrandLogo({ brand, disabled }) {
    const [imgError, setImgError] = useState(false)
    const logoSrc = brand.logo_dark_path || brand.logo_path

    if (logoSrc && !imgError) {
        return (
            <img
                src={logoSrc}
                alt={brand.name}
                className={`h-10 w-auto max-w-full object-contain transition-all ${disabled ? 'grayscale opacity-40' : ''}`}
                style={brand.logo_dark_path ? undefined : { filter: 'brightness(0) invert(1)' }}
                onError={() => setImgError(true)}
            />
        )
    }

    return (
        <div className={disabled ? 'grayscale opacity-40' : ''}>
            <BrandIconUnified brand={brand} size="lg" />
        </div>
    )
}

export default function BrandSelector({ brands, tenant }) {
    const { theme } = usePage().props
    const [processing, setProcessing] = useState(false)

    const hasDisabledBrands = brands.some(b => b.is_disabled)

    const handleSelect = (brand) => {
        if (processing || brand.is_disabled) return
        setProcessing(true)
        router.post('/gateway/select-brand', { brand_id: brand.id }, {
            onFinish: () => setProcessing(false),
        })
    }

    return (
        <div className="w-full max-w-lg animate-fade-in" style={{ animationDuration: '500ms' }}>
            <div className="text-center mb-12">
                <h1 className="text-4xl md:text-5xl font-semibold tracking-tight leading-tight text-white/95 mb-3">
                    {tenant?.name || theme?.name || 'Select Brand'}
                </h1>
                <p className="text-sm text-white/60 mt-2 max-w-md mx-auto">
                    Choose a brand to enter
                </p>
            </div>

            {hasDisabledBrands && (
                <div className="mb-6 px-4 py-3 rounded-lg bg-amber-500/10 border border-amber-500/20 text-amber-200 text-sm text-center">
                    Some brands are unavailable on the current plan. Contact your administrator to upgrade.
                </div>
            )}

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                {brands.map((brand) => {
                    const color = brand.primary_color || theme?.colors?.primary || '#6366f1'
                    const hasLogo = !!(brand.logo_dark_path || brand.logo_path)
                    const isDisabled = brand.is_disabled

                    return (
                        <button
                            key={brand.id}
                            type="button"
                            onClick={() => handleSelect(brand)}
                            disabled={processing || isDisabled}
                            className={`group relative overflow-hidden rounded-xl border p-6 text-left transition-all duration-200 ${
                                isDisabled
                                    ? 'border-white/[0.04] bg-white/[0.01] cursor-not-allowed'
                                    : 'border-white/[0.08] bg-white/[0.03] hover:scale-[1.02] hover:border-white/[0.16] hover:bg-white/10'
                            } disabled:opacity-50`}
                        >
                            <div className="flex flex-col items-start gap-4">
                                <BrandLogo brand={brand} disabled={isDisabled} />
                                {!hasLogo && (
                                    <h2 className={`text-lg font-medium ${isDisabled ? 'text-white/30' : 'text-white'}`}>
                                        {brand.name}
                                    </h2>
                                )}
                                <div className="flex items-center gap-2">
                                    {brand.is_default && !isDisabled && (
                                        <span className="text-[10px] uppercase tracking-widest text-white/30 inline-block">
                                            Default
                                        </span>
                                    )}
                                    {isDisabled && (
                                        <span className="inline-flex items-center gap-1 text-[10px] uppercase tracking-widest text-amber-400/70">
                                            <svg className="h-3 w-3" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" /></svg>
                                            Plan limit
                                        </span>
                                    )}
                                </div>
                            </div>

                            {!isDisabled && (
                                <>
                                    <div
                                        className="absolute top-0 left-0 right-0 h-[2px] opacity-0 group-hover:opacity-100 transition-opacity duration-500"
                                        style={{ backgroundColor: color }}
                                    />
                                    <div
                                        className="absolute inset-0 opacity-0 group-hover:opacity-100 transition-opacity duration-500 pointer-events-none"
                                        style={{
                                            background: `radial-gradient(circle at 50% 100%, ${color}12 0%, transparent 70%)`,
                                        }}
                                    />
                                </>
                            )}
                        </button>
                    )
                })}
            </div>
        </div>
    )
}

