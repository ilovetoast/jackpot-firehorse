import { motion } from 'framer-motion'

/**
 * 0–5 readiness as filled / empty dots (compact, no charts).
 * pulseKey: increment to replay a subtle fill animation (e.g. after score improves).
 */
export default function ReadinessScoreDots({ score = 0, max = 5, title, className = '', pulseKey = 0 }) {
    const s = Math.max(0, Math.min(max, Number(score) || 0))
    return (
        <div
            className={`inline-flex items-center gap-1 ${className}`}
            title={title || undefined}
            role="img"
            aria-label={`Readiness ${s} of ${max}`}
        >
            {Array.from({ length: max }).map((_, i) => (
                <motion.span
                    key={`${pulseKey}-${i}`}
                    initial={pulseKey > 0 && i < s ? { scale: 0.85, opacity: 0.6 } : false}
                    animate={{ scale: 1, opacity: 1 }}
                    transition={{ type: 'spring', stiffness: 420, damping: 22, delay: i * 0.04 }}
                    className={`h-2 w-2 rounded-full ${i < s ? 'bg-emerald-400/90' : 'bg-white/15'}`}
                />
            ))}
        </div>
    )
}
