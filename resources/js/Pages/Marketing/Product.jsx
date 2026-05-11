import { usePage } from '@inertiajs/react'
import MarketingLayout from '../../Components/Marketing/MarketingLayout'
import SectionHeader from '../../Components/Marketing/SectionHeader'
import CTASection from '../../Components/Marketing/CTASection'

const SYSTEMS = [
    {
        title: 'Asset System',
        eyebrow: '01',
        description: 'One source of truth for what ships — not a folder dump. Brand-scoped structure with collections and handoffs.',
        details: [
            { label: 'Upload & organize', text: 'Drag-and-drop with AI metadata, categories, and brand-level scoping' },
            { label: 'Version control', text: 'Track revisions with clear approval states — 5 to 250 versions per asset' },
            { label: 'Collections', text: 'Curate campaign sets, partner kits, and deliverables without duplicating files' },
            { label: 'Sharing', text: 'Download links with expiration, passwords, access restrictions, and revocation' },
        ],
        color: 'violet',
    },
    {
        title: 'Brand System',
        eyebrow: '02',
        description: 'Guidelines and signals stay attached to the work, not buried in a PDF.',
        details: [
            { label: 'Guidelines editor', text: 'Color palettes, typography, voice, and usage rules — editable and always current' },
            { label: 'Brand portal', text: 'Public-facing hub for partners and agencies to access approved assets' },
            { label: 'Consistency checks', text: 'AI surfaces misalignment before it reaches the client' },
            { label: 'Custom metadata', text: 'Tag assets with your own fields — campaigns, regions, licenses, anything' },
        ],
        color: 'violet',
    },
    {
        title: 'AI System',
        eyebrow: '03',
        description: 'Suggestions across metadata, tagging, and creative steps — with human review before anything changes.',
        details: [
            { label: 'Auto-tagging', text: 'AI generates tags, descriptions, and categories on upload — 1 credit each' },
            { label: 'Brand research', text: 'Extract intelligence from brand documents, websites, and competitor analysis' },
            { label: 'Generative editor', text: 'Edit images and generate new ones within brand guidelines' },
            { label: 'Video insights', text: 'Per-minute AI analysis of video content with structured output' },
            { label: 'Audio insights', text: 'Transcripts, mood, and summaries for MP3 / WAV / AAC — 1 credit + 1 per minute' },
        ],
        color: 'emerald',
    },
    {
        title: 'Workflow System',
        eyebrow: '04',
        description: 'Approvals that protect the brand without stalling launches. Roles tuned for agencies, in-house, and partners.',
        details: [
            { label: 'Approval workflows', text: 'Route reviews to the right roles — preserve history and keep states clear' },
            { label: 'Roles & permissions', text: 'Owner, admin, editor, viewer, creator — scoped per brand or company' },
            { label: 'Creator Module', text: 'Give external freelancers scoped upload access with seat-based billing' },
            { label: 'Agency transfers', text: 'Incubate client workspaces, then hand off ownership when they\u2019re ready' },
        ],
        color: 'amber',
    },
]

const COLOR_ACCENTS = {
    violet: { eyebrow: 'text-violet-400/80', dot: 'bg-violet-400/60', ring: 'ring-violet-500/15', label: 'text-violet-300' },
    emerald: { eyebrow: 'text-emerald-400/80', dot: 'bg-emerald-400/60', ring: 'ring-emerald-500/15', label: 'text-emerald-300' },
    amber: { eyebrow: 'text-amber-400/80', dot: 'bg-amber-400/60', ring: 'ring-amber-500/15', label: 'text-amber-300' },
}

export default function MarketingProduct() {
    const { auth, signup_enabled } = usePage().props
    const earlyHref = auth?.user ? '/app/overview' : signup_enabled !== false ? '/gateway?mode=register' : '/contact'
    const earlyLabel = auth?.user ? 'Go to app' : 'Get early access'

    return (
        <MarketingLayout>
            <section className="px-6 pt-16 pb-8 lg:px-8">
                <div className="mx-auto max-w-3xl text-center">
                    <p className="text-sm font-semibold uppercase tracking-[0.2em] text-violet-400/90">Product</p>
                    <h1 className="mt-3 font-display text-4xl font-bold tracking-tight text-white sm:text-5xl lg:text-6xl text-balance">
                        How Jackpot works
                    </h1>
                    <p className="mt-6 text-lg text-white/50 leading-relaxed">
                        Four systems. One execution layer — so brand standards show up in the work, not only in the handbook.
                    </p>
                </div>
            </section>

            {/* Systems */}
            <section className="border-t border-white/[0.06] py-20 sm:py-24">
                <div className="mx-auto max-w-5xl px-6 lg:px-8 space-y-12">
                    {SYSTEMS.map((sys) => {
                        const c = COLOR_ACCENTS[sys.color]
                        return (
                            <div
                                key={sys.title}
                                className={`rounded-2xl bg-white/[0.02] ring-1 ring-white/[0.06] hover:ring-white/[0.09] transition-[ring-color] overflow-hidden`}
                            >
                                <div className="p-8 sm:p-10">
                                    <div className="flex items-center gap-3 mb-4">
                                        <span className={`text-xs font-mono font-bold ${c.eyebrow}`}>{sys.eyebrow}</span>
                                        <h2 className="font-display text-2xl font-bold text-white tracking-tight sm:text-3xl">{sys.title}</h2>
                                    </div>
                                    <p className="text-base text-white/45 max-w-2xl">{sys.description}</p>

                                    <div className="mt-8 grid grid-cols-1 sm:grid-cols-2 gap-6">
                                        {sys.details.map((d) => (
                                            <div key={d.label} className={`rounded-xl bg-white/[0.02] ${c.ring} ring-1 p-5`}>
                                                <h4 className={`text-sm font-semibold ${c.label}`}>{d.label}</h4>
                                                <p className="mt-2 text-sm text-white/45 leading-relaxed">{d.text}</p>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            </div>
                        )
                    })}
                </div>
            </section>

            {/* Bottom section */}
            <section className="border-t border-white/[0.06] py-20">
                <div className="mx-auto max-w-7xl px-6 lg:px-8">
                    <SectionHeader
                        title="See the difference in outcomes"
                        description="Less rework. Fewer off-brand slips. Faster reviews. That's the payoff of execution-first design."
                        className="mb-0"
                    />
                </div>
            </section>

            <CTASection
                title="Ready to explore?"
                subtitle="Walk through benefits for your team — or jump straight in."
                primaryCta={{ text: earlyLabel, href: earlyHref }}
                secondaryCta={{ text: 'See pricing', href: '/pricing' }}
            />
        </MarketingLayout>
    )
}
