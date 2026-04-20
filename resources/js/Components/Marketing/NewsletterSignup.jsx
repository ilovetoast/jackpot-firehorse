import { useForm } from '@inertiajs/react'

/**
 * Small inline newsletter signup. Posts to /newsletter with explicit
 * marketing consent (required for CAN-SPAM / GDPR). Safe to drop into any
 * marketing page — no props required, but `source` can be passed to tag
 * where the signup happened.
 */
export default function NewsletterSignup({ source = null, className = '' }) {
    const { data, setData, post, processing, wasSuccessful, errors, reset } = useForm({
        email: '',
        consent_marketing: false,
        source: source || (typeof window !== 'undefined' ? window.location.pathname : null),
        website: '', // honeypot — must stay empty
    })

    const submit = (e) => {
        e.preventDefault()
        post('/newsletter', {
            preserveScroll: true,
            onSuccess: () => reset('email', 'consent_marketing'),
        })
    }

    return (
        <form onSubmit={submit} className={`space-y-3 ${className}`} noValidate>
            {/* Honeypot: visually hidden but still accessible to bots that scrape HTML */}
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

            <div className="flex flex-col sm:flex-row gap-2">
                <input
                    type="email"
                    required
                    autoComplete="email"
                    value={data.email}
                    onChange={(e) => setData('email', e.target.value)}
                    placeholder="you@company.com"
                    className="flex-1 rounded-xl bg-white/[0.03] px-4 py-3 text-sm text-white placeholder:text-white/30 ring-1 ring-inset ring-white/[0.08] focus:ring-2 focus:ring-indigo-400/60 focus:outline-none transition-shadow"
                />
                <button
                    type="submit"
                    disabled={processing || !data.consent_marketing}
                    className="inline-flex items-center justify-center rounded-xl bg-white px-5 py-3 text-sm font-semibold text-gray-900 shadow-lg shadow-black/20 hover:bg-gray-100 transition-colors disabled:cursor-not-allowed disabled:opacity-40"
                >
                    {processing ? 'Subscribing…' : 'Subscribe'}
                </button>
            </div>

            <label className="flex items-start gap-2 text-xs text-white/45 cursor-pointer">
                <input
                    type="checkbox"
                    checked={data.consent_marketing}
                    onChange={(e) => setData('consent_marketing', e.target.checked)}
                    className="mt-0.5 h-3.5 w-3.5 rounded border-white/20 bg-white/[0.03] text-indigo-500 focus:ring-indigo-400/60"
                />
                <span>I'd like to receive product updates and occasional news from Jackpot. Unsubscribe anytime.</span>
            </label>

            {errors.email && <p className="text-xs text-rose-300/90">{errors.email}</p>}
            {errors.consent_marketing && <p className="text-xs text-rose-300/90">{errors.consent_marketing}</p>}

            {wasSuccessful && (
                <p className="text-xs text-emerald-300/90" role="status">
                    You're on the list.
                </p>
            )}
        </form>
    )
}
