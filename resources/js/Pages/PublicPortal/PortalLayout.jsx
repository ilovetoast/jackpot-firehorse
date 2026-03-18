import { usePage } from '@inertiajs/react'
import LogoMark from '@/Components/Brand/LogoMark'

export default function PortalLayout({ children }) {
    const { theme, brand } = usePage().props

    const primary = theme?.colors?.primary || '#6366f1'
    const secondary = theme?.colors?.secondary || '#8b5cf6'

    return (
        <div
            className="min-h-screen text-white relative"
            style={{ background: theme?.background?.value || '#0B0B0D' }}
        >
            {/* Depth overlay */}
            <div className="fixed inset-0 pointer-events-none z-0">
                <div className="absolute inset-0 bg-black/25" />
                <div className="absolute inset-0 bg-gradient-to-b from-black/15 via-transparent to-black/35" />
            </div>

            {/* Header */}
            <header className="relative z-10 flex items-center justify-between px-6 sm:px-10 py-5">
                <LogoMark
                    name={theme?.name}
                    logo={theme?.logo}
                    size="md"
                />
                <div className="flex items-center gap-3">
                    {theme?.mode !== 'default' && (
                        <span className="text-[10px] uppercase tracking-widest text-white/25 font-medium">
                            Brand Portal
                        </span>
                    )}
                </div>
            </header>

            {/* Content */}
            <main className="relative z-10">
                {children}
            </main>

            {/* Footer */}
            <footer className="relative z-10 px-6 sm:px-10 py-8 text-center border-t border-white/[0.06]">
                <p className="text-xs text-white/25">
                    &copy; {new Date().getFullYear()} {brand?.name || theme?.name}
                </p>
                <p className="text-[10px] text-white/15 mt-1">
                    Powered by Jackpot
                </p>
            </footer>
        </div>
    )
}
