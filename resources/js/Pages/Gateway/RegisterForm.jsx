import { useForm, usePage } from '@inertiajs/react'
import { useEffect, useMemo, useState } from 'react'
import { firstError } from '../../utils/inertiaErrors'
import { refreshCsrfTokenFromServer } from '../../utils/csrf'

export default function RegisterForm({ context, onToggleLogin }) {
    const { theme, errors: sharedErrors = {} } = usePage().props

    const { data, setData, post, processing, errors: formErrors } = useForm({
        first_name: '',
        last_name: '',
        email: '',
        password: '',
        password_confirmation: '',
        company_name: '',
    })

    const errors = useMemo(
        () => ({ ...sharedErrors, ...formErrors }),
        [sharedErrors, formErrors],
    )

    const [focusedField, setFocusedField] = useState(null)

    useEffect(() => {
        refreshCsrfTokenFromServer().catch(() => {})
    }, [])

    const handleSubmit = async (e) => {
        e.preventDefault()
        try {
            await refreshCsrfTokenFromServer()
        } catch {
            /* still attempt */
        }
        post('/gateway/register')
    }

    const primary = theme?.colors?.primary || '#6366f1'

    const inputBorder = (field) =>
        focusedField === field
            ? `${primary}88`
            : firstError(errors[field])
                ? '#ef444488'
                : 'rgba(255,255,255,0.08)'

    const inputClass = 'w-full px-4 py-3.5 bg-white/[0.04] border rounded-lg text-white placeholder-white/35 focus:outline-none transition-all duration-500'

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
                    Get Started
                </h1>
                <p className="text-sm text-white/60 mt-2 max-w-md">
                    Create your brand workspace
                </p>
            </div>

            <form onSubmit={handleSubmit} className="space-y-4">
                <div className="grid grid-cols-2 gap-3">
                    <div>
                        <input
                            type="text"
                            value={data.first_name}
                            onChange={(e) => setData('first_name', e.target.value)}
                            onFocus={() => setFocusedField('first_name')}
                            onBlur={() => setFocusedField(null)}
                            placeholder="First name"
                            autoComplete="given-name"
                            className={inputClass}
                            style={{ borderColor: inputBorder('first_name') }}
                        />
                        {firstError(errors.first_name) && (
                            <p className="mt-1 text-xs text-red-400/90">{firstError(errors.first_name)}</p>
                        )}
                    </div>
                    <div>
                        <input
                            type="text"
                            value={data.last_name}
                            onChange={(e) => setData('last_name', e.target.value)}
                            onFocus={() => setFocusedField('last_name')}
                            onBlur={() => setFocusedField(null)}
                            placeholder="Last name"
                            autoComplete="family-name"
                            className={inputClass}
                            style={{ borderColor: inputBorder('last_name') }}
                        />
                        {firstError(errors.last_name) && (
                            <p className="mt-1 text-xs text-red-400/90">{firstError(errors.last_name)}</p>
                        )}
                    </div>
                </div>

                <div>
                    <input
                        type="email"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                        onFocus={() => setFocusedField('email')}
                        onBlur={() => setFocusedField(null)}
                        placeholder="Email"
                        autoComplete="email"
                        className={inputClass}
                        style={{ borderColor: inputBorder('email') }}
                    />
                    {firstError(errors.email) && (
                        <p className="mt-1 text-xs text-red-400/90">{firstError(errors.email)}</p>
                    )}
                </div>

                <div>
                    <input
                        type="text"
                        value={data.company_name}
                        onChange={(e) => setData('company_name', e.target.value)}
                        onFocus={() => setFocusedField('company_name')}
                        onBlur={() => setFocusedField(null)}
                        placeholder="Company / Brand name"
                        className={inputClass}
                        style={{ borderColor: inputBorder('company_name') }}
                    />
                    {firstError(errors.company_name) && (
                        <p className="mt-1 text-xs text-red-400/90">{firstError(errors.company_name)}</p>
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
                        autoComplete="new-password"
                        className={inputClass}
                        style={{ borderColor: inputBorder('password') }}
                    />
                    {firstError(errors.password) && (
                        <p className="mt-1 text-xs text-red-400/90">{firstError(errors.password)}</p>
                    )}
                </div>

                <div>
                    <input
                        type="password"
                        value={data.password_confirmation}
                        onChange={(e) => setData('password_confirmation', e.target.value)}
                        onFocus={() => setFocusedField('password_confirmation')}
                        onBlur={() => setFocusedField(null)}
                        placeholder="Confirm password"
                        autoComplete="new-password"
                        className={inputClass}
                        style={{ borderColor: inputBorder('password_confirmation') }}
                    />
                </div>

                <button
                    type="submit"
                    disabled={processing}
                    className="w-full py-3.5 px-6 rounded-lg font-semibold text-white transition-all duration-300 disabled:opacity-40"
                    style={{ backgroundColor: primary }}
                    onMouseEnter={(e) => e.currentTarget.style.opacity = '0.9'}
                    onMouseLeave={(e) => e.currentTarget.style.opacity = '1'}
                >
                    {processing ? 'Creating workspace...' : 'Create Workspace'}
                </button>
            </form>

            <div className="mt-8 text-center">
                <p className="text-sm text-white/50">
                    Already have an account?{' '}
                    <button
                        type="button"
                        onClick={onToggleLogin}
                        className="text-white/70 hover:text-white transition-colors duration-300 underline underline-offset-4 decoration-white/20 hover:decoration-white/50"
                    >
                        Sign in
                    </button>
                </p>
            </div>
        </div>
    )
}
