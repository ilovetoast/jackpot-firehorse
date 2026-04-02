import { useEffect, useState } from 'react'
import { Link, usePage } from '@inertiajs/react'
import MarketingLayout from '../Components/Marketing/MarketingLayout'
import HeroSection from '../Components/Marketing/HeroSection'

export default function Contact({ plan }) {
    const { auth, flash } = usePage().props
    const isEnterprise = plan === 'enterprise'
    const [salesMailto, setSalesMailto] = useState(() => {
        const subject = isEnterprise ? 'Enterprise Plan Inquiry' : 'Contact Request'
        return `mailto:sales@jackpot.local?subject=${encodeURIComponent(subject)}`
    })

    useEffect(() => {
        const host = typeof window !== 'undefined' ? window.location.hostname || 'jackpot.local' : 'jackpot.local'
        const subject = isEnterprise ? 'Enterprise Plan Inquiry' : 'Contact Request'
        setSalesMailto(`mailto:sales@${host}?subject=${encodeURIComponent(subject)}`)
    }, [isEnterprise])

    const backHref = auth?.user ? '/app/billing' : '/'
    const backLabel = auth?.user ? 'Back to billing' : 'Back to home'

    return (
        <MarketingLayout>
            <HeroSection
                title={isEnterprise ? 'Contact sales' : 'Contact us'}
                subtitle={
                    isEnterprise
                        ? 'Enterprise is a custom plan with dedicated infrastructure. Our team will reach out to discuss your needs and provide a tailored quote.'
                        : "Have questions? We'd love to hear from you — email us or connect through your account manager."
                }
                secondaryCta={{ text: backLabel, href: backHref }}
            />

            <section className="border-t border-white/[0.06] py-16 sm:py-20">
                <div className="mx-auto max-w-2xl px-6 lg:px-8">
                    {flash?.info && (
                        <div
                            className="mb-8 rounded-2xl border border-indigo-400/25 bg-indigo-500/10 px-5 py-4 text-sm text-indigo-100/90"
                            role="status"
                        >
                            {flash.info}
                        </div>
                    )}

                    <div className="rounded-2xl bg-white/[0.02] p-8 sm:p-10 ring-1 ring-white/[0.06]">
                        <p className="text-sm font-semibold uppercase tracking-[0.2em] text-violet-400/85">Reach us</p>
                        <p className="mt-4 text-lg text-white/55 leading-relaxed">
                            {isEnterprise
                                ? 'Tell us about scale, compliance, and how your org runs creative — we’ll follow up with next steps.'
                                : 'We read every message. For fastest routing, include your workspace or brand name if you already use Jackpot.'}
                        </p>
                        <div className="mt-8 flex flex-col sm:flex-row sm:items-center gap-4">
                            <a
                                href={salesMailto}
                                className="inline-flex items-center justify-center rounded-xl bg-white px-6 py-3 text-sm font-semibold text-gray-900 shadow-lg shadow-black/20 hover:bg-gray-100 transition-colors"
                            >
                                Email sales
                            </a>
                            <span className="text-sm text-white/40">or reply through your account manager</span>
                        </div>
                        <p className="mt-8 text-sm text-white/35 break-all">
                            <span className="text-white/45">Direct: </span>
                            <a
                                href={salesMailto}
                                className="font-medium text-indigo-400/90 hover:text-indigo-300 transition-colors"
                            >
                                {salesMailto.replace(/^mailto:/, '').split('?')[0]}
                            </a>
                        </p>
                    </div>

                    <div className="mt-10 flex flex-wrap gap-x-6 gap-y-2 text-sm">
                        <Link href="/product" className="font-semibold text-indigo-400 hover:text-indigo-300 transition-colors">
                            Product overview →
                        </Link>
                        <Link href="/agency" className="font-semibold text-white/50 hover:text-white/75 transition-colors">
                            Agency program →
                        </Link>
                    </div>
                </div>
            </section>
        </MarketingLayout>
    )
}
