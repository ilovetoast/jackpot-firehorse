import { motion } from 'framer-motion'
import { CheckIcon } from '@heroicons/react/24/solid'
import { SITE_PRIMARY_HEX } from '../../utils/colorUtils'

const STEP_ORDER = ['about', 'company', 'goals', 'review']

const STEP_LABELS = {
    about: 'You',
    company: 'Company',
    goals: 'Goals',
    review: 'Send',
}

/**
 * Onboarding-style step rail for the marketing sales inquiry flow.
 * Matches {@link StepProgressRail} visual language (dots, connectors, brand accent).
 */
export default function SalesInquiryProgressRail({ currentStep, brandColor = SITE_PRIMARY_HEX }) {
    const currentIdx = STEP_ORDER.indexOf(currentStep)

    return (
        <div className="flex items-center justify-center gap-1 sm:gap-2 overflow-x-auto pb-1 -mb-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
            {STEP_ORDER.map((step, idx) => {
                const isComplete = idx < currentIdx
                const isCurrent = idx === currentIdx
                const isLast = idx === STEP_ORDER.length - 1

                return (
                    <div key={step} className="flex items-center gap-1 sm:gap-2">
                        <div className="flex flex-col items-center gap-1.5 min-w-[3rem] sm:min-w-[4rem]">
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
                                animate={{ scale: isCurrent ? 1 : 0.85 }}
                                transition={{ type: 'spring', stiffness: 300, damping: 25 }}
                            >
                                {isComplete ? (
                                    <CheckIcon className="h-3.5 w-3.5 text-white" />
                                ) : isCurrent ? (
                                    <div className="h-2 w-2 rounded-full" style={{ backgroundColor: brandColor }} />
                                ) : (
                                    <div className="h-1.5 w-1.5 rounded-full bg-white/20" />
                                )}
                            </motion.div>

                            <span
                                className={`text-[10px] tracking-wider uppercase font-medium transition-colors duration-300 text-center leading-tight ${
                                    isCurrent ? 'text-white/70' : isComplete ? 'text-white/40' : 'text-white/20'
                                }`}
                            >
                                {STEP_LABELS[step]}
                            </span>
                        </div>

                        {!isLast && (
                            <div
                                className="h-px w-4 sm:w-8 mb-5 shrink-0 transition-colors duration-300"
                                style={{
                                    backgroundColor: isComplete ? `${brandColor}60` : 'rgba(255,255,255,0.08)',
                                }}
                            />
                        )}
                    </div>
                )
            })}
        </div>
    )
}

export const SALES_INQUIRY_STEPS = STEP_ORDER
