import { useForm, usePage } from '@inertiajs/react'
import { useMemo, useState } from 'react'
import { firstError } from '../../utils/inertiaErrors'

export default function LoginForm({ context, onToggleRegister }) {
    const { theme, errors: sharedErrors = {} } = usePage().props

    const { data, setData, post, processing, errors: formErrors } = useForm({
        email: '',
        password: '',
        remember: false,
    })

    const errors = useMemo(
        () => ({ ...sharedErrors, ...formErrors }),
        [sharedErrors, formErrors],
    )

    const [focusedField, setFocusedField] = useState(null)

    const handleSubmit = (e) => {
        e.preventDefault()
        post('/gateway/login')
    }

    const primary = theme?.colors?.primary || '#6366f1'

    const inputBorder = (field) =>
        focusedField === field
            ? `${primary}88`
            : firstError(errors[field])
                ? '#ef444488'
                : 'rgba(255,255,255,0.08)'

    const emailError = firstError(errors.email)
    const passwordError = firstError(errors.password)

    return (
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
                <h1 className="text-4xl md:text-5xl font-semibold tracking-tight leading-tight text-white/95 mb-2">
                    {theme?.name || 'Jackpot'}
                </h1>
                {theme?.tagline && (
                    <p className="text-sm text-white/50 mb-2">{theme.tagline}</p>
                )}
                <p className="text-sm text-white/60 mt-2 max-w-md">
                    {context?.tenant ? `Sign in to ${context.tenant.name}` : 'Sign in to continue'}
                </p>
            </div>

            <form onSubmit={handleSubmit} className="space-y-4">
                <div>
                    <input
                        type="email"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                        onFocus={() => setFocusedField('email')}
                        onBlur={() => setFocusedField(null)}
                        placeholder="Email"
                        autoComplete="email"
                        className="w-full px-4 py-3.5 bg-white/[0.04] border rounded-lg text-white placeholder-white/35 focus:outline-none transition-all duration-500"
                        style={{ borderColor: inputBorder('email') }}
                        aria-label="Email"
                        aria-invalid={emailError ? 'true' : 'false'}
                        aria-describedby={emailError ? 'login-email-error' : undefined}
                    />
                    {emailError && (
                        <p id="login-email-error" role="alert" className="mt-1.5 text-xs text-red-400/90">
                            {emailError}
                        </p>
                    )}
                </div>

                <div>
                    <input
                        type="password"
                        value={data.password}
                        onChange={(e) => setData('password', e.target.value)}
                        onFocus={() => setFocusedField('password')}
                        onBlur={() => setFocusedField(null)}
                        placeholder="Password"
                        autoComplete="current-password"
                        className="w-full px-4 py-3.5 bg-white/[0.04] border rounded-lg text-white placeholder-white/35 focus:outline-none transition-all duration-500"
                        style={{ borderColor: inputBorder('password') }}
                        aria-label="Password"
                        aria-invalid={passwordError ? 'true' : 'false'}
                        aria-describedby={passwordError ? 'login-password-error' : undefined}
                    />
                    {passwordError && (
                        <p id="login-password-error" className="mt-1.5 text-xs text-red-400/90">
                            {passwordError}
                        </p>
                    )}
                </div>

                <div className="flex items-center justify-between text-sm">
                    <label className="flex items-center gap-2 text-white/45 cursor-pointer">
                        <input
                            type="checkbox"
                            checked={data.remember}
                            onChange={(e) => setData('remember', e.target.checked)}
                            className="rounded border-white/20 bg-white/5 text-white/80 focus:ring-0 focus:ring-offset-0"
                        />
                        Remember me
                    </label>
                    <a
                        href="/forgot-password"
                        className="text-white/45 hover:text-white/70 transition-colors duration-300"
                    >
                        Forgot password?
                    </a>
                </div>

                <button
                    type="submit"
                    disabled={processing}
                    className="w-full py-3.5 px-6 rounded-lg font-semibold text-white transition-all duration-300 disabled:opacity-40"
                    style={{ backgroundColor: primary }}
                    onMouseEnter={(e) => e.currentTarget.style.opacity = '0.9'}
                    onMouseLeave={(e) => e.currentTarget.style.opacity = '1'}
                >
                    {processing ? 'Signing in...' : 'Sign In'}
                </button>
            </form>

            <div className="mt-8 text-center">
                <p className="text-sm text-white/50">
                    Don&apos;t have an account?{' '}
                    <button
                        type="button"
                        onClick={onToggleRegister}
                        className="text-white/70 hover:text-white transition-colors duration-300 underline underline-offset-4 decoration-white/20 hover:decoration-white/50"
                    >
                        Create one
                    </button>
                </p>
            </div>
        </div>
    )
}
