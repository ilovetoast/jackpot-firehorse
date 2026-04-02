import { usePage } from '@inertiajs/react'
import MarketingLayout from '../../Components/Marketing/MarketingLayout'
import HeroSection from '../../Components/Marketing/HeroSection'
import SectionHeader from '../../Components/Marketing/SectionHeader'
import FeatureGrid from '../../Components/Marketing/FeatureGrid'
import ComparisonBlock from '../../Components/Marketing/ComparisonBlock'
import CTASection from '../../Components/Marketing/CTASection'

const ICON = {
    spark: 'M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z',
    guide: 'M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125',
    flow: 'M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z',
}

export default function MarketingHome() {
    const { auth, signup_enabled } = usePage().props

    const primaryHref = auth?.user ? '/app/overview' : signup_enabled !== false ? '/gateway?mode=register' : '/contact'
    const primaryLabel = auth?.user ? 'Go to app' : 'Get early access'

    return (
        <MarketingLayout>
            <HeroSection
                title="Brand execution, not asset management"
                subtitle="One place to run creative work so every deliverable stays on-brand — from intake to approval — without the chaos of folders and one-off requests."
                primaryCta={{ text: primaryLabel, href: primaryHref }}
                secondaryCta={{ text: 'See how it works', href: '/product' }}
            />

            <section className="border-t border-white/[0.06] py-24 sm:py-28">
                <div className="mx-auto max-w-7xl px-6 lg:px-8">
                    <SectionHeader
                        eyebrow="The problem"
                        title="Creativity scales. Consistency rarely does."
                        description="Teams ship more channels, more variants, and more partners — while brand rules live in PDFs and assets scatter across drives. The gap isn’t storage; it’s execution."
                    />
                    <div className="mx-auto mt-16 max-w-3xl rounded-2xl bg-white/[0.02] p-10 ring-1 ring-white/[0.06]">
                        <ul className="space-y-4 text-lg text-white/55">
                            <li className="flex gap-3">
                                <span className="text-indigo-400/80">—</span>
                                <span>Version sprawl and unclear “what’s approved” slow every launch.</span>
                            </li>
                            <li className="flex gap-3">
                                <span className="text-indigo-400/80">—</span>
                                <span>Agencies and in-house teams redo work because signals don’t reach creators.</span>
                            </li>
                            <li className="flex gap-3">
                                <span className="text-indigo-400/80">—</span>
                                <span>Brand safety becomes a bottleneck instead of a guardrail.</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </section>

            <section className="border-t border-white/[0.06] py-24 sm:py-28">
                <div className="mx-auto max-w-7xl px-6 lg:px-8">
                    <SectionHeader
                        eyebrow="Contrast"
                        title="Built for outcomes, not file piles"
                        description="Traditional tools optimize for uploads. Jackpot optimizes for how brands actually ship work."
                        className="mb-16"
                    />
                    <ComparisonBlock
                        leftTitle="Traditional DAM"
                        rightTitle="Jackpot"
                        leftItems={[
                            'Starts and ends with “where is the file?”',
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

            <section className="border-t border-white/[0.06] py-24 sm:py-28">
                <div className="mx-auto max-w-7xl px-6 lg:px-8">
                    <SectionHeader
                        eyebrow="Pillars"
                        title="Three engines. One brand standard."
                        className="mb-16"
                    />
                    <FeatureGrid
                        columns={3}
                        items={[
                            {
                                title: 'Brand Intelligence',
                                description: 'Signals from guidelines and assets inform how work should look, read, and ship — before mistakes reach the client.',
                                iconPath: ICON.spark,
                            },
                            {
                                title: 'Guided Creation',
                                description: 'Creators move faster when the right context, templates, and checks meet them inside the workflow.',
                                iconPath: ICON.guide,
                            },
                            {
                                title: 'Agency Workflows',
                                description: 'Multi-brand operations, approvals, and shared spaces that mirror how agencies and clients collaborate.',
                                iconPath: ICON.flow,
                            },
                        ]}
                    />
                </div>
            </section>

            <section className="border-t border-white/[0.06] py-24 sm:py-28">
                <div className="mx-auto max-w-7xl px-6 lg:px-8">
                    <div className="grid grid-cols-1 gap-16 lg:grid-cols-2 lg:items-center">
                        <div>
                            <p className="text-sm font-semibold uppercase tracking-[0.2em] text-emerald-400/90">AI</p>
                            <h2 className="mt-3 text-3xl font-bold tracking-tight text-white sm:text-4xl lg:text-5xl text-balance">
                                Not a feature. The fabric.
                            </h2>
                            <p className="mt-6 text-lg text-white/50 leading-relaxed">
                                From metadata and tagging to creative assistance and review, AI runs through Jackpot with human gates and auditability — so speed never trades off brand trust.
                            </p>
                        </div>
                        <div className="rounded-2xl overflow-hidden ring-1 ring-white/[0.08] bg-gradient-to-br from-white/[0.04] to-indigo-950/20 aspect-[4/3] flex items-center justify-center p-10">
                            <p className="text-center text-sm text-white/40 max-w-xs leading-relaxed">
                                Intelligence layered across assets, workflows, and approvals — always under your rules.
                            </p>
                        </div>
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
