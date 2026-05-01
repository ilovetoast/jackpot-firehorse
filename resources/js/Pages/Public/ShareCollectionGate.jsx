/**
 * Password gate for password-protected collection share links (no assets until unlock).
 */
import { Head, usePage } from '@inertiajs/react'
import { LockClosedIcon } from '@heroicons/react/24/outline'
import { useCdn403Recovery } from '../../hooks/useCdn403Recovery'

export default function ShareCollectionGate({
    collection_title: collectionTitle = 'Collection',
    brand_name: brandName = '',
    branding_options: brandingOptions = {},
    unlock_url: unlockUrl = '',
    cdn_domain: cdnDomain = null,
}) {
    useCdn403Recovery(cdnDomain)
    const { errors } = usePage().props
    const passwordError = errors?.password
    const accentColor = brandingOptions?.accent_color || brandingOptions?.primary_color || '#4F46E5'
    const primaryColor = brandingOptions?.primary_color || accentColor
    const logoUrl = brandingOptions?.logo_url || null
    const themeDark = brandingOptions?.theme_dark ?? false
    const baseBg = themeDark ? '#0a0a0a' : '#f9fafb'
    const textColor = themeDark ? 'text-white' : 'text-gray-900'
    const textMuted = themeDark ? 'text-white/75' : 'text-gray-600'
    const csrf = typeof document !== 'undefined'
        ? document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
        : ''

    return (
        <div className="min-h-screen flex flex-col items-center justify-center px-4 py-12" style={{ backgroundColor: baseBg }}>
            <Head>
                <title>{collectionTitle ? `${collectionTitle} — Shared collection` : 'Shared collection'}</title>
                <meta name="robots" content="noindex, nofollow" />
            </Head>
            <div
                className="w-full max-w-md rounded-2xl border border-gray-200 bg-white p-8 shadow-xl"
                style={{ borderColor: themeDark ? 'rgba(255,255,255,0.08)' : undefined, backgroundColor: themeDark ? '#171717' : '#fff' }}
            >
                {logoUrl ? (
                    <div className="flex justify-center mb-6">
                        <img src={logoUrl} alt="" className="h-12 w-auto max-w-[200px] object-contain" onError={(e) => { e.target.style.display = 'none' }} />
                    </div>
                ) : null}
                <div className="flex justify-center mb-4">
                    <span className="inline-flex h-12 w-12 items-center justify-center rounded-full bg-indigo-50 text-indigo-600">
                        <LockClosedIcon className="h-6 w-6" aria-hidden="true" />
                    </span>
                </div>
                <p className={`text-center text-sm font-medium ${textMuted}`}>{brandName || 'Brand'}</p>
                <h1 className={`mt-1 text-center text-xl font-semibold ${textColor}`}>{collectionTitle}</h1>
                <p className={`mt-3 text-center text-sm ${textMuted}`}>
                    Enter the password to view this shared collection.
                </p>
                <form method="post" action={unlockUrl} className="mt-6 space-y-4">
                    <input type="hidden" name="_token" value={csrf} />
                    <div>
                        <label htmlFor="share-password" className={`block text-sm font-medium ${textColor}`}>
                            Password
                        </label>
                        <input
                            id="share-password"
                            name="password"
                            type="password"
                            autoComplete="off"
                            required
                            className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                            style={themeDark ? { backgroundColor: '#262626', borderColor: '#404040', color: '#fff' } : undefined}
                            aria-invalid={passwordError ? 'true' : 'false'}
                        />
                        {passwordError ? (
                            <p className="mt-2 text-sm text-red-600" role="alert">
                                The password is incorrect.
                            </p>
                        ) : null}
                    </div>
                    <button
                        type="submit"
                        className="flex w-full justify-center rounded-lg px-4 py-2.5 text-sm font-semibold text-white shadow focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                        style={{ backgroundColor: primaryColor }}
                    >
                        Unlock
                    </button>
                </form>
            </div>
        </div>
    )
}
