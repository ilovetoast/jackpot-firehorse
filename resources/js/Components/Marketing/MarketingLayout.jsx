import { Link, usePage } from '@inertiajs/react'
import { SITE_DEFAULT_CINEMATIC_BACKDROP_CSS, SITE_PRIMARY_HEX } from '../../utils/colorUtils'

const NAV = [
    { label: 'Home', href: '/' },
    { label: 'Product', href: '/product' },
    { label: 'Benefits', href: '/benefits' },
    { label: 'Pricing', href: '/pricing' },
    { label: 'Agency', href: '/agency' },
    { label: 'Contact', href: '/contact' },
]

/**
 * Dark marketing shell: ambient gradients, grain, nav, footer.
 * Stripe / Laravel product-page inspired: soft rings, minimal borders, violet family accents.
 *
 * @param {{ children: import('react').ReactNode, cinematicBackdrop?: boolean }} props
 * When `cinematicBackdrop` is true (legal pages), use the same site-primary radial stack as Brand Overview
 * before a custom brand primary is set — not the softer marketing blobs.
 */
export default function MarketingLayout({ children, cinematicBackdrop = false }) {
    const page = usePage()
    const { auth, signup_enabled } = page.props
    const u = page.url || '/'
    const pathname = (() => {
        const p = u.split('?')[0] || '/'
        return p === '' ? '/' : p
    })()

    const navButton = () => {
        if (auth?.user) {
            return { text: 'Workspace', href: '/app/overview' }
        }
        return { text: 'Sign in', href: '/gateway' }
    }
    const nb = navButton()

    return (
        <div className="bg-[#0B0B0D] text-white min-h-screen relative overflow-hidden">
            <div className="pointer-events-none fixed inset-0 z-0" aria-hidden="true">
                {cinematicBackdrop ? (
                    <>
                        <div
                            className="absolute inset-0"
                            style={{ background: SITE_DEFAULT_CINEMATIC_BACKDROP_CSS }}
                        />
                        <div
                            className="absolute inset-0"
                            style={{
                                background: `radial-gradient(circle at 30% 40%, ${SITE_PRIMARY_HEX}14, transparent 60%)`,
                            }}
                        />
                        <div className="absolute inset-0 bg-black/30" />
                        <div className="absolute inset-0 bg-gradient-to-b from-black/20 via-transparent to-black/50" />
                    </>
                ) : (
                    <>
                        <div className="absolute -top-[40%] -left-[20%] w-[80%] h-[80%] rounded-full bg-[#7c3aed]/[0.12] blur-[160px]" />
                        <div className="absolute -bottom-[30%] -right-[15%] w-[60%] h-[70%] rounded-full bg-[#8b5cf6]/[0.10] blur-[140px]" />
                        <div className="absolute top-[25%] right-[5%] w-[40%] h-[45%] rounded-full bg-[#06b6d4]/[0.04] blur-[100px]" />
                        <div className="absolute inset-0 bg-gradient-to-b from-[#0B0B0D] via-transparent to-[#0B0B0D]/90" />
                    </>
                )}
            </div>

            <div
                className="pointer-events-none fixed inset-0 z-[1] opacity-[0.035]"
                aria-hidden="true"
                style={{
                    backgroundImage: `url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noise'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noise)'/%3E%3C/svg%3E")`,
                    backgroundRepeat: 'repeat',
                }}
            />

            <nav className="relative z-50 border-b border-white/[0.06] backdrop-blur-sm bg-[#0B0B0D]/40">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div className="flex h-16 items-center justify-between gap-4">
                        <Link href="/" className="flex shrink-0 items-center">
                            <img
                                src="/jp-wordmark-inverted.svg"
                                alt="Jackpot"
                                className="h-7 w-auto sm:h-8"
                                decoding="async"
                            />
                        </Link>
                        <div className="hidden md:flex items-center gap-1">
                            {NAV.map((item) => {
                                const active =
                                    item.href === '/'
                                        ? pathname === '/'
                                        : pathname === item.href || pathname.startsWith(`${item.href}/`)
                                return (
                                    <Link
                                        key={item.href}
                                        href={item.href}
                                        className={`rounded-lg px-3 py-2 text-sm font-medium transition-colors ${
                                            active ? 'text-white bg-white/[0.08]' : 'text-white/60 hover:text-white hover:bg-white/[0.04]'
                                        }`}
                                    >
                                        {item.label}
                                    </Link>
                                )
                            })}
                        </div>
                        <div className="flex items-center gap-2 sm:gap-3">
                            {auth?.user ? (
                                <Link
                                    href={nb.href}
                                    className="rounded-lg bg-white px-4 py-2 text-sm font-semibold text-gray-900 shadow-sm hover:bg-gray-100 transition-colors"
                                >
                                    {nb.text}
                                </Link>
                            ) : (
                                <>
                                    <Link href="/gateway" className="hidden sm:inline text-sm font-medium text-white/65 hover:text-white transition-colors">
                                        Login
                                    </Link>
                                    {signup_enabled !== false && (
                                        <Link
                                            href="/gateway?mode=register"
                                            className="rounded-lg bg-white px-4 py-2 text-sm font-semibold text-gray-900 shadow-sm hover:bg-gray-100 transition-colors"
                                        >
                                            Sign up
                                        </Link>
                                    )}
                                </>
                            )}
                        </div>
                    </div>
                    <div className="flex md:hidden gap-1 pb-3 overflow-x-auto scrollbar-none">
                        {NAV.map((item) => {
                            const active =
                                item.href === '/'
                                    ? pathname === '/'
                                    : pathname === item.href || pathname.startsWith(`${item.href}/`)
                            return (
                                <Link
                                    key={item.href}
                                    href={item.href}
                                    className={`shrink-0 rounded-lg px-3 py-1.5 text-xs font-medium ${
                                        active ? 'text-white bg-white/[0.1]' : 'text-white/55 hover:text-white'
                                    }`}
                                >
                                    {item.label}
                                </Link>
                            )
                        })}
                    </div>
                </div>
            </nav>

            <main className="relative z-10">{children}</main>

            <footer className="relative z-10 border-t border-white/[0.06] mt-0">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-14">
                    <div className="flex flex-col sm:flex-row items-center justify-between gap-6">
                        <img
                            src="/jp-wordmark-inverted.svg"
                            alt="Jackpot"
                            className="h-7 w-auto opacity-90"
                            decoding="async"
                        />
                        <div className="flex flex-wrap justify-center gap-x-6 gap-y-2 text-sm text-white/45">
                            {NAV.map((item) => (
                                <Link key={item.href} href={item.href} className="hover:text-white/80 transition-colors">
                                    {item.label}
                                </Link>
                            ))}
                        </div>
                    </div>
                    <div className="mt-10 flex flex-wrap justify-center gap-x-5 gap-y-2 text-xs text-white/35">
                        <Link href="/terms" className="hover:text-white/70 transition-colors">
                            Terms of Service
                        </Link>
                        <span aria-hidden className="text-white/15">·</span>
                        <Link href="/privacy" className="hover:text-white/70 transition-colors">
                            Privacy Policy
                        </Link>
                        <span aria-hidden className="text-white/15">·</span>
                        <Link href="/dpa" className="hover:text-white/70 transition-colors">
                            DPA
                        </Link>
                        <span aria-hidden className="text-white/15">·</span>
                        <Link href="/subprocessors" className="hover:text-white/70 transition-colors">
                            Subprocessors
                        </Link>
                        <span aria-hidden className="text-white/15">·</span>
                        <Link href="/accessibility" className="hover:text-white/70 transition-colors">
                            Accessibility
                        </Link>
                    </div>
                    <p className="mt-6 text-center text-xs text-white/30">
                        © {new Date().getFullYear()} Jackpot LLC
                    </p>
                </div>
            </footer>
        </div>
    )
}
