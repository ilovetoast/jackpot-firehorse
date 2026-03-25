import { usePage } from '@inertiajs/react'
import { useCallback, useEffect, useState } from 'react'
import { togglePush } from '../services/pushService'

const GROUPS = [
    {
        key: 'activity',
        title: 'Activity',
        description: 'Uploads, approvals, generative results',
    },
    {
        key: 'account',
        title: 'Account',
        description: 'Invites, ownership, billing',
    },
    {
        key: 'system',
        title: 'System',
        description: 'Background processing updates',
    },
]

const defaultPreferences = () => ({
    activity: { push: true },
    account: { push: true },
    system: { push: false },
})

function mergePrefs(base, patch) {
    const out = { ...base }
    for (const g of GROUPS) {
        const k = g.key
        out[k] = { ...base[k], ...(patch[k] || {}) }
    }
    return out
}

/**
 * Per-group push toggles + master device registration for browser alerts.
 *
 * Future: email + push unified prefs; per-category quiet hours; multi-device list.
 */
export default function NotificationPreferences() {
    const page = usePage()
    const authUser = page.props.auth?.user
    const pushClientEnabled = Boolean(page.props.oneSignal?.client_enabled)

    const [prefs, setPrefs] = useState(defaultPreferences)
    const [pushEnabled, setPushEnabled] = useState(false)
    const [loading, setLoading] = useState(true)
    const [saving, setSaving] = useState(false)
    const [pushBusy, setPushBusy] = useState(false)
    const [error, setError] = useState(null)
    const [browserPermission, setBrowserPermission] = useState(
        typeof Notification !== 'undefined' ? Notification.permission : 'denied'
    )

    useEffect(() => {
        if (typeof Notification === 'undefined') {
            return
        }
        const sync = () => setBrowserPermission(Notification.permission)
        sync()
        const onFocus = () => sync()
        window.addEventListener('focus', onFocus)
        return () => window.removeEventListener('focus', onFocus)
    }, [])

    const load = useCallback(async () => {
        setError(null)
        try {
            const res = await fetch('/app/api/user/notification-preferences', {
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            })
            if (!res.ok) {
                throw new Error(`Failed to load (${res.status})`)
            }
            const data = await res.json()
            setPrefs(mergePrefs(defaultPreferences(), data.preferences || {}))
            setPushEnabled(data.push_enabled === true)
        } catch (e) {
            setError(e.message || 'Could not load preferences')
            setPrefs(defaultPreferences())
            setPushEnabled(false)
        } finally {
            setLoading(false)
        }
    }, [])

    useEffect(() => {
        void load()
    }, [load])

    const persist = useCallback(
        async (nextPrefs) => {
            const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? ''
            setSaving(true)
            setError(null)
            try {
                const res = await fetch('/app/api/user/notification-preferences', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': token,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ preferences: nextPrefs }),
                })
                if (!res.ok) {
                    const t = await res.text()
                    throw new Error(t || `Save failed (${res.status})`)
                }
                const data = await res.json()
                setPrefs(mergePrefs(defaultPreferences(), data.preferences || {}))
                setPushEnabled(data.push_enabled === true)
            } catch (e) {
                setError(e.message || 'Save failed')
                await load()
            } finally {
                setSaving(false)
            }
        },
        [load]
    )

    const setPush = (groupKey, value) => {
        const patch = {
            [groupKey]: { push: value },
        }
        const next = mergePrefs(prefs, patch)
        setPrefs(next)
        void persist(next)
    }

    const onMasterPushToggleClick = () => {
        if (!authUser?.id || pushBusy || saving) {
            return
        }
        if (!pushClientEnabled) {
            setError(
                'Browser push isn’t enabled for this site yet. Your team can turn it on in the server environment (PUSH_NOTIFICATIONS_ENABLED and ONESIGNAL_APP_ID), then reload this page.'
            )
            return
        }
        void onMasterPushToggle(pushEnabled !== true)
    }

    const onMasterPushToggle = async (nextOn) => {
        if (!authUser?.id || pushBusy || !pushClientEnabled) {
            return
        }
        setPushBusy(true)
        setError(null)
        try {
            await togglePush(authUser, nextOn)
            await load()
            if (typeof Notification !== 'undefined') {
                setBrowserPermission(Notification.permission)
            }
        } catch (e) {
            setError(e.message || 'Could not update push')
            await load()
        } finally {
            setPushBusy(false)
        }
    }

    if (loading) {
        return <p className="text-sm text-gray-500">Loading notification settings…</p>
    }

    const masterOn = pushEnabled === true
    const showAllowCallout = pushClientEnabled && browserPermission === 'default' && !masterOn

    const savingSpinner = (
        <span
            className="inline-block h-4 w-4 shrink-0 animate-spin rounded-full border-2 border-indigo-600 border-t-transparent"
            aria-hidden
        />
    )

    return (
        <div className="space-y-6">
            {error && (
                <p className="text-sm text-red-600" role="alert">
                    {error}
                </p>
            )}
            <p className="text-xs text-gray-500">
                Choose which kinds of updates you care about below.
                {pushClientEnabled ? (
                    <>
                        {' '}
                        Turning on <strong>Push notifications</strong> lets us send alerts to this browser after you allow
                        them in the prompt.
                    </>
                ) : (
                    <> Category choices apply when browser push is available for this site.</>
                )}
            </p>

            {!pushClientEnabled && (
                <div
                    className="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-800"
                    role="status"
                >
                    <p className="font-medium text-gray-900">Browser push isn’t turned on for this site yet</p>
                    <p className="mt-1 text-xs leading-relaxed text-gray-600">
                        You can still choose which kinds of updates you’d want. When your team enables notifications for
                        this app, reload the page and you’ll be able to allow alerts here.
                    </p>
                </div>
            )}

            {showAllowCallout && (
                <div
                    className="rounded-xl border border-indigo-100 bg-gradient-to-br from-indigo-50 to-white px-4 py-4 text-indigo-950 shadow-sm"
                    role="status"
                >
                    <p className="text-sm font-semibold">Get alerts on this device</p>
                    <p className="mt-1 text-xs leading-relaxed text-indigo-900/85">
                        You haven’t allowed notifications in this browser yet. Tap the button and confirm in your
                        browser’s prompt to finish setup.
                    </p>
                    <button
                        type="button"
                        disabled={pushBusy || saving}
                        onClick={() => onMasterPushToggle(true)}
                        className="mt-3 inline-flex w-full items-center justify-center rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50 sm:w-auto"
                    >
                        Allow notifications
                    </button>
                    {pushBusy && (
                        <div
                            className="mt-2 flex items-center gap-2 text-xs text-indigo-900/80"
                            role="status"
                            aria-live="polite"
                        >
                            {savingSpinner}
                            <span>Saving</span>
                        </div>
                    )}
                </div>
            )}

            <div className="rounded-lg border border-gray-200 bg-white px-4 py-4 shadow-sm">
                <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div className="min-w-0">
                        <p className="text-sm font-medium text-gray-900">Push notifications</p>
                        <p className="text-xs text-gray-500">Browser alerts on this device — on or off.</p>
                        {!pushClientEnabled && (
                            <p className="mt-2 text-xs text-gray-500">
                                Unavailable until push is enabled for this app (reload after your team turns it on).
                            </p>
                        )}
                        {browserPermission === 'denied' && (
                            <p className="mt-2 text-xs text-amber-700">
                                Notifications are blocked in your browser settings.
                            </p>
                        )}
                    </div>
                    <div className="flex shrink-0 items-center gap-2">
                        <span className="text-xs text-gray-500">Push</span>
                        <button
                            type="button"
                            role="switch"
                            aria-checked={masterOn}
                            aria-disabled={!pushClientEnabled}
                            title={
                                !pushClientEnabled
                                    ? 'Push is not enabled for this app yet — click for details'
                                    : undefined
                            }
                            disabled={pushBusy || saving}
                            onClick={onMasterPushToggleClick}
                            className={`relative inline-flex h-6 w-11 shrink-0 rounded-full border-2 border-transparent transition-colors focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-50 ${
                                !pushClientEnabled ? 'cursor-pointer opacity-60' : 'cursor-pointer'
                            } ${masterOn ? 'bg-indigo-600' : 'bg-gray-200'}`}
                        >
                            <span
                                className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition ${
                                    masterOn ? 'translate-x-5' : 'translate-x-1'
                                }`}
                            />
                        </button>
                    </div>
                </div>
                {pushBusy && !showAllowCallout && (
                    <div
                        className="mt-3 flex items-center gap-2 text-xs text-gray-600"
                        role="status"
                        aria-live="polite"
                    >
                        {savingSpinner}
                        <span>Saving</span>
                    </div>
                )}
            </div>

            <ul className="divide-y divide-gray-100 rounded-lg border border-gray-200 bg-gray-50/50">
                {GROUPS.map((g) => {
                    const on = prefs[g.key]?.push === true
                    return (
                        <li key={g.key} className="flex flex-col gap-2 px-4 py-4 sm:flex-row sm:items-center sm:justify-between">
                            <div className="min-w-0">
                                <p className="text-sm font-medium text-gray-900">{g.title}</p>
                                <p className="text-xs text-gray-500">{g.description}</p>
                            </div>
                            <div className="flex shrink-0 items-center gap-2">
                                <span className="text-xs text-gray-500">Push</span>
                                <button
                                    type="button"
                                    role="switch"
                                    aria-checked={on}
                                    disabled={saving}
                                    onClick={() => setPush(g.key, !on)}
                                    className={`relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-50 ${
                                        on ? 'bg-indigo-600' : 'bg-gray-200'
                                    }`}
                                >
                                    <span
                                        className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition ${
                                            on ? 'translate-x-5' : 'translate-x-1'
                                        }`}
                                    />
                                </button>
                            </div>
                        </li>
                    )
                })}
            </ul>
            {saving && !pushBusy && (
                <div className="flex items-center gap-2 text-xs text-gray-600" role="status" aria-live="polite">
                    {savingSpinner}
                    <span>Saving</span>
                </div>
            )}
        </div>
    )
}
