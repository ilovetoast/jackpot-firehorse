import { Head, useForm, Link, usePage } from '@inertiajs/react'
import { useMemo, useState } from 'react'
import GatewayLayout from '../Gateway/GatewayLayout'
import { firstError } from '../../utils/inertiaErrors'

export default function ForgotPassword({ status }) {
    const { flash, theme, errors: sharedErrors = {}, old = {} } = usePage().props
    const displayStatus = status || flash?.status

    const { data, setData, post, processing, errors: formErrors } = useForm({
        email: old.email || '',
    })

    const errors = useMemo(
        () => ({ ...sharedErrors, ...formErrors }),
        [sharedErrors, formErrors],
    )

    const [focusedField, setFocusedField] = useState(null)

    const primary = theme?.colors?.primary || '#7c3aed'

    const inputBorder = (field) =>
        focusedField === field
            ? `${primary}88`
            : firstError(errors[field])
                ? '#ef444488'
                : 'rgba(255,255,255,0.08)'

    const emailError = firstError(errors.email)

    const submit = (e) => {
        e.preventDefault()
        post('/forgot-password', {
            preserveState: true,
            preserveScroll: true,
        })
    }

    return (
        <>
            <Head title={`Forgot password · ${theme?.name || 'Jackpot'}`} />
            <GatewayLayout>
                <div className="w-full max-w-sm animate-fade-in" style={{ animationDuration: '500ms' }}>
                    <div className="text-center mb-10">
                        <div className="flex justify-center mb-6">
                            <div
                                className="h-14 w-14 rounded-xl flex items-center justify-center backdrop-blur"
                                style={{ backgroundColor: `${primary}15` }}
                            >
                                {theme?.logo ? (
                                    <img src={theme.logo} alt={theme.name} className="h-9 object-contain" />
                                ) : (
                                    <span className="text-xl font-semibold text-white">
                                        {theme?.name?.charAt(0) || 'J'}
                                    </span>
                                )}
                            </div>
                        </div>
                        <h1 className="font-display text-4xl md:text-5xl font-semibold tracking-tight leading-tight text-white/95 mb-2">
                            Forgot your password?
                        </h1>
                        <p className="text-sm text-white/60 mt-2 max-w-md mx-auto">
                            No worries! Enter your email address and we&apos;ll send you a link to reset your password.
                        </p>
                    </div>

                    {displayStatus && (
                        <div className="mb-6 px-4 py-3 rounded-lg bg-green-500/10 border border-green-500/20 text-green-300 text-sm text-center">
                            {displayStatus}
                        </div>
                    )}

                    <form className="space-y-4" onSubmit={submit}>
                        <div>
                            <input
                                id="email"
                                name="email"
                                type="email"
                                autoComplete="email"
                                required
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                onFocus={() => setFocusedField('email')}
                                onBlur={() => setFocusedField(null)}
                                placeholder="Email"
                                className="w-full px-4 py-3.5 bg-white/[0.04] border rounded-lg text-white placeholder-white/35 focus:outline-none transition-all duration-500"
                                style={{ borderColor: inputBorder('email') }}
                                aria-label="Email"
                                aria-invalid={emailError ? 'true' : 'false'}
                                aria-describedby={emailError ? 'forgot-email-error' : undefined}
                            />
                            {emailError && (
                                <p id="forgot-email-error" role="alert" className="mt-1.5 text-xs text-red-400/90">
                                    {emailError}
                                </p>
                            )}
                        </div>

                        <button
                            type="submit"
                            disabled={processing}
                            className="w-full py-3.5 px-6 rounded-lg font-semibold text-white transition-all duration-300 disabled:opacity-40"
                            style={{ backgroundColor: primary }}
                            onMouseEnter={(e) => {
                                e.currentTarget.style.opacity = '0.9'
                            }}
                            onMouseLeave={(e) => {
                                e.currentTarget.style.opacity = '1'
                            }}
                        >
                            {processing ? 'Sending...' : 'Send reset link'}
                        </button>
                    </form>

                    <div className="mt-8 text-center space-y-4">
                        <p className="text-sm text-white/50">
                            Remember your password?{' '}
                            <Link
                                href="/gateway"
                                className="text-white/70 hover:text-white transition-colors duration-300 underline underline-offset-4 decoration-white/20 hover:decoration-white/50"
                            >
                                Sign in
                            </Link>
                        </p>
                        <p className="text-sm text-white/50">
                            <Link
                                href="/"
                                className="text-white/70 hover:text-white transition-colors duration-300 underline underline-offset-4 decoration-white/20 hover:decoration-white/50"
                            >
                                ← Back to home
                            </Link>
                        </p>
                    </div>
                </div>
            </GatewayLayout>
        </>
    )
}
