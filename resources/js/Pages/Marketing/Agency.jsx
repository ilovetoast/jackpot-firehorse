import { Link, usePage } from '@inertiajs/react'
import MarketingLayout from '../../Components/Marketing/MarketingLayout'
import HeroSection from '../../Components/Marketing/HeroSection'
import FeatureGrid from '../../Components/Marketing/FeatureGrid'
import CTASection from '../../Components/Marketing/CTASection'

const PAINS = [
    {
        title: 'Multiple brands, one team',
        description: "Switching clients shouldn't mean switching mental models — or losing track of what each brand allows.",
        iconPath: 'M2.25 12.75V12A2.25 2.25 0 014.5 9h15A2.25 2.25 0 0121.75 12v.75m-8.69-6.44l-2.12-2.12a1.5 1.5 0 00-1.061-.44H4.5A2.25 2.25 0 002.25 6v12a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9a2.25 2.25 0 00-2.25-2.25h-5.379a1.5 1.5 0 01-1.06-.44z',
    },
    {
        title: 'Client approvals',
        description: 'Waiting on sign-off shouldn\'t freeze the studio. You need clear states and zero ambiguity about what\'s live.',
        iconPath: 'M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z',
    },
    {
        title: 'Asset chaos',
        description: 'Finals mixed with explorations, links that expire, and "use the logo from the email" — execution debt compounds.',
        iconPath: 'M3.75 12V6.75m0 0l3 3m-3-3l-3 3M3.75 12H18m-9 5.25h9m-9 0l-3-3m3 3l3-3',
    },
]

const SOLUTIONS = [
    {
        title: 'Brand-level organization',
        description: 'Each client brand gets its own execution context — guidelines, assets, and workflows stay scoped and searchable.',
        iconPath: 'M9.568 3H5.25A2.25 2.25 0 003 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 005.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 009.568 3z',
    },
    {
        title: 'Approval workflows',
        description: 'Route reviews to the right roles, preserve history, and keep "approved" meaningful across deliverables.',
        iconPath: 'M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z',
    },
    {
        title: 'Shared collections',
        description: 'Package what clients need — curated, permissioned, and aligned to how campaigns actually run.',
        iconPath: 'M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z',
    },
]

const TIERS = [
    {
        name: 'Silver',
        threshold: 'Starting tier',
        incubation: '30-day incubation window',
        extensions: 'Up to 14 days extension',
        color: 'from-gray-400/20 to-gray-500/10',
        ring: 'ring-gray-400/20',
        text: 'text-gray-300',
    },
    {
        name: 'Gold',
        threshold: '5+ activated clients',
        incubation: '60-day incubation window',
        extensions: 'Up to 30 days extension',
        color: 'from-purple-400/20 to-purple-500/10',
        ring: 'ring-purple-400/20',
        text: 'text-purple-300',
    },
    {
        name: 'Platinum',
        threshold: '15+ activated clients',
        incubation: '180-day incubation window',
        extensions: 'Up to 180 days extension',
        color: 'from-violet-300/20 to-violet-400/10',
        ring: 'ring-violet-300/20',
        text: 'text-violet-200',
    },
]

const FLOW_STEPS = [
    {
        step: '1',
        title: 'Create a client workspace',
        description: 'Set up a brand workspace under your agency account with the target plan\'s features.',
    },
    {
        step: '2',
        title: 'Build the brand out',
        description: 'Upload assets, configure guidelines, set up categories, and populate the workspace during the incubation window.',
    },
    {
        step: '3',
        title: 'Transfer ownership',
        description: 'When the client is ready, transfer ownership and billing. They keep everything you built.',
    },
    {
        step: '4',
        title: 'Earn partner credit',
        description: 'Each successful transfer with active billing earns activation credit toward your next partner tier.',
    },
]

export default function MarketingAgency() {
    const { auth, signup_enabled } = usePage().props
    const contactHref = '/contact?plan=agency'

    return (
        <MarketingLayout>
            <HeroSection
                title="Built for agencies"
                subtitle="Run many brands without many broken processes. Jackpot matches how studios deliver — from incubation to client handoff."
                primaryCta={{ text: 'Partner with Jackpot', href: contactHref }}
                secondaryCta={{ text: 'How the product works', href: '/product' }}
            />

            {/* Pain points */}
            <section className="border-t border-white/[0.06] py-24 sm:py-28">
                <div className="mx-auto max-w-7xl px-6 lg:px-8">
                    <p className="text-center text-sm font-semibold uppercase tracking-[0.2em] text-purple-400/80">Pain points</p>
                    <h2 className="mt-3 text-center font-display text-3xl font-bold text-white sm:text-4xl text-balance">Sound familiar?</h2>
                    <div className="mt-16">
                        <FeatureGrid columns={3} items={PAINS} />
                    </div>
                </div>
            </section>

            {/* Solutions */}
            <section className="border-t border-white/[0.06] py-24 sm:py-28">
                <div className="mx-auto max-w-7xl px-6 lg:px-8">
                    <p className="text-center text-sm font-semibold uppercase tracking-[0.2em] text-emerald-400/80">Solutions</p>
                    <h2 className="mt-3 text-center font-display text-3xl font-bold text-white sm:text-4xl text-balance">How Jackpot fits agency life</h2>
                    <div className="mt-16">
                        <FeatureGrid columns={3} items={SOLUTIONS} />
                    </div>
                </div>
            </section>

            {/* Incubation flow */}
            <section className="border-t border-white/[0.06] py-24 sm:py-28">
                <div className="mx-auto max-w-5xl px-6 lg:px-8">
                    <div className="text-center mb-14">
                        <p className="text-sm font-semibold uppercase tracking-[0.2em] text-violet-400/90">Incubation</p>
                        <h2 className="mt-3 font-display text-3xl font-bold text-white sm:text-4xl text-balance">From setup to handoff</h2>
                        <p className="mt-4 text-base text-white/40">Build out client workspaces on their target plan, then transfer when they're ready.</p>
                    </div>
                    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
                        {FLOW_STEPS.map((s) => (
                            <div key={s.step} className="relative">
                                <div className="flex h-10 w-10 items-center justify-center rounded-full bg-violet-500/20 ring-1 ring-violet-400/30 mb-4">
                                    <span className="text-sm font-bold text-violet-300">{s.step}</span>
                                </div>
                                <h3 className="text-base font-semibold text-white">{s.title}</h3>
                                <p className="mt-2 text-sm text-white/40 leading-relaxed">{s.description}</p>
                            </div>
                        ))}
                    </div>
                </div>
            </section>

            {/* Partner tiers */}
            <section className="border-t border-white/[0.06] py-24 sm:py-28">
                <div className="mx-auto max-w-5xl px-6 lg:px-8">
                    <div className="text-center mb-14">
                        <p className="text-sm font-semibold uppercase tracking-[0.2em] text-purple-400/90">Partner Tiers</p>
                        <h2 className="mt-3 font-display text-3xl font-bold text-white sm:text-4xl text-balance">Grow with every client you activate</h2>
                        <p className="mt-4 text-base text-white/40">Your tier advances automatically as you bring clients onto the platform.</p>
                    </div>
                    <div className="grid grid-cols-1 gap-6 sm:grid-cols-3">
                        {TIERS.map((tier) => (
                            <div
                                key={tier.name}
                                className={`rounded-2xl bg-gradient-to-br ${tier.color} ring-1 ${tier.ring} p-8 text-center`}
                            >
                                <h3 className={`text-xl font-bold ${tier.text}`}>{tier.name}</h3>
                                <p className="mt-1 text-xs text-white/30">{tier.threshold}</p>
                                <div className="mt-6 space-y-3">
                                    <div className="rounded-lg bg-white/[0.04] px-4 py-2">
                                        <p className="text-sm text-white/50">{tier.incubation}</p>
                                    </div>
                                    <div className="rounded-lg bg-white/[0.04] px-4 py-2">
                                        <p className="text-sm text-white/50">{tier.extensions}</p>
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                    <p className="mt-6 text-center text-xs text-white/25">
                        Referral tracking, partner analytics dashboard, and scoped brand access included at all tiers.
                    </p>
                </div>
            </section>

            {/* What agencies get */}
            <section className="border-t border-white/[0.06] py-20 sm:py-24">
                <div className="mx-auto max-w-7xl px-6 lg:px-8">
                    <div className="rounded-2xl bg-white/[0.02] ring-1 ring-white/[0.06] p-8 sm:p-12">
                        <h2 className="font-display text-2xl font-bold text-white mb-8">What agency partners get</h2>
                        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-8 gap-y-5">
                            {[
                                'Agency dashboard with client overview',
                                'Client workspace incubation & setup',
                                'Seamless ownership transfers',
                                'Referral attribution & tracking',
                                'Partner reward tracking (by tier)',
                                'Agency-scoped user sync across brands',
                                'Extension requests for incubation windows',
                                'Multi-brand asset execution',
                                'Approval workflows across all clients',
                            ].map((item) => (
                                <div key={item} className="flex items-center gap-2">
                                    <svg className="h-4 w-4 flex-shrink-0 text-emerald-400" fill="currentColor" viewBox="0 0 20 20">
                                        <path fillRule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clipRule="evenodd" />
                                    </svg>
                                    <span className="text-sm text-white/55">{item}</span>
                                </div>
                            ))}
                        </div>
                    </div>
                </div>
            </section>

            <CTASection
                title="Partner with Jackpot"
                subtitle="Talk to us about multi-brand rollout, client transfers, and how your studio operates."
                primaryCta={{ text: 'Contact partnerships', href: contactHref }}
                secondaryCta={{ text: 'See pricing', href: '/pricing' }}
            />
        </MarketingLayout>
    )
}
