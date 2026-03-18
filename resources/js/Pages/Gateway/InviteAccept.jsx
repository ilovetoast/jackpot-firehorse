import { useForm, usePage } from '@inertiajs/react'
import { useState } from 'react'

export default function InviteAccept({ invitation, isAuthenticated, token }) {
    const { theme } = usePage().props
    const primary = theme?.colors?.primary || '#6366f1'
    const portalInvite = theme?.portal?.invite || {}
    const [focusedField, setFocusedField] = useState(null)

    if (isAuthenticated) {
        return <AuthenticatedAccept invitation={invitation} token={token} primary={primary} portalInvite={portalInvite} />
    }

    return <GuestRegistration invitation={invitation} token={token} primary={primary} portalInvite={portalInvite} focusedField={focusedField} setFocusedField={setFocusedField} />
}

function AuthenticatedAccept({ invitation, token, primary, portalInvite }) {
    const { post, processing } = useForm()

    const handleAccept = () => {
        post(`/gateway/invite/${token}/accept`)
    }

    const headline = portalInvite.headline || "You're Invited"
    const subtext = portalInvite.subtext || null
    const ctaLabel = portalInvite.cta_label || 'Accept Invitation'

    return (
        <div className="w-full max-w-sm animate-fade-in text-center" style={{ animationDuration: '500ms' }}>
            <div className="mb-10">
                <div className="mx-auto h-16 w-16 rounded-2xl bg-white/[0.06] flex items-center justify-center mb-6">
                    <svg className="w-8 h-8 text-white/60" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" />
                    </svg>
                </div>
                <h1 className="text-3xl font-semibold text-white/95 mb-3">
                    {headline}
                </h1>
                {subtext ? (
                    <p className="text-white/50 mb-2">{subtext}</p>
                ) : (
                    <p className="text-white/50">
                        {invitation?.inviter_name ? `${invitation.inviter_name} invited you to join` : 'You have been invited to join'}
                    </p>
                )}
                <p className="text-xl font-medium text-white/90 mt-2">
                    {invitation?.tenant_name}
                </p>
            </div>

            {invitation?.brand_assignments?.length > 0 && (
                <div className="mb-8 text-left">
                    <p className="text-xs uppercase tracking-widest text-white/30 mb-3">Brand Access</p>
                    <div className="space-y-2">
                        {invitation.brand_assignments.map((ba, i) => (
                            <div key={i} className="flex items-center justify-between px-4 py-2.5 rounded-lg bg-white/[0.03] border border-white/[0.06]">
                                <span className="text-sm text-white/70">Brand #{ba.brand_id}</span>
                                <span className="text-xs text-white/40 capitalize">{ba.role}</span>
                            </div>
                        ))}
                    </div>
                </div>
            )}

            <button
                type="button"
                onClick={handleAccept}
                disabled={processing}
                className="w-full py-3.5 px-6 rounded-lg font-semibold text-white transition-all duration-300 disabled:opacity-40"
                style={{ backgroundColor: primary }}
                onMouseEnter={(e) => e.currentTarget.style.opacity = '0.9'}
                onMouseLeave={(e) => e.currentTarget.style.opacity = '1'}
            >
                {processing ? 'Joining...' : ctaLabel}
            </button>
        </div>
    )
}

function GuestRegistration({ invitation, token, primary, portalInvite, focusedField, setFocusedField }) {
    const { data, setData, post, processing, errors } = useForm({
        first_name: '',
        last_name: '',
        password: '',
        password_confirmation: '',
    })

    const handleSubmit = (e) => {
        e.preventDefault()
        post(`/gateway/invite/${token}/complete`)
    }

    const inputClass = 'w-full px-4 py-3.5 bg-white/[0.04] border rounded-lg text-white placeholder-white/35 focus:outline-none transition-all duration-500'

    const inputBorder = (field) =>
        focusedField === field
            ? `${primary}88`
            : errors[field]
                ? '#ef444488'
                : 'rgba(255,255,255,0.08)'

    const headline = portalInvite.headline || 'Complete Your Account'
    const subtext = portalInvite.subtext || null
    const ctaLabel = portalInvite.cta_label || 'Create Account & Join'

    return (
        <div className="w-full max-w-sm animate-fade-in" style={{ animationDuration: '500ms' }}>
            <div className="text-center mb-10">
                <h1 className="text-3xl font-semibold text-white/95 mb-3">
                    {headline}
                </h1>
                {subtext ? (
                    <p className="text-white/50 mb-1">{subtext}</p>
                ) : (
                    <p className="text-white/50 mb-1">
                        {invitation?.inviter_name ? `${invitation.inviter_name} invited you to join` : 'You have been invited to join'}
                    </p>
                )}
                <p className="text-lg font-medium text-white/80">
                    {invitation?.tenant_name}
                </p>
                <p className="text-sm text-white/50 mt-2">
                    {invitation?.email}
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
                        {errors.first_name && <p className="mt-1 text-xs text-red-400/90">{errors.first_name}</p>}
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
                        {errors.last_name && <p className="mt-1 text-xs text-red-400/90">{errors.last_name}</p>}
                    </div>
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
                    {errors.password && <p className="mt-1 text-xs text-red-400/90">{errors.password}</p>}
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

                {errors.invitation && (
                    <p className="text-xs text-red-400/90 text-center">{errors.invitation}</p>
                )}

                <button
                    type="submit"
                    disabled={processing}
                    className="w-full py-3.5 px-6 rounded-lg font-semibold text-white transition-all duration-300 disabled:opacity-40"
                    style={{ backgroundColor: primary }}
                    onMouseEnter={(e) => e.currentTarget.style.opacity = '0.9'}
                    onMouseLeave={(e) => e.currentTarget.style.opacity = '1'}
                >
                    {processing ? 'Setting up...' : ctaLabel}
                </button>
            </form>
        </div>
    )
}
