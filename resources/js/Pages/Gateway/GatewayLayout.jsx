import { usePage } from '@inertiajs/react'
import FilmGrainOverlay from '../../Components/FilmGrainOverlay'
import LogoMark from '../../Components/Brand/LogoMark'

export default function GatewayLayout({ children, onSwitchOpen, showHeaderLogo = true }) {
    const { theme, context } = usePage().props

    const primary = theme?.colors?.primary || '#7c3aed'
    const isDefault = theme?.mode === 'default'
    const isAuthenticated = context?.is_authenticated
    /** Show Jackpot attribution only after sign-in when workspace (tenant/brand) theme is active. */
    const showPoweredBy = isAuthenticated && !isDefault
    const canSwitch = context?.is_multi_company || context?.is_multi_brand
    const singleBrandWithLogo = Boolean(theme?.single_brand_tenant && (theme?.logo || theme?.logo_dark))

    return (
        <div
            className="min-h-screen bg-[#0B0B0D] text-white relative overflow-hidden"
            style={{
                '--gw-primary': primary,
                '--gw-secondary': theme?.colors?.secondary || '#8b5cf6',
            }}
        >
            {/* Background layer */}
            <div
                className="fixed inset-0 pointer-events-none"
                style={{ background: theme?.background?.value || '#0B0B0D' }}
            />

            {/* Cinematic depth overlay */}
            <div className="fixed inset-0 pointer-events-none">
                <div className="absolute inset-0 bg-black/30" />
                <div className="absolute inset-0 bg-gradient-to-b from-black/20 via-transparent to-black/40" />
            </div>

            {/* Content layer */}
            <div className="relative z-10 min-h-screen flex flex-col">
                {/* Header */}
                <div
                    className={`absolute top-6 left-6 right-6 flex items-center z-20 ${
                        showHeaderLogo ? 'justify-between' : 'justify-end'
                    }`}
                >
                    {showHeaderLogo ? <LogoMark size="sm" href={isDefault ? '/' : null} /> : null}

                    {isAuthenticated && canSwitch && onSwitchOpen && (
                        <button
                            type="button"
                            onClick={onSwitchOpen}
                            className="text-xs px-3 py-1.5 rounded-full bg-white/10 hover:bg-white/20 text-white/60 hover:text-white/90 transition-all duration-300 tracking-widest uppercase font-medium"
                        >
                            Switch
                        </button>
                    )}
                </div>

                {/* Main content */}
                <main className={`flex-1 flex flex-col items-center justify-center px-6 pb-16 pt-20 ${isDefault ? 'opacity-95' : 'opacity-100'}`}>
                    {children}
                </main>

                {/* Footer */}
                <footer className="px-8 py-6 text-center">
                    <p className="text-[11px] text-white/25 tracking-widest uppercase">
                        {singleBrandWithLogo ? 'Brand asset manager' : `${theme?.name || 'Jackpot'} · Brand asset manager`}
                    </p>
                    {showPoweredBy && (
                        <p className="text-[10px] text-white/15 tracking-widest uppercase mt-1">
                            Powered by Jackpot
                        </p>
                    )}
                </footer>
            </div>

            <FilmGrainOverlay />
        </div>
    )
}
