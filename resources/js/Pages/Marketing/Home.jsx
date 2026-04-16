import { usePage } from '@inertiajs/react'
import { Link } from '@inertiajs/react'
import MarketingLayout from '../../Components/Marketing/MarketingLayout'
import HeroSection from '../../Components/Marketing/HeroSection'
import SectionHeader from '../../Components/Marketing/SectionHeader'
import ComparisonBlock from '../../Components/Marketing/ComparisonBlock'
import CTASection from '../../Components/Marketing/CTASection'

const CAPABILITIES = [
    {
        title: 'AI-powered tagging & metadata',
        description: 'Auto-tag uploads, generate descriptions, and surface insights — governed by your brand rules.',
        iconPath: 'M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z',
        color: 'indigo',
    },
    {
        title: 'Brand guidelines built in',
        description: 'Color palettes, voice, typography, and usage rules live next to the assets — not in a PDF no one reads.',
        iconPath: 'M9.53 16.122a3 3 0 00-5.78 1.128 2.25 2.25 0 01-2.4 2.245 4.5 4.5 0 008.4-2.245c0-.399-.078-.78-.22-1.128zm0 0a15.998 15.998 0 003.388-1.62m-5.043-.025a15.994 15.994 0 011.622-3.395m3.42 3.42a15.995 15.995 0 004.764-4.648l3.876-5.814a1.151 1.151 0 00-1.597-1.597L14.146 6.32a15.996 15.996 0 00-4.649 4.763m3.42 3.42a6.776 6.776 0 00-3.42-3.42',
        color: 'violet',
    },
    {
        title: 'Approval workflows',
        description: 'Route reviews to the right roles, preserve history, and keep "approved" meaningful across deliverables.',
        iconPath: 'M9 12.75L11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 01-1.043 3.296 3.745 3.745 0 01-3.296 1.043A3.745 3.745 0 0112 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 01-3.296-1.043 3.745 3.745 0 01-1.043-3.296A3.745 3.745 0 013 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 011.043-3.296 3.746 3.746 0 013.296-1.043A3.746 3.746 0 0112 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 013.296 1.043 3.746 3.746 0 011.043 3.296A3.745 3.745 0 0121 12z',
        color: 'emerald',
    },
    {
        title: 'Secure sharing & downloads',
        description: 'Password-protected links, expiration controls, access restrictions, and revocation — all in one place.',
        iconPath: 'M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z',
        color: 'amber',
    },
    {
        title: 'Collections & brand portal',
        description: 'Package curated sets of assets for campaigns, partners, or clients — permissioned and on-brand.',
        iconPath: 'M2.25 12.75V12A2.25 2.25 0 014.5 9.75h15A2.25 2.25 0 0121.75 12v.75m-8.69-6.44l-2.12-2.12a1.5 1.5 0 00-1.061-.44H4.5A2.25 2.25 0 002.25 6v12a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9a2.25 2.25 0 00-2.25-2.25h-5.379a1.5 1.5 0 01-1.06-.44z',
        color: 'indigo',
    },
    {
        title: 'Agency & multi-brand',
        description: 'Run many client brands from one account. Incubate workspaces, transfer ownership, track referrals.',
        iconPath: 'M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z',
        color: 'violet',
    },
]

const COLOR_MAP = {
    indigo: { bg: 'from-indigo-500/20 to-violet-500/10', ring: 'ring-indigo-500/20', icon: 'text-indigo-300' },
    violet: { bg: 'from-violet-500/20 to-purple-500/10', ring: 'ring-violet-500/20', icon: 'text-violet-300' },
    emerald: { bg: 'from-emerald-500/20 to-teal-500/10', ring: 'ring-emerald-500/20', icon: 'text-emerald-300' },
    amber: { bg: 'from-amber-500/20 to-orange-500/10', ring: 'ring-amber-500/20', icon: 'text-amber-300' },
}

const PLANS_PREVIEW = [
    { name: 'Starter', price: '$59', tagline: 'For small brands getting organized' },
    { name: 'Pro', price: '$199', tagline: 'For growing teams with approval needs', popular: true },
    { name: 'Business', price: '$599', tagline: 'Full platform with SSO & Creator Module' },
]

const STATS = [
    { value: '50+', label: 'AI-powered actions per asset' },
    { value: '99.9%', label: 'Uptime SLA' },
    { value: '<200ms', label: 'Edge delivery globally' },
    { value: '0', label: 'PDFs to read for brand rules' },
]

export default function MarketingHome() {
    const { auth, signup_enabled } = usePage().props

    const primaryHref = auth?.user ? '/app/overview' : signup_enabled !== false ? '/gateway?mode=register' : '/contact'
    const primaryLabel = auth?.user ? 'Go to app' : 'Get early access'

    return (
        <MarketingLayout>
            <HeroSection
                slotMachine
                title="Brand execution, not asset management"
                subtitle="Not another digital asset manager — a brand asset manager built for execution. Every asset, every brand, every deliverable lined up and ready to hit. Like the reels above, when everything clicks into place, that's the jackpot."
                primaryCta={{ text: primaryLabel, href: primaryHref }}
                secondaryCta={{ text: 'See how it works', href: '/product' }}
            />

            {/* Stats bar */}
            <div className="border-y border-white/[0.06]">
                <div className="mx-auto max-w-7xl px-6 lg:px-8">
                    <div className="grid grid-cols-2 lg:grid-cols-4 divide-x divide-white/[0.06]">
                        {STATS.map((stat) => (
                            <div key={stat.label} className="px-6 py-8 text-center">
                                <div className="text-2xl font-bold text-white">{stat.value}</div>
                                <div className="mt-1 text-xs text-white/40">{stat.label}</div>
                            </div>
                        ))}
                    </div>
                </div>
            </div>

            {/* Problem section */}
            <section className="py-24 sm:py-28">
                <div className="mx-auto max-w-7xl px-6 lg:px-8">
                    <SectionHeader
                        eyebrow="The problem"
                        title="Creativity scales. Consistency rarely does."
                        description="Teams ship more channels, more variants, and more partners — while brand rules live in PDFs and assets scatter across drives. The gap isn't storage; it's execution."
                    />
                    <div className="mx-auto mt-16 max-w-3xl rounded-2xl bg-white/[0.02] p-10 ring-1 ring-white/[0.06]">
                        <ul className="space-y-4 text-lg text-white/55">
                            <li className="flex gap-3">
                                <span className="text-indigo-400/80">—</span>
                                <span>Version sprawl and unclear "what's approved" slow every launch.</span>
                            </li>
                            <li className="flex gap-3">
                                <span className="text-indigo-400/80">—</span>
                                <span>Agencies and in-house teams redo work because signals don't reach creators.</span>
                            </li>
                            <li className="flex gap-3">
                                <span className="text-indigo-400/80">—</span>
                                <span>Brand safety becomes a bottleneck instead of a guardrail.</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </section>

            {/* Comparison */}
            <section className="border-t border-white/[0.06] py-24 sm:py-28">
                <div className="mx-auto max-w-7xl px-6 lg:px-8">
                    <SectionHeader
                        eyebrow="Contrast"
                        title="Built for outcomes, not file piles"
                        description="Traditional tools optimize for uploads. Jackpot optimizes for how brands actually ship work."
                        className="mb-16"
                    />
                    <ComparisonBlock
                        leftTitle="Traditional asset tools"
                        rightTitle="Jackpot"
                        leftItems={[
                            'Starts and ends with "where is the file?"',
                            'Brand rules disconnected from day-to-day creation',
                            'AI bolted on — if it exists at all',
                        ]}
                        rightItems={[
                            'Ensures every asset stays on-brand through the workflow',
                            'Brand intelligence, creation, and approvals in one motion',
                            'AI embedded across tagging, guidance, and review — governed by your rules',
                        ]}
                    />
                </div>
            </section>

            {/* Capabilities grid */}
            <section className="border-t border-white/[0.06] py-24 sm:py-28">
                <div className="mx-auto max-w-7xl px-6 lg:px-8">
                    <SectionHeader
                        eyebrow="Capabilities"
                        title="Everything brands need to ship with confidence"
                        className="mb-16"
                    />
                    <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3 lg:gap-8">
                        {CAPABILITIES.map((cap) => {
                            const c = COLOR_MAP[cap.color] || COLOR_MAP.indigo
                            return (
                                <div
                                    key={cap.title}
                                    className="rounded-2xl bg-white/[0.03] p-8 ring-1 ring-white/[0.06] hover:ring-white/[0.1] transition-[box-shadow,ring-color] shadow-sm shadow-black/20"
                                >
                                    <div className={`mb-5 flex h-11 w-11 items-center justify-center rounded-xl bg-gradient-to-br ${c.bg} ring-1 ${c.ring}`}>
                                        <svg className={`h-5 w-5 ${c.icon}`} fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                            <path strokeLinecap="round" strokeLinejoin="round" d={cap.iconPath} />
                                        </svg>
                                    </div>
                                    <h3 className="text-lg font-semibold text-white tracking-tight">{cap.title}</h3>
                                    <p className="mt-3 text-sm leading-relaxed text-white/50">{cap.description}</p>
                                </div>
                            )
                        })}
                    </div>
                </div>
            </section>

            {/* AI section */}
            <section className="border-t border-white/[0.06] py-24 sm:py-28">
                <div className="mx-auto max-w-7xl px-6 lg:px-8">
                    <div className="grid grid-cols-1 gap-16 lg:grid-cols-2 lg:items-center">
                        <div>
                            <p className="text-sm font-semibold uppercase tracking-[0.2em] text-emerald-400/90">AI</p>
                            <h2 className="mt-3 font-display text-3xl font-bold tracking-tight text-white sm:text-4xl lg:text-5xl text-balance">
                                Not a feature. The fabric.
                            </h2>
                            <p className="mt-6 text-lg text-white/50 leading-relaxed">
                                From metadata and tagging to creative assistance and review, AI runs through Jackpot with human gates and auditability — so speed never trades off brand trust.
                            </p>
                            <ul className="mt-8 space-y-3">
                                {[
                                    'Auto-tagging with weighted credit budgets',
                                    'Brand research & PDF extraction',
                                    'Generative editor for images & copy',
                                    'Video insights with per-minute AI analysis',
                                ].map((item) => (
                                    <li key={item} className="flex items-center gap-2 text-sm text-white/55">
                                        <span className="h-1 w-1 rounded-full bg-emerald-400/80" />
                                        {item}
                                    </li>
                                ))}
                            </ul>
                        </div>
                        <div className="rounded-2xl overflow-hidden ring-1 ring-white/[0.08] bg-gradient-to-br from-white/[0.04] to-indigo-950/20 aspect-[4/3] flex items-center justify-center p-10">
                            <p className="text-center text-sm text-white/40 max-w-xs leading-relaxed">
                                Intelligence layered across assets, workflows, and approvals — always under your rules.
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            {/* Pricing preview */}
            <section className="border-t border-white/[0.06] py-24 sm:py-28">
                <div className="mx-auto max-w-7xl px-6 lg:px-8">
                    <SectionHeader
                        eyebrow="Pricing"
                        title="Simple plans that grow with you"
                        description="Start free. Upgrade when you're ready. No surprises."
                        className="mb-14"
                    />
                    <div className="mx-auto grid max-w-4xl grid-cols-1 gap-6 sm:grid-cols-3">
                        {PLANS_PREVIEW.map((plan) => (
                            <div
                                key={plan.name}
                                className={`rounded-2xl p-6 text-center ${
                                    plan.popular
                                        ? 'bg-gradient-to-b from-indigo-500/[0.12] to-violet-500/[0.06] ring-1 ring-indigo-400/30 shadow-lg shadow-indigo-900/30'
                                        : 'bg-white/[0.03] ring-1 ring-white/[0.06]'
                                }`}
                            >
                                {plan.popular && (
                                    <span className="inline-block mb-3 rounded-full bg-indigo-500/20 px-2.5 py-0.5 text-xs font-semibold text-indigo-300">
                                        Most popular
                                    </span>
                                )}
                                <div className="text-3xl font-bold text-white">{plan.price}</div>
                                <div className="text-sm text-white/40 mt-1">/month</div>
                                <h3 className="mt-3 text-base font-semibold text-white">{plan.name}</h3>
                                <p className="mt-1 text-xs text-white/40">{plan.tagline}</p>
                            </div>
                        ))}
                    </div>
                    <div className="mt-8 text-center">
                        <Link
                            href="/pricing"
                            className="inline-flex items-center gap-1 text-sm font-semibold text-indigo-400 hover:text-indigo-300 transition-colors"
                        >
                            See full plan comparison <span aria-hidden>→</span>
                        </Link>
                    </div>
                </div>
            </section>

            <CTASection
                title="Ship work your brand can stand behind"
                subtitle="See how teams replace tool sprawl with a single execution layer."
                primaryCta={{ text: primaryLabel, href: primaryHref }}
                secondaryCta={{ text: 'Why teams choose Jackpot', href: '/benefits' }}
            />
        </MarketingLayout>
    )
}
