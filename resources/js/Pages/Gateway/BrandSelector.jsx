import { router, usePage } from '@inertiajs/react'
import { useState } from 'react'

export default function BrandSelector({ brands, tenant }) {
    const { theme } = usePage().props
    const [processing, setProcessing] = useState(false)

    const handleSelect = (brand) => {
        if (processing) return
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

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                {brands.map((brand) => {
                    const color = brand.primary_color || theme?.colors?.primary || '#6366f1'
                    return (
                        <button
                            key={brand.id}
                            type="button"
                            onClick={() => handleSelect(brand)}
                            disabled={processing}
                            className="group relative overflow-hidden rounded-xl border border-white/[0.08] bg-white/[0.03] p-6 text-left transition-all duration-200 hover:scale-[1.02] hover:border-white/[0.16] hover:bg-white/10 disabled:opacity-50"
                        >
                            <div className="flex flex-col items-start gap-4">
                                <BrandIcon brand={brand} color={color} />
                                <div>
                                    <h2 className="text-lg font-medium text-white">
                                        {brand.name}
                                    </h2>
                                    {brand.is_default && (
                                        <span className="text-[10px] uppercase tracking-widest text-white/30 mt-1 inline-block">
                                            Default
                                        </span>
                                    )}
                                </div>
                            </div>

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
                        </button>
                    )
                })}
            </div>
        </div>
    )
}

function BrandIcon({ brand, color }) {
    const [imgError, setImgError] = useState(false)

    if (brand.logo_path && !imgError) {
        return (
            <img
                src={brand.logo_path}
                alt={brand.name}
                className="h-10 w-auto object-contain opacity-90"
                onError={() => setImgError(true)}
            />
        )
    }

    if (brand.icon) {
        return (
            <div
                className="h-10 w-10 rounded-lg flex items-center justify-center text-sm font-semibold text-white"
                style={{ backgroundColor: brand.icon_bg_color || color }}
            >
                {brand.icon}
            </div>
        )
    }

    return (
        <div
            className="h-10 w-10 rounded-lg flex items-center justify-center text-lg font-semibold text-white"
            style={{ backgroundColor: `${color}30` }}
        >
            {brand.name?.charAt(0)?.toUpperCase()}
        </div>
    )
}
