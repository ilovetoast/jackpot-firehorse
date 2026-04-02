import { Link, usePage } from '@inertiajs/react'
import JackpotLogo from '../JackpotLogo'

const NAV = [
    { label: 'Home', href: '/' },
    { label: 'Product', href: '/product' },
    { label: 'Benefits', href: '/benefits' },
    { label: 'Agency', href: '/agency' },
    { label: 'Contact', href: '/contact' },
]

/**
 * Dark marketing shell: ambient gradients, grain, nav, footer.
 * Stripe / Laravel product-page inspired: soft rings, minimal borders, indigo + violet accents.
 */
export default function MarketingLayout({ children }) {
    const page = usePage()
    const { auth, signup_enabled } = page.props
    const u = page.url || '/'
    const pathname = (() => {
        const p = u.split('?')[0] || '/'
        return p === '' ? '/' : p
    })()

    const navButton = () => {
        if (auth?.user) {
            return { text: 'Dashboard', href: '/gateway' }
        }
        return { text: 'Sign in', href: '/gateway' }
    }
    const nb = navButton()

    return (
        <div className="bg-[#0a0a0f] text-white min-h-screen relative overflow-hidden">
            <div className="pointer-events-none fixed inset-0 z-0" aria-hidden="true">
                <div className="absolute -top-[40%] -left-[20%] w-[80%] h-[80%] rounded-full bg-indigo-900/25 blur-[160px]" />
                <div className="absolute -bottom-[30%] -right-[15%] w-[60%] h-[70%] rounded-full bg-violet-900/20 blur-[140px]" />
                <div className="absolute top-[25%] right-[5%] w-[40%] h-[45%] rounded-full bg-emerald-900/10 blur-[100px]" />
                <div className="absolute inset-0 bg-gradient-to-b from-[#0a0a0f] via-transparent to-[#0a0a0f]/90" />
            </div>

            <div
                className="pointer-events-none fixed inset-0 z-[1] opacity-[0.035]"
                aria-hidden="true"
                style={{
                    backgroundImage: `url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noise'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noise)'/%3E%3C/svg%3E")`,
                    backgroundRepeat: 'repeat',
                }}
            />

            <nav className="relative z-50 border-b border-white/[0.06] backdrop-blur-sm bg-[#0a0a0f]/40">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div className="flex h-16 items-center justify-between gap-4">
                        <Link href="/" className="flex shrink-0 items-center">
                            <JackpotLogo className="h-8 w-auto" textClassName="text-xl font-bold text-white tracking-tight" />
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
                        <JackpotLogo className="h-7 w-auto opacity-90" textClassName="text-lg font-semibold text-white/90" />
                        <div className="flex flex-wrap justify-center gap-x-6 gap-y-2 text-sm text-white/45">
                            {NAV.map((item) => (
                                <Link key={item.href} href={item.href} className="hover:text-white/80 transition-colors">
                                    {item.label}
                                </Link>
                            ))}
                        </div>
                    </div>
                    <p className="mt-10 text-center text-xs text-white/30">Jackpot — Velvetysoft</p>
                </div>
            </footer>
        </div>
    )
}
