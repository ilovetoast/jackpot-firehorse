import { useState, useEffect } from 'react'
import { usePage } from '@inertiajs/react'

const DESTINATION_ROUTES = {
    assets: '/app/overview',
    guidelines: '/app/brand-guidelines',
    collections: '/app/collections',
}

export default function EnterTransition({ suppressAutoRedirect = false }) {
    const { theme } = usePage().props
    const [stage, setStage] = useState('init')

    const portal = theme?.portal?.entry || {}
    const isInstant = portal.style === 'instant'
    const destination = DESTINATION_ROUTES[portal.default_destination] || '/app/overview'

    useEffect(() => {
        if (suppressAutoRedirect) {
            return undefined
        }
        if (isInstant) {
            // Instant mode: quick 150ms fade-out, no progress bar — feels intentional, not jarring
            const t1 = setTimeout(() => setStage('fade'), 10)
            const t2 = setTimeout(() => {
                window.location.href = destination
            }, 160)
            return () => {
                clearTimeout(t1)
                clearTimeout(t2)
            }
        }

        const t1 = setTimeout(() => setStage('enter'), 100)
        const t2 = setTimeout(() => setStage('zoom'), 1200)
        const t3 = setTimeout(() => {
            window.location.href = destination
        }, 2800)
        return () => {
            clearTimeout(t1)
            clearTimeout(t2)
            clearTimeout(t3)
        }
    }, [isInstant, destination, suppressAutoRedirect])

    const primary = theme?.colors?.primary || '#7c3aed'
    const isJackpotDefault = theme?.mode === 'default'
    const singleBrand = Boolean(theme?.single_brand_tenant)
    /** Entry screen is always a dark surface — match {@link LogoMark}: prefer dark variant, then primary. */
    const entryLogoSrc = theme?.logo_dark || theme?.logo || null
    const hasLogo = Boolean(entryLogoSrc)
    const compactIdentity = singleBrand && !isJackpotDefault

    // Instant mode: minimal fade with brand presence
    if (isInstant) {
        return (
            <div
                className={`relative flex items-center justify-center text-center transition-opacity duration-150 ease-out ${
                    stage === 'fade' ? 'opacity-0' : 'opacity-100'
                }`}
                style={{ background: theme?.background?.value || '#0B0B0D' }}
            >
                <div className="flex flex-col items-center">
                    {isJackpotDefault ? (
                        <img
                            src="/jp-wordmark-inverted.svg"
                            alt="Jackpot"
                            className="h-8 w-auto max-w-[min(100%,14rem)] mb-3"
                            decoding="async"
                        />
                    ) : compactIdentity && hasLogo ? (
                        <img
                            src={entryLogoSrc}
                            alt={theme.name || 'Brand'}
                            className="h-10 w-auto max-w-[min(100%,16rem)] mb-3 object-contain"
                            decoding="async"
                        />
                    ) : compactIdentity && !hasLogo ? (
                        <p className="text-lg font-semibold tracking-tight text-white/95 mb-3">{theme?.name || 'Jackpot'}</p>
                    ) : (
                        <div
                            className="h-12 w-12 rounded-xl flex items-center justify-center mb-3"
                            style={{ background: `linear-gradient(135deg, ${primary}CC, ${primary}55)` }}
                        >
                            {entryLogoSrc ? (
                                <img src={entryLogoSrc} alt={theme.name} className="h-7 object-contain" />
                            ) : (
                                <span className="text-lg font-bold text-white">
                                    {theme?.name?.charAt(0) || 'J'}
                                </span>
                            )}
                        </div>
                    )}
                    <p className="text-xs text-white/40 tracking-wide">
                        {compactIdentity && hasLogo ? 'Entering workspace' : `Entering ${theme?.name || 'Jackpot'}`}
                    </p>
                </div>
            </div>
        )
    }

    return (
        <div className="relative flex flex-col items-center justify-center text-center">
            {/* Independent background zoom layer */}
            <div
                className={`absolute inset-0 -m-32 transition-transform duration-[2000ms] ease-out ${stage === 'zoom' ? 'scale-110' : 'scale-100'}`}
                style={{ background: theme?.background?.value || '#0B0B0D' }}
            />

            {/* Content layer */}
            <div
                className={[
                    'relative z-10 flex flex-col items-center justify-center text-center',
                    'transition-all duration-700 ease-out',
                    stage === 'init' ? 'opacity-0 translate-y-6' : '',
                    stage === 'enter' ? 'opacity-100 translate-y-0' : '',
                    stage === 'zoom' ? 'opacity-100 translate-y-0' : '',
                ].join(' ')}
            >
                {/* Logo / name with scale animation */}
                <div
                    className="transition-all duration-700 ease-out"
                    style={{
                        transform: stage === 'zoom' ? 'scale(1.05)' : 'scale(0.95)',
                        opacity: stage !== 'init' ? 1 : 0,
                    }}
                >
                    {isJackpotDefault ? (
                        <img
                            src="/jp-wordmark-inverted.svg"
                            alt="Jackpot"
                            className="h-16 w-auto max-w-full sm:h-20 md:h-24 lg:h-28 mx-auto mb-6 object-contain"
                            decoding="async"
                        />
                    ) : compactIdentity && hasLogo ? (
                        <img
                            src={entryLogoSrc}
                            alt={theme.name || 'Brand'}
                            className="h-24 md:h-36 lg:h-44 w-auto max-w-[min(100%,28rem)] object-contain mx-auto mb-6"
                            decoding="async"
                        />
                    ) : compactIdentity && !hasLogo ? (
                        <h1
                            className="text-5xl md:text-6xl font-semibold tracking-tight leading-tight text-white/95 mb-6 transition-opacity duration-500"
                            style={{ opacity: stage !== 'init' ? 1 : 0 }}
                        >
                            {theme?.name || 'JACKPOT'}
                        </h1>
                    ) : entryLogoSrc ? (
                        <img
                            src={entryLogoSrc}
                            alt={theme.name}
                            className="h-20 md:h-28 w-auto object-contain mx-auto mb-6"
                        />
                    ) : (
                        <div className="mb-6 flex items-center justify-center">
                            <div
                                className="h-20 w-20 md:h-24 md:w-24 rounded-2xl flex items-center justify-center"
                                style={{ background: `linear-gradient(135deg, ${primary}CC, ${primary}55)` }}
                            >
                                <span className="text-3xl md:text-4xl font-bold text-white">
                                    {theme?.name?.charAt(0) || 'J'}
                                </span>
                            </div>
                        </div>
                    )}
                </div>

                {!isJackpotDefault && !(compactIdentity && hasLogo) && (
                    <h1
                        className="text-5xl md:text-6xl font-semibold tracking-tight leading-tight text-white/95 mb-2 transition-opacity duration-500"
                        style={{ opacity: stage !== 'init' ? 1 : 0 }}
                    >
                        {theme?.name || 'JACKPOT'}
                    </h1>
                )}

                {!(compactIdentity && hasLogo) && (
                    <p
                        className="text-lg text-white/60 mb-2 transition-opacity duration-500 delay-100"
                        style={{ opacity: stage !== 'init' ? 1 : 0 }}
                    >
                        {theme?.tagline || 'Brand asset manager'}
                    </p>
                )}

                <p
                    className="text-xs text-white/40 mb-10 transition-opacity duration-500 delay-150 tracking-wide"
                    style={{ opacity: stage !== 'init' ? 1 : 0 }}
                >
                    {compactIdentity && hasLogo ? 'Entering workspace' : `Entering ${theme?.name || 'Jackpot'}`}
                </p>

                {/* Progress bar */}
                <div className="w-48 h-1 bg-white/10 rounded overflow-hidden">
                    <div
                        className="h-full transition-all duration-1000 ease-out"
                        style={{
                            width: stage === 'zoom' ? '100%' : '0%',
                            backgroundColor: primary,
                        }}
                    />
                </div>
            </div>
        </div>
    )
}
