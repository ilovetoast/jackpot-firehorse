import { motion } from 'framer-motion'
import { CheckIcon } from '@heroicons/react/24/solid'

const STEP_LABELS = {
    welcome: 'Welcome',
    brand_shell: 'Brand',
    starter_assets: 'Assets',
    categories: 'Organize',
    enrichment: 'Enrich',
    complete: 'Ready',
}

const STEP_ORDER = ['welcome', 'brand_shell', 'starter_assets', 'categories', 'enrichment', 'complete']

function stepIndex(key) {
    return STEP_ORDER.indexOf(key)
}

export default function StepProgressRail({ currentStep, brandColor = '#6366f1' }) {
    const currentIdx = stepIndex(currentStep)

    return (
        <div className="flex items-center justify-center gap-1 sm:gap-2">
            {STEP_ORDER.map((step, idx) => {
                const isComplete = idx < currentIdx
                const isCurrent = idx === currentIdx
                const isFuture = idx > currentIdx

                return (
                    <div key={step} className="flex items-center gap-1 sm:gap-2">
                        {/* Dot / pill */}
                        <div className="flex flex-col items-center gap-1.5">
                            <motion.div
                                className="relative flex items-center justify-center rounded-full transition-all duration-300"
                                style={{
                                    width: isCurrent ? 32 : 24,
                                    height: isCurrent ? 32 : 24,
                                    backgroundColor: isComplete
                                        ? brandColor
                                        : isCurrent
                                            ? `${brandColor}25`
                                            : 'rgba(255,255,255,0.06)',
                                    border: isCurrent ? `2px solid ${brandColor}` : 'none',
                                    boxShadow: isCurrent ? `0 0 16px ${brandColor}30` : 'none',
                                }}
                                animate={{
                                    scale: isCurrent ? 1 : 0.85,
                                }}
                                transition={{ type: 'spring', stiffness: 300, damping: 25 }}
                            >
                                {isComplete ? (
                                    <CheckIcon className="h-3.5 w-3.5 text-white" />
                                ) : isCurrent ? (
                                    <div
                                        className="h-2 w-2 rounded-full"
                                        style={{ backgroundColor: brandColor }}
                                    />
                                ) : (
                                    <div className="h-1.5 w-1.5 rounded-full bg-white/20" />
                                )}
                            </motion.div>

                            <span
                                className={`text-[10px] tracking-wider uppercase font-medium transition-colors duration-300 ${
                                    isCurrent ? 'text-white/70' : isComplete ? 'text-white/40' : 'text-white/20'
                                }`}
                            >
                                {STEP_LABELS[step]}
                            </span>
                        </div>

                        {/* Connector line */}
                        {idx < STEP_ORDER.length - 1 && (
                            <div
                                className="h-px w-6 sm:w-10 mb-5 transition-colors duration-300"
                                style={{
                                    backgroundColor: isComplete
                                        ? `${brandColor}60`
                                        : 'rgba(255,255,255,0.08)',
                                }}
                            />
                        )}
                    </div>
                )
            })}
        </div>
    )
}
