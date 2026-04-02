import { Link } from '@inertiajs/react'

/**
 * Full-width CTA band with soft gradient (Stripe-style emphasis).
 */
export default function CTASection({ title, subtitle, primaryCta, secondaryCta, className = '' }) {
    return (
        <section className={`relative border-t border-white/[0.06] ${className}`}>
            <div className="absolute inset-0 bg-gradient-to-r from-indigo-950/40 via-violet-950/30 to-emerald-950/20 pointer-events-none" aria-hidden />
            <div className="relative mx-auto max-w-4xl px-6 py-24 sm:py-28 lg:px-8 text-center">
                <h2 className="text-3xl font-bold tracking-tight text-white sm:text-4xl lg:text-5xl text-balance">{title}</h2>
                {subtitle && <p className="mx-auto mt-5 max-w-xl text-lg text-white/50">{subtitle}</p>}
                <div className="mt-10 flex flex-wrap items-center justify-center gap-4">
                    {primaryCta && (
                        <Link
                            href={primaryCta.href}
                            className="inline-flex rounded-xl bg-white px-6 py-3 text-sm font-semibold text-gray-900 shadow-lg hover:bg-gray-100 transition-colors"
                        >
                            {primaryCta.text}
                        </Link>
                    )}
                    {secondaryCta && (
                        <Link href={secondaryCta.href} className="text-sm font-semibold text-white/70 hover:text-white transition-colors">
                            {secondaryCta.text} →
                        </Link>
                    )}
                </div>
            </div>
        </section>
    )
}
