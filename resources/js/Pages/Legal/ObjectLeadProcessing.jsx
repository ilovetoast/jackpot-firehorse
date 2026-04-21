import { Head, Link, useForm, usePage } from '@inertiajs/react'
import MarketingLayout from '../../Components/Marketing/MarketingLayout'

/**
 * Public Art. 21 self-service for visitors who submitted contact / newsletter / sales forms
 * before having an account. See ContactLeadController::objectToProcessing.
 */
export default function ObjectLeadProcessing() {
    const { flash } = usePage().props
    const { data, setData, post, processing, errors } = useForm({
        email: '',
        website: '',
    })

    const submit = (e) => {
        e.preventDefault()
        post('/privacy/contact-leads/object-to-processing', { preserveScroll: true })
    }

    return (
        <MarketingLayout cinematicBackdrop>
            <Head title="Object to lead processing — Jackpot" />
            <section className="relative">
                <div className="mx-auto max-w-lg px-6 lg:px-8 py-16 sm:py-24">
                    <p className="text-xs font-semibold uppercase tracking-[0.2em] text-white/40">Privacy</p>
                    <h1 className="mt-3 font-display text-3xl font-bold tracking-tight text-white sm:text-4xl">
                        Object to processing of lead data
                    </h1>
                    <p className="mt-4 text-sm text-white/60 leading-relaxed">
                        If you previously contacted us or signed up for updates using an email address
                        before you had a Jackpot account, you can object to our processing of that
                        marketing and lead information here. This does not replace rights available
                        to account holders in the app — see our{' '}
                        <Link href="/privacy#s8" className="text-white/80 underline hover:text-white">
                            Privacy Policy
                        </Link>
                        .
                    </p>

                    {flash?.info && (
                        <div
                            className="mt-8 rounded-2xl border border-emerald-400/25 bg-emerald-500/10 px-5 py-4 text-sm text-emerald-100/90"
                            role="status"
                        >
                            {flash.info}
                        </div>
                    )}

                    <form onSubmit={submit} className="mt-10 space-y-6">
                        <div>
                            <label htmlFor="email" className="block text-sm font-medium text-white/80">
                                Email address
                            </label>
                            <input
                                id="email"
                                name="email"
                                type="email"
                                autoComplete="email"
                                required
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                className="mt-1.5 block w-full rounded-md border border-white/10 bg-white/5 px-3 py-2 text-sm text-white placeholder:text-white/30 focus:border-indigo-400/50 focus:outline-none focus:ring-1 focus:ring-indigo-400/40"
                                placeholder="you@company.com"
                            />
                            {errors.email && <p className="mt-1 text-sm text-red-300">{errors.email}</p>}
                        </div>
                        {/* Honeypot — leave empty */}
                        <div className="hidden" aria-hidden="true">
                            <label htmlFor="website">Website</label>
                            <input
                                id="website"
                                name="website"
                                type="text"
                                tabIndex={-1}
                                autoComplete="off"
                                value={data.website}
                                onChange={(e) => setData('website', e.target.value)}
                            />
                        </div>
                        <button
                            type="submit"
                            disabled={processing}
                            className="inline-flex w-full justify-center rounded-md bg-indigo-500 px-4 py-2.5 text-sm font-semibold text-white shadow hover:bg-indigo-400 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-300 disabled:opacity-60"
                        >
                            {processing ? 'Submitting…' : 'Submit objection'}
                        </button>
                    </form>

                    <p className="mt-10 text-xs text-white/40">
                        <Link href="/privacy" className="text-white/50 hover:text-white/70">
                            ← Back to Privacy Policy
                        </Link>
                    </p>
                </div>
            </section>
        </MarketingLayout>
    )
}
