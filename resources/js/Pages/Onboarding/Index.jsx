import { useState, useCallback, useMemo } from 'react'
import { Head, router, usePage } from '@inertiajs/react'
import { motion, AnimatePresence } from 'framer-motion'
import FilmGrainOverlay from '../../Components/FilmGrainOverlay'
import StepProgressRail from '../../Components/Onboarding/StepProgressRail'
import WelcomeStep from '../../Components/Onboarding/WelcomeStep'
import BrandShellStep from '../../Components/Onboarding/BrandShellStep'
import CategorySelectionStep from '../../Components/Onboarding/CategorySelectionStep'
import EnrichmentStep from '../../Components/Onboarding/EnrichmentStep'
import CompletionStep from '../../Components/Onboarding/CompletionStep'
import { workspaceOverviewBackdropCss, ensureDarkModeContrast } from '../../utils/colorUtils'

const STEPS = ['welcome', 'brand_shell', 'categories', 'enrichment', 'complete']

function stepIndex(key) {
    return STEPS.indexOf(key)
}

async function postJson(url, data) {
    const res = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
        },
        body: JSON.stringify(data),
    })
    if (!res.ok) throw new Error(`${res.status}`)
    return res.json()
}

function normalizeStep(step) {
    // Legacy progress rows may still be parked on the removed starter_assets
    // step — nudge them straight to the next live step.
    if (step === 'starter_assets') return 'categories'
    return step || 'welcome'
}

export default function OnboardingIndex({ brand: initialBrand, progress: initialProgress, categories: initialCategories = [] }) {
    const { auth } = usePage().props
    const [currentStep, setCurrentStep] = useState(normalizeStep(initialProgress?.current_step))
    const [brand, setBrand] = useState(initialBrand)
    const [progress, setProgress] = useState(initialProgress)

    // Live preview colors — updated in real-time as user picks colors in BrandShellStep
    const [liveColors, setLiveColors] = useState({
        primary: initialBrand?.primary_color || null,
        secondary: initialBrand?.secondary_color || null,
        accent: initialBrand?.accent_color || null,
    })

    const JACKPOT_PRIMARY = '#6366f1'
    const JACKPOT_SECONDARY = '#8b5cf6'

    const rawPrimary = liveColors.primary || brand?.primary_color || null
    const rawSecondary = liveColors.secondary || brand?.secondary_color || null
    const rawAccent = liveColors.accent || brand?.accent_color || null

    // Pick the best brand color for UI chrome on the dark cinematic shell.
    // ensureDarkModeContrast lightens dark colors or falls back to site indigo for near-blacks.
    // We try primary first — if it's too dark, try secondary, then accent, then site default.
    const accentColor = useMemo(() => {
        if (!rawPrimary) return JACKPOT_PRIMARY
        const safe = ensureDarkModeContrast(rawPrimary, JACKPOT_PRIMARY, 3)
        // If primary was adjusted beyond recognition (near-black achromatic), try secondary/accent
        if (safe === '#6366f1' && rawPrimary) {
            if (rawSecondary) {
                const sec = ensureDarkModeContrast(rawSecondary, JACKPOT_PRIMARY, 3)
                if (sec !== '#6366f1') return sec
            }
            if (rawAccent) {
                const acc = ensureDarkModeContrast(rawAccent, JACKPOT_PRIMARY, 3)
                if (acc !== '#6366f1') return acc
            }
        }
        return safe
    }, [rawPrimary, rawSecondary, rawAccent])

    const backdropPrimary = rawPrimary || JACKPOT_PRIMARY
    const backdropSecondary = rawSecondary || rawAccent || JACKPOT_SECONDARY
    const backdropAccent = rawAccent || null

    const isAgencyCreated = progress?.is_agency_created || false

    const handleColorsChange = useCallback((colors) => {
        setLiveColors(prev => ({ ...prev, ...colors }))
    }, [])

    const backdropBackground = useMemo(
        () => workspaceOverviewBackdropCss(backdropPrimary, backdropSecondary, backdropAccent),
        [backdropPrimary, backdropSecondary, backdropAccent],
    )

    const goToStep = useCallback((step) => {
        setCurrentStep(step)
        window.scrollTo(0, 0)
    }, [])

    const handleStart = useCallback(() => {
        goToStep('brand_shell')
    }, [goToStep])

    const [logoFetchUrl, setLogoFetchUrl] = useState(null)

    const handleBrandShellSave = useCallback(async (data) => {
        if (data.logo_fetch_url) setLogoFetchUrl(data.logo_fetch_url)
        const result = await postJson('/app/onboarding/brand-shell', data)
        if (result.progress) setProgress(result.progress)
        if (result.brand) setBrand(prev => ({ ...prev, ...result.brand }))
        goToStep('categories')
    }, [goToStep])

    const handleCategoriesSave = useCallback(async (data) => {
        const res = await fetch('/app/onboarding/category-preferences', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
            body: JSON.stringify(data),
        })
        if (res.ok) {
            const result = await res.json()
            if (result.progress) setProgress(result.progress)
        }
        goToStep('enrichment')
    }, [goToStep])

    const handleCategoriesSkip = useCallback(() => {
        goToStep('enrichment')
    }, [goToStep])

    const handleEnrichmentSave = useCallback(async (data) => {
        const result = await postJson('/app/onboarding/enrichment', data)
        if (result.progress) setProgress(result.progress)
        goToStep('complete')
    }, [goToStep])

    const handleEnrichmentSkip = useCallback(async () => {
        const result = await postJson('/app/onboarding/enrichment', {})
        if (result.progress) setProgress(result.progress)
        goToStep('complete')
    }, [goToStep])

    // Activation: marks minimum setup done, exits cinematic flow
    const handleActivate = useCallback(async () => {
        await postJson('/app/onboarding/activate', {})
        router.visit('/app/overview')
    }, [])

    // "Finish later" — exit cinematic flow to Overview (blocking card still shows there)
    const handleDismiss = useCallback(async () => {
        await postJson('/app/onboarding/dismiss', {})
        router.visit('/app/overview')
    }, [])

    const handleBack = useCallback((fromStep) => {
        const idx = stepIndex(fromStep)
        if (idx > 0) {
            goToStep(STEPS[idx - 1])
        }
    }, [goToStep])

    const showProgressRail = currentStep !== 'welcome'

    return (
        <div className="min-h-screen bg-[#0B0B0D] text-white relative overflow-hidden">
            <Head title="Set Up Your Workspace" />

            <div
                className="fixed inset-0 pointer-events-none will-change-transform transition-[background] duration-700"
                style={{ background: backdropBackground }}
            />
            <div
                className="fixed inset-0 pointer-events-none transition-[background] duration-700"
                style={{
                    background: `radial-gradient(circle at 30% 40%, ${backdropPrimary}14, transparent 60%)`,
                }}
            />
            <div className="fixed inset-0 pointer-events-none">
                <div className="absolute inset-0 bg-black/30" />
                <div className="absolute inset-0 bg-gradient-to-b from-black/20 via-transparent to-black/50" />
            </div>

            <div className="relative z-10 min-h-screen flex flex-col">
                <div className="px-6 py-5 flex items-center justify-between">
                    <img
                        src="/jp-wordmark-inverted.svg"
                        alt="Jackpot"
                        className="h-8 w-auto brightness-0 invert"
                        decoding="async"
                    />
                    {progress && !progress.is_activated && currentStep !== 'complete' && (
                        <span className="text-xs text-white/25">
                            {progress.activation_percent}% complete
                        </span>
                    )}
                </div>

                {showProgressRail && (
                    <motion.div
                        initial={{ opacity: 0, y: -8 }}
                        animate={{ opacity: 1, y: 0 }}
                        transition={{ duration: 0.3 }}
                        className="px-6 pb-4"
                    >
                        <StepProgressRail currentStep={currentStep} brandColor={accentColor} />
                    </motion.div>
                )}

                <main className="flex-1 flex flex-col items-center justify-center px-6 pb-16">
                    <div className="w-full max-w-4xl">
                        <AnimatePresence mode="wait">
                            {currentStep === 'welcome' && (
                                <WelcomeStep
                                    key="welcome"
                                    brandName={brand?.name}
                                    brandColor={accentColor}
                                    isAgencyCreated={isAgencyCreated}
                                    onStart={handleStart}
                                    onDismiss={handleDismiss}
                                />
                            )}

                            {currentStep === 'brand_shell' && (
                                <BrandShellStep
                                    key="brand_shell"
                                    brand={brand}
                                    brandColor={accentColor}
                                    progress={progress}
                                    onSave={handleBrandShellSave}
                                    onBack={() => handleBack('brand_shell')}
                                    onColorsChange={handleColorsChange}
                                />
                            )}

                            {currentStep === 'categories' && (
                                <CategorySelectionStep
                                    key="categories"
                                    brandColor={accentColor}
                                    categories={initialCategories}
                                    onSave={handleCategoriesSave}
                                    onBack={() => handleBack('categories')}
                                    onSkip={handleCategoriesSkip}
                                />
                            )}

                            {currentStep === 'enrichment' && (
                                <EnrichmentStep
                                    key="enrichment"
                                    brandColor={accentColor}
                                    initialWebsiteUrl={logoFetchUrl}
                                    onSave={handleEnrichmentSave}
                                    onBack={() => handleBack('enrichment')}
                                    onSkip={handleEnrichmentSkip}
                                />
                            )}

                            {currentStep === 'complete' && (
                                <CompletionStep
                                    key="complete"
                                    brandName={brand?.name}
                                    brandColor={accentColor}
                                    progress={progress}
                                    onFinish={handleActivate}
                                />
                            )}
                        </AnimatePresence>
                    </div>
                </main>

                <footer className="px-8 py-5 text-center">
                    <p className="text-[11px] text-white/15 tracking-widest uppercase">
                        Jackpot &middot; Brand Operating System
                    </p>
                </footer>
            </div>

            <FilmGrainOverlay />
        </div>
    )
}
