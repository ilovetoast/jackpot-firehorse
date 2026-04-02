import { Link } from '@inertiajs/react'

/**
 * Large-type hero with optional secondary link.
 */
export default function HeroSection({
    title,
    subtitle,
    primaryCta,
    secondaryCta,
    align = 'center',
    className = '',
}) {
    const alignCls = align === 'left' ? 'text-left items-start' : 'text-center items-center'

    return (
        <section className={`relative px-6 pt-16 pb-20 sm:pt-20 sm:pb-28 lg:px-8 ${className}`}>
            <div className={`mx-auto max-w-4xl flex flex-col ${alignCls}`}>
                <h1 className="text-4xl font-bold tracking-tight text-white sm:text-5xl lg:text-6xl text-balance leading-[1.08]">
                    {title}
                </h1>
                {subtitle && (
                    <p className="mt-6 max-w-2xl text-lg sm:text-xl leading-relaxed text-white/55 text-pretty">{subtitle}</p>
                )}
                <div className={`mt-10 flex flex-wrap gap-4 ${align === 'center' ? 'justify-center' : ''}`}>
                    {primaryCta && (
                        <Link
                            href={primaryCta.href}
                            className="inline-flex items-center justify-center rounded-xl bg-white px-6 py-3 text-sm font-semibold text-gray-900 shadow-lg shadow-black/20 hover:bg-gray-100 transition-colors"
                        >
                            {primaryCta.text}
                        </Link>
                    )}
                    {secondaryCta && (
                        <Link
                            href={secondaryCta.href}
                            className="inline-flex items-center gap-1 text-sm font-semibold text-indigo-400 hover:text-indigo-300 transition-colors"
                        >
                            {secondaryCta.text}
                            <span aria-hidden="true">→</span>
                        </Link>
                    )}
                </div>
            </div>
        </section>
    )
}
