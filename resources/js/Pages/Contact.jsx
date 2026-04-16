import { useEffect, useState } from 'react'
import { Link, usePage } from '@inertiajs/react'
import MarketingLayout from '../Components/Marketing/MarketingLayout'

const PLAN_CONTEXTS = {
    enterprise: {
        title: 'Enterprise inquiry',
        subtitle: 'Dedicated infrastructure, custom integrations, and volume pricing for large organizations.',
        prompts: ['Team size and number of brands', 'Compliance or security requirements', 'Current tools you want to replace'],
    },
    agency: {
        title: 'Agency partnership',
        subtitle: 'Multi-brand management, client incubation, transfers, and partner rewards.',
        prompts: ['How many client brands you manage', 'Your current asset management workflow', 'Interest in incubation & referral programs'],
    },
    default: {
        title: 'Get in touch',
        subtitle: 'Have questions about Jackpot? We read every message.',
        prompts: ['Your workspace or brand name', 'What you\'re looking to accomplish', 'Any specific features you want to discuss'],
    },
}

export default function Contact({ plan }) {
    const { auth, flash } = usePage().props
    const ctx = PLAN_CONTEXTS[plan] || PLAN_CONTEXTS.default

    const [salesMailto, setSalesMailto] = useState(() => {
        const subject = plan === 'enterprise' ? 'Enterprise Plan Inquiry' : plan === 'agency' ? 'Agency Partnership Inquiry' : 'Contact Request'
        return `mailto:sales@jackpot.local?subject=${encodeURIComponent(subject)}`
    })

    useEffect(() => {
        const host = typeof window !== 'undefined' ? window.location.hostname || 'jackpot.local' : 'jackpot.local'
        const subject = plan === 'enterprise' ? 'Enterprise Plan Inquiry' : plan === 'agency' ? 'Agency Partnership Inquiry' : 'Contact Request'
        setSalesMailto(`mailto:sales@${host}?subject=${encodeURIComponent(subject)}`)
    }, [plan])

    const backHref = auth?.user ? '/app/billing' : '/'
    const backLabel = auth?.user ? 'Back to billing' : 'Back to home'

    return (
        <MarketingLayout>
            <section className="px-6 pt-16 pb-8 lg:px-8">
                <div className="mx-auto max-w-3xl text-center">
                    <p className="text-sm font-semibold uppercase tracking-[0.2em] text-violet-400/90">Contact</p>
                    <h1 className="mt-3 text-4xl font-bold tracking-tight text-white sm:text-5xl text-balance">
                        {ctx.title}
                    </h1>
                    <p className="mt-6 text-lg text-white/50 leading-relaxed">{ctx.subtitle}</p>
                </div>
            </section>

            <section className="border-t border-white/[0.06] py-16 sm:py-20">
                <div className="mx-auto max-w-2xl px-6 lg:px-8">
                    {flash?.info && (
                        <div className="mb-8 rounded-2xl border border-indigo-400/25 bg-indigo-500/10 px-5 py-4 text-sm text-indigo-100/90" role="status">
                            {flash.info}
                        </div>
                    )}

                    <div className="rounded-2xl bg-white/[0.02] p-8 sm:p-10 ring-1 ring-white/[0.06]">
                        <div className="flex flex-col sm:flex-row sm:items-center gap-4 mb-8">
                            <a
                                href={salesMailto}
                                className="inline-flex items-center justify-center rounded-xl bg-white px-6 py-3 text-sm font-semibold text-gray-900 shadow-lg shadow-black/20 hover:bg-gray-100 transition-colors"
                            >
                                Email our team
                            </a>
                            <span className="text-sm text-white/40">or reply through your account manager</span>
                        </div>

                        <p className="text-sm text-white/35 mb-6">
                            <span className="text-white/45">Direct: </span>
                            <a href={salesMailto} className="font-medium text-indigo-400/90 hover:text-indigo-300 transition-colors">
                                {salesMailto.replace(/^mailto:/, '').split('?')[0]}
                            </a>
                        </p>

                        <div className="border-t border-white/[0.06] pt-6">
                            <p className="text-xs font-semibold uppercase tracking-wider text-white/30 mb-3">Helpful to include</p>
                            <ul className="space-y-2">
                                {ctx.prompts.map((prompt) => (
                                    <li key={prompt} className="flex items-center gap-2 text-sm text-white/45">
                                        <span className="h-1 w-1 rounded-full bg-indigo-400/60 flex-shrink-0" />
                                        {prompt}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    </div>

                    <div className="mt-10 flex flex-wrap gap-x-6 gap-y-2 text-sm">
                        <Link href="/product" className="font-semibold text-indigo-400 hover:text-indigo-300 transition-colors">
                            Product overview →
                        </Link>
                        <Link href="/pricing" className="font-semibold text-white/50 hover:text-white/75 transition-colors">
                            Pricing →
                        </Link>
                        <Link href="/agency" className="font-semibold text-white/50 hover:text-white/75 transition-colors">
                            Agency program →
                        </Link>
                        <Link href={backHref} className="font-semibold text-white/35 hover:text-white/55 transition-colors">
                            {backLabel} →
                        </Link>
                    </div>
                </div>
            </section>
        </MarketingLayout>
    )
}
