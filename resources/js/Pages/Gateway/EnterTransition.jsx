import { useState, useEffect } from 'react'
import { usePage } from '@inertiajs/react'

const DESTINATION_ROUTES = {
    assets: '/app/overview',
    guidelines: '/app/brand-guidelines',
    collections: '/app/collections',
}

export default function EnterTransition() {
    const { theme } = usePage().props
    const [stage, setStage] = useState('init')

    const portal = theme?.portal?.entry || {}
    const isInstant = portal.style === 'instant'
    const destination = DESTINATION_ROUTES[portal.default_destination] || '/app/overview'

    useEffect(() => {
        if (isInstant) {
            // Instant mode: quick 150ms fade-out, no progress bar — feels intentional, not jarring
            const t1 = setTimeout(() => setStage('fade'), 10)
            const t2 = setTimeout(() => {
                window.location.href = destination
            }, 160)
            return () => { clearTimeout(t1); clearTimeout(t2) }
        }

        const t1 = setTimeout(() => setStage('enter'), 100)
        const t2 = setTimeout(() => setStage('zoom'), 1200)
        const t3 = setTimeout(() => {
            window.location.href = destination
        }, 2800)
        return () => { clearTimeout(t1); clearTimeout(t2); clearTimeout(t3) }
    }, [isInstant, destination])

    const primary = theme?.colors?.primary || '#6366f1'

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
                    <div
                        className="h-12 w-12 rounded-xl flex items-center justify-center mb-3"
                        style={{ background: `linear-gradient(135deg, ${primary}CC, ${primary}55)` }}
                    >
                        <span className="text-lg font-bold text-white">
                            {theme?.name?.charAt(0) || 'J'}
                        </span>
                    </div>
                    <p className="text-xs text-white/40 tracking-wide">
                        Entering {theme?.name || 'Jackpot'}
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
                    {theme?.logo ? (
                        <img
                            src={theme.logo}
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

                <h1
                    className="text-5xl md:text-6xl font-semibold tracking-tight leading-tight text-white/95 mb-2 transition-opacity duration-500"
                    style={{ opacity: stage !== 'init' ? 1 : 0 }}
                >
                    {theme?.name || 'JACKPOT'}
                </h1>

                <p
                    className="text-lg text-white/60 mb-2 transition-opacity duration-500 delay-100"
                    style={{ opacity: stage !== 'init' ? 1 : 0 }}
                >
                    {theme?.tagline || 'Brand Operating System'}
                </p>

                <p
                    className="text-xs text-white/40 mb-10 transition-opacity duration-500 delay-150 tracking-wide"
                    style={{ opacity: stage !== 'init' ? 1 : 0 }}
                >
                    Entering {theme?.name || 'Jackpot'}
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
