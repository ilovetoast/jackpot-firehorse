import { Link } from '@inertiajs/react'
import SlotMachineLogo from './SlotMachineLogo'

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
    /** Optional brand lockup above the headline. `variant: 'inverted'` = light wordmark on dark (no panel). */
    logo,
    /** When true, replaces the static logo with an animated slot-machine reel. */
    slotMachine = false,
}) {
    const alignCls = align === 'left' ? 'text-left items-start' : 'text-center items-center'
    const logoJustify = align === 'left' ? 'justify-start' : 'justify-center'
    const inverted = logo?.variant === 'inverted'

    return (
        <section className={`relative px-6 pt-16 pb-20 sm:pt-20 sm:pb-28 lg:px-8 ${className}`}>
            <div className={`mx-auto max-w-4xl flex flex-col ${alignCls}`}>
                {slotMachine ? (
                    <div className={`mb-10 sm:mb-12 flex w-full ${logoJustify}`}>
                        <SlotMachineLogo className="h-24 sm:h-32 md:h-40 lg:h-48 xl:h-52" />
                    </div>
                ) : logo?.src ? (
                    <div className={`mb-10 sm:mb-12 flex w-full ${logoJustify}`}>
                        {inverted ? (
                            <img
                                src={logo.src}
                                alt={logo.alt ?? 'Jackpot'}
                                className="h-20 w-auto max-w-full sm:h-28 md:h-32 lg:h-40 xl:h-44"
                                decoding="async"
                            />
                        ) : (
                            <div className="inline-flex max-w-full rounded-2xl bg-white px-8 py-6 shadow-xl shadow-black/25 ring-1 ring-black/5 sm:px-12 sm:py-8 lg:px-14 lg:py-10">
                                <img
                                    src={logo.src}
                                    alt={logo.alt ?? 'Jackpot'}
                                    className="h-16 w-auto max-w-full sm:h-20 md:h-24 lg:h-28 xl:h-32"
                                    decoding="async"
                                />
                            </div>
                        )}
                    </div>
                ) : null}
                <h1 className="font-display text-4xl font-bold tracking-tight text-white sm:text-5xl lg:text-6xl text-balance leading-[1.08]">
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
                            className="inline-flex items-center gap-1 text-sm font-semibold text-violet-400 hover:text-violet-300 transition-colors"
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
