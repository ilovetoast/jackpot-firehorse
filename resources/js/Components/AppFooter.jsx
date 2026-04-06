/**
 * @param {{ variant?: 'light' | 'dark' }} props
 * Use variant="dark" on cinematic / full-bleed dark pages (e.g. Creators dashboard).
 */
export default function AppFooter({ variant = 'light' }) {
    const dark = variant === 'dark'

    return (
        <footer
            className={
                dark ? 'bg-transparent' : 'border-t border-gray-200 bg-white'
            }
        >
            <div className="mx-auto max-w-7xl px-4 py-4 sm:px-6 lg:px-8">
                <p
                    className={
                        dark
                            ? 'text-center text-sm text-white/45'
                            : 'text-center text-sm text-gray-500'
                    }
                >
                    <span className={dark ? 'text-white/55' : undefined}>Jackpot</span> &copy;{' '}
                    {new Date().getFullYear()} -{' '}
                    <a
                        href="https://velvetysoft.com"
                        target="_blank"
                        rel="noopener noreferrer"
                        className={
                            dark
                                ? 'text-white/50 underline-offset-2 transition hover:text-white/85 hover:underline'
                                : 'text-gray-600 underline-offset-2 hover:text-gray-900 hover:underline'
                        }
                    >
                        Velvetysoft
                    </a>
                </p>
            </div>
        </footer>
    )
}
