import { useEffect, useMemo, useState } from 'react'
import { Link, usePage } from '@inertiajs/react'
import {
    readStoredConsent,
    writeStoredConsent,
    consentIsCurrent,
    postConsentToServer,
    formatConsentSaveError,
    STORAGE_KEY,
} from '../utils/cookieConsent'
import { retryPerformanceTrackingAfterConsent } from '../utils/performanceTracking'

/**
 * Geo-gated cookie banner (EEA/UK/CH = opt-in; elsewhere = notice + choices).
 * Gates non-essential tech per Privacy Policy §9.
 */
export default function CookieConsentBanner() {
    const { props } = usePage()
    const privacy = props.privacy || {}
    const policyVersion = privacy.cookie_policy_version || window.__jackpotPrivacyBootstrap?.cookie_policy_version || '1'
    const strictOptIn = !!(
        privacy.strict_opt_in_region ?? window.__jackpotPrivacyBootstrap?.strict_opt_in_region
    )
    const gpc = !!(privacy.gpc ?? window.__jackpotPrivacyBootstrap?.gpc)
    const authUser = props.auth?.user

    const isAppShell = useMemo(() => {
        if (typeof window === 'undefined') {
            return false
        }
        return /^\/app(\/|$)/.test(window.location.pathname || '')
    }, [props.url])

    const [open, setOpen] = useState(false)
    const [customize, setCustomize] = useState(false)
    const [functional, setFunctional] = useState(false)
    const [analytics, setAnalytics] = useState(false)
    const [marketing, setMarketing] = useState(false)
    const [saving, setSaving] = useState(false)
    const [error, setError] = useState(null)

    const serverConsentUpdated = privacy.cookie_consent?.updated_at

    useEffect(() => {
        const stored = readStoredConsent()
        const serverConsent = privacy.cookie_consent

        if (authUser && serverConsent?.purposes && serverConsent.policy_version === policyVersion) {
            writeStoredConsent(serverConsent.purposes, policyVersion)
            setOpen(false)
            return
        }

        if (stored && consentIsCurrent(stored, policyVersion)) {
            setFunctional(!!stored.purposes?.functional)
            setAnalytics(!!stored.purposes?.analytics)
            setMarketing(!!stored.purposes?.marketing)
            setOpen(false)
            return
        }

        if (stored && !consentIsCurrent(stored, policyVersion)) {
            localStorage.removeItem(STORAGE_KEY)
        }

        setFunctional(false)
        setAnalytics(false)
        setMarketing(false)
        setOpen(true)
    }, [authUser?.id, policyVersion, serverConsentUpdated])

    const applyGpc = () => {
        if (gpc) {
            setAnalytics(false)
            setMarketing(false)
        }
    }

    useEffect(() => {
        applyGpc()
    }, [gpc])

    const save = async (next) => {
        setSaving(true)
        setError(null)
        const purposes = gpc
            ? { ...next, analytics: false, marketing: false }
            : next
        try {
            writeStoredConsent(purposes, policyVersion)
            await postConsentToServer(purposes, policyVersion)
            setFunctional(purposes.functional)
            setAnalytics(purposes.analytics)
            setMarketing(purposes.marketing)
            setCustomize(false)
            setOpen(false)
            retryPerformanceTrackingAfterConsent()
            if (purposes.functional) {
                import('../services/pushService')
                    .then((m) => m.loadOneSignalSdkIfConfigured?.())
                    .catch(() => {})
            }
            window.dispatchEvent(new CustomEvent('jackpot:cookie-consent-updated', { detail: { purposes } }))
        } catch (e) {
            setError(formatConsentSaveError(e))
        } finally {
            setSaving(false)
        }
    }

    const onAcceptAll = () => save({ functional: true, analytics: true, marketing: true })
    const onRejectNonEssential = () => save({ functional: false, analytics: false, marketing: false })
    const onSaveCustom = () => save({ functional, analytics, marketing })

    if (!open) {
        return null
    }

    const barClass = isAppShell
        ? 'border-t border-gray-200 bg-white text-gray-900 shadow-[0_-4px_24px_rgba(0,0,0,0.08)]'
        : 'border-t border-white/10 bg-neutral-950/95 text-white backdrop-blur-md'

    const btnPrimary = isAppShell
        ? 'rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-indigo-500'
        : 'rounded-md bg-white px-3 py-1.5 text-sm font-semibold text-neutral-900 hover:bg-white/90'

    const btnSecondary = isAppShell
        ? 'rounded-md bg-white px-3 py-1.5 text-sm font-semibold text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50'
        : 'rounded-md border border-white/20 bg-transparent px-3 py-1.5 text-sm font-semibold text-white/90 hover:bg-white/10'

    const muted = isAppShell ? 'text-gray-600' : 'text-white/65'

    return (
        <div
            className={`fixed inset-x-0 bottom-0 z-[200] ${barClass}`}
            role="dialog"
            aria-modal="true"
            aria-labelledby="cookie-consent-title"
        >
            <div className="mx-auto max-w-5xl px-4 py-4 sm:px-6 lg:px-8">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div className={`min-w-0 text-sm ${muted}`}>
                        <p id="cookie-consent-title" className={`font-medium ${isAppShell ? 'text-gray-900' : 'text-white'}`}>
                            {strictOptIn ? 'Cookies & similar technologies' : 'We respect your privacy'}
                        </p>
                        <p className="mt-1 leading-relaxed">
                            We use strictly necessary cookies to run the service. With your permission we also use optional
                            cookies for push notifications, analytics, and marketing.{' '}
                            <Link
                                href="/privacy"
                                className={isAppShell ? 'font-medium text-indigo-600 hover:text-indigo-500' : 'font-medium text-white underline-offset-2 hover:underline'}
                            >
                                Privacy Policy
                            </Link>
                            {gpc && (
                                <span className="mt-1 block text-xs opacity-90">
                                    Global Privacy Control (GPC) is on — analytics and marketing stay off.
                                </span>
                            )}
                        </p>
                    </div>
                    <div className="flex flex-shrink-0 flex-wrap items-center gap-2 sm:justify-end">
                        <button type="button" className={btnSecondary} onClick={() => setCustomize(true)} disabled={saving}>
                            Customize
                        </button>
                        <button type="button" className={btnSecondary} onClick={onRejectNonEssential} disabled={saving}>
                            {strictOptIn ? 'Reject non-essential' : 'Essential only'}
                        </button>
                        <button type="button" className={btnPrimary} onClick={onAcceptAll} disabled={saving}>
                            {saving ? 'Saving…' : 'Accept all'}
                        </button>
                    </div>
                </div>
                {error && <p className="mt-2 text-sm text-red-600">{error}</p>}
            </div>

            {customize && (
                <div className="fixed inset-0 z-[201] flex items-end justify-center bg-black/50 p-4 sm:items-center">
                    <div
                        className={`max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-xl p-6 shadow-xl ${
                            isAppShell ? 'bg-white text-gray-900 ring-1 ring-gray-200' : 'bg-neutral-900 text-white ring-1 ring-white/10'
                        }`}
                        role="document"
                    >
                        <h3 className="text-lg font-semibold">Cookie preferences</h3>
                        <p className={`mt-2 text-sm ${isAppShell ? 'text-gray-600' : 'text-white/70'}`}>
                            Toggle optional categories. You can change this anytime in Profile → Privacy & cookies.
                        </p>
                        <ul className="mt-4 space-y-4">
                            <li className="flex items-start justify-between gap-3">
                                <div>
                                    <p className="font-medium">Strictly necessary</p>
                                    <p className={`text-sm ${isAppShell ? 'text-gray-500' : 'text-white/55'}`}>
                                        Required for login, security, and core features. Always on.
                                    </p>
                                </div>
                                <span className="text-sm font-medium text-emerald-600">On</span>
                            </li>
                            <li className="flex items-start justify-between gap-3">
                                <div>
                                    <p className="font-medium">Functional</p>
                                    <p className={`text-sm ${isAppShell ? 'text-gray-500' : 'text-white/55'}`}>
                                        Push notifications and similar features (e.g. OneSignal).
                                    </p>
                                </div>
                                <label className="inline-flex cursor-pointer items-center">
                                    <input
                                        type="checkbox"
                                        className="h-4 w-4 rounded border-gray-300 text-indigo-600"
                                        checked={functional}
                                        onChange={(e) => setFunctional(e.target.checked)}
                                    />
                                </label>
                            </li>
                            <li className="flex items-start justify-between gap-3">
                                <div>
                                    <p className="font-medium">Analytics</p>
                                    <p className={`text-sm ${isAppShell ? 'text-gray-500' : 'text-white/55'}`}>
                                        Helps us measure performance and improve the product.
                                    </p>
                                </div>
                                <label className="inline-flex cursor-pointer items-center">
                                    <input
                                        type="checkbox"
                                        className="h-4 w-4 rounded border-gray-300 text-indigo-600 disabled:opacity-50"
                                        checked={analytics}
                                        disabled={gpc}
                                        onChange={(e) => setAnalytics(e.target.checked)}
                                    />
                                </label>
                            </li>
                            <li className="flex items-start justify-between gap-3">
                                <div>
                                    <p className="font-medium">Marketing</p>
                                    <p className={`text-sm ${isAppShell ? 'text-gray-500' : 'text-white/55'}`}>
                                        Relevant updates and campaigns (where permitted).
                                    </p>
                                </div>
                                <label className="inline-flex cursor-pointer items-center">
                                    <input
                                        type="checkbox"
                                        className="h-4 w-4 rounded border-gray-300 text-indigo-600 disabled:opacity-50"
                                        checked={marketing}
                                        disabled={gpc}
                                        onChange={(e) => setMarketing(e.target.checked)}
                                    />
                                </label>
                            </li>
                        </ul>
                        <div className="mt-6 flex justify-end gap-2">
                            <button
                                type="button"
                                className={btnSecondary}
                                onClick={() => setCustomize(false)}
                                disabled={saving}
                            >
                                Cancel
                            </button>
                            <button type="button" className={btnPrimary} onClick={onSaveCustom} disabled={saving}>
                                {saving ? 'Saving…' : 'Save preferences'}
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    )
}
