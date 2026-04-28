/**
 * @param {{ variant?: 'light' | 'dark' | 'settings' }} props
 * Use variant="dark" on cinematic / full-bleed dark pages (e.g. Creators dashboard).
 * Use variant="settings" on account/company settings: compact legal + copyright only.
 * Light variant uses a transparent background so the page background shows through (no solid white strip).
 */
export default function AppFooter({ variant = 'light' }) {
    const dark = variant === 'dark'
    const settings = variant === 'settings'

    const linkBase = 'underline-offset-2 transition hover:underline'
    const linkClass = dark
        ? `text-white/55 hover:text-white/90 ${linkBase}`
        : settings
          ? `text-slate-400/78 hover:text-slate-500/80 ${linkBase}`
          : `text-slate-500/80 hover:text-slate-600/90 ${linkBase}`

    const copyrightClass = dark
        ? 'text-center text-sm font-medium uppercase tracking-[0.1em] text-white/50'
        : settings
          ? 'text-center text-xs font-medium uppercase tracking-[0.1em] text-slate-400/45'
          : 'text-center text-sm font-medium uppercase tracking-[0.1em] text-slate-500/70'

    const legalClass = dark
        ? 'text-center text-xs font-medium uppercase tracking-[0.08em] text-white/40'
        : settings
          ? 'text-center text-[11px] font-medium leading-snug uppercase tracking-[0.08em] text-slate-400/70'
          : 'text-center text-xs font-medium uppercase tracking-[0.08em] text-slate-500/75'

    return (
        <footer
            className={
                dark
                    ? 'bg-transparent'
                    : 'border-t border-gray-200/50 bg-transparent'
            }
        >
            <div
                className={`mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 space-y-1.5 ${
                    settings ? 'py-3' : 'py-4'
                }`}
            >
                <p className={`${copyrightClass} antialiased`}>
                    <span
                        className={
                            dark ? 'text-white/60' : settings ? 'text-slate-400/78' : 'text-slate-500/90'
                        }
                    >
                        Jackpot
                    </span>{' '}
                    <span
                        className={
                            dark
                                ? 'text-white/45'
                                : settings
                                  ? 'text-slate-400/40'
                                  : 'text-slate-400/65'
                        }
                    >
                        &copy; {new Date().getFullYear()}
                    </span>{' '}
                    <span
                        aria-hidden
                        className={
                            dark
                                ? 'text-white/25'
                                : settings
                                  ? 'text-slate-400/32'
                                  : 'text-slate-400/55'
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
                <p className={`${legalClass} antialiased`}>
                    <a href="/terms" className={linkClass}>
                        Terms
                    </a>
                    <span aria-hidden className="mx-1.5 opacity-40">·</span>
                    <a href="/privacy" className={linkClass}>
                        Privacy
                    </a>
                    <span aria-hidden className="mx-1.5 opacity-40">·</span>
                    <a href="/dpa" className={linkClass}>
                        DPA
                    </a>
                    <span aria-hidden className="mx-1.5 opacity-40">·</span>
                    <a href="/subprocessors" className={linkClass}>
                        Subprocessors
                    </a>
                    <span aria-hidden className="mx-1.5 opacity-40">·</span>
                    <a href="/accessibility" className={linkClass}>
                        Accessibility
                    </a>
                </p>
            </div>
        </footer>
    )
}
