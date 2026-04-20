import { useState } from 'react'
import { Link, useForm, usePage } from '@inertiajs/react'
import MarketingLayout from '../Components/Marketing/MarketingLayout'
import NewsletterSignup from '../Components/Marketing/NewsletterSignup'
import SalesInquiryForm from '../Components/Marketing/SalesInquiryForm'

const PLAN_CONTEXTS = {
    enterprise: {
        title: 'Enterprise inquiry',
        subtitle: 'Dedicated infrastructure, custom integrations, and volume pricing for large organizations.',
        prompts: ['Team size and number of brands', 'Compliance or security requirements', 'Current tools you want to replace'],
        defaultTab: 'sales',
    },
    agency: {
        title: 'Agency partnership',
        subtitle: 'Multi-brand management, client incubation, transfers, and partner rewards.',
        prompts: ['How many client brands you manage', 'Your current asset management workflow', 'Interest in incubation & referral programs'],
        defaultTab: 'sales',
    },
    default: {
        title: 'Get in touch',
        subtitle: 'Have questions about Jackpot? We read every message.',
        prompts: ['Your workspace or brand name', 'What you\'re looking to accomplish', 'Any specific features you want to discuss'],
        defaultTab: 'quick',
    },
}

const PLAN_KEY = (plan) => (plan && PLAN_CONTEXTS[plan] ? plan : 'default')

export default function Contact({ plan }) {
    const { auth, flash } = usePage().props
    const planKey = PLAN_KEY(plan)
    const ctx = PLAN_CONTEXTS[planKey]

    // `sales` tab = qualified demo-request form (longer), `quick` = lightweight
    // contact form. Enterprise/agency plan routes land on sales by default
    // because those visitors are already self-qualifying by picking that plan.
    const [tab, setTab] = useState(ctx.defaultTab)

    const backHref = auth?.user ? '/app/billing' : '/'
    const backLabel = auth?.user ? 'Back to billing' : 'Back to home'

    return (
        <MarketingLayout>
            <section className="px-6 pt-16 pb-8 lg:px-8">
                <div className="mx-auto max-w-3xl text-center">
                    <p className="text-sm font-semibold uppercase tracking-[0.2em] text-violet-400/90">Contact</p>
                    <h1 className="mt-3 font-display text-4xl font-bold tracking-tight text-white sm:text-5xl text-balance">
                        {ctx.title}
                    </h1>
                    <p className="mt-6 text-lg text-white/50 leading-relaxed">{ctx.subtitle}</p>
                </div>
            </section>

            <section className="border-t border-white/[0.06] py-16 sm:py-20">
                <div className="mx-auto max-w-3xl px-6 lg:px-8">
                    {flash?.info && (
                        <div
                            className="mb-8 rounded-2xl border border-emerald-400/25 bg-emerald-500/10 px-5 py-4 text-sm text-emerald-100/90"
                            role="status"
                        >
                            {flash.info}
                        </div>
                    )}

                    <div className="mb-6 flex gap-1 rounded-xl bg-white/[0.03] p-1 ring-1 ring-white/[0.06] w-full sm:w-fit">
                        <TabButton active={tab === 'quick'} onClick={() => setTab('quick')}>
                            Quick message
                        </TabButton>
                        <TabButton active={tab === 'sales'} onClick={() => setTab('sales')}>
                            Talk to sales
                        </TabButton>
                    </div>

                    <div className="rounded-2xl bg-white/[0.02] p-8 sm:p-10 ring-1 ring-white/[0.06]">
                        {tab === 'quick' ? (
                            <QuickContactForm planKey={planKey} prompts={ctx.prompts} />
                        ) : (
                            <div>
                                <div className="mb-6">
                                    <h2 className="font-display text-xl font-semibold text-white">Request a demo</h2>
                                    <p className="mt-1 text-sm text-white/45">
                                        Tell us a bit about your team and we'll set up a walk-through tailored to your workflow.
                                    </p>
                                </div>
                                <SalesInquiryForm planInterest={planKey} />
                            </div>
                        )}
                    </div>

                    <div className="mt-10 rounded-2xl bg-white/[0.02] p-8 ring-1 ring-white/[0.06]">
                        <div className="mb-4">
                            <p className="text-sm font-semibold text-white">Not ready to talk yet?</p>
                            <p className="mt-1 text-sm text-white/45">Get occasional product updates in your inbox.</p>
                        </div>
                        <NewsletterSignup source="/contact" />
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

function TabButton({ active, onClick, children }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={`flex-1 sm:flex-none rounded-lg px-5 py-2 text-sm font-semibold transition-colors ${
                active
                    ? 'bg-white text-gray-900 shadow-sm'
                    : 'text-white/55 hover:text-white hover:bg-white/[0.04]'
            }`}
        >
            {children}
        </button>
    )
}

function QuickContactForm({ planKey, prompts }) {
    const { data, setData, post, processing, errors, wasSuccessful, reset } = useForm({
        name: '',
        email: '',
        company: '',
        plan_interest: planKey,
        message: '',
        consent_marketing: false,
        source: '/contact',
        website: '',
    })

    const submit = (e) => {
        e.preventDefault()
        post('/contact', {
            preserveScroll: true,
            onSuccess: () => reset('name', 'email', 'company', 'message', 'consent_marketing'),
        })
    }

    return (
        <form onSubmit={submit} noValidate className="space-y-5">
            <div className="absolute left-[-9999px] top-auto h-px w-px overflow-hidden" aria-hidden="true">
                <label>
                    Website
                    <input
                        type="text"
                        tabIndex={-1}
                        autoComplete="off"
                        value={data.website}
                        onChange={(e) => setData('website', e.target.value)}
                    />
                </label>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <Field label="Name" error={errors.name}>
                    <input
                        type="text"
                        autoComplete="name"
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        className={inputClass}
                    />
                </Field>
                <Field label="Company" error={errors.company}>
                    <input
                        type="text"
                        autoComplete="organization"
                        value={data.company}
                        onChange={(e) => setData('company', e.target.value)}
                        className={inputClass}
                    />
                </Field>
            </div>

            <Field label="Email" required error={errors.email}>
                <input
                    type="email"
                    required
                    autoComplete="email"
                    value={data.email}
                    onChange={(e) => setData('email', e.target.value)}
                    className={inputClass}
                />
            </Field>

            <Field label="Message" error={errors.message} hint="What are you looking to accomplish?">
                <textarea
                    rows={5}
                    value={data.message}
                    onChange={(e) => setData('message', e.target.value)}
                    className={`${inputClass} resize-y`}
                />
            </Field>

            <label className="flex items-start gap-2 text-xs text-white/45 cursor-pointer">
                <input
                    type="checkbox"
                    checked={data.consent_marketing}
                    onChange={(e) => setData('consent_marketing', e.target.checked)}
                    className="mt-0.5 h-3.5 w-3.5 rounded border-white/20 bg-white/[0.03] text-indigo-500 focus:ring-indigo-400/60"
                />
                <span>Also send me occasional product updates. (Optional — we'll reply to your message either way.)</span>
            </label>

            <div className="pt-2 flex flex-col sm:flex-row sm:items-center gap-3">
                <button
                    type="submit"
                    disabled={processing}
                    className="inline-flex items-center justify-center rounded-xl bg-white px-6 py-3 text-sm font-semibold text-gray-900 shadow-lg shadow-black/20 hover:bg-gray-100 transition-colors disabled:cursor-not-allowed disabled:opacity-40"
                >
                    {processing ? 'Sending…' : 'Send message'}
                </button>
                <span className="text-xs text-white/35">Usually we reply within one business day.</span>
            </div>

            {wasSuccessful && (
                <p className="text-sm text-emerald-300/90" role="status">
                    Thanks — your message is on its way.
                </p>
            )}

            <div className="mt-8 border-t border-white/[0.06] pt-6">
                <p className="text-xs font-semibold uppercase tracking-wider text-white/30 mb-3">Helpful to include</p>
                <ul className="space-y-2">
                    {prompts.map((prompt) => (
                        <li key={prompt} className="flex items-center gap-2 text-sm text-white/45">
                            <span className="h-1 w-1 rounded-full bg-indigo-400/60 flex-shrink-0" />
                            {prompt}
                        </li>
                    ))}
                </ul>
            </div>
        </form>
    )
}

const inputClass =
    'w-full rounded-xl bg-white/[0.03] px-4 py-3 text-sm text-white placeholder:text-white/30 ring-1 ring-inset ring-white/[0.08] focus:ring-2 focus:ring-indigo-400/60 focus:outline-none transition-shadow'

function Field({ label, required, error, hint, children }) {
    return (
        <label className="block">
            <div className="flex items-baseline justify-between mb-1.5">
                <span className="text-xs font-semibold uppercase tracking-wider text-white/50">
                    {label}
                    {required && <span className="text-rose-300/80 ml-1">*</span>}
                </span>
                {hint && <span className="text-xs text-white/30">{hint}</span>}
            </div>
            {children}
            {error && <p className="mt-1 text-xs text-rose-300/90">{error}</p>}
        </label>
    )
}
