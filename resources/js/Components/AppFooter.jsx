import { Link } from '@inertiajs/react'

/**
 * @param {{ variant?: 'light' | 'dark' | 'settings' }} props
 * Use variant="dark" on cinematic / full-bleed dark pages (e.g. Creators dashboard).
 * Use variant="settings" on account/company settings: smaller legal line + small Jackpot mark linking home.
 * Light variant uses a transparent background so the page background shows through (no solid white strip).
 */
export default function AppFooter({ variant = 'light' }) {
    const dark = variant === 'dark'
    const settings = variant === 'settings'

    const linkBase = 'underline-offset-2 transition hover:underline'
    const linkClass = dark
        ? `text-white/50 hover:text-white/85 ${linkBase}`
        : `text-gray-600 hover:text-gray-900 ${linkBase}`

    const copyrightClass = dark
        ? 'text-center text-sm text-white/45'
        : settings
          ? 'text-center text-xs text-gray-500'
          : 'text-center text-sm text-gray-500'

    const legalClass = dark
        ? 'text-center text-xs text-white/35'
        : settings
          ? 'text-center text-[11px] leading-snug text-gray-400'
          : 'text-center text-xs text-gray-400'

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
                <p className={copyrightClass}>
                    <span className={dark ? 'text-white/55' : undefined}>Jackpot</span> &copy;{' '}
                    {new Date().getFullYear()} -{' '}
                    <a
                        href="https://velvetysoft.com"
                        target="_blank"
                        rel="noopener noreferrer"
                        className={linkClass}
                    >
                        Velvetysoft
                    </a>
                </p>
                <p className={legalClass}>
                    <a href="/terms" className={linkClass}>Terms</a>
                    <span aria-hidden className="mx-1.5 opacity-40">·</span>
                    <a href="/privacy" className={linkClass}>Privacy</a>
                    <span aria-hidden className="mx-1.5 opacity-40">·</span>
                    <a href="/dpa" className={linkClass}>DPA</a>
                    <span aria-hidden className="mx-1.5 opacity-40">·</span>
                    <a href="/subprocessors" className={linkClass}>Subprocessors</a>
                    <span aria-hidden className="mx-1.5 opacity-40">·</span>
                    <a href="/accessibility" className={linkClass}>Accessibility</a>
                </p>
                {settings && (
                    <p className="flex justify-center pt-0.5">
                        <Link
                            href="/"
                            className="inline-block rounded opacity-90 transition hover:opacity-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500/35 focus-visible:ring-offset-2 focus-visible:ring-offset-gray-50"
                            aria-label="Jackpot home"
                        >
                            <img
                                src="/jp-icon.png"
                                alt=""
                                className="h-3.5 w-auto"
                                decoding="async"
                                aria-hidden
                            />
                        </Link>
                    </p>
                )}
            </div>
        </footer>
    )
}
