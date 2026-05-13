import JackpotQuietFooterMark from './JackpotQuietFooterMark'

/**
 * @param {{
 *   variant?: 'light' | 'dark' | 'settings'
 *   showWordmark?: boolean
 *   showLegalLinks?: boolean
 *   className?: string
 * }} props
 * Use variant="dark" on cinematic / full-bleed dark pages (e.g. Creators dashboard, Agency overview).
 * Use variant="settings" on account/company settings: compact legal + copyright only.
 * Light variant uses a transparent background so the page background shows through (no solid white strip).
 * Legal links default on for light/settings (authenticated manage surfaces); default off for dark unless you pass showLegalLinks.
 */
export default function AppFooter({
    variant = 'light',
    showWordmark = true,
    showLegalLinks: showLegalLinksProp,
    className = '',
}) {
    const dark = variant === 'dark'
    const settings = variant === 'settings'
    const showLegalLinks = showLegalLinksProp !== undefined ? showLegalLinksProp : !dark

    const linkBase = 'underline-offset-2 transition hover:underline'
    const linkClass = dark
        ? `text-white/45 hover:text-white/75 ${linkBase}`
        : settings
          ? `text-slate-400/55 hover:text-slate-500/75 ${linkBase}`
          : `text-slate-400/50 hover:text-slate-500/70 ${linkBase}`

    const copyrightClass = dark
        ? 'text-center text-[10px] font-normal uppercase tracking-[0.14em] text-white/38'
        : settings
          ? 'text-center text-[10px] font-normal uppercase tracking-[0.12em] text-slate-400/40'
          : 'text-center text-[10px] font-normal uppercase tracking-[0.12em] text-slate-400/45'

    const legalClass = dark
        ? 'text-center text-[10px] font-normal uppercase tracking-[0.1em] text-white/32'
        : settings
          ? 'text-center text-[10px] font-normal leading-snug uppercase tracking-[0.1em] text-slate-400/38'
          : 'text-center text-[10px] font-normal uppercase tracking-[0.1em] text-slate-400/40'

    return (
        <footer
            className={`${dark ? 'bg-transparent' : 'border-t border-gray-200/35 bg-transparent'} ${className}`.trim()}
        >
            <div
                className={`mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-2.5 ${showWordmark ? 'space-y-1' : 'space-y-0.5'}`}
            >
                {showWordmark ? (
                    <JackpotQuietFooterMark surface={dark ? 'dark' : 'light'} />
                ) : null}
                <p className={`${copyrightClass} antialiased`}>
                    <span
                        className={
                            dark ? 'text-white/45' : settings ? 'text-slate-400/50' : 'text-slate-400/55'
                        }
                    >
                        Jackpot
                    </span>{' '}
                    <span
                        className={
                            dark
                                ? 'text-white/35'
                                : settings
                                  ? 'text-slate-400/38'
                                  : 'text-slate-400/42'
                        }
                    >
                        &copy; {new Date().getFullYear()}
                    </span>{' '}
                    <span
                        aria-hidden
                        className={
                            dark
                                ? 'text-white/22'
                                : settings
                                  ? 'text-slate-400/28'
                                  : 'text-slate-400/32'
                        }
                    >
                        ·
                    </span>{' '}
                    <a
                        href="https://velvetysoft.com"
                        target="_blank"
                        rel="noopener noreferrer"
                        className={linkClass}
                    >
                        Velvetysoft
                    </a>
                </p>
                {showLegalLinks ? (
                    <p className={`${legalClass} antialiased`}>
                        <a href="/terms" className={linkClass}>
                            Terms
                        </a>
                        <span aria-hidden className="mx-1 opacity-30">
                            ·
                        </span>
                        <a href="/privacy" className={linkClass}>
                            Privacy
                        </a>
                        <span aria-hidden className="mx-1 opacity-30">
                            ·
                        </span>
                        <a href="/dpa" className={linkClass}>
                            DPA
                        </a>
                        <span aria-hidden className="mx-1 opacity-30">
                            ·
                        </span>
                        <a href="/subprocessors" className={linkClass}>
                            Subprocessors
                        </a>
                        <span aria-hidden className="mx-1 opacity-30">
                            ·
                        </span>
                        <a href="/accessibility" className={linkClass}>
                            Accessibility
                        </a>
                    </p>
                ) : null}
            </div>
        </footer>
    )
}
