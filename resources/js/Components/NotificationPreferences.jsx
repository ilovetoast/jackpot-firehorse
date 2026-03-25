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
 * Per-group push toggles + master device push (OneSignal).
 *
 * Future: email + push unified prefs; per-category quiet hours; multi-device list.
 */
export default function NotificationPreferences() {
    const page = usePage()
    const authUser = page.props.auth?.user

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

    const onMasterPushToggle = async (nextOn) => {
        if (!authUser?.id || pushBusy) {
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

    return (
        <div className="space-y-6">
            {error && (
                <p className="text-sm text-red-600" role="alert">
                    {error}
                </p>
            )}
            <p className="text-xs text-gray-500">
                Email and other channels can be added here later. Push requires browser permission and the master toggle
                below; category toggles choose which types of updates may be sent.
            </p>

            <div className="rounded-lg border border-gray-200 bg-white px-4 py-4 shadow-sm">
                <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div className="min-w-0">
                        <p className="text-sm font-medium text-gray-900">Push notifications</p>
                        <p className="text-xs text-gray-500">Enable or disable web push for this browser (OneSignal).</p>
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
                            disabled={pushBusy || saving}
                            onClick={() => onMasterPushToggle(!masterOn)}
                            className={`relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-50 ${
                                masterOn ? 'bg-indigo-600' : 'bg-gray-200'
                            }`}
                        >
                            <span
                                className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition ${
                                    masterOn ? 'translate-x-5' : 'translate-x-1'
                                }`}
                            />
                        </button>
                    </div>
                </div>
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
            {(saving || pushBusy) && <p className="text-xs text-gray-400">Saving…</p>}
        </div>
    )
}
