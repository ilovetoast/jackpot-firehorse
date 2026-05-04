/**
 * Password gate for password-protected collection share links (no assets until unlock).
 */
import { useEffect, useRef, useState } from 'react'
import { Head, usePage } from '@inertiajs/react'
import { LockClosedIcon, EyeIcon, EyeSlashIcon } from '@heroicons/react/24/outline'
import { useCdn403Recovery } from '../../hooks/useCdn403Recovery'
import FilmGrainOverlay from '../../Components/FilmGrainOverlay'
import { contrastTextOnPrimary } from '../../utils/contrastTextOnPrimary'
import { publicShareCinemaLayers } from '../../utils/publicShareCinemaBackground'

export default function ShareCollectionGate({
    collection_title: collectionTitle = 'Collection',
    brand_name: brandName = '',
    branding_options: brandingOptions = {},
    public_share_theme: publicShareThemeProp = null,
    unlock_url: unlockUrl = '',
    cdn_domain: cdnDomain = null,
}) {
    useCdn403Recovery(cdnDomain)
    const { errors } = usePage().props
    const passwordError = errors?.password
    const theme = publicShareThemeProp || brandingOptions || {}
    const primaryColor = theme.primary_color || theme.accent_color || '#7c3aed'
    const accentColor = theme.accent_color || primaryColor
    const logoUrl = theme.logo_url || null
    const { color: unlockBtnText } = contrastTextOnPrimary(primaryColor)
    const { cinemaBase, noPhoto: cinemaStackNoPhoto } = publicShareCinemaLayers(primaryColor, accentColor)
    const passwordRef = useRef(null)
    const [showPassword, setShowPassword] = useState(false)

    useEffect(() => {
        passwordRef.current?.focus()
    }, [])

    const csrf =
        typeof document !== 'undefined' ? document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '' : ''

    return (
        <div className="min-h-screen relative overflow-hidden text-white" style={{ backgroundColor: cinemaBase }}>
            <Head>
                <title>{collectionTitle ? `${collectionTitle} — Shared collection` : 'Shared collection'}</title>
                <meta name="robots" content="noindex, nofollow" />
            </Head>

            <div className="fixed inset-0 pointer-events-none" aria-hidden>
                <div className="absolute inset-0" style={{ background: cinemaStackNoPhoto }} />
                <div className="absolute inset-0 bg-[radial-gradient(ellipse_85%_55%_at_50%_0%,rgba(255,255,255,0.04)_0%,transparent_55%)]" />
                <div className="absolute inset-0 bg-gradient-to-b from-black/25 via-transparent to-black/70" />
                <div className="absolute inset-0 bg-gradient-to-r from-black/50 via-transparent to-black/50 opacity-80" />
            </div>

            <div className="relative z-10 min-h-screen flex flex-col items-center justify-center px-4 py-16">
                <div className="w-full max-w-md rounded-2xl border border-white/[0.08] bg-zinc-950/35 p-8 shadow-2xl shadow-black/50 backdrop-blur-xl ring-1 ring-white/[0.04]">
                    {logoUrl ? (
                        <div className="flex justify-center mb-6">
                            <img
                                src={logoUrl}
                                alt=""
                                className="h-14 w-auto max-w-[220px] object-contain"
                                onError={(e) => {
                                    e.target.style.display = 'none'
                                }}
                            />
                        </div>
                    ) : (
                        <div className="flex justify-center mb-6">
                            <span
                                className="inline-flex h-14 w-14 items-center justify-center rounded-2xl border border-white/15 bg-white/5 text-white/90"
                                aria-hidden
                            >
                                <LockClosedIcon className="h-7 w-7" />
                            </span>
                        </div>
                    )}
                    <p className="text-center text-xs font-medium uppercase tracking-[0.2em] text-white/45">Protected collection</p>
                    <p className="text-center text-sm font-medium text-white/70 mt-2">{brandName || 'Brand'}</p>
                    <h1 className="mt-2 text-center text-2xl font-semibold tracking-tight text-white">{collectionTitle}</h1>
                    <p className="mt-4 text-center text-sm text-white/60 leading-relaxed">
                        Enter the password to view this shared collection.
                    </p>
                    <form method="post" action={unlockUrl} className="mt-8 space-y-5">
                        <input type="hidden" name="_token" value={csrf} />
                        <div>
                            <label htmlFor="share-password" className="block text-sm font-medium text-white/80">
                                Password
                            </label>
                            <div className="relative mt-2">
                                <input
                                    ref={passwordRef}
                                    id="share-password"
                                    name="password"
                                    type={showPassword ? 'text' : 'password'}
                                    autoComplete="off"
                                    required
                                    className="block w-full rounded-xl border border-white/15 bg-black/35 py-2.5 pl-3 pr-11 text-sm text-white placeholder:text-white/35 shadow-inner focus:border-white/30 focus:outline-none focus:ring-2 focus:ring-white/20"
                                    aria-invalid={passwordError ? 'true' : 'false'}
                                />
                                <button
                                    type="button"
                                    className="absolute inset-y-0 right-0 flex items-center pr-3 text-white/45 hover:text-white/80"
                                    onClick={() => setShowPassword((v) => !v)}
                                    aria-label={showPassword ? 'Hide password' : 'Show password'}
                                    tabIndex={-1}
                                >
                                    {showPassword ? (
                                        <EyeSlashIcon className="h-5 w-5" aria-hidden />
                                    ) : (
                                        <EyeIcon className="h-5 w-5" aria-hidden />
                                    )}
                                </button>
                            </div>
                            {passwordError ? (
                                <p className="mt-2 text-sm text-red-300" role="alert">
                                    The password is incorrect.
                                </p>
                            ) : null}
                        </div>
                        <button
                            type="submit"
                            className="flex w-full justify-center rounded-xl px-4 py-3 text-sm font-semibold shadow-lg transition hover:opacity-95 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white/30"
                            style={{ backgroundColor: primaryColor, color: unlockBtnText }}
                        >
                            Unlock
                        </button>
                    </form>
                    <p className="mt-6 text-center text-[11px] uppercase tracking-widest text-white/25">Secure shared link</p>
                </div>
            </div>

            <FilmGrainOverlay />
        </div>
    )
}
