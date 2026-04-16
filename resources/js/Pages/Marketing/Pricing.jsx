import { usePage, Link } from '@inertiajs/react'
import MarketingLayout from '../../Components/Marketing/MarketingLayout'
import CTASection from '../../Components/Marketing/CTASection'

const PLANS = [
    {
        id: 'free',
        name: 'Free',
        price: '$0',
        period: 'forever',
        tagline: 'Try the platform. See what AI-powered brand asset management can do.',
        limits: [
            { value: '1 GB', label: 'Storage' },
            { value: '2', label: 'Users' },
            { value: '10 MB', label: 'Max upload' },
            { value: '1', label: 'Brand' },
            { value: '75', label: 'AI credits/mo' },
            { value: '25/mo', label: 'Downloads' },
        ],
        features: [
            'Brand asset management',
            'AI auto-tagging & suggestions',
            'Edge content delivery',
            'Basic analytics',
        ],
        notIncluded: ['Approval workflows', 'Versioning', 'Brand portal', 'Add-ons'],
    },
    {
        id: 'starter',
        name: 'Starter',
        price: '$59',
        period: '/month',
        tagline: 'Everything a small brand needs to stay organized.',
        limits: [
            { value: '50 GB', label: 'Storage' },
            { value: '5', label: 'Users' },
            { value: '50 MB', label: 'Max upload' },
            { value: '1', label: 'Brand' },
            { value: '300', label: 'AI credits/mo' },
            { value: '200/mo', label: 'Downloads' },
        ],
        features: [
            'All Free features',
            'Asset versioning (5 per file)',
            'Custom sharing & permissions',
            'Internal collections',
            '5 custom metadata fields',
            'Storage & AI credit add-ons',
            'Quarterly workshops',
        ],
        notIncluded: ['Approval workflows', 'Brand portal'],
    },
    {
        id: 'pro',
        name: 'Pro',
        price: '$199',
        period: '/month',
        tagline: 'For teams that need approvals, roles, and more AI.',
        popular: true,
        limits: [
            { value: '250 GB', label: 'Storage' },
            { value: '20', label: 'Users' },
            { value: 'Unlimited', label: 'Upload' },
            { value: '3', label: 'Brands' },
            { value: '1,500', label: 'AI credits/mo' },
            { value: '1,000/mo', label: 'Downloads' },
        ],
        features: [
            'All Starter features',
            'Full approval workflows',
            'Versioning (25 per file)',
            'Brand portal & guidelines editor',
            'Advanced roles & permissions',
            'Private categories',
            'SSO / Single sign-on',
            'Creator Module available (add-on)',
            'All add-ons available',
            'Priority phone support',
        ],
        notIncluded: [],
    },
    {
        id: 'business',
        name: 'Business',
        price: '$599',
        period: '/month',
        tagline: 'Full platform power for large teams and agencies.',
        limits: [
            { value: '1 TB', label: 'Storage' },
            { value: '75', label: 'Users' },
            { value: 'Unlimited', label: 'Upload' },
            { value: '10', label: 'Brands' },
            { value: '6,000', label: 'AI credits/mo' },
            { value: 'Unlimited', label: 'Downloads' },
        ],
        features: [
            'All Pro features',
            'Creator Module included (50 seats)',
            'Public collections & portal',
            'Password-protected download links',
            'Download policy controls',
            'Non-expiring links',
            'Versioning (250 per file)',
        ],
        notIncluded: [],
    },
]

const ADDONS = [
    {
        name: 'Extra Storage',
        options: ['+100 GB — $19/mo', '+500 GB — $69/mo', '+1 TB — $129/mo'],
        note: 'Available on Starter+',
        iconPath: 'M20.25 6.375c0 2.278-3.694 4.125-8.25 4.125S3.75 8.653 3.75 6.375m16.5 0c0-2.278-3.694-4.125-8.25-4.125S3.75 4.097 3.75 6.375m16.5 0v11.25c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125V6.375m16.5 0v3.75m-16.5-3.75v3.75m16.5 0v3.75C20.25 16.153 16.556 18 12 18s-8.25-1.847-8.25-4.125v-3.75m16.5 0c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125',
    },
    {
        name: 'AI Credit Packs',
        options: ['+500 credits — $29/mo', '+2,000 credits — $89/mo', '+10,000 credits — $349/mo'],
        note: 'Available on Starter+',
        iconPath: 'M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z',
    },
    {
        name: 'Creator Module',
        options: ['25 creator seats — $99/mo', '+25 seat pack — $49/mo', '+100 seat pack — $149/mo'],
        note: 'Add-on for Pro, included in Business',
        iconPath: 'M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z',
    },
]

const AI_COSTS = [
    { action: 'Auto-tag / Suggestions / Insights', cost: '1 credit' },
    { action: 'PDF brand extraction', cost: '5 credits' },
    { action: 'Presentation preview', cost: '10 credits' },
    { action: 'Generative image edits', cost: '15 credits' },
    { action: 'Generative image creation', cost: '20 credits' },
    { action: 'Brand research', cost: '25 credits' },
    { action: 'Video insights', cost: '5 + 3/min' },
]

export default function MarketingPricing() {
    const { auth, signup_enabled } = usePage().props
    const earlyHref = auth?.user ? '/app/overview' : signup_enabled !== false ? '/gateway?mode=register' : '/contact'
    const earlyLabel = auth?.user ? 'Go to app' : 'Get started free'

    return (
        <MarketingLayout>
            <section className="px-6 pt-16 pb-4 lg:px-8">
                <div className="mx-auto max-w-3xl text-center">
                    <p className="text-sm font-semibold uppercase tracking-[0.2em] text-indigo-400/90">Pricing</p>
                    <h1 className="mt-3 font-display text-4xl font-bold tracking-tight text-white sm:text-5xl lg:text-6xl text-balance">
                        Simple pricing. No surprises.
                    </h1>
                    <p className="mt-6 text-lg text-white/50 leading-relaxed">
                        Start free, upgrade when you're ready. Every plan includes edge delivery, AI features, and analytics.
                    </p>
                </div>
            </section>

            {/* Plan cards */}
            <section className="py-16 sm:py-20">
                <div className="mx-auto max-w-7xl px-6 lg:px-8">
                    <div className="grid grid-cols-1 gap-6 lg:grid-cols-4">
                        {PLANS.map((plan) => (
                            <div
                                key={plan.id}
                                className={`relative flex flex-col rounded-2xl p-8 transition-all duration-300 ease-out hover:scale-[1.015] hover:-translate-y-0.5 motion-reduce:hover:scale-100 motion-reduce:hover:translate-y-0 ${
                                    plan.popular
                                        ? 'bg-gradient-to-b from-indigo-500/[0.12] to-violet-500/[0.06] ring-1 ring-indigo-400/30 shadow-xl shadow-indigo-900/30 lg:scale-[1.03] hover:ring-indigo-400/50 hover:shadow-2xl hover:shadow-indigo-800/40'
                                        : 'bg-white/[0.03] ring-1 ring-white/[0.06] hover:bg-white/[0.05] hover:ring-white/[0.12] hover:shadow-lg hover:shadow-indigo-900/20'
                                }`}
                            >
                                {plan.popular && (
                                    <span className="absolute -top-3 left-1/2 -translate-x-1/2 rounded-full bg-indigo-500 px-3 py-1 text-xs font-semibold text-white shadow-lg">
                                        Most popular
                                    </span>
                                )}

                                <div className="mb-6">
                                    <h3 className="text-lg font-semibold text-white">{plan.name}</h3>
                                    <div className="mt-2 flex items-baseline">
                                        <span className="text-4xl font-bold text-white">{plan.price}</span>
                                        <span className="ml-1 text-sm text-white/40">{plan.period}</span>
                                    </div>
                                    <p className="mt-2 text-sm text-white/40">{plan.tagline}</p>
                                </div>

                                {/* Key limits */}
                                <div className="grid grid-cols-3 gap-px rounded-xl bg-white/[0.06] overflow-hidden mb-6">
                                    {plan.limits.map((item) => (
                                        <div key={item.label} className="bg-[#0B0B0D] px-2 py-2.5 text-center">
                                            <div className="text-sm font-bold text-white leading-tight">{item.value}</div>
                                            <div className="text-[9px] uppercase tracking-wider text-white/35 mt-1 leading-tight">{item.label}</div>
                                        </div>
                                    ))}
                                </div>

                                {/* Features */}
                                <ul className="space-y-2 mb-6 flex-1">
                                    {plan.features.map((f) => (
                                        <li key={f} className="flex items-start gap-2 text-sm text-white/60">
                                            <svg className="h-4 w-4 mt-0.5 flex-shrink-0 text-indigo-400" fill="currentColor" viewBox="0 0 20 20">
                                                <path fillRule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clipRule="evenodd" />
                                            </svg>
                                            {f}
                                        </li>
                                    ))}
                                    {plan.notIncluded.map((f) => (
                                        <li key={f} className="flex items-start gap-2 text-sm text-white/25">
                                            <svg className="h-4 w-4 mt-0.5 flex-shrink-0 text-white/15" fill="currentColor" viewBox="0 0 20 20">
                                                <path fillRule="evenodd" d="M4 10a.75.75 0 01.75-.75h10.5a.75.75 0 010 1.5H4.75A.75.75 0 014 10z" clipRule="evenodd" />
                                            </svg>
                                            {f}
                                        </li>
                                    ))}
                                </ul>

                                <div className="mt-auto">
                                    {plan.id === 'free' ? (
                                        <Link
                                            href={earlyHref}
                                            className="flex w-full justify-center rounded-xl bg-white/10 py-2.5 text-sm font-semibold text-white hover:bg-white/15 transition"
                                        >
                                            {earlyLabel}
                                        </Link>
                                    ) : (
                                        <Link
                                            href={auth?.user ? '/app/billing' : earlyHref}
                                            className={`flex w-full justify-center rounded-xl py-2.5 text-sm font-semibold transition ${
                                                plan.popular
                                                    ? 'bg-white text-gray-900 hover:bg-gray-100 shadow-lg shadow-black/20'
                                                    : 'bg-white/10 text-white hover:bg-white/15'
                                            }`}
                                        >
                                            {auth?.user ? 'Change plan' : earlyLabel}
                                        </Link>
                                    )}
                                </div>
                            </div>
                        ))}
                    </div>

                    {/* Enterprise */}
                    <div className="mt-8 rounded-2xl bg-white/[0.02] ring-1 ring-white/[0.06] p-8 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                        <div>
                            <h3 className="text-lg font-semibold text-white">Enterprise</h3>
                            <p className="text-sm text-white/40 mt-1">Dedicated infrastructure, custom integrations, agency templates, and volume pricing.</p>
                        </div>
                        <Link
                            href="/contact?plan=enterprise"
                            className="inline-flex items-center justify-center rounded-xl bg-white px-6 py-3 text-sm font-semibold text-gray-900 hover:bg-gray-100 transition whitespace-nowrap"
                        >
                            Contact sales
                        </Link>
                    </div>
                </div>
            </section>

            {/* Add-ons */}
            <section className="border-t border-white/[0.06] py-24 sm:py-28">
                <div className="mx-auto max-w-7xl px-6 lg:px-8">
                    <div className="text-center mb-14">
                        <p className="text-sm font-semibold uppercase tracking-[0.2em] text-violet-400/90">Add-ons</p>
                        <h2 className="mt-3 font-display text-3xl font-bold text-white sm:text-4xl text-balance">Boost any plan</h2>
                        <p className="mt-4 text-base text-white/40">Need more storage, AI credits, or creator seats? Add them month-to-month.</p>
                    </div>
                    <div className="grid grid-cols-1 gap-6 sm:grid-cols-3">
                        {ADDONS.map((addon) => (
                            <div key={addon.name} className="rounded-2xl bg-white/[0.03] p-8 ring-1 ring-white/[0.06]">
                                <div className="mb-4 flex h-11 w-11 items-center justify-center rounded-xl bg-gradient-to-br from-violet-500/20 to-purple-500/10 ring-1 ring-violet-500/20">
                                    <svg className="h-5 w-5 text-violet-300" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" d={addon.iconPath} />
                                    </svg>
                                </div>
                                <h3 className="text-lg font-semibold text-white">{addon.name}</h3>
                                <ul className="mt-4 space-y-2">
                                    {addon.options.map((opt) => (
                                        <li key={opt} className="text-sm text-white/50">{opt}</li>
                                    ))}
                                </ul>
                                <p className="mt-4 text-xs text-white/30">{addon.note}</p>
                            </div>
                        ))}
                    </div>
                </div>
            </section>

            {/* AI credit costs */}
            <section className="border-t border-white/[0.06] py-24 sm:py-28">
                <div className="mx-auto max-w-3xl px-6 lg:px-8">
                    <div className="text-center mb-12">
                        <p className="text-sm font-semibold uppercase tracking-[0.2em] text-emerald-400/90">AI Credits</p>
                        <h2 className="mt-3 font-display text-3xl font-bold text-white sm:text-4xl text-balance">What do credits cost?</h2>
                        <p className="mt-4 text-base text-white/40">Every AI action has a transparent credit cost. No hidden charges.</p>
                    </div>
                    <div className="rounded-2xl bg-white/[0.03] ring-1 ring-white/[0.06] overflow-hidden">
                        {AI_COSTS.map((row, i) => (
                            <div
                                key={row.action}
                                className={`flex items-center justify-between px-6 py-4 ${
                                    i < AI_COSTS.length - 1 ? 'border-b border-white/[0.04]' : ''
                                }`}
                            >
                                <span className="text-sm text-white/60">{row.action}</span>
                                <span className="text-sm font-semibold text-white">{row.cost}</span>
                            </div>
                        ))}
                    </div>
                    <p className="mt-4 text-center text-xs text-white/30">
                        When credits run out, AI actions pause until next month or you purchase an add-on pack.
                    </p>
                </div>
            </section>

            {/* Agency callout */}
            <section className="border-t border-white/[0.06] py-20 sm:py-24">
                <div className="mx-auto max-w-7xl px-6 lg:px-8">
                    <div className="rounded-2xl bg-gradient-to-br from-purple-500/[0.08] to-violet-500/[0.04] ring-1 ring-purple-400/20 p-10 sm:p-14 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-8">
                        <div className="max-w-xl">
                            <p className="text-xs font-semibold uppercase tracking-wider text-purple-400/80">Agency Partner Program</p>
                            <h2 className="mt-3 font-display text-2xl font-bold text-white sm:text-3xl">Run an agency or studio?</h2>
                            <p className="mt-3 text-sm text-white/45 leading-relaxed">
                                Client incubation, ownership transfers, referral tracking, and tiered partner rewards. Talk to our partnerships team about volume pricing and multi-brand rollout.
                            </p>
                        </div>
                        <div className="flex flex-col sm:flex-row gap-3">
                            <Link
                                href="/agency"
                                className="inline-flex items-center justify-center rounded-xl bg-purple-500 px-6 py-3 text-sm font-semibold text-white hover:bg-purple-400 transition"
                            >
                                Learn more
                            </Link>
                            <Link
                                href="/contact?plan=agency"
                                className="inline-flex items-center justify-center rounded-xl bg-white/10 px-6 py-3 text-sm font-semibold text-white hover:bg-white/15 transition"
                            >
                                Talk to partnerships
                            </Link>
                        </div>
                    </div>
                </div>
            </section>

            <CTASection
                title="Start free. Scale when ready."
                subtitle="No credit card required for the free plan. Upgrade, downgrade, or cancel anytime."
                primaryCta={{ text: earlyLabel, href: earlyHref }}
                secondaryCta={{ text: 'Contact sales', href: '/contact?plan=enterprise' }}
            />
        </MarketingLayout>
    )
}
