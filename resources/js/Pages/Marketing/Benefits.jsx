import { usePage } from '@inertiajs/react'
import MarketingLayout from '../../Components/Marketing/MarketingLayout'
import SectionHeader from '../../Components/Marketing/SectionHeader'
import CTASection from '../../Components/Marketing/CTASection'

const BENEFITS = [
    {
        title: 'Consistency at scale',
        body: 'The more channels and partners you add, the harder it is to keep one voice. Jackpot aligns execution so “on-brand” is the default — not a weekly fire drill.',
    },
    {
        title: 'Faster execution',
        body: 'Creators spend time on craft, not hunting files or re-asking for rules. Context, assets, and checks live in the same flow.',
    },
    {
        title: 'Fewer approval cycles',
        body: 'Review happens at the right step with the right people — so you stop looping the same fixes after everything is “final.”',
    },
    {
        title: 'Brand safety',
        body: 'Guardrails and audit trails give marketing and legal confidence without turning every request into a ticket queue.',
    },
]

function VisualPlaceholder({ label, className = '' }) {
    return (
        <div
            className={`rounded-2xl ring-1 ring-white/[0.08] bg-gradient-to-br from-white/[0.04] to-indigo-950/30 flex items-center justify-center min-h-[200px] ${className}`}
            aria-hidden
        >
            <span className="text-xs font-medium uppercase tracking-wider text-white/30">{label}</span>
        </div>
    )
}

export default function MarketingBenefits() {
    const { auth, signup_enabled } = usePage().props
    const earlyHref = auth?.user ? '/app/overview' : signup_enabled !== false ? '/gateway?mode=register' : '/contact'
    const earlyLabel = auth?.user ? 'Go to app' : 'Get early access'

    return (
        <MarketingLayout>
            <section className="px-6 pt-16 pb-8 lg:px-8">
                <div className="mx-auto max-w-3xl text-center">
                    <p className="text-sm font-semibold uppercase tracking-[0.2em] text-violet-400/90">Benefits</p>
                    <h1 className="mt-3 text-4xl font-bold tracking-tight text-white sm:text-5xl lg:text-6xl text-balance">
                        Why teams choose Jackpot
                    </h1>
                    <p className="mt-6 text-lg text-white/50 leading-relaxed">
                        Outcomes you can explain to leadership in one sentence — without selling “another DAM.”
                    </p>
                </div>
            </section>

            <section className="py-16 sm:py-20">
                <div className="mx-auto max-w-7xl px-6 lg:px-8">
                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-12 lg:gap-16 items-start">
                        <div className="space-y-12">
                            {BENEFITS.slice(0, 2).map((b) => (
                                <div key={b.title} className="max-w-xl">
                                    <h2 className="text-2xl font-bold text-white tracking-tight">{b.title}</h2>
                                    <p className="mt-4 text-base text-white/50 leading-relaxed">{b.body}</p>
                                </div>
                            ))}
                        </div>
                        <div className="space-y-6">
                            <VisualPlaceholder label="Workflow preview" className="aspect-[4/3]" />
                            <VisualPlaceholder label="Brand signals" className="aspect-video" />
                        </div>
                    </div>
                </div>
            </section>

            <section className="border-t border-white/[0.06] py-16 sm:py-20">
                <div className="mx-auto max-w-7xl px-6 lg:px-8">
                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-12 lg:gap-16 items-start lg:flex-row-reverse">
                        <div className="space-y-6 lg:order-first">
                            <VisualPlaceholder label="Approval path" className="aspect-video" />
                            <VisualPlaceholder label="Multi-brand view" className="aspect-[4/3]" />
                        </div>
                        <div className="space-y-12 lg:order-last">
                            {BENEFITS.slice(2).map((b) => (
                                <div key={b.title} className="max-w-xl lg:ml-auto">
                                    <h2 className="text-2xl font-bold text-white tracking-tight">{b.title}</h2>
                                    <p className="mt-4 text-base text-white/50 leading-relaxed">{b.body}</p>
                                </div>
                            ))}
                        </div>
                    </div>
                </div>
            </section>

            <section className="border-t border-white/[0.06] py-20">
                <div className="mx-auto max-w-7xl px-6 lg:px-8">
                    <SectionHeader title="Built for brand-led organizations" description="Agencies, creative teams, and marketing orgs that can’t afford generic storage stories." />
                </div>
            </section>

            <CTASection
                title="Put execution on the same level as creativity"
                primaryCta={{ text: earlyLabel, href: earlyHref }}
                secondaryCta={{ text: 'Built for agencies', href: '/agency' }}
            />
        </MarketingLayout>
    )
}
