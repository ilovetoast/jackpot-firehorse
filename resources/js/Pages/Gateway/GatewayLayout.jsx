import { useMemo } from 'react'
import { usePage } from '@inertiajs/react'
import FilmGrainOverlay from '../../Components/FilmGrainOverlay'
import LogoMark from '../../Components/Brand/LogoMark'
import { brandGatewayHoverCssVars } from '../../utils/colorUtils'

export default function GatewayLayout({
    children,
    showHeaderLogo = true,
    /**
     * 'default'    — centred with generous vertical padding (login, single-brand pick)
     * 'lanes'      — reduced padding, wider max-width, horizontal lanes layout
     * 'enterprise' — minimal padding, full-width, many-brand layout
     */
    layoutMode = 'default',
    /** Jackpot purple cinematic before a workspace brand is chosen (login, pickers). */
    ambient = 'theme',
    /** Brand primary/secondary while a picker card is hovered — drives animated ambient layer. */
    ambientHoverBrand = null,
}) {
    const { theme, context } = usePage().props

    const jackpotPick = ambient === 'jackpot-pick'
    /** Product purple — matches gateway login / marketing energy when tenant brand is unknown. */
    const primary = jackpotPick ? '#7c3aed' : theme?.colors?.primary || '#7c3aed'
    const secondary = jackpotPick ? '#6d28d9' : theme?.colors?.secondary || '#8b5cf6'
    const isDefault = theme?.mode === 'default' || jackpotPick
    const isAuthenticated = context?.is_authenticated
    /** Show Jackpot attribution only after sign-in when workspace (tenant/brand) theme is active. */
    const showPoweredBy = isAuthenticated && !isDefault
    const singleBrandWithLogo = Boolean(theme?.single_brand_tenant && (theme?.logo || theme?.logo_dark))
    const brandPreviewActive = jackpotPick && Boolean(ambientHoverBrand)

    const brandAmbientStyle = useMemo(() => {
        if (!ambientHoverBrand) {
            return { opacity: 0 }
        }
        return {
            opacity: 1,
            ...brandGatewayHoverCssVars(ambientHoverBrand.primary, ambientHoverBrand.secondary),
        }
    }, [ambientHoverBrand])

    /**
     * Jackpot cinematic (pre-brand): deep violet void + soft violet/indigo spotlights.
     * Stays on product purple until the user lands in a chosen workspace theme.
     */
    const jackpotBg = [
        'radial-gradient(ellipse 130% 92% at 50% -14%, rgba(167,139,250,0.38) 0%, rgba(124,58,237,0.16) 32%, transparent 58%)',
        'radial-gradient(ellipse 100% 78% at 4% 72%, rgba(91,33,182,0.38) 0%, transparent 52%)',
        'radial-gradient(ellipse 95% 72% at 96% 28%, rgba(139,92,246,0.26) 0%, transparent 50%)',
        'radial-gradient(ellipse 115% 48% at 50% 108%, rgba(12,3,22,0.92) 0%, transparent 56%)',
        'linear-gradient(168deg, #160a22 0%, #0c0614 42%, #07030e 100%)',
    ].join(', ')

    /**
     * Brand cinematic background — same depth/atmosphere as Jackpot but using the
     * brand's primary/secondary as spotlight colors. Used when a branded URL is visited
     * (e.g. /gateway?brand=nebo) so the login form sits in the brand's visual identity.
     */
    const brandCinematicBg = useMemo(() => {
        if (jackpotPick) return null
        const p = primary   // already resolved from theme
        const s = secondary
        // Convert hex to rgba helper for inline use
        const hex2rgba = (hex, a) => {
            const h = hex.replace('#', '')
            const r = parseInt(h.slice(0, 2), 16)
            const g = parseInt(h.slice(2, 4), 16)
            const b = parseInt(h.slice(4, 6), 16)
            return `rgba(${r},${g},${b},${a})`
        }
        return [
            `radial-gradient(ellipse 130% 92% at 50% -14%, ${hex2rgba(p, 0.36)} 0%, ${hex2rgba(p, 0.14)} 32%, transparent 58%)`,
            `radial-gradient(ellipse 100% 78% at 4% 72%,  ${hex2rgba(s, 0.32)} 0%, transparent 52%)`,
            `radial-gradient(ellipse 95%  72% at 96% 28%, ${hex2rgba(p, 0.22)} 0%, transparent 50%)`,
            `radial-gradient(ellipse 115% 48% at 50% 108%, rgba(12,3,22,0.92) 0%, transparent 56%)`,
            'linear-gradient(168deg, #0d0a12 0%, #070510 42%, #03020a 100%)',
        ].join(', ')
    }, [jackpotPick, primary, secondary])

    return (
        <div
            className={`min-h-screen text-white relative overflow-hidden transition-colors duration-700 ease-out ${
                brandPreviewActive || !jackpotPick ? 'bg-[#0B0B0D]' : 'bg-[#07030e]'
            }`}
            style={{
                '--gw-primary': primary,
                '--gw-secondary': secondary,
            }}
        >
            {/* Base background — Jackpot cinematic, brand cinematic, or tenant/default theme */}
            <div
                className="fixed inset-0 pointer-events-none"
                style={{
                    background: jackpotPick
                        ? jackpotBg
                        : brandCinematicBg || theme?.background?.value || '#0B0B0D',
                }}
            />

            {/* Brand entry preview — opacity + RGB channels transition between brands */}
            {jackpotPick ? (
                <div
                    className="gateway-brand-ambient fixed inset-0 pointer-events-none z-[1]"
                    style={brandAmbientStyle}
                    aria-hidden
                />
            ) : null}

            {/* Cinematic depth: neutral for tenant themes; violet haze for Jackpot pre-brand shell */}
            <div
                className={`fixed inset-0 pointer-events-none z-[2] transition-opacity duration-700 ease-out ${
                    jackpotPick && brandPreviewActive ? 'opacity-0' : 'opacity-100'
                }`}
            >
                {jackpotPick ? (
                    <>
                        <div className="absolute inset-0 bg-gradient-to-b from-violet-950/45 via-purple-950/15 to-black/80 transition-opacity duration-700 ease-out" />
                        <div className="absolute inset-0 bg-[radial-gradient(ellipse_88%_65%_at_50%_50%,transparent_0%,rgba(0,0,0,0.52)_100%)] transition-opacity duration-700 ease-out" />
                        <div
                            className="absolute inset-0 opacity-[0.5] mix-blend-soft-light transition-opacity duration-700 ease-out"
                            style={{
                                background:
                                    'radial-gradient(ellipse 125% 80% at 50% -8%, rgba(167,139,250,0.22) 0%, transparent 44%), radial-gradient(ellipse 70% 55% at 100% 65%, rgba(124,58,237,0.18) 0%, transparent 48%)',
                            }}
                        />
                    </>
                ) : (
                    <>
                        {/* Vignette + depth for brand/tenant cinematic background */}
                        <div className="absolute inset-0 bg-black/28" />
                        <div className="absolute inset-0 bg-gradient-to-b from-black/22 via-transparent to-black/42" />
                        <div
                            className="absolute inset-0 opacity-40 mix-blend-soft-light"
                            style={{
                                background: `radial-gradient(ellipse 125% 80% at 50% -8%, ${primary}38 0%, transparent 44%), radial-gradient(ellipse 70% 55% at 100% 65%, ${secondary}28 0%, transparent 48%)`,
                            }}
                        />
                    </>
                )}
            </div>

            {/* Content layer */}
            <div className="relative z-10 min-h-screen flex flex-col">
                {/* Header */}
                <div className="absolute top-6 left-6 right-6 flex items-center z-20">
                    {showHeaderLogo ? (
                        <LogoMark size="sm" href={isDefault ? '/?marketing_site=1' : null} forceJackpotWordmark={jackpotPick && !brandPreviewActive} />
                    ) : null}
                </div>

                {/* Main content */}
                <main
                    className={[
                        'flex-1 flex flex-col items-center justify-center px-6',
                        layoutMode === 'lanes'      ? 'pb-10 pt-14 sm:pt-16'    :
                        layoutMode === 'enterprise' ? 'pb-8  pt-12 sm:pt-14'    :
                        'pb-16 pt-20',
                        isDefault ? 'opacity-95' : 'opacity-100',
                    ].join(' ')}
                >
                    {children}
                </main>

                {/* Footer */}
                <footer className="px-8 py-6 text-center">
                    <p className="text-[11px] text-white/25 tracking-widest uppercase">
                        {jackpotPick
                            ? 'Jackpot · Brand asset manager'
                            : singleBrandWithLogo
                              ? 'Brand asset manager'
                              : `${theme?.name || 'Jackpot'} · Brand asset manager`}
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
