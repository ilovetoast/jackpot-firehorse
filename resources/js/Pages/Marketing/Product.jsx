import { usePage } from '@inertiajs/react'
import MarketingLayout from '../../Components/Marketing/MarketingLayout'
import SectionHeader from '../../Components/Marketing/SectionHeader'
import CTASection from '../../Components/Marketing/CTASection'

const GROUPS = [
    {
        title: 'Asset System',
        bullets: [
            'One source of truth for what ships — not a folder dump.',
            'Brand-scoped structure that matches how teams actually search.',
            'Collections and handoffs without duplicating files.',
        ],
    },
    {
        title: 'Brand System',
        bullets: [
            'Guidelines and signals stay attached to the work.',
            'Consistency checks where creators already are.',
            'Less “read the PDF” — more “the system nudges you right.”',
        ],
    },
    {
        title: 'AI System',
        bullets: [
            'Suggestions across metadata, tagging, and creative steps.',
            'Human review before anything customer-facing changes.',
            'Audit-friendly — speed without silent drift.',
        ],
    },
    {
        title: 'Workflow System',
        bullets: [
            'Approvals that protect the brand without stalling launches.',
            'Roles tuned for agencies, in-house, and partners.',
            'Shared spaces that respect who owns what.',
        ],
    },
]

export default function MarketingProduct() {
    const { auth, signup_enabled } = usePage().props
    const earlyHref = auth?.user ? '/app/overview' : signup_enabled !== false ? '/gateway?mode=register' : '/contact'
    const earlyLabel = auth?.user ? 'Go to app' : 'Get early access'

    return (
        <MarketingLayout>
            <section className="px-6 pt-16 pb-12 lg:px-8">
                <div className="mx-auto max-w-3xl text-center">
                    <p className="text-sm font-semibold uppercase tracking-[0.2em] text-indigo-400/90">Product</p>
                    <h1 className="mt-3 text-4xl font-bold tracking-tight text-white sm:text-5xl lg:text-6xl text-balance">
                        How Jackpot works
                    </h1>
                    <p className="mt-6 text-lg text-white/50 leading-relaxed">
                        Four systems. One execution layer — so brand standards show up in the work, not only in the handbook.
                    </p>
                </div>
            </section>

            <section className="border-t border-white/[0.06] py-24 sm:py-28">
                <div className="mx-auto max-w-5xl px-6 lg:px-8 space-y-16">
                    {GROUPS.map((g) => (
                        <div
                            key={g.title}
                            className="rounded-2xl bg-white/[0.02] p-10 sm:p-12 ring-1 ring-white/[0.06] hover:ring-white/[0.09] transition-[ring-color]"
                        >
                            <h2 className="text-2xl font-bold text-white tracking-tight sm:text-3xl">{g.title}</h2>
                            <ul className="mt-8 space-y-4">
                                {g.bullets.map((line) => (
                                    <li key={line} className="flex gap-3 text-base text-white/55 leading-relaxed">
                                        <span className="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-indigo-400/80" aria-hidden />
                                        <span>{line}</span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ))}
                </div>
            </section>

            <section className="border-t border-white/[0.06] py-20">
                <div className="mx-auto max-w-7xl px-6 lg:px-8">
                    <SectionHeader
                        title="See the difference in outcomes"
                        description="Less rework. Fewer off-brand slips. Faster reviews."
                        className="mb-0"
                    />
                </div>
            </section>

            <CTASection
                title="Ready to explore?"
                subtitle="Walk through benefits for your team — or jump straight in."
                primaryCta={{ text: earlyLabel, href: earlyHref }}
                secondaryCta={{ text: 'Why teams choose Jackpot', href: '/benefits' }}
            />
        </MarketingLayout>
    )
}
