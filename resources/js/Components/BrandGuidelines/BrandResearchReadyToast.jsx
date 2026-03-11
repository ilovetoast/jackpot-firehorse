/**
 * Toast shown when polling detects research_finalized === true.
 * Displays "Brand research is ready" with a CTA to review.
 */
import { useEffect, useState } from 'react'
import { router } from '@inertiajs/react'

export default function BrandResearchReadyToast({ visible, onDismiss, brandId, accentColor = '#06b6d4' }) {
    const [mounted, setMounted] = useState(false)

    useEffect(() => {
        if (visible) setMounted(true)
    }, [visible])

    const handleReview = () => {
        onDismiss?.()
        if (brandId) {
            router.visit(
                typeof route === 'function'
                    ? route('brands.brand-guidelines.builder', { brand: brandId, step: 'research-summary' })
                    : `/app/brands/${brandId}/brand-guidelines/builder?step=research-summary`
            )
        }
    }

    if (!visible || !mounted) return null

    return (
        <div className="fixed bottom-6 left-1/2 -translate-x-1/2 z-50 max-w-md w-full mx-4">
            <div
                className="rounded-xl border border-white/20 bg-[#1a1820] shadow-xl p-4 flex flex-col sm:flex-row items-start sm:items-center gap-3"
                style={{ boxShadow: `0 0 0 1px rgba(255,255,255,0.08), 0 20px 40px -12px rgba(0,0,0,0.5)` }}
            >
                <div className="flex-1 min-w-0">
                    <p className="text-sm font-semibold text-white">Brand research is ready.</p>
                    <p className="text-sm text-white/70 mt-0.5">
                        Review the extracted insights and continue building your guidelines.
                    </p>
                </div>
                <div className="flex items-center gap-2 w-full sm:w-auto">
                    <button
                        type="button"
                        onClick={handleReview}
                        className="flex-1 sm:flex-none px-4 py-2 rounded-lg font-medium text-white text-sm transition-colors"
                        style={{ backgroundColor: accentColor }}
                    >
                        Review Research
                    </button>
                    <button
                        type="button"
                        onClick={onDismiss}
                        className="p-2 rounded-lg text-white/50 hover:text-white/80 hover:bg-white/10 transition-colors"
                        aria-label="Dismiss"
                    >
                        <svg className="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z" />
                        </svg>
                    </button>
                </div>
            </div>
        </div>
    )
}
