import { useCallback } from 'react'
import CinematicLayout from '../../Layouts/CinematicLayout'
import { mockBrands } from '../../brand/mockBrands'

export default function BrandSelect({ onSelect }) {
    const handleSelect = useCallback(
        (brand) => {
            const url = new URL(window.location.href)
            url.searchParams.set('brand', brand.slug)
            window.history.pushState({}, '', url.toString())
            onSelect(brand)
        },
        [onSelect]
    )

    return (
        <CinematicLayout>
            <div className="min-h-screen flex flex-col items-center justify-center px-6 py-16">
                <h1 className="text-[56px] md:text-[96px] font-light tracking-tight text-white/95 mb-4">
                    Select a Brand System
                </h1>
                <p className="text-lg text-white/65 mb-16">
                    Choose a brand to enter the operating system
                </p>
                <div className="flex flex-col gap-6 w-full max-w-md">
                    {mockBrands.map((brand) => (
                        <button
                            key={brand.id}
                            type="button"
                            onClick={() => handleSelect(brand)}
                            className="group relative overflow-hidden rounded-lg border border-white/[0.08] bg-white/[0.04] p-8 text-left transition-all duration-500 ease-in-out hover:border-[var(--brand-primary)] hover:bg-white/[0.06] hover:translate-y-[-2px]"
                            style={{ '--brand-primary': brand.primaryColor }}
                        >
                            <div className="flex items-center gap-6">
                                <img
                                    src={brand.logoUrl}
                                    alt={brand.name}
                                    className="h-12 w-auto object-contain opacity-90"
                                />
                                <div>
                                    <h2 className="text-2xl font-medium text-white">
                                        {brand.name}
                                    </h2>
                                    <p className="text-sm text-white/65 mt-1">
                                        {brand.tagline}
                                    </p>
                                </div>
                            </div>
                            <div
                                className="absolute inset-0 opacity-0 group-hover:opacity-100 transition-opacity duration-500"
                                style={{
                                    background: `radial-gradient(circle at 50% 50%, ${brand.primaryColor}15 0%, transparent 70%)`,
                                }}
                            />
                        </button>
                    ))}
                </div>
            </div>
        </CinematicLayout>
    )
}
