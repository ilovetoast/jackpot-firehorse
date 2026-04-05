/**
 * Logged-in user accepting a collection invite. Matches Gateway invite accept styling.
 */
import { Head, useForm, usePage } from '@inertiajs/react'
import GatewayLayout from '../Gateway/GatewayLayout'

export default function CollectionInviteAccept({ token, collection, brand, email, inviter }) {
    const { theme } = usePage().props
    const primary = theme?.colors?.primary || '#6366f1'
    const portalInvite = theme?.portal?.invite || {}
    const { post, processing } = useForm({})

    const handleAccept = (e) => {
        e.preventDefault()
        post(route('collection-invite.accept.submit', { token }), {
            preserveScroll: true,
            preserveState: true,
            withAllErrors: true,
        })
    }

    const headline = portalInvite.headline || 'Collection invitation'
    const subtext = portalInvite.subtext || null
    const ctaLabel = portalInvite.cta_label || 'Accept invitation'

    return (
        <>
            <Head title={`${collection?.name ? `${collection.name} · ` : ''}${theme?.name || 'Jackpot'}`} />
            <GatewayLayout>
                <div className="w-full max-w-sm animate-fade-in text-center" style={{ animationDuration: '500ms' }}>
                    <div className="mb-10">
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
                            <p className="text-white/50 mb-2">{subtext}</p>
                        ) : (
                            <p className="text-white/50">
                                {inviter?.name ? `${inviter.name} invited you to view` : 'You’ve been invited to view'}
                            </p>
                        )}
                        <p className="text-xl font-medium text-white/90 mt-2">{collection?.name}</p>
                        {brand?.name ? <p className="text-sm text-white/40 mt-1">{brand.name}</p> : null}
                        <p className="text-sm text-white/50 mt-3">Signed in as {email}</p>
                    </div>

                    <form onSubmit={handleAccept}>
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
                            {processing ? 'Accepting…' : ctaLabel}
                        </button>
                    </form>
                </div>
            </GatewayLayout>
        </>
    )
}
