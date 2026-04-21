import { useEffect, useState } from 'react'
import { usePage, Link } from '@inertiajs/react'
import {
    readStoredConsent,
    writeStoredConsent,
    consentIsCurrent,
    postConsentToServer,
    formatConsentSaveError,
} from '../utils/cookieConsent'
import { retryPerformanceTrackingAfterConsent } from '../utils/performanceTracking'

/**
 * Account settings: cookie / similar-tech preferences (mirrors CookieConsentBanner).
 */
export default function PrivacyPreferences() {
    const { privacy } = usePage().props
    const policyVersion = privacy?.cookie_policy_version || '1'
    const gpc = !!privacy?.gpc

    const [functional, setFunctional] = useState(false)
    const [analytics, setAnalytics] = useState(false)
    const [marketing, setMarketing] = useState(false)
    const [saving, setSaving] = useState(false)
    const [savedFlash, setSavedFlash] = useState(false)
    const [error, setError] = useState(null)

    useEffect(() => {
        const stored = readStoredConsent()
        const server = privacy?.cookie_consent
        if (server?.purposes && server.policy_version === policyVersion) {
            setFunctional(!!server.purposes.functional)
            setAnalytics(!!server.purposes.analytics)
            setMarketing(!!server.purposes.marketing)
            return
        }
        if (stored && consentIsCurrent(stored, policyVersion)) {
            setFunctional(!!stored.purposes?.functional)
            setAnalytics(!!stored.purposes?.analytics)
            setMarketing(!!stored.purposes?.marketing)
        }
    }, [privacy?.cookie_consent?.updated_at, policyVersion])

    const save = async () => {
        setSaving(true)
        setError(null)
        setSavedFlash(false)
        const purposes = gpc
            ? { functional, analytics: false, marketing: false }
            : { functional, analytics, marketing }
        try {
            writeStoredConsent(purposes, policyVersion)
            await postConsentToServer(purposes, policyVersion)
            retryPerformanceTrackingAfterConsent()
            if (purposes.functional) {
                import('../services/pushService')
                    .then((m) => m.loadOneSignalSdkIfConfigured?.())
                    .catch(() => {})
            }
            setSavedFlash(true)
            setTimeout(() => setSavedFlash(false), 2500)
        } catch (e) {
            setError(formatConsentSaveError(e))
        } finally {
            setSaving(false)
        }
    }

    return (
        <div className="space-y-6">
            <p className="text-sm text-gray-600">
                Control optional cookies and similar technologies. See our{' '}
                <Link href="/privacy" className="font-medium text-indigo-600 hover:text-indigo-500">
                    Privacy Policy
                </Link>{' '}
                for details.
            </p>
            {gpc && (
                <p className="rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-900 ring-1 ring-amber-200">
                    Global Privacy Control (GPC) is enabled in your browser — analytics and marketing stay off.
                </p>
            )}
            <ul className="divide-y divide-gray-100 rounded-lg border border-gray-200">
                <li className="flex items-center justify-between gap-4 px-4 py-3">
                    <div>
                        <p className="text-sm font-medium text-gray-900">Strictly necessary</p>
                        <p className="text-xs text-gray-500">Required for the service. Always on.</p>
                    </div>
                    <span className="text-sm font-medium text-emerald-700">On</span>
                </li>
                <li className="flex items-center justify-between gap-4 px-4 py-3">
                    <div>
                        <p className="text-sm font-medium text-gray-900">Functional</p>
                        <p className="text-xs text-gray-500">Push notifications (OneSignal) and similar features.</p>
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
                <li className="flex items-center justify-between gap-4 px-4 py-3">
                    <div>
                        <p className="text-sm font-medium text-gray-900">Analytics</p>
                        <p className="text-xs text-gray-500">Anonymous performance metrics to improve reliability.</p>
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
                <li className="flex items-center justify-between gap-4 px-4 py-3">
                    <div>
                        <p className="text-sm font-medium text-gray-900">Marketing</p>
                        <p className="text-xs text-gray-500">Product news and campaigns where permitted.</p>
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
            <div className="flex flex-wrap items-center gap-3">
                <button
                    type="button"
                    onClick={save}
                    disabled={saving}
                    className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50"
                >
                    {saving ? 'Saving…' : 'Save privacy preferences'}
                </button>
                {savedFlash && <span className="text-sm text-emerald-700">Saved.</span>}
                {error && <span className="text-sm text-red-600">{error}</span>}
            </div>
        </div>
    )
}
