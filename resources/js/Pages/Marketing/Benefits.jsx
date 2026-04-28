import { usePage } from '@inertiajs/react'
import MarketingLayout from '../../Components/Marketing/MarketingLayout'
import SectionHeader from '../../Components/Marketing/SectionHeader'
import CTASection from '../../Components/Marketing/CTASection'

const BENEFITS = [
    {
        title: 'Consistency at scale',
        body: 'The more channels and partners you add, the harder it is to keep one voice. Jackpot aligns execution so "on-brand" is the default — not a weekly fire drill.',
        metric: { value: '100%', label: 'brand-scoped asset organization' },
        iconPath: 'M3.75 3v11.25A2.25 2.25 0 006 16.5h2.25M3.75 3h-1.5m1.5 0h16.5m0 0h1.5m-1.5 0v11.25A2.25 2.25 0 0118 16.5h-2.25m-7.5 0h7.5m-7.5 0l-1 3m8.5-3l1 3m0 0l.5 1.5m-.5-1.5h-9.5m0 0l-.5 1.5M9 11.25v1.5M12 9v3.75m3-6v6',
    },
    {
        title: 'Faster execution',
        body: 'Creators spend time on craft, not hunting files or re-asking for rules. Context, assets, and checks live in the same flow.',
        metric: { value: 'Edge', label: 'delivery worldwide' },
        iconPath: 'M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z',
    },
    {
        title: 'Fewer approval cycles',
        body: 'Review happens at the right step with the right people — so you stop looping the same fixes after everything is "final."',
        metric: { value: 'Built-in', label: 'workflow approvals & history' },
        iconPath: 'M9 12.75L11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 01-1.043 3.296 3.745 3.745 0 01-3.296 1.043A3.745 3.745 0 0112 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 01-3.296-1.043 3.745 3.745 0 01-1.043-3.296A3.745 3.745 0 013 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 011.043-3.296 3.746 3.746 0 013.296-1.043A3.746 3.746 0 0112 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 013.296 1.043 3.746 3.746 0 011.043 3.296A3.745 3.745 0 0121 12z',
    },
    {
        title: 'Brand safety',
        body: 'Guardrails and audit trails give marketing and legal confidence without turning every request into a ticket queue.',
        metric: { value: 'Full', label: 'audit trail & access controls' },
        iconPath: 'M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z',
    },
]

const WHO_ITS_FOR = [
    {
        title: 'Marketing teams',
        description: 'Manage brand assets, run campaigns, and ship on-brand content without juggling five tools.',
        iconPath: 'M10.34 15.84c-.688-.06-1.386-.09-2.09-.09H7.5a4.5 4.5 0 110-9h.75c.704 0 1.402-.03 2.09-.09m0 9.18c.253.962.584 1.892.985 2.783.247.55.06 1.21-.463 1.511l-.657.38c-.551.318-1.26.117-1.527-.461a20.845 20.845 0 01-1.44-4.282m3.102.069a18.03 18.03 0 01-.59-4.59c0-1.586.205-3.124.59-4.59m0 9.18a23.848 23.848 0 018.835 2.535M10.34 6.66a23.847 23.847 0 008.835-2.535m0 0A23.74 23.74 0 0018.795 3m.38 1.125a23.91 23.91 0 011.014 5.395m-1.014 8.855c-.118.38-.245.754-.38 1.125m.38-1.125a23.91 23.91 0 001.014-5.395m0-3.46c.495.413.811 1.035.811 1.73 0 .695-.316 1.317-.811 1.73m0-3.46a24.347 24.347 0 010 3.46',
    },
    {
        title: 'Creative agencies',
        description: 'Multi-brand workspaces, client incubation, ownership transfers, and partner tier rewards.',
        iconPath: 'M2.25 12.75V12A2.25 2.25 0 014.5 9.75h15A2.25 2.25 0 0121.75 12v.75m-8.69-6.44l-2.12-2.12a1.5 1.5 0 00-1.061-.44H4.5A2.25 2.25 0 002.25 6v12a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9a2.25 2.25 0 00-2.25-2.25h-5.379a1.5 1.5 0 01-1.06-.44z',
    },
    {
        title: 'Brand managers',
        description: 'Guidelines, approvals, and version control in one place. No more "is this the latest?"',
        iconPath: 'M9.53 16.122a3 3 0 00-5.78 1.128 2.25 2.25 0 01-2.4 2.245 4.5 4.5 0 008.4-2.245c0-.399-.078-.78-.22-1.128zm0 0a15.998 15.998 0 003.388-1.62m-5.043-.025a15.994 15.994 0 011.622-3.395m3.42 3.42a15.995 15.995 0 004.764-4.648l3.876-5.814a1.151 1.151 0 00-1.597-1.597L14.146 6.32a15.996 15.996 0 00-4.649 4.763m3.42 3.42a6.776 6.776 0 00-3.42-3.42',
    },
]

export default function MarketingBenefits() {
    const { auth, signup_enabled } = usePage().props
    const earlyHref = auth?.user ? '/app/overview' : signup_enabled !== false ? '/gateway?mode=register' : '/contact'
    const earlyLabel = auth?.user ? 'Go to app' : 'Get early access'

    return (
        <MarketingLayout>
            <section className="px-6 pt-16 pb-8 lg:px-8">
                <div className="mx-auto max-w-3xl text-center">
                    <p className="text-sm font-semibold uppercase tracking-[0.2em] text-violet-400/90">Benefits</p>
                    <h1 className="mt-3 font-display text-4xl font-bold tracking-tight text-white sm:text-5xl lg:text-6xl text-balance">
                        Why teams choose Jackpot
                    </h1>
                    <p className="mt-6 text-lg text-white/50 leading-relaxed">
                        Outcomes you can explain to leadership in one sentence — without selling "another asset tool."
                    </p>
                </div>
            </section>

            {/* Benefits grid */}
            <section className="border-t border-white/[0.06] py-20 sm:py-24">
                <div className="mx-auto max-w-7xl px-6 lg:px-8">
                    <div className="grid grid-cols-1 gap-6 sm:grid-cols-2">
                        {BENEFITS.map((b) => (
                            <div
                                key={b.title}
                                className="rounded-2xl bg-white/[0.03] ring-1 ring-white/[0.06] hover:ring-white/[0.1] transition-[ring-color] p-8 sm:p-10 flex flex-col"
                            >
                                <div className="flex items-start gap-4 mb-4">
                                    <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-violet-500/20 to-violet-500/10 ring-1 ring-violet-500/20">
                                        <svg className="h-5 w-5 text-violet-300" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                            <path strokeLinecap="round" strokeLinejoin="round" d={b.iconPath} />
                                        </svg>
                                    </div>
                                    <div>
                                        <h2 className="font-display text-xl font-bold text-white tracking-tight">{b.title}</h2>
                                    </div>
                                </div>
                                <p className="text-sm text-white/45 leading-relaxed flex-1">{b.body}</p>
                                <div className="mt-6 rounded-xl bg-white/[0.04] ring-1 ring-white/[0.06] px-5 py-4 flex items-center gap-4">
                                    <span className="text-2xl font-bold text-violet-300">{b.metric.value}</span>
                                    <span className="text-xs text-white/35 leading-tight">{b.metric.label}</span>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            </section>

            {/* Who it's for */}
            <section className="border-t border-white/[0.06] py-20 sm:py-24">
                <div className="mx-auto max-w-7xl px-6 lg:px-8">
                    <SectionHeader
                        eyebrow="Built for"
                        title="Brand-led organizations"
                        description="Agencies, creative teams, and marketing orgs that can't afford generic storage stories."
                        className="mb-14"
                    />
                    <div className="grid grid-cols-1 gap-6 sm:grid-cols-3">
                        {WHO_ITS_FOR.map((item) => (
                            <div
                                key={item.title}
                                className="rounded-2xl bg-white/[0.03] p-8 ring-1 ring-white/[0.06] hover:ring-white/[0.1] transition-[box-shadow,ring-color]"
                            >
                                <div className="mb-5 flex h-11 w-11 items-center justify-center rounded-xl bg-gradient-to-br from-violet-500/20 to-purple-500/10 ring-1 ring-violet-500/20">
                                    <svg className="h-5 w-5 text-violet-300" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" d={item.iconPath} />
                                    </svg>
                                </div>
                                <h3 className="text-lg font-semibold text-white">{item.title}</h3>
                                <p className="mt-3 text-sm text-white/45 leading-relaxed">{item.description}</p>
                            </div>
                        ))}
                    </div>
                </div>
            </section>

            <CTASection
                title="Put execution on the same level as creativity"
                subtitle="See how Jackpot replaces tool sprawl with a single brand execution layer."
                primaryCta={{ text: earlyLabel, href: earlyHref }}
                secondaryCta={{ text: 'See pricing', href: '/pricing' }}
            />
        </MarketingLayout>
    )
}
