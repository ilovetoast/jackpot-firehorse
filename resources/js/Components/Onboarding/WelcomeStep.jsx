import { motion } from 'framer-motion'
import { SparklesIcon, SwatchIcon, PhotoIcon, DocumentTextIcon } from '@heroicons/react/24/outline'
import { getContrastTextColor, ensureDarkModeContrast } from '../../utils/colorUtils'

const SETUP_ITEMS = [
    { icon: SwatchIcon, label: 'Brand basics', desc: 'Name, mark, and colors' },
    { icon: PhotoIcon, label: 'First assets', desc: 'Upload a few core files' },
    { icon: DocumentTextIcon, label: 'Guidelines', desc: "Optional — we'll process them" },
]

export default function WelcomeStep({ brandName, brandColor = '#6366f1', isAgencyCreated = false, onStart, onDismiss }) {
    // The incoming brandColor has already been passed through ensureDarkModeContrast for
    // chrome on the dark shell. For the solid CTA button we need a second safety net so
    // very light brand primaries (near-white) don't end up as a white button with white
    // text. ensureDarkModeContrast with a higher min ratio clamps toward a readable tone,
    // and getContrastTextColor picks black/white text against that final surface.
    const accent = ensureDarkModeContrast(brandColor, '#6366f1', 4.5)
    const buttonTextColor = getContrastTextColor(accent)

    const headline = isAgencyCreated
        ? `Finish setting up ${brandName || 'your workspace'}`
        : `Let's set up ${brandName || 'your workspace'}`

    const subtitle = isAgencyCreated
        ? "Your team created the workspace — now let's finish the brand shell. A few essentials are still needed before your library opens."
        : 'A quick setup — about 3 minutes for the essentials. You can always refine things later.'

    return (
        <motion.div
            initial={{ opacity: 0, y: 16 }}
            animate={{ opacity: 1, y: 0 }}
            exit={{ opacity: 0, y: -12 }}
            transition={{ duration: 0.4 }}
            className="flex flex-col items-center text-center max-w-lg mx-auto"
        >
            <div
                className="h-16 w-16 rounded-2xl flex items-center justify-center mb-8"
                style={{
                    background: `linear-gradient(135deg, ${accent}30, ${accent}12)`,
                    boxShadow: `0 0 48px ${accent}18`,
                }}
            >
                <SparklesIcon className="h-7 w-7" style={{ color: accent }} />
            </div>

            <h1 className="text-3xl sm:text-4xl font-semibold tracking-tight text-white/95 leading-tight">
                {headline}
            </h1>
            <p className="mt-3 text-base text-white/45 leading-relaxed max-w-md">
                {subtitle}
            </p>

            <div className="mt-10 w-full max-w-sm space-y-3">
                {SETUP_ITEMS.map((item, idx) => (
                    <motion.div
                        key={item.label}
                        initial={{ opacity: 0, x: -8 }}
                        animate={{ opacity: 1, x: 0 }}
                        transition={{ delay: 0.2 + idx * 0.1, duration: 0.3 }}
                        className="flex items-center gap-4 rounded-xl px-4 py-3 bg-white/[0.04] border border-white/[0.06]"
                    >
                        <div
                            className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg"
                            style={{ backgroundColor: `${accent}20` }}
                        >
                            <item.icon className="h-5 w-5" style={{ color: accent }} />
                        </div>
                        <div className="text-left">
                            <p className="text-sm font-medium text-white/80">{item.label}</p>
                            <p className="text-xs text-white/35">{item.desc}</p>
                        </div>
                    </motion.div>
                ))}
            </div>

            <div className="mt-10 flex flex-col items-center gap-3">
                <button
                    type="button"
                    onClick={onStart}
                    className="px-8 py-3.5 rounded-xl text-base font-semibold transition-all duration-300 hover:brightness-110"
                    style={{
                        background: `linear-gradient(135deg, ${accent}, ${accent}dd)`,
                        boxShadow: `0 4px 24px ${accent}30`,
                        color: buttonTextColor,
                    }}
                >
                    {isAgencyCreated ? 'Continue setup' : 'Start setup'}
                </button>
                {onDismiss && (
                    <button
                        type="button"
                        onClick={onDismiss}
                        className="text-sm text-white/30 hover:text-white/50 transition-colors duration-200"
                    >
                        Finish later
                    </button>
                )}
            </div>
        </motion.div>
    )
}
