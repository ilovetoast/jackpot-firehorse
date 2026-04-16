/**
 * New-user registration for collection-only invite. Cinematic layout matches Gateway invite flow + brand theme.
 */
import { Head, useForm, usePage } from '@inertiajs/react'
import { useEffect, useMemo, useState } from 'react'
import GatewayLayout from '../Gateway/GatewayLayout'
import { firstError } from '../../utils/inertiaErrors'

export default function CollectionInviteRegistration({
    token,
    collection,
    brand,
    email,
    inviter,
}) {
    const { theme, errors: sharedErrors = {}, old } = usePage().props
    const primary = theme?.colors?.primary || '#6366f1'
    const portalInvite = theme?.portal?.invite || {}
    const [focusedField, setFocusedField] = useState(null)

    const initialFormData = useMemo(
        () => ({
            first_name: old?.first_name ?? '',
            last_name: old?.last_name ?? '',
            password: '',
            password_confirmation: '',
        }),
        [old],
    )

    const { data, setData, post, processing, errors: formErrors } = useForm(initialFormData)

    const oldSyncKey = useMemo(() => (old ? JSON.stringify(old) : ''), [old])

    useEffect(() => {
        if (!old || Object.keys(old).length === 0) {
            return
        }
        if (old.first_name !== undefined && old.first_name !== null) {
            setData('first_name', old.first_name)
        }
        if (old.last_name !== undefined && old.last_name !== null) {
            setData('last_name', old.last_name)
        }
    }, [oldSyncKey, old, setData])

    const errors = useMemo(() => ({ ...sharedErrors, ...formErrors }), [sharedErrors, formErrors])

    const handleSubmit = (e) => {
        e.preventDefault()
        post(route('collection-invite.complete', { token }), {
            preserveState: true,
            preserveScroll: true,
            withAllErrors: true,
        })
    }

    const inputClass =
        'w-full px-4 py-3.5 bg-white/[0.04] border rounded-lg text-white placeholder-white/35 focus:outline-none transition-all duration-500'

    const inputBorder = (field) =>
        focusedField === field
            ? `${primary}88`
            : firstError(errors[field])
              ? '#ef444488'
              : 'rgba(255,255,255,0.08)'

    const headline = portalInvite.headline || 'Complete your access'
    const subtext = portalInvite.subtext || null
    const ctaLabel = portalInvite.cta_label || 'Create account & view collection'

    const hasValidationError = ['invitation', 'password', 'password_confirmation', 'first_name', 'last_name'].some(
        (key) => firstError(errors[key]),
    )

    return (
        <>
            <Head title={`${collection?.name ? `${collection.name} · ` : ''}${theme?.name || 'Jackpot'}`} />
            <GatewayLayout>
                <div className="w-full max-w-sm animate-fade-in" style={{ animationDuration: '500ms' }}>
                    <div className="text-center mb-10">
                        <div className="mx-auto h-16 w-16 rounded-2xl bg-white/[0.06] flex items-center justify-center mb-6">
                            <svg
                                className="w-8 h-8 text-white/60"
                                fill="none"
                                viewBox="0 0 24 24"
                                strokeWidth="1.5"
                                stroke="currentColor"
                            >
                                <path
                                    strokeLinecap="round"
                                    strokeLinejoin="round"
                                    d="M2.25 12.75V12A2.25 2.25 0 014.5 9.75h15A2.25 2.25 0 0121.75 12v.75m-8.69-6.44l-2.12-2.12a1.5 1.5 0 00-1.061-.44H4.5A2.25 2.25 0 002.25 6v12a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9a2.25 2.25 0 00-2.25-2.25h-5.379a1.5 1.5 0 01-1.06-.44z"
                                />
                            </svg>
                        </div>
                        <h1 className="text-3xl font-semibold text-white/95 mb-3">{headline}</h1>
                        {subtext ? (
                            <p className="text-white/50 mb-1">{subtext}</p>
                        ) : (
                            <p className="text-white/50 mb-1">
                                {inviter?.name
                                    ? `${inviter.name} invited you to a shared collection`
                                    : 'You’ve been invited to a shared collection'}
                            </p>
                        )}
                        <p className="text-lg font-medium text-white/90 mt-2">{collection?.name}</p>
                        {brand?.name ? <p className="text-xs text-white/40 mt-1">{brand.name}</p> : null}
                        <p className="text-sm text-white/50 mt-3">{email}</p>
                    </div>

                    <form onSubmit={handleSubmit} className="space-y-4" noValidate>
                        {hasValidationError && (
                            <div
                                role="alert"
                                className="rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-200/95"
                            >
                                {firstError(errors.invitation) ? (
                                    <p className="font-medium">{firstError(errors.invitation)}</p>
                                ) : (
                                    <p className="text-red-100/90">Please check the fields below and try again.</p>
                                )}
                            </div>
                        )}

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
                                    aria-invalid={firstError(errors.first_name) ? 'true' : 'false'}
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
                                    aria-invalid={firstError(errors.last_name) ? 'true' : 'false'}
                                />
                                {firstError(errors.last_name) && (
                                    <p className="mt-1 text-xs text-red-400/90">{firstError(errors.last_name)}</p>
                                )}
                            </div>
                        </div>

                        <div>
                            <p className="mb-2 text-xs text-white/40">
                                Choose a strong password. You’ll use it to sign in and open this collection.
                            </p>
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
                                aria-invalid={firstError(errors.password) ? 'true' : 'false'}
                            />
                            {errors.password != null && errors.password !== '' && (
                                <div className="mt-1 text-xs text-red-400/90 space-y-1">
                                    {Array.isArray(errors.password) ? (
                                        errors.password.map((msg, i) => (
                                            <p key={i}>{msg}</p>
                                        ))
                                    ) : (
                                        <p>{errors.password}</p>
                                    )}
                                </div>
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
                                aria-invalid={firstError(errors.password_confirmation) ? 'true' : 'false'}
                            />
                            {firstError(errors.password_confirmation) && (
                                <p className="mt-1 text-xs text-red-400/90">{firstError(errors.password_confirmation)}</p>
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
                            {processing ? 'Creating account…' : ctaLabel}
                        </button>
                    </form>
                </div>
            </GatewayLayout>
        </>
    )
}
